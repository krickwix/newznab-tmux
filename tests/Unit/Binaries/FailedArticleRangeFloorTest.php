<?php

declare(strict_types=1);

namespace Tests\Unit\Binaries;

use App\Services\Binaries\FailedArticleRangeFloor;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The cycle fans ~20 get_range children over one window and each advances
 * usenet_groups.last_record with a monotonic max, so a child whose chunk
 * failed has its range carried past by a luckier sibling -- stored nowhere,
 * queued nowhere. The floor is the shared state that stops that, and it is
 * bounded so a permanently unfetchable range cannot wedge the group instead.
 */
final class FailedArticleRangeFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.binaries_failed_range_floor_seconds' => 3600,
            'nntmux.binaries_failed_range_max_attempts' => 3,
        ]);
        Cache::store('array')->flush();
    }

    public function test_a_failed_range_holds_the_cursor_at_the_article_before_it(): void
    {
        $floor = new FailedArticleRangeFloor;
        $floor->record(5, 1_000, 1_999);

        // 999 is the highest cursor position that still leaves 1000-1999 unread.
        self::assertSame(999, $floor->floorFor(5));
        self::assertNull($floor->floorFor(6), 'The floor is per group.');
    }

    public function test_a_lower_failure_takes_over_the_floor_and_restarts_its_budget(): void
    {
        $floor = new FailedArticleRangeFloor;
        $floor->record(5, 5_000, 5_999);
        $floor->record(5, 1_000, 1_999);

        self::assertSame(999, $floor->floorFor(5));

        // The budget restarted, so the lower range still gets its full
        // allowance rather than inheriting the higher range's spent attempts.
        $floor->record(5, 1_000, 1_999);
        $floor->record(5, 1_000, 1_999);
        self::assertSame(999, $floor->floorFor(5));
    }

    public function test_a_higher_failure_does_not_lift_an_existing_floor(): void
    {
        $floor = new FailedArticleRangeFloor;
        $floor->record(5, 1_000, 1_999);
        $floor->record(5, 5_000, 5_999);

        self::assertSame(999, $floor->floorFor(5));
    }

    public function test_the_floor_is_released_after_its_attempt_budget_is_spent(): void
    {
        $floor = new FailedArticleRangeFloor;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $floor->record(5, 1_000, 1_999);
            self::assertSame(999, $floor->floorFor(5), 'Attempt '.$attempt.' should still hold.');
        }

        // A range that can never be fetched -- expired, withdrawn -- must not
        // wedge the group behind it forever, so the fourth failure gives up
        // and says so in the log rather than stalling.
        $floor->record(5, 1_000, 1_999);
        self::assertNull($floor->floorFor(5));
        self::assertNull($floor->floorFor(5), 'Giving up is remembered, not re-evaluated each call.');
    }

    public function test_reading_the_protected_range_drops_the_floor(): void
    {
        $floor = new FailedArticleRangeFloor;
        $floor->record(5, 1_000, 1_999);

        // A scan that did not reach the failed range changes nothing.
        $floor->clearIfCovered(5, 500, 999);
        self::assertSame(999, $floor->floorFor(5));

        // One that covered its first article does.
        $floor->clearIfCovered(5, 900, 1_100);
        self::assertNull($floor->floorFor(5));
    }

    public function test_the_mechanism_is_off_when_the_window_is_zero(): void
    {
        config(['nntmux.binaries_failed_range_floor_seconds' => 0]);
        $floor = new FailedArticleRangeFloor;

        $floor->record(5, 1_000, 1_999);

        self::assertFalse($floor->enabled());
        self::assertNull($floor->floorFor(5));
    }
}
