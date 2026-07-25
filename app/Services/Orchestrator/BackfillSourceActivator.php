<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Services\NNTP\NntpArticleDate;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class BackfillSourceActivator
{
    private const int SAMPLE_SIZE = 10_000;

    private const int ORCHESTRATED_BACKFILL_TARGET_DAYS = 9_999;

    private const float MINIMUM_HEADER_COVERAGE = 0.9;

    private const float MINIMUM_YENC_RATIO = 0.5;

    public function __construct(private NNTPService $nntp) {}

    /**
     * @return array{
     *     group:string,
     *     provider_first:int,
     *     provider_last:int,
     *     cursor:int,
     *     cursor_postdate:string,
     *     sample_start:int,
     *     sample_end:int,
     *     headers:int,
     *     yenc_headers:int,
     *     multipart_headers:int,
     *     complete_binary_files:int
     * }
     */
    public function inspect(string $group): array
    {
        $group = trim($group);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,254}$/D', $group) !== 1) {
            throw new RuntimeException('The group name is invalid.');
        }
        $probeAllowlist = (array) config('nntmux.orchestrator.backfill_probe_groups', []);
        $allowAll = false;
        foreach ($probeAllowlist as $allowed) {
            if (strcasecmp(trim((string) $allowed), 'all') === 0) {
                $allowAll = true;
                break;
            }
        }
        if (! $allowAll && ! in_array($group, $probeAllowlist, true)) {
            throw new RuntimeException("Group {$group} is not in the configured backfill probe allowlist.");
        }

        $local = DB::table('usenet_groups')->where('name', $group)->first();
        if ($local === null) {
            throw new RuntimeException("Local group {$group} does not exist.");
        }
        if ((int) $local->active !== 0) {
            throw new RuntimeException("Group {$group} is active; backfill-only activation refuses to alter it.");
        }

        $useAlternate = config('nntmux_nntp.use_alternate_nntp_server') === true;
        $connected = $useAlternate
            ? $this->nntp->doConnect(false, true)
            : $this->nntp->doConnect();
        if ($connected !== true) {
            throw new RuntimeException('Unable to connect to the configured NNTP provider.');
        }

        try {
            $summary = $this->nntp->selectGroup($group, false, true);
            if (NNTPService::isError($summary) || ! is_array($summary)) {
                throw new RuntimeException("Provider GROUP verification failed for {$group}.");
            }
            if (($summary['group'] ?? null) !== $group) {
                throw new RuntimeException("Provider selected a different group while verifying {$group}.");
            }

            $providerFirst = (int) ($summary['first'] ?? 0);
            $providerLast = (int) ($summary['last'] ?? 0);
            if ($providerFirst <= 0 || $providerLast < $providerFirst) {
                throw new RuntimeException("Provider returned an invalid range for {$group}.");
            }
            if ($providerLast - $providerFirst < 20_000) {
                throw new RuntimeException("Provider range for {$group} has less than the required 20,000 article reserve.");
            }

            $sampleStart = max($providerFirst, $providerLast - self::SAMPLE_SIZE + 1);
            $headers = $this->nntp->getXOVER($sampleStart.'-'.$providerLast);
            if (NNTPService::isError($headers) || ! is_array($headers)) {
                throw new RuntimeException("Provider XOVER verification failed for {$group}.");
            }

            $minimumHeaders = (int) ceil(($providerLast - $sampleStart + 1) * self::MINIMUM_HEADER_COVERAGE);
            if (count($headers) < $minimumHeaders) {
                throw new RuntimeException("Provider XOVER sample for {$group} is too sparse.");
            }
            $this->assertExactWindowBoundaries($headers, $sampleStart, $providerLast, $group);

            $density = $this->density($headers);
            if ($density['yenc_headers'] / max(1, count($headers)) < self::MINIMUM_YENC_RATIO) {
                throw new RuntimeException("Provider XOVER sample for {$group} is below the 50% yEnc density floor.");
            }
            if ($density['complete_binary_files'] < 1) {
                throw new RuntimeException("Provider XOVER sample for {$group} contains no complete multipart binary.");
            }

            $cursor = $this->latestSaneCursor($headers);
            if ($cursor === null || $cursor['number'] !== $providerLast) {
                throw new RuntimeException("Provider XOVER sample for {$group} has no sane date on the provider high article.");
            }
            if ($providerLast === PHP_INT_MAX) {
                throw new RuntimeException("Provider high article for {$group} cannot be incremented safely.");
            }
            if ($providerLast + 1 - $providerFirst < 20_000) {
                throw new RuntimeException("Readable cursor for {$group} has less than the required 20,000 article reserve.");
            }

            return [
                'group' => $group,
                'provider_first' => $providerFirst,
                'provider_last' => $providerLast,
                'cursor' => $providerLast + 1,
                'cursor_postdate' => date('Y-m-d H:i:s', $cursor['timestamp']),
                'sample_start' => $sampleStart,
                'sample_end' => $providerLast,
                'headers' => count($headers),
                'yenc_headers' => $density['yenc_headers'],
                'multipart_headers' => $density['multipart_headers'],
                'complete_binary_files' => $density['complete_binary_files'],
            ];
        } finally {
            $this->nntp->doQuit(true);
        }
    }

    /**
     * @param array{
     *     group:string,
     *     provider_first:int,
     *     provider_last:int,
     *     cursor:int,
     *     cursor_postdate:string,
     *     sample_start:int,
     *     sample_end:int,
     *     headers:int,
     *     yenc_headers:int,
     *     multipart_headers:int,
     *     complete_binary_files:int
     * } $inspection
     */
    public function apply(array $inspection): bool
    {
        if (config('nntmux_nntp.use_alternate_nntp_server') === true) {
            throw new RuntimeException('Apply is disabled for the alternate provider until retry reconnection preserves provider identity.');
        }

        return DB::transaction(function () use ($inspection): bool {
            $this->assertManagedRuntime();
            $group = DB::table('usenet_groups')
                ->where('name', $inspection['group'])
                ->lockForUpdate()
                ->first();
            if ($group === null) {
                throw new RuntimeException("Local group {$inspection['group']} no longer exists.");
            }
            if ((int) $group->active !== 0) {
                throw new RuntimeException("Group {$inspection['group']} became active during verification.");
            }

            $initialized = (int) $group->first_record > 0
                && (int) $group->last_record > 0
                && NntpArticleDate::timestamp($group->first_record_postdate) !== null
                && NntpArticleDate::timestamp($group->last_record_postdate) !== null;
            if ($initialized) {
                $this->assertExistingCursorIsSafe($group, $inspection);
                if ((int) $group->backfill === 1) {
                    return false;
                }

                DB::table('usenet_groups')->where('id', $group->id)->update([
                    'backfill' => 1,
                    'last_updated' => now(),
                ]);

                return true;
            }
            if ((int) $group->backfill === 1) {
                throw new RuntimeException("Existing backfill cursor for {$inspection['group']} is not safe to preserve.");
            }
            if ((int) $group->first_record !== 0
                || (int) $group->last_record !== 0
                || $group->first_record_postdate !== null
                || $group->last_record_postdate !== null) {
                throw new RuntimeException("Disabled group {$inspection['group']} is partially initialized; refusing an implicit cursor reset.");
            }

            DB::table('usenet_groups')->where('id', $group->id)->update([
                'active' => 0,
                'backfill' => 1,
                'backfill_target' => self::ORCHESTRATED_BACKFILL_TARGET_DAYS,
                'first_record' => $inspection['cursor'],
                'first_record_postdate' => $inspection['cursor_postdate'],
                'last_record' => $inspection['provider_last'],
                'last_record_postdate' => $inspection['cursor_postdate'],
                'last_updated' => now(),
            ]);

            return true;
        });
    }

    /**
     * @param  array{group:string,provider_first:int,provider_last:int}  $inspection
     */
    private function assertExistingCursorIsSafe(object $group, array $inspection): void
    {
        $existingCursor = (int) $group->first_record;
        $existingLast = (int) $group->last_record;
        if ($existingLast === PHP_INT_MAX
            || $existingCursor < $inspection['provider_first']
            || $existingCursor > $existingLast + 1
            || $existingLast < $inspection['provider_first']
            || $existingLast > $inspection['provider_last']) {
            throw new RuntimeException("Existing backfill cursor for {$inspection['group']} is not safe to preserve.");
        }
    }

    private function assertManagedRuntime(): void
    {
        $settings = DB::table('settings')
            ->whereIn('name', ['orchestrator_mode', 'orchestrator_lease_until', 'backfillthreads', 'backfill_groups'])
            ->lockForUpdate()
            ->pluck('value', 'name');
        if ((string) $settings->get('orchestrator_mode', '') !== 'active'
            || (int) $settings->get('orchestrator_lease_until', 0) < time() + 30) {
            throw new RuntimeException('Apply requires a fresh active orchestrator lease.');
        }
        if ((int) $settings->get('backfillthreads', 0) !== 1) {
            throw new RuntimeException('Apply requires backfillthreads=1.');
        }
        if ((int) $settings->get('backfill_groups', 0) !== 1) {
            throw new RuntimeException('Apply requires backfill_groups=1.');
        }
    }

    /** @param array<mixed> $headers */
    private function assertExactWindowBoundaries(array $headers, int $start, int $end, string $group): void
    {
        $numbers = [];
        foreach ($headers as $header) {
            if (! is_array($header) || ! isset($header['Number']) || ! is_numeric($header['Number'])) {
                throw new RuntimeException("Provider XOVER sample for {$group} contains an invalid article number.");
            }
            $number = (int) $header['Number'];
            if ($number < $start || $number > $end || isset($numbers[$number])) {
                throw new RuntimeException("Provider XOVER sample for {$group} is outside or duplicates the requested window.");
            }
            $numbers[$number] = true;
        }
        if (! isset($numbers[$start], $numbers[$end])) {
            throw new RuntimeException("Provider XOVER sample for {$group} does not contain both exact window boundaries.");
        }
    }

    /**
     * @param  array<mixed>  $headers
     * @return array{yenc_headers:int,multipart_headers:int,complete_binary_files:int}
     */
    private function density(array $headers): array
    {
        $yencHeaders = 0;
        $multipartHeaders = 0;
        $binaryParts = [];

        foreach ($headers as $header) {
            if (! is_array($header)) {
                continue;
            }
            $subject = (string) ($header['Subject'] ?? '');
            if (preg_match('/yEnc/i', $subject) === 1) {
                $yencHeaders++;
            }
            if (preg_match('/[\(\[]\s*\d+\s*\/\s*\d+\s*[\)\]]/', $subject) === 1) {
                $multipartHeaders++;
            }
            if (preg_match('/"([^"]+)"\s+yEnc\s+[\(\[]\s*(\d+)\s*\/\s*(\d+)\s*[\)\]]/i', $subject, $matches) !== 1) {
                continue;
            }

            $part = (int) $matches[2];
            $total = (int) $matches[3];
            if ($part < 1 || $total < $part) {
                continue;
            }
            $binaryParts[$matches[1]]['total'] = $total;
            $binaryParts[$matches[1]]['parts'][$part] = true;
        }

        $complete = 0;
        foreach ($binaryParts as $binary) {
            if (count($binary['parts']) >= $binary['total']) {
                $complete++;
            }
        }

        return [
            'yenc_headers' => $yencHeaders,
            'multipart_headers' => $multipartHeaders,
            'complete_binary_files' => $complete,
        ];
    }

    /**
     * @param  array<mixed>  $headers
     * @return array{number:int,timestamp:int}|null
     */
    private function latestSaneCursor(array $headers): ?array
    {
        $cursor = null;
        foreach ($headers as $header) {
            if (! is_array($header)) {
                continue;
            }
            $number = (int) ($header['Number'] ?? 0);
            $timestamp = NntpArticleDate::timestamp($header['Date'] ?? null);
            if ($number > 0 && $timestamp !== null && ($cursor === null || $number > $cursor['number'])) {
                $cursor = ['number' => $number, 'timestamp' => $timestamp];
            }
        }

        return $cursor;
    }
}
