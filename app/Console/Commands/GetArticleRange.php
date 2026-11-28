<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\Binaries\ArticleRangeFloor;
use App\Services\Binaries\BinariesService;
use App\Services\Distributed\BackfillExecutionGuard;
use App\Services\Distributed\CurrentForwardPermitGate;
use App\Services\NNTP\NntpArticleDate;
use App\Services\NNTP\NNTPService;
use App\Services\Orchestrator\CurrentForwardRefreshTrustPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GetArticleRange extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'articles:get-range
                            {mode : Mode: binaries or backfill}
                            {group : Group name}
                            {first : First article number}
                            {last : Last article number}
                            {--backfill-generation= : Claim one exact orchestrator backfill range}
                            {--current-forward-generation= : Claim one exact orchestrator current-forward permit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get a range of article headers for a group';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mode = $this->argument('mode');
        $groupName = $this->argument('group');
        $firstArticle = (int) $this->argument('first');
        $lastArticle = (int) $this->argument('last');
        $generationOption = $this->option('current-forward-generation');
        $currentForward = is_numeric($generationOption) && (int) $generationOption > 0;
        $backfillGenerationOption = $this->option('backfill-generation');
        $managedBackfillGeneration = is_numeric($backfillGenerationOption) && (int) $backfillGenerationOption > 0
            ? (int) $backfillGenerationOption
            : null;
        $claimedGeneration = null;
        $permitGate = null;
        $backfillGuard = null;
        $backfillReceiptId = 0;
        $rangeFloor = null;
        $claimedRangeGroupId = 0;
        $rangeSettled = false;

        if (! \in_array($mode, ['binaries', 'backfill'], true)) {
            $this->error('Mode must be either "binaries" or "backfill".');

            return self::FAILURE;
        }
        if ((new CurrentForwardRefreshTrustPolicy)->protects((string) $groupName) && ! $currentForward) {
            $this->error("Group {$groupName} requires an exact current-forward permit.");

            return self::FAILURE;
        }
        if ($generationOption !== null && ! $currentForward) {
            $this->error('Current-forward generation must be a positive integer.');

            return self::FAILURE;
        }
        if ($mode !== 'backfill' && $backfillGenerationOption !== null) {
            $this->error('Backfill generation may be used only in backfill mode.');

            return self::FAILURE;
        }

        try {
            if ($mode === 'backfill') {
                $backfillGuard = app(BackfillExecutionGuard::class);
                if ($backfillGuard->enforcementEnabled() && $managedBackfillGeneration === null) {
                    $backfillGuard->assertLegacyCommandAllowed('articles:get-range backfill');
                }
                if ($managedBackfillGeneration !== null) {
                    $backfillReceiptId = $backfillGuard->claimRange(
                        $managedBackfillGeneration,
                        (string) $groupName,
                        $firstArticle,
                        $lastArticle,
                    );
                }
            }
            if ($currentForward) {
                if ($mode !== 'binaries') {
                    throw new \RuntimeException('Current-forward permits require binaries mode.');
                }
                $requestedGeneration = (int) $generationOption;
                $permitGate = app(CurrentForwardPermitGate::class);
                if ($permitGate->claim($requestedGeneration, (string) $groupName, $firstArticle, $lastArticle) === null) {
                    throw new \RuntimeException('Current-forward permit was absent, stale, or did not match the exact range.');
                }
                $claimedGeneration = $requestedGeneration;
            }
            $nntp = $this->getNntp();
            $groupMySQL = UsenetGroup::getByName($groupName)->toArray();

            if ($groupMySQL === null) {
                $this->error("Group not found: {$groupName}");

                return self::FAILURE;
            }

            $groupSummary = $nntp->selectGroup($groupMySQL['name']);
            if (NNTPService::isError($groupSummary)) {
                $groupSummary = $nntp->dataError($nntp, $groupMySQL['name']);
            }
            if (NNTPService::isError($groupSummary) || ! is_array($groupSummary)) {
                throw new \RuntimeException('Unable to select the current-forward group from the provider.');
            }

            $this->refreshSelectedProviderRange($groupMySQL['name'], $groupSummary);
            $selectedRange = $this->clampToSelectedProviderRange($firstArticle, $lastArticle, $groupSummary);
            if ($currentForward && $selectedRange !== [$firstArticle, $lastArticle]) {
                throw new \RuntimeException('Provider range would clamp or omit the exact current-forward window.');
            }
            if ($managedBackfillGeneration !== null && $selectedRange !== [$firstArticle, $lastArticle]) {
                throw new \RuntimeException('Provider range would clamp or omit the exact managed backfill interval.');
            }
            if ($selectedRange === null) {
                $this->info("Requested range {$firstArticle}-{$lastArticle} is outside the selected provider range");

                return self::SUCCESS;
            }
            [$firstArticle, $lastArticle] = $selectedRange;

            $binaries = new BinariesService;
            $binaries->setNntp($nntp);

            // Claimed before the fetch, not after it fails. The ~20 children
            // of one cycle each advance this group's cursor with a monotonic
            // max, and the fetch is the slow part of a child's life, so a
            // failure recorded afterwards arrives when siblings have already
            // carried the cursor past this range. Claiming first protects the
            // range for the whole time it is at risk.
            $rangeFloor = app(ArticleRangeFloor::class);
            $claimedRangeGroupId = (int) $groupMySQL['id'];
            $rangeFloor->claim($mode, $claimedRangeGroupId, $firstArticle, $lastArticle);

            try {
                $return = $binaries->scan(
                    $groupMySQL,
                    $firstArticle,
                    $lastArticle,
                    ((int) Settings::settingValue('safepartrepair') === 1 ? 'update' : 'backfill'),
                    currentForwardPermit: $currentForward,
                    currentForwardGeneration: $claimedGeneration,
                    failOnStorageError: $currentForward || $managedBackfillGeneration !== null,
                );
            } finally {
                if ($binaries->lastFetchFailed()) {
                    $rangeFloor->markFailed($mode, $claimedRangeGroupId, $firstArticle, $lastArticle);
                    $rangeSettled = true;
                }
            }

            if (empty($return)) {
                if ($currentForward) {
                    throw new \RuntimeException('Provider returned no usable headers for the exact current-forward window.');
                }
                if ($managedBackfillGeneration !== null) {
                    throw new \RuntimeException('Provider returned no usable headers for the exact managed backfill interval.');
                }

                // An empty range that was actually read holds nothing back;
                // the cursor has to be allowed past it or it stalls there.
                if (! $rangeSettled) {
                    $rangeFloor->release($mode, $claimedRangeGroupId, $firstArticle, $lastArticle);
                    $rangeSettled = true;
                }

                return self::SUCCESS;
            }
            if ($currentForward
                && ((int) ($return['firstArticleNumber'] ?? 0) !== $firstArticle
                    || (int) ($return['lastArticleNumber'] ?? 0) !== $lastArticle)
            ) {
                throw new \RuntimeException('Provider did not return both exact current-forward boundaries.');
            }

            // Released before the cursor update, so this child's own range
            // does not bound its own advance.
            if (! $rangeSettled) {
                $rangeFloor->release($mode, $claimedRangeGroupId, $firstArticle, $lastArticle);
                $rangeSettled = true;
            }

            $this->updateGroupRecords($mode, $groupMySQL, $return, $firstArticle);
            if ($currentForward && ! $permitGate->complete((int) $claimedGeneration)) {
                throw new \RuntimeException('Current-forward cursor completion receipt could not be recorded.');
            }
            if ($backfillReceiptId > 0 && ! $backfillGuard?->completeRange($backfillReceiptId)) {
                throw new \RuntimeException('Backfill range completion receipt could not be recorded.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // A claim taken and not settled means the range was not read, so
            // it must keep holding the cursor -- and be counted, so a range
            // that throws every cycle is written off rather than wedging the
            // group for as long as the claim lives.
            if ($rangeFloor !== null && ! $rangeSettled && $claimedRangeGroupId > 0) {
                $rangeFloor->markFailed($mode, $claimedRangeGroupId, $firstArticle, $lastArticle);
            }
            if ($claimedGeneration !== null && $permitGate !== null) {
                $permitGate->fail($claimedGeneration, $e->getMessage());
            }
            if ($backfillReceiptId > 0 && $backfillGuard !== null) {
                $backfillGuard->failRange($backfillReceiptId, $e->getMessage());
            }
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $groupSummary
     * @return array{int, int}|null
     */
    private function clampToSelectedProviderRange(int $firstArticle, int $lastArticle, array $groupSummary): ?array
    {
        $providerFirst = (int) ($groupSummary['first'] ?? 0);
        $providerLast = (int) ($groupSummary['last'] ?? 0);
        if ($firstArticle <= 0 || $lastArticle < $firstArticle || $providerFirst <= 0 || $providerLast < $providerFirst) {
            return null;
        }

        $first = max($firstArticle, $providerFirst);
        $last = min($lastArticle, $providerLast);

        return $first <= $last ? [$first, $last] : null;
    }

    /** @param array<string, mixed> $groupSummary */
    private function refreshSelectedProviderRange(string $groupName, array $groupSummary): void
    {
        $providerFirst = (int) ($groupSummary['first'] ?? 0);
        $providerLast = (int) ($groupSummary['last'] ?? 0);
        if ($providerFirst <= 0 || $providerLast < $providerFirst) {
            return;
        }

        DB::table('short_groups')->where('name', $groupName)->update([
            'first_record' => $providerFirst,
            'last_record' => $providerLast,
            'updated' => now(),
        ]);
    }

    /**
     * Update group records based on mode.
     *
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $return
     */
    private function updateGroupRecords(string $mode, array $groupMySQL, array $return, int $rangeFirstArticle): void
    {
        switch ($mode) {
            case 'binaries':
                $rangeFloor = app(ArticleRangeFloor::class);
                $groupId = (int) $groupMySQL['id'];
                // The furthest article the cycle actually read, which may be a
                // sibling's range rather than this child's: whichever child
                // finishes last is the only one that can see the whole cycle,
                // and it is not necessarily the highest chunk.
                $lastRecord = max((int) $return['lastArticleNumber'], (int) $rangeFloor->readMaxFor($groupId));
                // Never past a range a sibling is still fetching or has failed
                // to fetch. The WHERE below means a capped value at or beneath
                // the current cursor is a no-op, not a regression.
                $floor = $rangeFloor->floorFor($groupId);

                if ($floor !== null && $lastRecord > $floor) {
                    // Logged, not printed: a get_range child's stdout is read
                    // by nobody, and a held cursor is the operational event an
                    // operator needs to see if a range keeps failing.
                    Log::notice(sprintf(
                        'Holding %s at article %d: a lower range of this cycle is unread.',
                        (string) ($groupMySQL['name'] ?? 'unknown'),
                        $floor,
                    ));
                    $lastRecord = $floor;
                } else {
                    $rangeFloor->pruneIfSettled(ArticleRangeFloor::LANE_FORWARD, $groupId);
                }

                if ($lastRecord <= (int) $groupMySQL['last_record']) {
                    return;
                }
                $unixTime = NntpArticleDate::timestamp($return['lastArticleDate'] ?? null);
                $updates = [
                    'last_record' => $lastRecord,
                    'last_updated' => now()->toDateTimeString(),
                ];
                if ($unixTime !== null) {
                    $updates['last_record_postdate'] = date('Y-m-d H:i:s', $unixTime);
                }
                DB::table('usenet_groups')
                    ->where('id', $groupMySQL['id'])
                    ->where('last_record', '<', $lastRecord)
                    ->update($updates);
                break;

            case 'backfill':
                $rangeFloor = app(ArticleRangeFloor::class);
                $groupId = (int) $groupMySQL['id'];
                $readMin = $rangeFloor->readMinFor($groupId);
                $firstRecord = $readMin === null
                    ? (int) $return['firstArticleNumber']
                    : min((int) $return['firstArticleNumber'], $readMin);
                // Backfill walks the other way, so the bound is a ceiling: the
                // cursor must not drop below a range nobody has read.
                $ceiling = $rangeFloor->ceilingFor($groupId);

                if ($ceiling !== null && $firstRecord < $ceiling) {
                    Log::notice(sprintf(
                        'Holding %s at article %d: a higher range of this cycle is unread.',
                        (string) ($groupMySQL['name'] ?? 'unknown'),
                        $ceiling,
                    ));
                    $firstRecord = $ceiling;
                } else {
                    $rangeFloor->pruneIfSettled(ArticleRangeFloor::LANE_BACKFILL, $groupId);
                }

                if ($firstRecord >= (int) $groupMySQL['first_record']) {
                    return;
                }
                $unixTime = NntpArticleDate::timestamp($return['firstArticleDate'] ?? null);
                $updates = [
                    'first_record' => $firstRecord,
                    'last_updated' => now()->toDateTimeString(),
                ];
                if ($unixTime !== null) {
                    $updates['first_record_postdate'] = date('Y-m-d H:i:s', $unixTime);
                }
                $updated = DB::table('usenet_groups')
                    ->where('id', $groupMySQL['id'])
                    ->where('first_record', '>', $firstRecord)
                    ->update($updates);

                if ($updated > 0) {
                    $this->disableBackfillIfProviderFloorReached($groupMySQL, $firstRecord);
                }
                break;

            default:
                return;
        }
    }

    /**
     * @param  array<string, mixed>  $groupMySQL
     */
    private function disableBackfillIfProviderFloorReached(array $groupMySQL, int $firstArticleNumber): void
    {
        if ((int) Settings::settingValue('disablebackfillgroup') !== 1) {
            return;
        }

        $providerFirst = (int) DB::table('short_groups')->where('name', $groupMySQL['name'])->max('first_record');
        if ($providerFirst <= 0) {
            $providerFirst = $firstArticleNumber;
        }

        if ($firstArticleNumber > $providerFirst) {
            return;
        }

        DB::table('usenet_groups')->where('id', $groupMySQL['id'])->update([
            'backfill' => 0,
            'last_updated' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Get NNTP connection.
     */
    private function getNntp(): NNTPService
    {
        $nntp = new NNTPService;

        $connectResult = config('nntmux_nntp.use_alternate_nntp_server') === true
            ? $nntp->doConnect(false, true)
            : $nntp->doConnect();

        if ($connectResult !== true) {
            $errorMessage = 'Unable to connect to usenet.';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            throw new \Exception($errorMessage);
        }

        return $nntp;
    }
}
