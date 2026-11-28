<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Remembers, per group, the lowest article a header fetch has just failed on,
 * so the group cursor cannot be advanced past it by a luckier sibling.
 *
 * A cycle fans out ~20 get_range children over one contiguous window, and
 * updateGroupAfterScan() advances usenet_groups.last_record with a monotonic
 * max. A child whose chunk failed therefore loses the range permanently: a
 * higher sibling moves last_record past it, nothing stored it, and part repair
 * only tracks parts of binaries that were stored, so there is no record that
 * the range ever existed. The 2026-11-28 compression trial lost 11 of 153
 * chunks exactly this way, but the leapfrog is not specific to compression --
 * any transport-level NNTP failure does it.
 *
 * The floor is held in the shared cache because the children are separate
 * processes and the sibling that must honour the floor is not the one that
 * discovered it.
 *
 * Deliberately bounded. A floor that could be re-armed forever would wedge a
 * group whose range is permanently unfetchable -- an expired or withdrawn
 * window is a worse outcome than the leapfrog it prevents. After
 * maxAttempts() consecutive failures at the same point the floor is reported
 * as exhausted, advancement resumes, and the abandoned range is logged by
 * name. So the behaviour is "retry this range for a few cycles, then skip it
 * loudly", against today's "skip it silently, first time, always".
 */
class FailedArticleRangeFloor
{
    private const string KEY_PREFIX = 'nntmux:binaries:failed-range:';

    /**
     * Record that a fetch of [$first, $last] failed for this group.
     *
     * The floor is the article before the failed range, because that is the
     * highest cursor position that still leaves the failed range unread.
     */
    public function record(int $groupId, int $first, int $last): void
    {
        if (! $this->enabled()) {
            return;
        }

        $floor = max(0, $first - 1);
        $existing = $this->entry($groupId);

        // A failure lower than the one already recorded is a new, worse
        // problem, so it takes over the floor and restarts the attempt count.
        // A failure at or above the current floor is the same problem again.
        $attempts = ($existing !== null && $floor >= $existing['floor'])
            ? $existing['attempts'] + 1
            : 1;

        $this->put($groupId, [
            'floor' => ($existing !== null && $existing['floor'] < $floor) ? $existing['floor'] : $floor,
            'first' => ($existing !== null && $existing['floor'] < $floor) ? $existing['first'] : $first,
            'last' => ($existing !== null && $existing['floor'] < $floor) ? $existing['last'] : $last,
            'attempts' => $attempts,
        ]);
    }

    /**
     * Highest article the cursor may reach, or null when nothing is held back.
     */
    public function floorFor(int $groupId): ?int
    {
        $entry = $this->entry($groupId);

        if ($entry === null) {
            return null;
        }

        if ($entry['attempts'] > $this->maxAttempts()) {
            $this->logExhausted($groupId, $entry);
            $this->clear($groupId);

            return null;
        }

        return $entry['floor'];
    }

    /**
     * Drop the floor once the range it was protecting has been read.
     *
     * Called with the bounds of a scan that succeeded: if it covered the first
     * article of the failed range, the reason for the floor is gone.
     */
    public function clearIfCovered(int $groupId, int $first, int $last): void
    {
        $entry = $this->entry($groupId);

        if ($entry === null) {
            return;
        }

        if ($first <= $entry['first'] && $last >= $entry['first']) {
            $this->clear($groupId);
        }
    }

    public function clear(int $groupId): void
    {
        try {
            $this->store()?->forget(self::KEY_PREFIX.$groupId);
        } catch (Throwable $e) {
            $this->logFailure('clear', $e);
        }
    }

    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    public function ttl(): int
    {
        return max(0, (int) config('nntmux.binaries_failed_range_floor_seconds', 0));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('nntmux.binaries_failed_range_max_attempts', 3));
    }

    /**
     * @return array{floor: int, first: int, last: int, attempts: int}|null
     */
    private function entry(int $groupId): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $entry = $this->store()?->get(self::KEY_PREFIX.$groupId);
        } catch (Throwable $e) {
            $this->logFailure('read', $e);

            return null;
        }

        if (! is_array($entry) || ! isset($entry['floor'], $entry['first'], $entry['last'], $entry['attempts'])) {
            return null;
        }

        return [
            'floor' => (int) $entry['floor'],
            'first' => (int) $entry['first'],
            'last' => (int) $entry['last'],
            'attempts' => (int) $entry['attempts'],
        ];
    }

    /**
     * @param  array{floor: int, first: int, last: int, attempts: int}  $entry
     */
    private function put(int $groupId, array $entry): void
    {
        try {
            $this->store()?->put(self::KEY_PREFIX.$groupId, $entry, $this->ttl());
        } catch (Throwable $e) {
            $this->logFailure('write', $e);
        }
    }

    /**
     * @param  array{floor: int, first: int, last: int, attempts: int}  $entry
     */
    private function logExhausted(int $groupId, array $entry): void
    {
        Log::error(sprintf(
            'Abandoning articles %d-%d for group id %d after %d failed fetches; the group cursor '
            .'is no longer held back and this range will not be read again.',
            $entry['first'],
            $entry['last'],
            $groupId,
            $entry['attempts'] - 1,
        ));
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
            Log::debug('Failed article range floor '.$stage.' failed: '.$e->getMessage());
        }
    }
}
