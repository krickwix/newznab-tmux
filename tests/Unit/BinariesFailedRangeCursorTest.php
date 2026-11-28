<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\FailedArticleRangeFloor;
use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A header fetch that fails stored nothing and queued nothing for part repair,
 * so advancing usenet_groups.last_record past it loses the range for good.
 * scan() used to report that with the same bare [] it uses for a range that
 * legitimately held no articles, and updateGroupAfterScan() advanced on both.
 */
final class BinariesFailedRangeCursorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.binaries_failed_range_floor_seconds' => 3600,
            'nntmux.binaries_failed_range_max_attempts' => 3,
        ]);
        Cache::store('array')->flush();
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
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
        DB::table('settings')->insert(['name' => 'last_run_time', 'value' => '']);
        DB::table('usenet_groups')->insert([
            'id' => 5,
            'name' => 'alt.binaries.blu-ray',
            'first_record' => 100,
            'first_record_postdate' => '2026-06-06 00:00:00',
            'last_record' => 1_000,
            'last_record_postdate' => '2026-06-07 01:11:32',
            'last_updated' => '2026-06-07 01:11:32',
            'active' => 1,
            'backfill' => 1,
        ]);
    }

    public function test_a_failed_header_download_leaves_the_cursor_where_it_was(): void
    {
        $service = new BinariesService(
            new BinariesConfig(partRepair: false, echoCli: false),
            nntp: $this->failingNntp(),
        );

        $service->updateGroup([
            'id' => 5,
            'name' => 'alt.binaries.blu-ray',
            'first_record' => 100,
            'first_record_postdate' => '2026-06-06 00:00:00',
            'last_record' => 1_000,
            'last_record_postdate' => '2026-06-07 01:11:32',
        ]);

        self::assertSame(1_000, (int) DB::table('usenet_groups')->where('id', 5)->value('last_record'));
        // And the failure is published so the cycle's other children honour it.
        self::assertNotNull((new FailedArticleRangeFloor)->floorFor(5));
    }

    public function test_a_sibling_that_succeeded_higher_up_cannot_carry_the_cursor_past_the_failure(): void
    {
        // The failing child of this cycle recorded its range; this is the
        // sibling process that fetched a higher chunk successfully.
        (new FailedArticleRangeFloor)->record(5, 1_200, 1_399);

        $this->applyScanResult(['lastArticleNumber' => 2_000, 'lastArticleDate' => 'Sat, 01 Aug 2026 17:48:51 UTC'], 2_000);

        // 1199, not 2000: articles 1200-1399 were never read by anyone.
        self::assertSame(1_199, (int) DB::table('usenet_groups')->where('id', 5)->value('last_record'));
    }

    public function test_an_empty_range_is_advanced_past_but_not_beyond_a_failed_one(): void
    {
        (new FailedArticleRangeFloor)->record(5, 1_200, 1_399);

        // An empty range must still be advanced past or the cursor stalls on
        // it forever -- just not past the failed range below it.
        $this->applyScanResult([], 2_000);

        self::assertSame(1_199, (int) DB::table('usenet_groups')->where('id', 5)->value('last_record'));
    }

    public function test_without_a_failure_the_cursor_advances_as_before(): void
    {
        $this->applyScanResult(['lastArticleNumber' => 2_000, 'lastArticleDate' => 'Sat, 01 Aug 2026 17:48:51 UTC'], 2_000);

        self::assertSame(2_000, (int) DB::table('usenet_groups')->where('id', 5)->value('last_record'));
    }

    public function test_a_floor_already_behind_the_cursor_is_written_off_rather_than_stalling_the_group(): void
    {
        // Only backfill reads below last_record, so a floor beneath the cursor
        // cannot get its range re-read by the forward scan. Honouring it would
        // stop the group dead until the attempt budget ran out and still not
        // recover the range. This is the one case the mechanism cannot win --
        // a sibling's advance landing before the failing child records its
        // floor -- so the range is given up loudly instead.
        (new FailedArticleRangeFloor)->record(5, 400, 499);

        $this->applyScanResult(['lastArticleNumber' => 2_000, 'lastArticleDate' => 'Sat, 01 Aug 2026 17:48:51 UTC'], 2_000);

        self::assertSame(2_000, (int) DB::table('usenet_groups')->where('id', 5)->value('last_record'));
        self::assertNull((new FailedArticleRangeFloor)->floorFor(5), 'A useless floor must not be left to stall later passes.');
    }

    public function test_a_floor_at_the_cursor_still_holds(): void
    {
        // The boundary: the failed range starts exactly where the cursor is,
        // so the forward scan will offer it again and the floor is worth
        // keeping.
        (new FailedArticleRangeFloor)->record(5, 1_001, 1_400);

        $this->applyScanResult(['lastArticleNumber' => 2_000, 'lastArticleDate' => 'Sat, 01 Aug 2026 17:48:51 UTC'], 2_000);

        self::assertSame(1_000, (int) DB::table('usenet_groups')->where('id', 5)->value('last_record'));
    }

    /**
     * @param  array<string, mixed>  $scanSummary
     */
    private function applyScanResult(array $scanSummary, int $last): void
    {
        $service = new BinariesService(
            new BinariesConfig(partRepair: false, echoCli: false),
            nntp: Mockery::mock(NNTPService::class),
        );

        $groupMySQL = [
            'id' => 5,
            'name' => 'alt.binaries.blu-ray',
            'first_record' => 100,
            'first_record_postdate' => '2026-06-06 00:00:00',
            'last_record' => 1_000,
        ];

        $method = new ReflectionMethod(BinariesService::class, 'updateGroupAfterScan');
        $method->invokeArgs($service, [&$groupMySQL, ['first' => 100, 'last' => 9_000], $scanSummary, $last]);
    }

    private function failingNntp(): NNTPService
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->andReturn([
            'group' => 'alt.binaries.blu-ray',
            'first' => 10,
            'last' => 5_000,
        ]);
        // Both the first attempt and downloadHeaders()' retry-without-
        // compression attempt fail, which is what a transport fault looks like.
        $nntp->shouldReceive('getXOVER')->andReturn(new NntpError('Decompression of OVER headers failed.', 1000));
        $nntp->shouldReceive('doQuit')->andReturn(true);
        $nntp->shouldReceive('doConnect')->andReturn(true);
        $nntp->shouldReceive('enableCompression')->andReturn(true);

        return $nntp;
    }
}
