<?php

declare(strict_types=1);

namespace Tests\Unit\Binaries;

use App\Services\Binaries\ArticleRangeFloor;
use App\Services\Metrics\DistributedWorkerTelemetry;
use Illuminate\Support\Facades\Cache;
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
    }

    public function test_an_in_flight_range_holds_the_forward_cursor_below_it(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(5, 1_000, 1_199);

        // 999 is the highest cursor position that still leaves 1000-1199 to be
        // read. Protected from the moment the fetch starts, not from the
        // moment it fails.
        self::assertSame(999, $floor->floorFor(5));
        self::assertNull($floor->floorFor(6), 'Ranges are per group.');
    }

    public function test_the_lowest_unread_range_sets_the_floor(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(5, 1_400, 1_599);
        $floor->claim(5, 1_000, 1_199);
        $floor->claim(5, 1_200, 1_399);

        self::assertSame(999, $floor->floorFor(5));

        // As lower ranges are read the floor rises behind them.
        $floor->release(5, 1_000, 1_199);
        self::assertSame(1_199, $floor->floorFor(5));

        $floor->release(5, 1_200, 1_399);
        self::assertSame(1_399, $floor->floorFor(5));
    }

    public function test_a_fully_read_cycle_reports_how_far_it_got_and_holds_nothing(): void
    {
        $floor = new ArticleRangeFloor;
        foreach ([[1_000, 1_199], [1_200, 1_399], [1_400, 1_599]] as [$first, $last]) {
            $floor->claim(5, $first, $last);
        }
        foreach ([[1_400, 1_599], [1_200, 1_399], [1_000, 1_199]] as [$first, $last]) {
            $floor->release(5, $first, $last);
        }

        // Nothing unread, and the furthest article the cycle read is reported
        // so the last child to finish -- which need not be the highest chunk --
        // can still advance the cursor to the end of the window.
        self::assertNull($floor->floorFor(5));
        self::assertSame(1_599, $floor->readMaxFor(5));
        self::assertSame(1_000, $floor->readMinFor(5));
    }

    public function test_a_failed_range_keeps_holding_the_cursor_and_is_counted(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(5, 1_000, 1_199);
        $floor->release(5, 1_000, 1_199);
        $floor->claim(5, 1_200, 1_399);
        $floor->markFailed(5, 1_200, 1_399);

        self::assertSame(1_199, $floor->floorFor(5));
        self::assertSame(1, $this->items()['article_range']['floored']);
    }

    public function test_attempts_are_counted_per_range_not_per_group(): void
    {
        $floor = new ArticleRangeFloor;

        // A different range failing must not spend the budget of this one.
        for ($i = 0; $i < 5; $i++) {
            $floor->claim(5, 9_000, 9_199);
            $floor->markFailed(5, 9_000, 9_199);
        }

        $floor->claim(5, 1_000, 1_199);
        $floor->markFailed(5, 1_000, 1_199);

        self::assertSame(999, $floor->floorFor(5), 'The low range should still be protected.');
    }

    public function test_a_range_that_never_succeeds_is_written_off_rather_than_wedging_the_group(): void
    {
        $floor = new ArticleRangeFloor;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $floor->claim(5, 1_000, 1_199);
            $floor->markFailed(5, 1_000, 1_199);
            self::assertSame(999, $floor->floorFor(5), 'Attempt '.$attempt.' should still hold.');
        }

        // Expired or withdrawn ranges exist, and holding the cursor behind one
        // forever is worse than the leapfrog it prevents.
        $floor->claim(5, 1_000, 1_199);
        $floor->markFailed(5, 1_000, 1_199);

        self::assertNull($floor->floorFor(5));
        self::assertSame(1, $this->items()['article_range']['written_off']);
    }

    public function test_an_abandoned_claim_still_protects_its_range(): void
    {
        config(['nntmux.binaries_article_range_stale_claim_seconds' => 60]);
        $floor = new ArticleRangeFloor;
        $floor->claim(5, 1_000, 1_199);

        // A child killed mid-fetch never releases and never read its range, so
        // the claim must keep protecting it rather than ageing into a release.
        $this->ageClaims(5, 120);

        self::assertSame(999, $floor->floorFor(5));
    }

    public function test_backfill_is_bounded_in_the_other_direction(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(5, 1_000, 1_199);
        $floor->claim(5, 1_200, 1_399);
        $floor->release(5, 1_000, 1_199);

        // first_record walks down, so an unread range above it is a ceiling.
        self::assertSame(1_400, $floor->ceilingFor(5));
        self::assertSame(1_000, $floor->readMinFor(5));
    }

    public function test_the_guard_is_off_by_default(): void
    {
        config(['nntmux.binaries_article_range_floor_seconds' => 0]);
        $floor = new ArticleRangeFloor;

        $floor->claim(5, 1_000, 1_199);
        $floor->markFailed(5, 1_000, 1_199);

        self::assertFalse($floor->enabled());
        self::assertNull($floor->floorFor(5));
        self::assertNull($floor->ceilingFor(5));
    }

    /** @return array<string, array<string, int>> */
    private function items(): array
    {
        return (new DistributedWorkerTelemetry)->snapshot(['binaries'])['workers']['binaries']['items'];
    }

    private function ageClaims(int $groupId, int $seconds): void
    {
        $key = 'nntmux:binaries:article-ranges:'.$groupId;
        $entries = Cache::store('array')->get($key);
        self::assertIsArray($entries);

        foreach ($entries as $rangeKey => $entry) {
            $entries[$rangeKey]['at'] = $entry['at'] - $seconds;
        }

        Cache::store('array')->put($key, $entries, 3600);
    }
}
