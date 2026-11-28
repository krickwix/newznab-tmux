<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\Metrics\DistributedWorkerTelemetry;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a group's article cursor from moving past a range no child has read.
 *
 * A cycle fans ~20 get_range children across one contiguous window. Each child
 * advances usenet_groups.last_record with a monotonic max (or lowers
 * first_record for backfill), so a child whose fetch failed has its range
 * carried past by any sibling that succeeded further along. Nothing stored
 * those articles, and part repair only tracks parts of binaries that were
 * stored, so there is no record the range ever existed. All 11 losses during
 * the 2026-11-28 compression trial went this way, and nothing about it is
 * specific to compression -- any transport-level NNTP failure does it.
 *
 * The claim is taken BEFORE the fetch, which is the whole point of this
 * version. Recording a failure afterwards cannot work: the fetch is the slow
 * part of a child's life, siblings covering higher sub-ranges of the same
 * window finish inside that window and advance the cursor, so by the time a
 * failing child had something to publish the cursor had already passed it.
 * Claiming first means the range is protected for the entire time it is at
 * risk.
 *
 * Both directions are guarded, because both cursors leapfrog:
 *   - binaries moves last_record up, so the bound is a floor: the lowest
 *     unread `first` minus one.
 *   - backfill moves first_record down, so the bound is a ceiling: the highest
 *     unread `last` plus one.
 *
 * Bounded on purpose. A range that can never be fetched -- expired, withdrawn,
 * a group the provider has lost -- must not wedge the group behind it forever,
 * which would be a worse outcome than the leapfrog. After maxAttempts()
 * failures of the same (group, first, last) the entry is written off,
 * reported, and logged by article number.
 */
class ArticleRangeFloor
{
    private const string KEY_PREFIX = 'nntmux:binaries:article-ranges:';

    private const string STATE_IN_FLIGHT = 'in_flight';

    private const string STATE_FAILED = 'failed';

    private const string STATE_DONE = 'done';

    public function __construct(
        private readonly DistributedWorkerTelemetry $telemetry = new DistributedWorkerTelemetry,
    ) {}

    /**
     * Declare a range as being fetched now, before the fetch starts.
     *
     * Re-claiming the same range carries its attempt count forward, so a range
     * that keeps failing is counted per range rather than per group.
     *
     * An attempt is charged here only when the previous claim was abandoned --
     * a child that died without reaching markFailed(). Charging every re-claim
     * would double-count the ordinary failure path, which markFailed() has
     * already counted.
     */
    public function claim(int $groupId, int $first, int $last): void
    {
        $this->mutate($groupId, function (array $entries) use ($first, $last): array {
            $key = self::rangeKey($first, $last);
            $existing = $entries[$key] ?? null;
            $attempts = (int) ($existing['attempts'] ?? 0);

            if (($existing['abandoned'] ?? false) === true) {
                $attempts++;

                if ($attempts > $this->maxAttempts()) {
                    unset($entries[$key]);
                    $this->reportWriteOff($first, $last, $attempts - 1);

                    return $entries;
                }
            }

            $entries[$key] = [
                'first' => $first,
                'last' => $last,
                'state' => self::STATE_IN_FLIGHT,
                'attempts' => $attempts,
                'at' => time(),
            ];

            return $entries;
        });
    }

    /**
     * The range was read. It stops holding the cursor back, but it is recorded
     * as done rather than forgotten.
     *
     * Forgetting it would throttle the pipeline to one chunk per cycle. The
     * children finish in any order, and whichever finishes last is the only
     * one that sees no outstanding claims -- if that is a low chunk, its own
     * lastArticleNumber is all it could advance to, and the higher chunks it
     * cannot see would be re-fetched next cycle. Keeping the read ranges lets
     * that child advance to the furthest article the cycle actually read.
     */
    public function release(int $groupId, int $first, int $last): void
    {
        $this->mutate($groupId, static function (array $entries) use ($first, $last): array {
            $key = self::rangeKey($first, $last);
            $entries[$key] = [
                'first' => $first,
                'last' => $last,
                'state' => self::STATE_DONE,
                'attempts' => (int) ($entries[$key]['attempts'] ?? 0),
                'at' => time(),
            ];

            return $entries;
        });
    }

