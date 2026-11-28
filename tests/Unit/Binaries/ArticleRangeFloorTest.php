<?php

declare(strict_types=1);

namespace Tests\Unit\Binaries;

use App\Services\Binaries\ArticleRangeFloor;
use App\Services\Metrics\DistributedWorkerTelemetry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use Tests\TestCase;

/**
 * A cycle fans ~20 get_range children across one window and each advances the
 * group cursor with a monotonic max, so a child whose fetch failed has its
 * range carried past by a sibling that succeeded further along.
 *
 * The claim is taken before the fetch, which is what makes this work at all:
 * recording the failure afterwards arrives too late, because the fetch is the
 * slow part and siblings advance the cursor during it.
 */
final class ArticleRangeFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.binaries_article_range_floor_seconds' => 3600,
            'nntmux.binaries_article_range_max_attempts' => 3,
        ]);
        Cache::store('array')->flush();

        // The lost-claim log is deduplicated per process, so the registry has
        // to be cleared or only the first test in a run would see a warning.
        $reported = new ReflectionProperty(ArticleRangeFloor::class, 'reportedLostClaims');
        $reported->setValue(null, []);
    }

    public function test_an_in_flight_range_holds_the_forward_cursor_below_it(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);

        // 999 is the highest cursor position that still leaves 1000-1199 to be
        // read. Protected from the moment the fetch starts, not from the
        // moment it fails.
        self::assertSame(999, $floor->floorFor(5));
        self::assertNull($floor->floorFor(6), 'Ranges are per group.');
    }

    public function test_the_lowest_unread_range_sets_the_floor(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_400, 1_599);
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_200, 1_399);

        self::assertSame(999, $floor->floorFor(5));

        // As lower ranges are read the floor rises behind them.
        $floor->release(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        self::assertSame(1_199, $floor->floorFor(5));

        $floor->release(ArticleRangeFloor::LANE_FORWARD, 5, 1_200, 1_399);
        self::assertSame(1_399, $floor->floorFor(5));
    }

    public function test_a_fully_read_cycle_reports_how_far_it_got_and_holds_nothing(): void
    {
        $floor = new ArticleRangeFloor;
        foreach ([[1_000, 1_199], [1_200, 1_399], [1_400, 1_599]] as [$first, $last]) {
            $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, $first, $last);
        }
        foreach ([[1_400, 1_599], [1_200, 1_399], [1_000, 1_199]] as [$first, $last]) {
            $floor->release(ArticleRangeFloor::LANE_FORWARD, 5, $first, $last);
        }

        // Nothing unread, and the furthest article the cycle read is reported
        // so the last child to finish -- which need not be the highest chunk --
        // can still advance the cursor to the end of the window.
        self::assertNull($floor->floorFor(5));
        self::assertSame(1_599, $floor->readMaxFor(5));
        // readMinFor is the backfill lane's reader, and this cycle was forward.
        self::assertNull($floor->readMinFor(5));
    }

    public function test_a_failed_range_keeps_holding_the_cursor_and_is_counted(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        $floor->release(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_200, 1_399);
        $floor->markFailed(ArticleRangeFloor::LANE_FORWARD, 5, 1_200, 1_399);

        self::assertSame(1_199, $floor->floorFor(5));
        self::assertSame(1, $this->items()['article_range']['floored']);
    }

    public function test_attempts_are_counted_per_range_not_per_group(): void
    {
        $floor = new ArticleRangeFloor;

        // A different range failing must not spend the budget of this one.
        for ($i = 0; $i < 5; $i++) {
            $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 9_000, 9_199);
            $floor->markFailed(ArticleRangeFloor::LANE_FORWARD, 5, 9_000, 9_199);
        }

        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        $floor->markFailed(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);

        self::assertSame(999, $floor->floorFor(5), 'The low range should still be protected.');
    }

    public function test_a_range_that_never_succeeds_is_written_off_rather_than_wedging_the_group(): void
    {
        $floor = new ArticleRangeFloor;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
            $floor->markFailed(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
            self::assertSame(999, $floor->floorFor(5), 'Attempt '.$attempt.' should still hold.');
        }

        // Expired or withdrawn ranges exist, and holding the cursor behind one
        // forever is worse than the leapfrog it prevents.
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        $floor->markFailed(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);

        self::assertNull($floor->floorFor(5));
        self::assertSame(1, $this->items()['article_range']['written_off']);
    }

    public function test_an_unsettled_claim_keeps_protecting_its_range(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);

        // A child killed mid-fetch never releases and never read its range, so
        // an in-flight claim counts as unread for as long as it stands. It
        // bounds its own lane only -- a forward claim is not a backfill
        // ceiling.
        self::assertSame(999, $floor->floorFor(5));
        self::assertNull($floor->ceilingFor(5));
    }

    public function test_a_child_killed_before_it_can_report_is_still_bounded(): void
    {
        $floor = new ArticleRangeFloor;

        // SIGKILL and the OOM reaper give a child no chance to reach either
        // release() or markFailed(), so nothing charges the attempt. The claim
        // kept protecting its range with attempts stuck at 0, the write-off
        // bound never fired, and the cursor stayed pinned -- the open-ended
        // hold the bound exists to prevent.
        //
        // No clock is involved: the kill lands seconds into the fetch and the
        // range is re-claimed a cycle later, so a staleness window would never
        // elapse. An in-flight claim being re-claimed is itself the proof its
        // holder died.
        for ($cycle = 1; $cycle <= 4; $cycle++) {
            $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);

            self::assertSame(999, $floor->floorFor(5), 'Cycle '.$cycle.' should still hold the cursor.');
        }

        // The fifth claim is the one that exceeds the budget.
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);

        self::assertNull($floor->floorFor(5), 'The bound must fire for killed children too.');
        self::assertSame(1, $this->items()['article_range']['written_off']);
        self::assertSame(4, $this->items()['article_range']['abandoned']);
    }

    public function test_a_claim_killed_within_seconds_is_counted_just_the_same(): void
    {
        $floor = new ArticleRangeFloor;

        // The realistic trigger: the binaries pod has a 2Gi limit, so an OOM
        // kill lands seconds in and the next cycle re-claims ~65 s later. A
        // staleness clock of any sensible length never fires here, and the
        // first version of this fix looped forever on it.
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);

        self::assertSame(1, $this->items()['article_range']['abandoned']);
        self::assertSame(999, $floor->floorFor(5));
    }

    public function test_an_ordinary_failure_is_not_charged_twice(): void
    {
        $floor = new ArticleRangeFloor;

        // markFailed() already counts the attempt, so a re-claim of a range
        // that failed normally must not count it again -- that would spend the
        // budget at double rate and abandon a recoverable range early.
        for ($cycle = 1; $cycle <= 3; $cycle++) {
            $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
            $floor->markFailed(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        }

        self::assertSame(999, $floor->floorFor(5));
        self::assertSame(3, $this->items()['article_range']['floored']);
        self::assertSame(0, $this->items()['article_range']['written_off']);
    }

    public function test_a_lock_timeout_is_reported_rather_than_silently_dropping_a_claim(): void
    {
        config(['nntmux.binaries_article_range_lock_wait_seconds' => 1]);
        $floor = new ArticleRangeFloor;
        // Hold the group's lock so the claim cannot take it. A dropped claim
        // leaves its range unprotected, which must not be invisible.
        $held = Cache::store('array')->lock('nntmux:binaries:article-ranges:5:lock', 30);
        self::assertTrue($held->get());

        try {
            $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        } finally {
            $held->release();
        }

        self::assertNull($floor->floorFor(5), 'The claim was lost, so nothing is held.');
        self::assertSame(1, $this->items()['article_range']['lock_timeout']);
    }

    public function test_a_rechunked_window_still_carries_its_attempt_count(): void
    {
        $floor = new ArticleRangeFloor;

        // Chunk starts sit on a maxmssgs lattice anchored at the cursor, and
        // the floor holds the cursor at first - 1, so the start realigns
        // exactly next cycle. The end can move -- a trailing partial chunk
        // shrinks as the server advances, and the lead moved
        // max_headers_iteration twice inside ten minutes today. Keying the
        // attempt on the pair left such a range unable to carry its count, so
        // it was bounded only by the key TTL.
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_150);
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_099);
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);

        self::assertSame(3, $this->items()['article_range']['abandoned']);
        self::assertSame(999, $floor->floorFor(5), 'Three abandonments are inside the budget.');

        // Fifth claim, still the same start, exceeds it.
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_050);

        self::assertNull($floor->floorFor(5));
        self::assertSame(1, $this->items()['article_range']['written_off']);
    }

    public function test_a_moved_end_is_tracked_so_the_ceiling_follows_it(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(ArticleRangeFloor::LANE_BACKFILL, 5, 1_000, 1_199);
        $floor->claim(ArticleRangeFloor::LANE_BACKFILL, 5, 1_000, 1_099);

        // Keying on the start must not freeze the end: backfill's ceiling is
        // derived from it.
        self::assertSame(1_100, $floor->ceilingFor(5));
    }

    public function test_a_lost_claim_is_logged_once_per_process(): void
    {
        config(['nntmux.binaries_article_range_lock_wait_seconds' => 1]);
        Log::spy();
        $floor = new ArticleRangeFloor;
        $held = Cache::store('array')->lock('nntmux:binaries:article-ranges:5:lock', 30);
        self::assertTrue($held->get());

        try {
            $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
            $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_200, 1_399);
            $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_400, 1_599);
        } finally {
            $held->release();
        }

        // mutate() runs ~3 times per child, so an unconditional line would be
        // ~60 per cycle per group with Redis down and would bury the signal it
        // exists to surface. The counter still records every occurrence.
        Log::shouldHaveReceived('warning')->once();
        self::assertSame(3, $this->items()['article_range']['lock_timeout']);
    }

    public function test_backfill_is_bounded_in_the_other_direction(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(ArticleRangeFloor::LANE_BACKFILL, 5, 1_000, 1_199);
        $floor->claim(ArticleRangeFloor::LANE_BACKFILL, 5, 1_200, 1_399);
        $floor->release(ArticleRangeFloor::LANE_BACKFILL, 5, 1_000, 1_199);

        // first_record walks down, so an unread range above it is a ceiling.
        self::assertSame(1_400, $floor->ceilingFor(5));
        self::assertSame(1_000, $floor->readMinFor(5));
    }

    public function test_the_two_lanes_cannot_freeze_each_other(): void
    {
        $floor = new ArticleRangeFloor;

        // One group can run both lanes at once -- hdtv.x264 has backfill = 1
        // and was 42,917 behind this morning, which puts it over the forward
        // get_range threshold too. With a shared bound the backfill claim down
        // at the first_record region drove the forward floor below
        // last_record, updateGroupRecords('binaries') capped and returned
        // without advancing, and the same range was re-fetched every cycle
        // forever while scan() kept storing it.
        $floor->claim(ArticleRangeFloor::LANE_BACKFILL, 5, 1_000, 1_199);
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 9_000_000, 9_009_999);

        // The forward floor sees only the forward claim.
        self::assertSame(8_999_999, $floor->floorFor(5));
        // The backfill ceiling sees only the backfill claim.
        self::assertSame(1_200, $floor->ceilingFor(5));
    }

    public function test_a_lane_prunes_only_its_own_settled_ranges(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 9_000_000, 9_009_999);
        $floor->release(ArticleRangeFloor::LANE_FORWARD, 5, 9_000_000, 9_009_999);
        $floor->claim(ArticleRangeFloor::LANE_BACKFILL, 5, 1_000, 1_199);

        $floor->pruneIfSettled(ArticleRangeFloor::LANE_FORWARD, 5);

        // The forward lane is settled and cleared; the backfill claim it has
        // no business touching is still standing.
        self::assertNull($floor->readMaxFor(5));
        self::assertSame(1_200, $floor->ceilingFor(5));
    }

    public function test_the_same_start_in_both_lanes_is_not_mistaken_for_a_death(): void
    {
        $floor = new ArticleRangeFloor;

        // Cursors can meet. Two lanes claiming the same start must not read as
        // one lane being re-claimed, which would charge a spurious attempt and
        // write off a live range after three.
        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        $floor->claim(ArticleRangeFloor::LANE_BACKFILL, 5, 1_000, 1_199);

        self::assertSame(0, $this->items()['article_range']['abandoned']);
    }

    public function test_the_guard_is_off_by_default(): void
    {
        config(['nntmux.binaries_article_range_floor_seconds' => 0]);
        $floor = new ArticleRangeFloor;

        $floor->claim(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);
        $floor->markFailed(ArticleRangeFloor::LANE_FORWARD, 5, 1_000, 1_199);

        self::assertFalse($floor->enabled());
        self::assertNull($floor->floorFor(5));
        self::assertNull($floor->ceilingFor(5));
    }

    /** @return array<string, array<string, int>> */
    private function items(): array
    {
        return (new DistributedWorkerTelemetry)->snapshot(['binaries'])['workers']['binaries']['items'];
    }
}
