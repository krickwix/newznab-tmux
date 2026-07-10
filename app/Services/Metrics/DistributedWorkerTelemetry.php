<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Stores low-cardinality distributed-worker telemetry in the Redis cache store.
 *
 * Metrics are best effort: an unavailable telemetry store must never stop a
 * processing lane. Callers and the exporter share a fixed outcome vocabulary,
 * so neither exception messages nor release IDs can become metric labels.
 */
class DistributedWorkerTelemetry
{
    /** @var list<string> */
    public const RUN_OUTCOMES = [
        'success',
        'failure',
        'disabled',
        'lock_contended',
        'lock_error',
        'terminated',
    ];

    /** @var array<string, list<string>> */
    public const ITEMS_BY_WORKER = [
        'nzb-backlog' => ['nzb'],
    ];

    /** @var list<string> */
    public const ITEM_RESULTS = [
        'scanned',
        'selected',
        'attempted',
        'created',
        'failed',
        'marked_failed',
        'scan_exhausted',
    ];

    private const KEY_PREFIX = 'metrics:distributed_worker:';

    public function startRun(string $worker, ?float $now = null): float
    {
        $startedAt = $now ?? microtime(true);
        if (! $this->validWorker($worker)) {
            return $startedAt;
        }

        try {
            $store = $this->store();
            $store->forever($this->key($worker, 'in_progress_started_at'), $startedAt);
            $store->forever($this->key($worker, 'last_started_timestamp_seconds'), $startedAt);
        } catch (Throwable) {
            // Telemetry is deliberately non-blocking.
        }

        return $startedAt;
    }

    public function finishRun(
        string $worker,
        string $outcome,
        float $startedAt,
        ?float $now = null,
    ): bool {
        if (! $this->validWorker($worker) || ! in_array($outcome, self::RUN_OUTCOMES, true)) {
            return false;
        }

        $finishedAt = $now ?? microtime(true);

        try {
            $store = $this->store();
            $this->increment($store, $this->key($worker, 'runs:'.$outcome));
            $store->forever($this->key($worker, 'last_duration_seconds'), max(0.0, $finishedAt - $startedAt));
            $store->forever($this->key($worker, 'last_completed_timestamp_seconds'), $finishedAt);
            $store->forget($this->key($worker, 'in_progress_started_at'));

            if ($outcome === 'success') {
                $store->forever($this->key($worker, 'last_success_timestamp_seconds'), $finishedAt);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function recordRunOutcome(string $worker, string $outcome): bool
    {
        if (! $this->validWorker($worker) || ! in_array($outcome, self::RUN_OUTCOMES, true)) {
            return false;
        }

        try {
            $this->increment($this->store(), $this->key($worker, 'runs:'.$outcome));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function recordItem(string $worker, string $item, string $result, int $count = 1): bool
    {
        if (
            ! $this->validWorker($worker)
            || ! in_array($item, self::ITEMS_BY_WORKER[$worker] ?? [], true)
            || ! in_array($result, self::ITEM_RESULTS, true)
            || $count < 1
        ) {
            return false;
        }

        try {
            $this->increment($this->store(), $this->key($worker, 'items:'.$item.':'.$result), $count);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function recordSelectorDuration(float $seconds): bool
    {
        if (! is_finite($seconds) || $seconds < 0) {
            return false;
        }

        try {
            $this->store()->forever($this->key('nzb-backlog', 'selector_last_duration_seconds'), $seconds);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $workers
     * @return array{
     *     available: bool,
     *     nzb_selector_last_duration_seconds: float,
     *     workers: array<string, array{
     *         runs: array<string, int>,
     *         items: array<string, array<string, int>>,
     *         last_duration_seconds: float,
     *         last_started_timestamp_seconds: float,
     *         last_completed_timestamp_seconds: float,
     *         last_success_timestamp_seconds: float,
     *         in_progress: bool,
     *         in_progress_age_seconds: float
     *     }>
     * }
     */
    public function snapshot(array $workers, ?float $now = null): array
    {
        $timestamp = $now ?? microtime(true);
        $snapshot = [
            'available' => true,
            'nzb_selector_last_duration_seconds' => 0.0,
            'workers' => [],
        ];

        try {
            $store = $this->store();
            foreach ($workers as $worker) {
                if (! $this->validWorker($worker)) {
                    continue;
                }

                $startedAt = (float) ($store->get($this->key($worker, 'in_progress_started_at')) ?? 0);
                $runs = [];
                foreach (self::RUN_OUTCOMES as $outcome) {
                    $runs[$outcome] = (int) ($store->get($this->key($worker, 'runs:'.$outcome)) ?? 0);
                }

                $items = [];
                foreach (self::ITEMS_BY_WORKER[$worker] ?? [] as $item) {
                    $items[$item] = [];
                    foreach (self::ITEM_RESULTS as $result) {
                        $items[$item][$result] = (int) ($store->get($this->key($worker, 'items:'.$item.':'.$result)) ?? 0);
                    }
                }

                $snapshot['workers'][$worker] = [
                    'runs' => $runs,
                    'items' => $items,
                    'last_duration_seconds' => (float) ($store->get($this->key($worker, 'last_duration_seconds')) ?? 0),
                    'last_started_timestamp_seconds' => (float) ($store->get($this->key($worker, 'last_started_timestamp_seconds')) ?? 0),
                    'last_completed_timestamp_seconds' => (float) ($store->get($this->key($worker, 'last_completed_timestamp_seconds')) ?? 0),
                    'last_success_timestamp_seconds' => (float) ($store->get($this->key($worker, 'last_success_timestamp_seconds')) ?? 0),
                    'in_progress' => $startedAt > 0,
                    'in_progress_age_seconds' => $startedAt > 0 ? max(0.0, $timestamp - $startedAt) : 0.0,
                ];
            }

            $snapshot['nzb_selector_last_duration_seconds'] = (float) (
                $store->get($this->key('nzb-backlog', 'selector_last_duration_seconds')) ?? 0
            );
        } catch (Throwable) {
            $snapshot['available'] = false;
            $snapshot['nzb_selector_last_duration_seconds'] = 0.0;
            $snapshot['workers'] = [];
        }

        return $snapshot;
    }

    private function store(): Repository
    {
        return Cache::store((string) config('nntmux.distributed_lock_store', 'redis'));
    }

    private function key(string $worker, string $suffix): string
    {
        return self::KEY_PREFIX.$worker.':'.$suffix;
    }

    private function validWorker(string $worker): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $worker) === 1;
    }

    private function increment(Repository $store, string $key, int $amount = 1): void
    {
        if ($store->increment($key, $amount) === false) {
            $store->forever($key, $amount);
        }
    }
}