    /**
     * The fetch failed. Keep holding the cursor, and count the attempt against
     * this range so a permanently dead one eventually gets written off.
     */
    public function markFailed(int $groupId, int $first, int $last): void
    {
        $this->mutate($groupId, function (array $entries) use ($first, $last): array {
            $key = self::rangeKey($first, $last);
            $attempts = (int) ($entries[$key]['attempts'] ?? 0) + 1;

            if ($attempts > $this->maxAttempts()) {
                unset($entries[$key]);
                $this->reportWriteOff($first, $last, $attempts - 1);

                return $entries;
            }

            $entries[$key] = [
                'first' => $first,
                'last' => $last,
                'state' => self::STATE_FAILED,
                'attempts' => $attempts,
                'at' => time(),
            ];
            $this->telemetry->recordItem('binaries', 'article_range', 'floored');

            return $entries;
        });
    }

    /**
     * Highest article last_record may reach, or null when nothing is held
     * back. Used by the forward (binaries) cursor.
     */
    public function floorFor(int $groupId): ?int
    {
        $firsts = $this->unreadValues($groupId, 'first');

        return $firsts === [] ? null : max(0, min($firsts) - 1);
    }

    /**
     * Lowest article first_record may reach, or null when nothing is held
     * back. Used by the backfill cursor, which moves the other way.
     */
    public function ceilingFor(int $groupId): ?int
    {
        $lasts = $this->unreadValues($groupId, 'last');

        return $lasts === [] ? null : max($lasts) + 1;
    }

    /**
     * Furthest article this group's cycle has actually read forward, or null
     * when nothing is recorded.
     */
    public function readMaxFor(int $groupId): ?int
    {
        $lasts = $this->readValues($groupId, 'last');

        return $lasts === [] ? null : max($lasts);
    }

    /**
     * Furthest article this group's cycle has actually read backward.
     */
    public function readMinFor(int $groupId): ?int
    {
        $firsts = $this->readValues($groupId, 'first');

        return $firsts === [] ? null : min($firsts);
    }

    /**
     * Drop a group's ranges once none are outstanding.
     *
     * Called by whichever child advanced the cursor to the end of what the
     * cycle read, so the next cycle starts from an empty set rather than
     * inheriting completed ranges that would cap it.
     */
    public function pruneIfSettled(int $groupId): void
    {
        $this->mutate($groupId, static function (array $entries): array {
            foreach ($entries as $entry) {
                if ($entry['state'] !== self::STATE_DONE) {
                    return $entries;
                }
            }

            return [];
        });
    }

    /**
     * @return list<int>
     */
    private function unreadValues(int $groupId, string $field): array
    {
        return array_values(array_map(
            static fn (array $entry): int => $entry[$field],
            array_filter(
                $this->entries($groupId),
                static fn (array $entry): bool => $entry['state'] !== self::STATE_DONE,
            ),
        ));
    }

    /**
     * @return list<int>
     */
    private function readValues(int $groupId, string $field): array
    {
        return array_values(array_map(
            static fn (array $entry): int => $entry[$field],
            array_filter(
                $this->entries($groupId),
                static fn (array $entry): bool => $entry['state'] === self::STATE_DONE,
            ),
        ));
    }

    public function enabled(): bool
    {
        return $this->ttl() > 0;
    }

    public function ttl(): int
    {
        return max(0, (int) config('nntmux.binaries_article_range_floor_seconds', 0));
    }

    /**
     * How long a child waits for the per-group lock before giving up and
     * reporting the claim as lost.
     */
    public function lockWaitSeconds(): int
    {
        return max(1, (int) config('nntmux.binaries_article_range_lock_wait_seconds', 5));
    }

    public function maxAttempts(): int
    {
        return max(1, (int) config('nntmux.binaries_article_range_max_attempts', 3));
    }

    /**
     * How long an in-flight claim may stand before it is treated as a failure.
     *
     * A child killed mid-fetch never releases its claim, and the range was not
     * read, so the claim must keep protecting it -- but as a failure, which is
     * counted and therefore bounded, rather than as an in-flight claim that
     * would hold the cursor until the whole key expired.
     */
    public function staleClaimSeconds(): int
    {
        return max(60, (int) config('nntmux.binaries_article_range_stale_claim_seconds', 1800));
    }

