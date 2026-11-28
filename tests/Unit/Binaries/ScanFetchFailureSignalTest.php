<?php

declare(strict_types=1);

namespace Tests\Unit\Binaries;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * A failed fetch and an empty range are different facts, and the cursor has to
 * treat them differently -- but the distinction must not travel in scan()'s
 * return value.
 *
 * An earlier attempt returned ['fetchFailed' => true], which is truthy, and
 * that defeated GetArticleRange's empty($return) guard: managed backfill then
 * fell through to completeRange() and receipted a range as complete when
 * nothing had been read. The signal is reported beside the result instead, so
 * every existing reader of the return value keeps its behaviour.
 */
final class ScanFetchFailureSignalTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
    }

    public function test_a_failed_download_still_returns_an_empty_result(): void
    {
        $service = $this->serviceReturning(new NntpError('Decompression of OVER headers failed.', 1000));

        $return = $service->scan($this->group(), 1_001, 1_200, 'backfill');

        // Empty, so GetArticleRange's empty($return) guard still fires and
        // managed backfill fails the range instead of receipting it.
        self::assertSame([], $return);
        self::assertTrue($service->lastFetchFailed());
    }

    public function test_a_range_that_simply_held_no_articles_is_not_reported_as_a_failure(): void
    {
        $service = $this->serviceReturning([]);

        $return = $service->scan($this->group(), 1_001, 1_200, 'backfill');

        // Also empty, but the cursor must be allowed past this one or it
        // stalls on an empty range forever.
        self::assertSame([], $return);
        self::assertFalse($service->lastFetchFailed());
    }

    public function test_the_signal_is_reset_for_each_scan(): void
    {
        $service = $this->serviceReturning(new NntpError('boom', 1000));
        $service->scan($this->group(), 1_001, 1_200, 'backfill');
        self::assertTrue($service->lastFetchFailed());

        // A later clean scan must not inherit it.
        $service = $this->serviceReturning([]);
        $service->scan($this->group(), 1_201, 1_400, 'backfill');
        self::assertFalse($service->lastFetchFailed());
    }

    /** @return array<string, mixed> */
    private function group(): array
    {
        return ['id' => 1, 'name' => 'alt.binaries.boneless', 'last_record' => 1_000, 'first_record' => 1_000];
    }

    private function serviceReturning(mixed $xover): BinariesService
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getXOVER')->andReturn($xover);
        $nntp->shouldReceive('selectGroup')->andReturn(['group' => 'alt.binaries.boneless', 'first' => 1, 'last' => 9_000]);
        $nntp->shouldReceive('doQuit')->andReturn(true);
        $nntp->shouldReceive('doConnect')->andReturn(true);
        $nntp->shouldReceive('enableCompression')->andReturn(true);

        return new BinariesService(
            new BinariesConfig(partRepair: false, echoCli: false),
            nntp: $nntp,
        );
    }
}
