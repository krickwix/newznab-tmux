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
 * The two cursors are tracked separately. They share a group but move in
 * opposite directions, and a shared bound freezes both: a backfill claim down
 * at the first_record region would drive the forward floor below last_record,
 * and updateGroupRecords('binaries') would then cap and return without
 * advancing -- forever, while scan() kept storing the same articles every
 * cycle. Each entry carries its lane, floorFor() reads forward claims only and
 * ceilingFor() backfill claims only, and the lane is part of the key so the
 * two can never collide.
 *
 * That collision also mattered for the death proof below: two lanes claiming
 * the same start would have looked like one lane dying, charging a spurious
 * attempt and writing off a live range after three.
 *
 * Scope: this guards the get_range path only. Groups whose remaining gap is
 * within maxmssgs * 2 are queued by BinariesRunner as a single
 * update_group_headers task, which runs one sequential child with no siblings
 * to leapfrog it and never claims. Today that means the floor is active for
 * boneless and inactive for teevee, hdtv.x264, moovee, rail and
 * midnightmovies -- correct, because those lanes cannot lose a range this
 * way, but not the coverage someone might assume from the name.
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

    public const string LANE_FORWARD = 'binaries';

    public const string LANE_BACKFILL = 'backfill';

    /**
     * Causes already reported in this process, so a Redis outage does not
     * drown the worker log in the warning it exists to surface.
     *
     * @var array<string, true>
     */
    private static array $reportedLostClaims = [];

    public function __construct(
        private readonly DistributedWorkerTelemetry $telemetry = new DistributedWorkerTelemetry,
    ) {}

    /**
     * Declare a range as being fetched now, before the fetch starts.
     *
     * Re-claiming the same range carries its attempt count forward, so a range
     * that keeps failing is counted per range rather than per group.
     *
     * An attempt is charged here when the previous claim on this range was
     * still in flight, which means its holder died without reaching either
     * release() or markFailed(). That is the only way a range can be
     * re-claimed while outstanding: chunks within a cycle are disjoint, and
     * the next cycle cannot re-claim a range whose holder settled it.
     *
     * The proof rests on the parent joining its children before the next cycle
     * starts (BinariesRunner waits for every child, then sleeps) and on
     * BaseRunner having no retry, so a child killed at
     * multiprocessing_max_child_time is not re-run inside the cycle. If cycles
     * are ever allowed to overlap, a live claim becomes indistinguishable from
     * a dead one and this charges attempts against ranges that are still being
     * fetched.
     *
     * Detected from the state rather than from a staleness clock. A clock only
     * catches a child that died long enough ago, and the realistic trigger is
     * the opposite -- the binaries pod has a 2Gi limit, so an OOM kill lands
     * seconds into the fetch and the range is re-claimed a cycle later, far
     * inside any sensible staleness window. The first version of this fix
     * used the clock and would have looped forever on exactly the case ingest
     * described.
     *
     * Only an unsettled claim is charged. Charging every re-claim would
     * double-count the ordinary failure path, where markFailed() has already
     * counted it, and halve a recoverable range's budget.
     */
    public function claim(string $lane, int $groupId, int $first, int $last): void
    {
        $this->mutate($groupId, function (array $entries) use ($lane, $first, $last): array {
            $key = self::rangeKey($lane, $first);
            $existing = $entries[$key] ?? null;
            $attempts = (int) ($existing['attempts'] ?? 0);

            if (($existing['state'] ?? null) === self::STATE_IN_FLIGHT) {
                $attempts++;
                $this->telemetry->recordItem('binaries', 'article_range', 'abandoned');

                if ($attempts > $this->maxAttempts()) {
                    unset($entries[$key]);
                    $this->reportWriteOff($first, $last, $attempts - 1);

                    return $entries;
                }
            }

            $entries[$key] = [
                'lane' => $lane,
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
    public function release(string $lane, int $groupId, int $first, int $last): void
    {
        $this->mutate($groupId, static function (array $entries) use ($lane, $first, $last): array {
            $key = self::rangeKey($lane, $first);
            $entries[$key] = [
                'lane' => $lane,
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
    public function markFailed(string $lane, int $groupId, int $first, int $last): void
    {
        $this->mutate($groupId, function (array $entries) use ($lane, $first, $last): array {
            $key = self::rangeKey($lane, $first);
            $attempts = (int) ($entries[$key]['attempts'] ?? 0) + 1;

            if ($attempts > $this->maxAttempts()) {
                unset($entries[$key]);
                $this->reportWriteOff($first, $last, $attempts - 1);

                return $entries;
            }

            $entries[$key] = [
                'lane' => $lane,
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
        $firsts = $this->unreadValues($groupId, self::LANE_FORWARD, 'first');

        return $firsts === [] ? null : max(0, min($firsts) - 1);
    }

    /**
     * Lowest article first_record may reach, or null when nothing is held
     * back. Used by the backfill cursor, which moves the other way.
     */
    public function ceilingFor(int $groupId): ?int
    {
        $lasts = $this->unreadValues($groupId, self::LANE_BACKFILL, 'last');

        return $lasts === [] ? null : max($lasts) + 1;
    }

    /**
     * Furthest article this group's cycle has actually read forward, or null
     * when nothing is recorded.
     */
    public function readMaxFor(int $groupId): ?int
    {
        $lasts = $this->readValues($groupId, self::LANE_FORWARD, 'last');

        return $lasts === [] ? null : max($lasts);
    }

    /**
     * Furthest article this group's cycle has actually read backward.
     */
    public function readMinFor(int $groupId): ?int
    {
        $firsts = $this->readValues($groupId, self::LANE_BACKFILL, 'first');

        return $firsts === [] ? null : min($firsts);
    }

    /**
     * Drop a group's ranges once none are outstanding.
     *
     * Called by whichever child advanced the cursor to the end of what the
     * cycle read, so the next cycle starts from an empty set rather than
     * inheriting completed ranges that would cap it.
     */
    public function pruneIfSettled(string $lane, int $groupId): void
    {
        $this->mutate($groupId, static function (array $entries) use ($lane): array {
            $pruned = $entries;

            foreach ($entries as $key => $entry) {
                if ($entry['lane'] !== $lane) {
                    continue;
                }

                if ($entry['state'] !== self::STATE_DONE) {
                    return $entries;
                }

                unset($pruned[$key]);
            }

            return $pruned;
        });
    }

    /**
     * @return list<int>
     */
    private function unreadValues(int $groupId, string $lane, string $field): array
    {
        return array_values(array_map(
            static fn (array $entry): int => $entry[$field],
            array_filter(
                $this->entries($groupId),
                static fn (array $entry): bool => $entry['lane'] === $lane && $entry['state'] !== self::STATE_DONE,
            ),
        ));
    }

    /**
     * @return list<int>
     */
    private function readValues(int $groupId, string $lane, string $field): array
    {
        return array_values(array_map(
            static fn (array $entry): int => $entry[$field],
            array_filter(
                $this->entries($groupId),
                static fn (array $entry): bool => $entry['lane'] === $lane && $entry['state'] === self::STATE_DONE,
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
     * @return array<string, array{lane: string, first: int, last: int, state: string, attempts: int, at: int}>
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

        foreach ($stored as $key => $entry) {
            if (! is_array($entry) || ! isset($entry['first'], $entry['last'], $entry['state'], $entry['attempts'], $entry['at'])) {
                continue;
            }

            // In-flight claims are left in flight. An earlier version
            // reclassified stale ones as failed, which turned out to do
            // nothing: unreadValues() already treats both as unread, so
            // protection was identical, and the reclassification was not
            // persisted so it could not be counted either. claim() detects an
            // unsettled claim from its state instead.
            $entries[(string) $key] = [
                // Entries written before the lanes were split carry no lane,
                // and are read as forward.
                //
                // Not harmless, and not claimed to be: a legacy backfill entry
                // read this way holds the FORWARD cursor back until the key
                // expires. The alternative is worse -- treating a lane-less
                // entry as settled stops it protecting its range, which lets a
                // cursor advance past something genuinely unread and
                // reintroduces the loss this class exists to prevent. A
                // TTL-bounded stall beats an unbounded loss.
                //
                // Empty in practice, because the flag has never been non-zero.
                // If it is ever enabled, deployed, and then upgraded across
                // this commit, flush the nntmux:binaries:article-ranges:
                // prefix as part of the rollout.
                'lane' => (string) ($entry['lane'] ?? self::LANE_FORWARD),
                'first' => (int) $entry['first'],
                'last' => (int) $entry['last'],
                'state' => (string) $entry['state'],
                'attempts' => (int) $entry['attempts'],
                'at' => (int) $entry['at'],
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
     * @param  callable(array<string, array{lane: string, first: int, last: int, state: string, attempts: int, at: int}>): array<string, mixed>  $change
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
            $this->reportLostClaim('store_unavailable', $groupId, 'The shared cache is unavailable.');

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
                $this->reportLostClaim('lock_timeout', $groupId, 'The per-group lock timed out.');

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
            $this->reportLostClaim('write_failed', $groupId, $e->getMessage());
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

    /**
     * Keyed on the range's first article alone, not on the first/last pair.
     *
     * BinariesRunner::safeBinaryQueueEntries() builds every full chunk as
     * start = cursor + j * maxmssgs + 1, so chunk starts lie on a maxmssgs
     * lattice anchored at the cursor. The floor holds the cursor at
     * min(unread first) - 1, which is a lattice point minus one, so the next
     * cycle's lattice realigns exactly and an abandoned range reappears with
     * an identical start even though its index shifted. Only `last` can move,
     * which is why keying on the pair left a range whose end changed unable to
     * carry its attempt count -- bounded only by the key TTL instead of the
     * attempt budget.
     *
     * If the end shrank, the floor protects slightly more than the re-fetched
     * range. Since the bound is first - 1 either way, that costs nothing.
     *
     * Changing maxmssgs changes the lattice step and invalidates the keys of
     * any floor in flight; no keying choice survives that. Changing
     * max_headers_iteration only moves the trailing partial chunk, which this
     * keying does survive.
     */
    private static function rangeKey(string $lane, int $first): string
    {
        return $lane.':'.$first;
    }

    /**
     * Report a lost claim once per cause per process.
     *
     * mutate() runs about three times per child, so with Redis down an
     * unconditional line per call is ~60 per cycle per group and roughly 55
     * cycles an hour. The signal this exists to make visible would bury
     * everything else in the worker log. A get_range child is short-lived, so
     * once per process is once per affected fetch.
     */
    private function reportLostClaim(string $cause, int $groupId, string $detail): void
    {
        if (isset(self::$reportedLostClaims[$cause])) {
            return;
        }

        self::$reportedLostClaims[$cause] = true;

        Log::warning(sprintf(
            'Article range claim for group %d was not recorded (%s): %s This range is not '
            .'protected from a sibling advancing past it. Further occurrences in this process '
            .'are not logged.',
            $groupId,
            $cause,
            $detail,
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
            Log::debug('Article range floor '.$stage.' failed: '.$e->getMessage());
        }
    }
}
