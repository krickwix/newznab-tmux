<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\ReleaseSearchIndexSync;
use InvalidArgumentException;
use Throwable;

class NativeHashedFixNameSearchSync
{
    private const MAX_COMMITTED_RELEASE_IDS = 10000;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sync(array $payload): array
    {
        $writeCommit = $this->extractWriteCommit($payload);
        $releaseIds = $this->committedReleaseIds($writeCommit['committed_release_ids'] ?? []);
        $writesCommitted = $this->nonNegativeInteger($writeCommit, 'writes_committed', 'write_commit.writes_committed');

        if ($writesCommitted !== count($releaseIds)) {
            throw new InvalidArgumentException('Native search sync committed ID count does not match writes_committed.');
        }

        if ($writesCommitted === 0 && $releaseIds === [] && $this->hasUncommittedReleaseIds($writeCommit)) {
            throw new InvalidArgumentException('Native search sync cannot sync reports without committed release IDs when skipped or blocked release IDs are present.');
        }

        $synced = 0;
        foreach ($releaseIds as $releaseId) {
            try {
                ReleaseSearchIndexSync::forIds([$releaseId]);
                $synced++;
            } catch (Throwable $exception) {
                throw new NativeSearchSideEffectSyncFailed(
                    $this->failureReport($releaseIds, $synced, $releaseId),
                    $exception,
                );
            }
        }

        return [
            'schema_version' => 1,
            'mode' => 'native-search-side-effect-sync',
            'dry_run' => false,
            'source_job' => 'hashed-fixnames',
            'search_updates_seen' => count($releaseIds),
            'search_updates_synced' => $synced,
            'search_updates_failed' => 0,
            'release_ids' => $releaseIds,
            'failed_release_ids' => [],
            'writes' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractWriteCommit(array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Native search sync requires schema_version=1.');
        }

        if (($payload['mode'] ?? null) !== 'shadow') {
            throw new InvalidArgumentException('Native search sync requires mode=shadow.');
        }

        if (($payload['dry_run'] ?? null) !== false) {
            throw new InvalidArgumentException('Native search sync requires a committed native report.');
        }

        $job = $payload['native_worker']['job'] ?? null;
        if ($job !== 'hashed-fixnames') {
            throw new InvalidArgumentException('Native search sync supports only hashed-fixnames reports.');
        }

        $writeCommit = $payload['hashed_fixnames']['write_commit'] ?? null;
        if (! is_array($writeCommit)) {
            throw new InvalidArgumentException('Native search sync report is missing hashed_fixnames.write_commit.');
        }

        if (($writeCommit['lock_acquired'] ?? null) !== true) {
            throw new InvalidArgumentException('Native search sync requires write_commit.lock_acquired=true.');
        }

        $nativeWorker = $payload['native_worker'] ?? null;
        if (! is_array($nativeWorker)) {
            throw new InvalidArgumentException('Native search sync report is missing native_worker.');
        }

        $nativeWrites = $this->nonNegativeInteger($nativeWorker, 'writes', 'native_worker.writes');
        $writesCommitted = $this->nonNegativeInteger($writeCommit, 'writes_committed', 'write_commit.writes_committed');
        if ($nativeWrites !== $writesCommitted) {
            throw new InvalidArgumentException('Native search sync native_worker.writes does not match write_commit.writes_committed.');
        }

        $updatesAttempted = $this->nonNegativeInteger($writeCommit, 'single_column_updates_attempted', 'write_commit.single_column_updates_attempted');
        if ($updatesAttempted < $writesCommitted) {
            throw new InvalidArgumentException('Native search sync write_commit.single_column_updates_attempted cannot be lower than writes_committed.');
        }

        foreach ([
            'single_column_updates_committed',
            'single_column_rows_affected',
        ] as $field) {
            if ($this->nonNegativeInteger($writeCommit, $field, "write_commit.{$field}") !== $writesCommitted) {
                throw new InvalidArgumentException("Native search sync write_commit.{$field} does not match writes_committed.");
            }
        }

        return $writeCommit;
    }

    /**
     * @param  mixed  $value
     * @return list<int>
     */
    private function committedReleaseIds(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Native search sync committed_release_ids must be an array.');
        }

        $ids = [];
        foreach ($value as $id) {
            if (! is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Native search sync committed_release_ids must contain positive integer release IDs.');
            }

            if (isset($ids[$id])) {
                throw new InvalidArgumentException("Native search sync committed_release_ids contains duplicate release ID [{$id}].");
            }

            $ids[$id] = true;
        }

        if (count($ids) > self::MAX_COMMITTED_RELEASE_IDS) {
            throw new InvalidArgumentException('Native search sync committed_release_ids exceeds the maximum sync batch size.');
        }

        $releaseIds = array_keys($ids);
        sort($releaseIds, SORT_NUMERIC);

        return $releaseIds;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function nonNegativeInteger(array $source, string $key, string $path): int
    {
        $value = $source[$key] ?? null;
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Native search sync {$path} must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $writeCommit
     */
    private function hasUncommittedReleaseIds(array $writeCommit): bool
    {
        foreach (['skipped_release_ids', 'blocked_release_ids', 'blocked_status_release_ids'] as $field) {
            $ids = $writeCommit[$field] ?? [];
            if (is_array($ids) && $ids !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $releaseIds
     * @return array<string, mixed>
     */
    private function failureReport(array $releaseIds, int $synced, int $failedReleaseId): array
    {
        return [
            'schema_version' => 1,
            'mode' => 'native-search-side-effect-sync',
            'dry_run' => false,
            'source_job' => 'hashed-fixnames',
            'search_updates_seen' => count($releaseIds),
            'search_updates_synced' => $synced,
            'search_updates_failed' => 1,
            'release_ids' => $releaseIds,
            'failed_release_ids' => [$failedReleaseId],
            'writes' => 0,
            'error' => 'search-update-failed',
        ];
    }
}
