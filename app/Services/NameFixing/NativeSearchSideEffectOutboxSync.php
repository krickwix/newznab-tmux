<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Facades\Search;
use App\Support\ReleaseSearchIndexSync;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class NativeSearchSideEffectOutboxSync
{
    private const MAX_LIMIT = 10000;

    private const FAILURE_CODE = 'search-update-failed';

    /**
     * @return array<string, mixed>
     */
    public function syncPending(int $limit = 100, ?string $sourceJob = null): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('Native search outbox sync limit must be between 1 and 10000.');
        }

        $rows = DB::table('native_worker_side_effects')
            ->where(fn ($query) => $this->scopeSupportedRows($query, $sourceJob))
            ->whereIn('status', ['pending', 'processing'])
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $releaseIds = [];
        $predbIds = [];
        $failedReleaseIds = [];
        $failedPredbIds = [];
        $deadLetteredReleaseIds = [];
        $deadLetteredPredbIds = [];
        $synced = 0;

        foreach ($rows as $row) {
            $sideEffectID = (int) $row->release_id;

            $claim = $this->claimRow($row);
            if ($claim === null) {
                continue;
            }

            if ($this->isPredbSearchRow($row)) {
                $predbIds[] = $sideEffectID;
            } else {
                $releaseIds[] = $sideEffectID;
            }

            try {
                $this->assertSupportedRow($row);
                $this->syncRow($row);
                if ($this->markSynced($claim)) {
                    $synced++;
                }
            } catch (Throwable) {
                if (((int) $row->attempts + 1) >= $this->maxAttempts()) {
                    if ($this->markFailed($claim)) {
                        if ($this->isPredbSearchRow($row)) {
                            $failedPredbIds[] = $sideEffectID;
                            $deadLetteredPredbIds[] = $sideEffectID;
                        } else {
                            $failedReleaseIds[] = $sideEffectID;
                            $deadLetteredReleaseIds[] = $sideEffectID;
                        }
                    }

                    continue;
                }

                if ($this->markRetryable($claim)) {
                    if ($this->isPredbSearchRow($row)) {
                        $failedPredbIds[] = $sideEffectID;
                    } else {
                        $failedReleaseIds[] = $sideEffectID;
                    }
                }
            }
        }

        sort($releaseIds, SORT_NUMERIC);
        sort($predbIds, SORT_NUMERIC);
        sort($failedReleaseIds, SORT_NUMERIC);
        sort($failedPredbIds, SORT_NUMERIC);
        sort($deadLetteredReleaseIds, SORT_NUMERIC);
        sort($deadLetteredPredbIds, SORT_NUMERIC);

        return [
            'schema_version' => 1,
            'mode' => 'native-search-side-effect-outbox-sync',
            'dry_run' => false,
            'source_job' => 'native-worker-outbox',
            'search_updates_seen' => count($releaseIds) + count($predbIds),
            'search_updates_synced' => $synced,
            'search_updates_failed' => count($failedReleaseIds) + count($failedPredbIds),
            'search_updates_dead_lettered' => count($deadLetteredReleaseIds) + count($deadLetteredPredbIds),
            'release_ids' => $releaseIds,
            'predb_ids' => $predbIds,
            'failed_release_ids' => $failedReleaseIds,
            'failed_predb_ids' => $failedPredbIds,
            'dead_lettered_release_ids' => $deadLetteredReleaseIds,
            'dead_lettered_predb_ids' => $deadLetteredPredbIds,
            'writes' => 0,
        ];
    }

    /**
     * @param  Builder  $query
     */
    private function scopeSupportedRows($query, ?string $sourceJob): void
    {
        if ($sourceJob !== null) {
            $query->where('job', $sourceJob)
                ->where('effect', in_array($sourceJob, ['metadata-refresh', 'irc'], true) ? 'predb-search-sync' : 'release-search-sync');

            return;
        }

        $query->where(function ($query): void {
            $query->where(function ($query): void {
                $query->where('job', 'hashed-fixnames')
                    ->where('effect', 'release-search-sync');
            })->orWhere(function ($query): void {
                $query->where('job', 'metadata-refresh')
                    ->where('effect', 'predb-search-sync');
            })->orWhere(function ($query): void {
                $query->where('job', 'irc')
                    ->where('effect', 'predb-search-sync');
            });
        });
    }

    /**
     * @return array{id: int, attempts: int}|null
     */
    private function claimRow(object $row): ?array
    {
        $id = (int) $row->id;
        $attempts = (int) $row->attempts + 1;

        $claimed = DB::table('native_worker_side_effects')
            ->where('id', $id)
            ->where('attempts', (int) $row->attempts)
            ->whereIn('status', ['pending', 'processing'])
            ->where(function ($query): void {
                $query->whereNull('available_at')
                    ->orWhere('available_at', '<=', now());
            })
            ->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'available_at' => now()->addMinutes(5),
                'updated_at' => now(),
            ]) === 1;

        if (! $claimed) {
            return null;
        }

        return [
            'id' => $id,
            'attempts' => $attempts,
        ];
    }

    private function assertSupportedRow(object $row): void
    {
        $sideEffectID = (int) $row->release_id;
        if ($sideEffectID <= 0) {
            throw new InvalidArgumentException('Native search outbox target ID must be positive.');
        }

        if ($this->isPredbSearchRow($row)) {
            if ($row->status_column !== 'predb_id') {
                throw new InvalidArgumentException('Native predb search outbox status_column is not supported.');
            }

            if ($row->status_reason !== 'predb-import') {
                throw new InvalidArgumentException('Native predb search outbox status_reason is not supported.');
            }

            if ((int) $row->status_value !== 1) {
                throw new InvalidArgumentException('Native predb search outbox status_value is not supported.');
            }

            return;
        }

        if (! in_array($row->status_column, ['proc_crc32', 'proc_hash16k'], true)) {
            throw new InvalidArgumentException('Native search outbox status_column is not supported.');
        }

        if (! in_array($row->status_reason, ['crc-miss', 'par-hash-miss'], true)) {
            throw new InvalidArgumentException('Native search outbox status_reason is not supported.');
        }

        if ((int) $row->status_value !== 1) {
            throw new InvalidArgumentException('Native search outbox status_value is not supported.');
        }
    }

    private function isPredbSearchRow(object $row): bool
    {
        return in_array($row->job, ['metadata-refresh', 'irc'], true) && $row->effect === 'predb-search-sync';
    }

    private function syncRow(object $row): void
    {
        if ($this->isPredbSearchRow($row)) {
            $predb = DB::table('predb')
                ->select(['id', 'title', 'source'])
                ->where('id', (int) $row->release_id)
                ->first();

            if ($predb === null) {
                throw new InvalidArgumentException('Native predb search outbox row references a missing predb row.');
            }

            Search::insertPredb([
                'id' => (int) $predb->id,
                'title' => (string) $predb->title,
                'filename' => '',
                'source' => (string) $predb->source,
            ]);

            return;
        }

        ReleaseSearchIndexSync::forIds([(int) $row->release_id]);
    }

    /**
     * @param  array{id: int, attempts: int}  $claim
     */
    private function markSynced(array $claim): bool
    {
        return DB::table('native_worker_side_effects')
            ->where('id', $claim['id'])
            ->where('status', 'processing')
            ->where('attempts', $claim['attempts'])
            ->update([
                'status' => 'synced',
                'available_at' => null,
                'processed_at' => now(),
                'last_error_code' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * @param  array{id: int, attempts: int}  $claim
     */
    private function markRetryable(array $claim): bool
    {
        return DB::table('native_worker_side_effects')
            ->where('id', $claim['id'])
            ->where('status', 'processing')
            ->where('attempts', $claim['attempts'])
            ->update([
                'status' => 'pending',
                'available_at' => now()->addMinute(),
                'processed_at' => null,
                'last_error_code' => self::FAILURE_CODE,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * @param  array{id: int, attempts: int}  $claim
     */
    private function markFailed(array $claim): bool
    {
        return DB::table('native_worker_side_effects')
            ->where('id', $claim['id'])
            ->where('status', 'processing')
            ->where('attempts', $claim['attempts'])
            ->update([
                'status' => 'failed',
                'available_at' => null,
                'processed_at' => now(),
                'last_error_code' => self::FAILURE_CODE,
                'updated_at' => now(),
            ]) === 1;
    }

    private function maxAttempts(): int
    {
        $attempts = (int) config('nntmux.native_worker_search_outbox_max_attempts', 5);

        return max(1, min(1000, $attempts));
    }
}
