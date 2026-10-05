<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class PrometheusSafetySignalProvider
{
    private const string LAST_GOOD_KEY = 'nntmux:orchestrator:last-good-safety-sample';

    /**
     * Safety signals, fail-closed, with each reading's measurability reported
     * alongside it.
     *
     * The *_safe booleans stay fail-closed: an unreadable or stale query is
     * treated as unsafe, which is the correct default. But false alone cannot
     * distinguish "the limit is genuinely breached" from "Prometheus did not
     * answer", and those call for opposite operator responses. The companion
     * *_known flags carry that distinction without changing any gate.
     *
     * @return array{
     *     fresh: bool,
     *     memory_safe: bool,
     *     cpu_safe: bool,
     *     storage_safe: bool,
     *     storage_available_bytes: int,
     *     memory_known: bool,
     *     cpu_known: bool,
     *     storage_known: bool,
     *     sample_source?: string,
     *     sample_age_seconds?: int,
     * }
     */
    public function signals(): array
    {
        $signals = $this->liveSignals();
        if ($signals['fresh']) {
            $this->remember($signals);

            return $signals;
        }

        return $this->held($signals) ?? $signals;
    }

    /**
     * @return array<string, mixed>
     */
    private function liveSignals(): array
    {
        try {
            $storage = $this->query(
                (string) config('nntmux.orchestrator.promql.storage_available'),
                (string) config('nntmux.orchestrator.promql_freshness.storage_available'),
            );
            $memory = $this->query(
                (string) config('nntmux.orchestrator.promql.database_memory'),
                (string) config('nntmux.orchestrator.promql_freshness.database_memory'),
            );
            $cpu = $this->query(
                (string) config('nntmux.orchestrator.promql.database_cpu'),
                (string) config('nntmux.orchestrator.promql_freshness.database_cpu'),
            );

            return [
                'fresh' => $storage !== null && $memory !== null && $cpu !== null,
                'memory_safe' => $memory !== null && $memory < (float) config('nntmux.orchestrator.database_memory_limit_bytes'),
                'cpu_safe' => $cpu !== null && $cpu < (float) config('nntmux.orchestrator.database_cpu_limit_cores'),
                'storage_safe' => $storage !== null && $storage >= (float) config('nntmux.orchestrator.storage_floor_bytes'),
                'storage_available_bytes' => max(0, (int) ($storage ?? 0)),
                'memory_known' => $memory !== null,
                'cpu_known' => $cpu !== null,
                'storage_known' => $storage !== null,
            ];
        } catch (Throwable) {
            return [
                'fresh' => false,
                'memory_safe' => false,
                'cpu_safe' => false,
                'storage_safe' => false,
                'storage_available_bytes' => 0,
                'memory_known' => false,
                'cpu_known' => false,
                'storage_known' => false,
            ];
        }
    }

    /**
     * Last-known-good hold. A fetch that answered nothing usable is not a
     * breach, so for a bounded window the previous fresh sample stands in for
     * it. A reading that is present and over a limit is never masked, and a
     * held sample that carried a breach still reports it.
     *
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>|null
     */
    private function held(array $live): ?array
    {
        $hold = (int) config('nntmux.orchestrator.safety_sample_hold_seconds', 120);
        if ($hold <= 0 || $this->liveBreach($live)) {
            return null;
        }

        try {
            $last = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->get(self::LAST_GOOD_KEY);
        } catch (Throwable) {
            return null;
        }
        $age = is_array($last) ? time() - (int) ($last['observed_at'] ?? 0) : -1;
        if ($age < 0 || $age > $hold || ! is_array($last['signals'] ?? null)) {
            return null;
        }

        return [...$last['signals'], 'sample_source' => 'held', 'sample_age_seconds' => $age];
    }

    /**
     * @param  array<string, mixed>  $live
     */
    private function liveBreach(array $live): bool
    {
        return ($live['memory_known'] && ! $live['memory_safe'])
            || ($live['cpu_known'] && ! $live['cpu_safe'])
            || ($live['storage_known'] && ! $live['storage_safe']);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function remember(array $signals): void
    {
        if ((int) config('nntmux.orchestrator.safety_sample_hold_seconds', 120) <= 0) {
            return;
        }

        try {
            Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->put(
                self::LAST_GOOD_KEY,
                ['observed_at' => time(), 'signals' => $signals],
                max(1, (int) config('nntmux.orchestrator.safety_sample_hold_seconds', 120)) + 60,
            );
        } catch (Throwable) {
            // The hold is an optimisation; losing it only restores fail-closed.
        }
    }

    private function query(string $query, string $freshnessQuery): ?float
    {
        if ($query === '' || $freshnessQuery === '') {
            return null;
        }

        try {
            return $this->readSample($query, $freshnessQuery);
        } catch (Throwable) {
            // One unanswered query must not erase the other two signals.
            return null;
        }
    }

    private function readSample(string $query, string $freshnessQuery): ?float
    {
        $value = $this->queryValue($query);
        if ($value === null) {
            return null;
        }
        $sampleAt = $this->queryValue($freshnessQuery);
        if ($sampleAt === null) {
            return null;
        }

        $now = microtime(true);
        $maximumAge = (int) config('nntmux.orchestrator.prometheus_sample_max_age_seconds', 120);
        if ($sampleAt > $now + 30 || $now - $sampleAt > $maximumAge) {
            return null;
        }

        return $value;
    }

    private function queryValue(string $query): ?float
    {
        $response = Http::timeout(5)
            ->retry(
                (int) config('nntmux.orchestrator.prometheus_retry_attempts', 3),
                100,
                throw: false,
            )
            ->get(rtrim((string) config('nntmux.orchestrator.prometheus_url'), '/').'/api/v1/query', ['query' => $query]);
        if (! $response->successful() || $response->json('status') !== 'success') {
            return null;
        }

        $result = $response->json('data.result');
        if (! is_array($result) || count($result) !== 1 || ! isset($result[0]['value'][1]) || ! is_numeric($result[0]['value'][1])) {
            return null;
        }

        return (float) $result[0]['value'][1];
    }
}