    /**
     * @return array<string, array{first: int, last: int, state: string, attempts: int, at: int, abandoned: bool}>
     */
    private function entries(int $groupId): array
    {
        if (! $this->enabled()) {
            return [];
        }

        try {
            $stored = $this->store()?->get(self::KEY_PREFIX.$groupId);
        } catch (Throwable $e) {
            $this->logFailure('read', $e);

            return [];
        }

        if (! is_array($stored)) {
            return [];
        }

        $entries = [];
        $cutoff = time() - $this->staleClaimSeconds();

        foreach ($stored as $key => $entry) {
            if (! is_array($entry) || ! isset($entry['first'], $entry['last'], $entry['state'], $entry['attempts'], $entry['at'])) {
                continue;
            }

            // An abandoned claim still protects its range; it just stops being
            // open-ended. Flagged rather than silently rewritten, because the
            // flag is what lets claim() charge the abandonment an attempt --
            // a child killed by SIGKILL or the OOM reaper never reaches
            // markFailed(), so without this its attempts stay at 0 and the
            // write-off bound never fires. The range would then hold the
            // cursor until the whole key expired, which is the open-ended
            // hold this class exists to avoid.
            $abandoned = $entry['state'] === self::STATE_IN_FLIGHT && (int) $entry['at'] < $cutoff;

            $entries[(string) $key] = [
                'first' => (int) $entry['first'],
                'last' => (int) $entry['last'],
                'state' => $abandoned ? self::STATE_FAILED : (string) $entry['state'],
                'attempts' => (int) $entry['attempts'],
                'at' => (int) $entry['at'],
                'abandoned' => $abandoned,
            ];
        }

        return $entries;
    }

    /**
     * Read-modify-write under a per-group lock.
     *
     * Children of one cycle mutate the same group's entry set concurrently, so
     * an unlocked update would lose claims -- and a lost claim is a range that
     * silently stops being protected, which is the bug this class exists to
     * prevent.
     *
     * @param  callable(array<string, array{first: int, last: int, state: string, attempts: int, at: int, abandoned: bool}>): array<string, mixed>  $change
     */
    private function mutate(int $groupId, callable $change): void
    {
        if (! $this->enabled()) {
            return;
        }

        $store = $this->store();

        if ($store === null) {
            // Not counted, because the counter lives in the store that is
            // unavailable -- recordItem() would silently fail too and leave a
            // flat line that reads as "never happened". The log is the signal
            // here, and it is unconditional rather than debug-gated: a lost
            // claim means a range is unprotected, which is the failure this
            // class exists to prevent.
            Log::warning(sprintf(
                'Article range claim for group %d was not recorded: the shared cache is unavailable, '
                .'so this range is not protected from a sibling advancing past it.',
                $groupId,
            ));

            return;
        }

        try {
            $lockProvider = $store->getStore();
            $lock = $lockProvider instanceof LockProvider
                ? $lockProvider->lock(self::KEY_PREFIX.$groupId.':lock', 10)
                : null;

            if ($lock !== null && ! $lock->block($this->lockWaitSeconds())) {
                // The store is reachable here, so this one does count.
                $this->telemetry->recordItem('binaries', 'article_range', 'lock_timeout');
                Log::warning(sprintf(
                    'Article range claim for group %d was not recorded: the per-group lock timed out, '
                    .'so this range is not protected from a sibling advancing past it.',
                    $groupId,
                ));

                return;
            }

            try {
                $entries = $change($this->entries($groupId));

                if ($entries === []) {
                    $store->forget(self::KEY_PREFIX.$groupId);

                    return;
                }

                $store->put(self::KEY_PREFIX.$groupId, $entries, $this->ttl());
            } finally {
                $lock?->release();
            }
        } catch (Throwable $e) {
            $this->telemetry->recordItem('binaries', 'article_range', 'lock_timeout');
            Log::warning(sprintf(
                'Article range claim for group %d was not recorded: %s',
                $groupId,
                $e->getMessage(),
            ));
            $this->logFailure('write', $e);
        }
    }

    private function reportWriteOff(int $first, int $last, int $attempts): void
    {
        $this->telemetry->recordItem('binaries', 'article_range', 'written_off');
        Log::error(sprintf(
            'Abandoning articles %d-%d after %d failed fetches; the group cursor is no longer held '
            .'back and this range will not be read again.',
            $first,
            $last,
            $attempts,
        ));
    }

    private static function rangeKey(int $first, int $last): string
    {
        return $first.'-'.$last;
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
            Log::debug('Article range floor '.$stage.' failed: '.$e->getMessage());
        }
    }
}
