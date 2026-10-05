<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Negative cache and per-source circuit breaker for external metadata
 * lookups, kept in the cache store so state survives between refresh runs.
 * A cache outage degrades to "no cache, breaker closed".
 */
class ExternalSourceGuard
{
    public function isOpen(string $source): bool
    {
        $state = $this->get($this->breakerKey($source));

        return is_array($state) && (int) ($state['open_until'] ?? 0) > time();
    }

    public function recordSuccess(string $source): void
    {
        $this->forget($this->breakerKey($source));
    }

    public function recordFailure(string $source): void
    {
        $threshold = (int) config('external_metadata.breaker_threshold', 3);
        if ($threshold <= 0) {
            return;
        }

        $state = $this->get($this->breakerKey($source));
        $failures = (is_array($state) ? (int) ($state['failures'] ?? 0) : 0) + 1;
        $openUntil = $failures >= $threshold ? time() + max(1, (int) config('external_metadata.breaker_cooldown', 900)) : 0;

        $this->put($this->breakerKey($source), ['failures' => $failures, 'open_until' => $openUntil], 86400);
    }

    public function isNegative(string $source, string $key): bool
    {
        return $this->get($this->negativeKey($source, $key)) === 1;
    }

    public function rememberNegative(string $source, string $key): void
    {
        $ttl = (int) config('external_metadata.negative_ttl', 86400);
        if ($ttl > 0) {
            $this->put($this->negativeKey($source, $key), 1, $ttl);
        }
    }

    /** A status that says the source is unhealthy rather than that it has no match. */
    public static function isFailureStatus(?int $status): bool
    {
        return $status === null || $status >= 500 || in_array($status, [401, 403, 429], true);
    }

    private function breakerKey(string $source): string
    {
        return "nntmux:extmeta:breaker:{$source}";
    }

    private function negativeKey(string $source, string $key): string
    {
        return "nntmux:extmeta:neg:{$source}:".sha1($key);
    }

    private function get(string $key): mixed
    {
        try {
            return Cache::store((string) config('cache.default', 'redis'))->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function put(string $key, mixed $value, int $ttl): void
    {
        try {
            Cache::store((string) config('cache.default', 'redis'))->put($key, $value, $ttl);
        } catch (Throwable) {
            // Losing guard state only means one more live lookup.
        }
    }

    private function forget(string $key): void
    {
        try {
            Cache::store((string) config('cache.default', 'redis'))->forget($key);
        } catch (Throwable) {
        }
    }
}
