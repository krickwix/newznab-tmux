<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NntpArticleDate;
use App\Services\NNTP\NNTPService;
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
                            {last : Last article number}';

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

        if (! \in_array($mode, ['binaries', 'backfill'], true)) {
            $this->error('Mode must be either "binaries" or "backfill".');

            return self::FAILURE;
        }

        try {
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
                return self::FAILURE;
            }

            $this->refreshSelectedProviderRange($groupMySQL['name'], $groupSummary);
            $selectedRange = $this->clampToSelectedProviderRange($firstArticle, $lastArticle, $groupSummary);
            if ($selectedRange === null) {
                $this->info("Requested range {$firstArticle}-{$lastArticle} is outside the selected provider range");

                return self::SUCCESS;
            }
            [$firstArticle, $lastArticle] = $selectedRange;

            $binaries = new BinariesService;
            $binaries->setNntp($nntp);
            $return = $binaries->scan(
                $groupMySQL,
                $firstArticle,
                $lastArticle,
                ((int) Settings::settingValue('safepartrepair') === 1 ? 'update' : 'backfill')
            );

            if (empty($return)) {
                return self::SUCCESS;
            }

            $this->updateGroupRecords($mode, $groupMySQL, $return, $firstArticle);

            return self::SUCCESS;
        } catch (\Throwable $e) {
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
                if ($return['lastArticleNumber'] <= $groupMySQL['last_record']) {
                    return;
                }
                $unixTime = NntpArticleDate::timestamp($return['lastArticleDate'] ?? null);
                $updates = [
                    'last_record' => $return['lastArticleNumber'],
                    'last_updated' => now()->toDateTimeString(),
                ];
                if ($unixTime !== null) {
                    $updates['last_record_postdate'] = date('Y-m-d H:i:s', $unixTime);
                }
                DB::table('usenet_groups')
                    ->where('id', $groupMySQL['id'])
                    ->where('last_record', '<', $return['lastArticleNumber'])
                    ->update($updates);
                break;

            case 'backfill':
                if ($return['firstArticleNumber'] >= $groupMySQL['first_record']) {
                    return;
                }
                $unixTime = NntpArticleDate::timestamp($return['firstArticleDate'] ?? null);
                $updates = [
                    'first_record' => $return['firstArticleNumber'],
                    'last_updated' => now()->toDateTimeString(),
                ];
                if ($unixTime !== null) {
                    $updates['first_record_postdate'] = date('Y-m-d H:i:s', $unixTime);
                }
                $updated = DB::table('usenet_groups')
                    ->where('id', $groupMySQL['id'])
                    ->where('first_record', '>', $return['firstArticleNumber'])
                    ->update($updates);

                if ($updated > 0) {
                    $this->disableBackfillIfProviderFloorReached($groupMySQL, (int) $return['firstArticleNumber']);
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
