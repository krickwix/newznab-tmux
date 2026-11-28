<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\GetArticleRange;
use App\Models\Settings;
use App\Services\Binaries\ArticleRangeFloor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The parallel get_range path is where all 11 production losses happened: ~20
 * children over one contiguous window, each advancing the group cursor with a
 * monotonic max, so a failed child's range is carried past by a sibling that
 * succeeded further along.
 */
final class GetArticleRangeFloorCursorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.binaries_article_range_floor_seconds' => 3600,
            'nntmux.binaries_article_range_max_attempts' => 3,
        ]);
        Cache::store('array')->flush();
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE short_groups (
            name VARCHAR(255),
            first_record INTEGER,
            last_record INTEGER,
            updated DATETIME NULL
        )');
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            first_record INTEGER,
            first_record_postdate DATETIME NULL,
            last_record INTEGER,
            last_record_postdate DATETIME NULL,
            last_updated DATETIME NULL,
            active INTEGER,
            backfill INTEGER
        )');
        // Pinned rather than left absent. Settings::settingValue() memoises
        // the whole settings map in a static for 30 s, so a test that leaves
        // disablebackfillgroup set makes the backfill cursor here take a
        // branch this fixture was not built for -- which is how this test
        // passed alone and errored in the full suite.
        DB::table('settings')->insert(['name' => 'disablebackfillgroup', 'value' => '0']);
        Settings::forgetCachedSettings();

        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.boneless',
            'first_record' => 1_000,
            'first_record_postdate' => '2026-06-06 00:00:00',
            'last_record' => 1_000,
            'last_record_postdate' => '2026-11-27 00:00:00',
            'last_updated' => '2026-11-27 00:00:00',
            'active' => 1,
            'backfill' => 1,
        ]);
    }

    public function test_a_sibling_that_succeeded_higher_up_cannot_carry_the_cursor_past_a_failed_range(): void
    {
        $floor = new ArticleRangeFloor;
        // Two children of one cycle: the low one failed, the high one is about
        // to record its success.
        $floor->claim(1, 1_001, 1_200);
        $floor->markFailed(1, 1_001, 1_200);
        $floor->claim(1, 1_201, 1_400);
        $floor->release(1, 1_201, 1_400);

        $this->updateGroupRecords('binaries', 1_400);

        // 1000, not 1400: articles 1001-1200 were never read by anyone, and
        // moving the cursor past them is how they were lost.
        self::assertSame(1_000, $this->lastRecord());
    }

    public function test_an_in_flight_sibling_is_enough_to_hold_the_cursor(): void
    {
        $floor = new ArticleRangeFloor;
        // Still fetching -- not yet known to have failed. This is the case a
        // floor published only on failure cannot cover, because the sibling
        // below is slower than the one recording its success here.
        $floor->claim(1, 1_001, 1_200);
        $floor->claim(1, 1_201, 1_400);
        $floor->release(1, 1_201, 1_400);

        $this->updateGroupRecords('binaries', 1_400);

        self::assertSame(1_000, $this->lastRecord());
    }

    public function test_the_last_child_to_finish_advances_to_the_end_of_what_the_cycle_read(): void
    {
        $floor = new ArticleRangeFloor;
        foreach ([[1_001, 1_200], [1_201, 1_400], [1_401, 1_600]] as [$first, $last]) {
            $floor->claim(1, $first, $last);
        }
        // The high chunks finish first; the lowest finishes last and is the
        // one that performs this update.
        $floor->release(1, 1_401, 1_600);
        $floor->release(1, 1_201, 1_400);
        $floor->release(1, 1_001, 1_200);

        $this->updateGroupRecords('binaries', 1_200);

        // 1600, not 1200. Capping at the finishing child's own range would
        // throttle the lane to one chunk per cycle and re-fetch the rest.
        self::assertSame(1_600, $this->lastRecord());
        // And the settled cycle is cleared, so it cannot cap the next one.
        self::assertNull($floor->floorFor(1));
        self::assertNull($floor->readMaxFor(1));
    }

    public function test_without_the_guard_the_cursor_advances_as_before(): void
    {
        config(['nntmux.binaries_article_range_floor_seconds' => 0]);

        $this->updateGroupRecords('binaries', 1_400);

        self::assertSame(1_400, $this->lastRecord());
    }

    public function test_a_cap_never_moves_the_cursor_backwards(): void
    {
        DB::table('usenet_groups')->where('id', 1)->update(['last_record' => 5_000]);
        $floor = new ArticleRangeFloor;
        $floor->claim(1, 1_001, 1_200);

        $this->updateGroupRecords('binaries', 1_400, lastRecord: 5_000);

        // The WHERE last_record < ? guard makes a capped value beneath the
        // cursor a no-op rather than a regression.
        self::assertSame(5_000, $this->lastRecord());
    }

    public function test_backfill_is_held_by_an_unread_range_directly_above_it(): void
    {
        $floor = new ArticleRangeFloor;
        // Backfill walks down: the child covering 901-999 failed, the one
        // covering 601-700 succeeded and is recording it.
        $floor->claim(1, 901, 999);
        $floor->markFailed(1, 901, 999);
        $floor->claim(1, 601, 700);
        $floor->release(1, 601, 700);

        $this->updateGroupRecords('backfill', 601);

        // first_record stays at 1000 rather than dropping to 601, which would
        // step over 901-999 for good.
        self::assertSame(1_000, $this->firstRecord());
    }

    public function test_backfill_still_advances_down_to_the_edge_of_the_unread_gap(): void
    {
        $floor = new ArticleRangeFloor;
        // 901-999 was read by some child of this cycle, 801-900 was not.
        $floor->claim(1, 901, 999);
        $floor->release(1, 901, 999);
        $floor->claim(1, 801, 900);
        $floor->markFailed(1, 801, 900);
        $floor->claim(1, 601, 700);
        $floor->release(1, 601, 700);

        $this->updateGroupRecords('backfill', 601);

        // 901, not 601 and not 1000: the cap stops the cursor at the gap
        // rather than freezing it, so a failed range costs one gap's worth of
        // progress and not the whole cycle's.
        self::assertSame(901, $this->firstRecord());
    }

    public function test_binaries_still_advances_up_to_the_edge_of_the_unread_gap(): void
    {
        $floor = new ArticleRangeFloor;
        $floor->claim(1, 1_001, 1_200);
        $floor->release(1, 1_001, 1_200);
        $floor->claim(1, 1_201, 1_400);
        $floor->markFailed(1, 1_201, 1_400);
        $floor->claim(1, 1_401, 1_600);
        $floor->release(1, 1_401, 1_600);

        $this->updateGroupRecords('binaries', 1_600);

        // 1200: everything below the gap is kept, nothing above it is claimed.
        self::assertSame(1_200, $this->lastRecord());
    }

    public function test_a_fully_read_backfill_cycle_advances_to_its_lowest_article(): void
    {
        $floor = new ArticleRangeFloor;
        foreach ([[601, 700], [701, 800], [801, 900]] as [$first, $last]) {
            $floor->claim(1, $first, $last);
            $floor->release(1, $first, $last);
        }

        // The child covering 801-900 finishes last and performs the update.
        $this->updateGroupRecords('backfill', 801);

        self::assertSame(601, $this->firstRecord());
    }

    private function updateGroupRecords(string $mode, int $boundary, int $lastRecord = 1_000): void
    {
        $method = new ReflectionMethod(GetArticleRange::class, 'updateGroupRecords');
        $method->setAccessible(true);
        $method->invoke(new GetArticleRange, $mode, [
            'id' => 1,
            'name' => 'alt.binaries.boneless',
            'first_record' => 1_000,
            'last_record' => $lastRecord,
        ], $mode === 'binaries'
            ? ['lastArticleNumber' => $boundary, 'lastArticleDate' => 'Sat, 28 Nov 2026 01:00:00 +0000']
            : ['firstArticleNumber' => $boundary, 'firstArticleDate' => 'Sat, 28 Nov 2026 01:00:00 +0000'],
            $boundary);
    }

    protected function tearDown(): void
    {
        Settings::forgetCachedSettings();

        parent::tearDown();
    }

    private function lastRecord(): int
    {
        return (int) DB::table('usenet_groups')->where('id', 1)->value('last_record');
    }

    private function firstRecord(): int
    {
        return (int) DB::table('usenet_groups')->where('id', 1)->value('first_record');
    }
}
