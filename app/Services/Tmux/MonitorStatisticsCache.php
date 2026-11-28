<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fleet-shared store for one tmux monitor statistics snapshot.
 *
 * Every distributed worker that gates on release or collection counts calls
 * TmuxMonitorService::collectStatistics() on its own monitor_delay (30 s
 * live), and each refresh issues the same ~20 statements: nine category counts
 * over releases, four proc_query aggregates, a collections count and the
 * information_schema table reads. Nine such lanes recompute an identical
 * answer on nine unsynchronised schedules.
 *
 * The snapshot is a property of the database, not of the pod reading it, so
 * one computation answers for the whole fleet until it ages out. The key is
 * deliberately neither per-pod nor per-job.
 *
 * Only the database-derived half of the monitor state travels: settings,
 * counts.now, and the two timer groups. counts.start is a per-process
 * since-launch baseline and stays local, so a pod adopting another pod's
 * snapshot still reports its own deltas.
 */
class MonitorStatisticsCache
{
    private const string KEY = 'nntmux:tmux-monitor:statistics';

    /**
     * Count keys DistributedJobCatalog::resolve() reads to decide whether a
     * lane has work.
     *
     * Every one is consulted with a ?? 0 default, so a snapshot that is merely
     * short -- not empty -- reads as "nothing to do" and disables the lane.
     * Requiring them before a snapshot is published or adopted is what makes
     * "never publish a partial read" true rather than aspirational: a test
     * against [] alone only catches total failure, and the pass that fails
     * here fails partway.
     *
     * MonitorStatisticsCacheTest scans the catalog's source and fails if it
     * grows a read this list does not cover.
     *
     * @var list<string>
     */
    public const array REQUIRED_COUNT_KEYS = [
        'backfill_groups_date',
        'backfill_groups_days',
        'collections_table',
        'other_hashed',
        'processanime',
        'processbooks',
        'processconsole',
        'processgames',
        'processmovies',
        'processmusic',
        'processnfo',
        'processrenames',
        'processtv',
        'work',
    ];

    /**
     * Read a shared snapshot, or null to compute a fresh one.
     *
     * Fails open in every direction: sharing disabled, nothing published yet,
     * a snapshot missing any of its four parts, or a cache that cannot be
     * reached all mean "compute locally". A half-applied snapshot would hand
     * the job catalog counts without the settings that interpret them.
     *
     * @return array{settings: array<string, mixed>, counts: array<string, mixed>, query_timers: array<string, mixed>, new_old: array<string, mixed>}|null
     */
    public function read(): ?array
    {
        if ($this->ttl() <= 0) {
            return null;
        }

        try {
            $snapshot = $this->store()?->get(self::KEY);
        } catch (Throwable $e) {
            $this->logFailure('read', $e);

            return null;
        }

        if (! is_array($snapshot)) {
            return null;
        }

        foreach (['settings', 'counts', 'query_timers', 'new_old'] as $part) {
            if (! isset($snapshot[$part]) || ! is_array($snapshot[$part])) {
                return null;
            }
        }

        if ($snapshot['settings'] === [] || ! self::countsAreComplete($snapshot['counts'])) {
            return null;
        }

        return [
            'settings' => $snapshot['settings'],
            'counts' => $snapshot['counts'],
            'query_timers' => $snapshot['query_timers'],
            'new_old' => $snapshot['new_old'],
        ];
    }

    /**
     * Publish a freshly computed snapshot for the rest of the fleet. A failure
     * here costs the sharing, never the caller.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $queryTimers
     * @param  array<string, mixed>  $newOld
     */
    public function write(array $settings, array $counts, array $queryTimers, array $newOld): void
    {
        $ttl = $this->ttl();

        if ($ttl <= 0) {
            return;
        }

        // Never publish a partial read. getProcessCounts() and
        // getTableCounts() swallow their own exceptions, so a failed pass
        // leaves counts short rather than throwing, and publishing that would
        // spread one pod's failed read across every lane for the whole TTL.
        // The caller already declines to publish an incomplete pass; this is
        // the second line, and it also rejects a snapshot shaped by a
        // different build of the statistics pass.
        if ($settings === [] || ! self::countsAreComplete($counts)) {
            return;
        }

        try {
            $this->store()?->put(self::KEY, [
                'settings' => $settings,
                'counts' => $counts,
                'query_timers' => $queryTimers,
                'new_old' => $newOld,
            ], $ttl);
        } catch (Throwable $e) {
            $this->logFailure('write', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    private static function countsAreComplete(array $counts): bool
    {
        foreach (self::REQUIRED_COUNT_KEYS as $key) {
            if (! array_key_exists($key, $counts)) {
                return false;
            }
        }

        return true;
    }

    public function ttl(): int
    {
        return max(0, (int) config('nntmux.distributed_statistics_cache_seconds', 0));
    }

    private function store(): ?Repository
    {
        try {
            return Cache::store((string) config('nntmux.distributed_lock_store', 'redis'));
        } catch (Throwable $e) {
            $this->logFailure('resolve', $e);

            return null;
        }
    }

    private function logFailure(string $stage, Throwable $e): void
    {
        if (config('app.debug') === true) {
            Log::debug('Shared monitor statistics cache '.$stage.' failed: '.$e->getMessage());
        }
    }
}
