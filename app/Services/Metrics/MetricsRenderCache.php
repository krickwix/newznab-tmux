<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Short-lived store for one rendered Prometheus exposition body.
 *
 * The exporter is scraped every 30 s by one Prometheus job and recomputes the
 * whole exposition on every scrape. Measured on the live database that is
 * ~7% of MariaDB's busy time for a single pod, and the cost grows with the
 * backlog: the two collections aggregates alone were 3.06 s per render at
 * 1.03M collections.
 *
 * So a rendered body is reusable for a configured window and the next scrape
 * inside it costs no database time at all. The trade is resolution: at the
 * default 60 s against a 30 s scrape interval, roughly every other sample
 * repeats the previous one. These are backlog and progress gauges read by
 * dashboards and by alerts with thresholds in the hundreds of seconds, not
 * inputs to any gate -- the orchestrator takes its own snapshot -- so a minute
 * of age costs nothing that matters. Raise the window to cut the cost further;
 * set it to 0 to compute on every scrape as before.
 *
 * A served body is never silently stale: render() appends a live
 * nntmux_metric_cache_age_seconds alongside it, so a wedged or slow exporter
 * is visible rather than looking healthy on a frozen copy.
 */
class MetricsRenderCache
{
    private const string KEY = 'nntmux:metrics:rendered-body';

    /**
     * Read a reusable body, or null to render a fresh one.
     *
     * Fails open in every direction: caching off, nothing stored yet, a
     * malformed entry, or an unreachable cache all mean "render it".
     *
     * @return array{body: string, rendered_at: float}|null
     */
    public function read(): ?array
    {
        if ($this->ttl() <= 0) {
            return null;
        }

        try {
            $entry = $this->store()?->get(self::KEY);
        } catch (Throwable $e) {
            $this->logFailure('read', $e);

            return null;
        }

        if (! is_array($entry)
            || ! isset($entry['body'], $entry['rendered_at'])
            || ! is_string($entry['body'])
            || $entry['body'] === ''
            || ! is_numeric($entry['rendered_at'])
        ) {
            return null;
        }

        return [
            'body' => $entry['body'],
            'rendered_at' => (float) $entry['rendered_at'],
        ];
    }

    /**
     * Store a freshly rendered body. A failure here costs the reuse, never the
     * scrape that produced it.
     */
    public function write(string $body, float $renderedAt): void
    {
        $ttl = $this->ttl();

        if ($ttl <= 0 || $body === '') {
            return;
        }

        try {
            $this->store()?->put(self::KEY, [
                'body' => $body,
                'rendered_at' => $renderedAt,
            ], $ttl);
        } catch (Throwable $e) {
            $this->logFailure('write', $e);
        }
    }

    public function ttl(): int
    {
        return max(0, (int) config('nntmux.metrics_render_cache_seconds', 0));
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
            Log::debug('Metrics render cache '.$stage.' failed: '.$e->getMessage());
        }
    }
}
