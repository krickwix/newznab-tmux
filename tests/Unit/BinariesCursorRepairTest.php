<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class BinariesCursorRepairTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
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
    }

    public function test_group_cursor_ahead_of_server_newest_is_clamped(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 5,
            'name' => 'alt.binaries.blu-ray',
            'first_record' => 100,
            'first_record_postdate' => '2026-06-06 00:00:00',
            'last_record' => 1000,
            'last_record_postdate' => '2026-06-07 01:11:32',
            'last_updated' => '2026-06-07 01:11:32',
            'active' => 1,
            'backfill' => 1,
        ]);

        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')
            ->once()
            ->with('alt.binaries.blu-ray')
            ->andReturn([
                'group' => 'alt.binaries.blu-ray',
                'first' => 10,
                'last' => 500,
            ]);
        $nntp->shouldNotReceive('getOverview');
        $nntp->shouldNotReceive('getXOVER');

        $service = new BinariesService(
            new BinariesConfig(partRepair: false, echoCli: false),
            nntp: $nntp
        );

        $service->updateGroup([
            'id' => 5,
            'name' => 'alt.binaries.blu-ray',
            'first_record' => 100,
            'first_record_postdate' => '2026-06-06 00:00:00',
            'last_record' => 1000,
            'last_record_postdate' => '2026-06-07 01:11:32',
        ]);

        $this->assertSame(500, (int) DB::table('usenet_groups')->where('id', 5)->value('last_record'));
        $this->assertNotNull(DB::table('usenet_groups')->where('id', 5)->value('last_updated'));
    }

    public function test_group_check_with_no_new_articles_touches_last_updated(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 7711,
            'name' => 'alt.binaries.multimedia.vintage-film.post-1960',
            'first_record' => 8949,
            'first_record_postdate' => '2008-08-27 21:37:29',
            'last_record' => 11028948,
            'last_record_postdate' => '2026-06-09 02:35:06',
            'last_updated' => '2026-06-10 04:29:44',
            'active' => 1,
            'backfill' => 1,
        ]);

        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')
            ->once()
            ->with('alt.binaries.multimedia.vintage-film.post-1960')
            ->andReturn([
                'group' => 'alt.binaries.multimedia.vintage-film.post-1960',
                'first' => 4,
                'last' => 11028948,
            ]);
        $nntp->shouldNotReceive('getOverview');
        $nntp->shouldNotReceive('getXOVER');

        $service = new BinariesService(
            new BinariesConfig(partRepair: false, echoCli: false),
            nntp: $nntp
        );

        $service->updateGroup([
            'id' => 7711,
            'name' => 'alt.binaries.multimedia.vintage-film.post-1960',
            'first_record' => 8949,
            'first_record_postdate' => '2008-08-27 21:37:29',
            'last_record' => 11028948,
            'last_record_postdate' => '2026-06-09 02:35:06',
        ]);

        $this->assertSame(11028948, (int) DB::table('usenet_groups')->where('id', 7711)->value('last_record'));
        $this->assertNotSame(
            '2026-06-10 04:29:44',
            DB::table('usenet_groups')->where('id', 7711)->value('last_updated')
        );
    }

    public function test_tiny_new_group_with_no_scan_range_initializes_cursor(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 8801,
            'name' => 'a.b.cd.lossless',
            'first_record' => 0,
            'first_record_postdate' => null,
            'last_record' => 0,
            'last_record_postdate' => null,
            'last_updated' => null,
            'active' => 1,
            'backfill' => 1,
        ]);

        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')
            ->once()
            ->with('a.b.cd.lossless')
            ->andReturn([
                'group' => 'a.b.cd.lossless',
                'first' => 2,
                'last' => 905,
            ]);
        $nntp->shouldNotReceive('getOverview');
        $nntp->shouldNotReceive('getXOVER');

        $service = new BinariesService(
            new BinariesConfig(
                messageBuffer: 10000,
                partRepair: false,
                newGroupScanByDays: false,
                newGroupMessagesToScan: 50000,
                echoCli: false
            ),
            nntp: $nntp
        );

        $service->updateGroup([
            'id' => 8801,
            'name' => 'a.b.cd.lossless',
            'first_record' => 0,
            'first_record_postdate' => null,
            'last_record' => 0,
            'last_record_postdate' => null,
        ]);

        $group = DB::table('usenet_groups')->where('id', 8801)->first();

        $this->assertSame(2, (int) $group->first_record);
        $this->assertSame(2, (int) $group->last_record);
        $this->assertNotNull($group->first_record_postdate);
        $this->assertNotNull($group->last_record_postdate);
        $this->assertNotNull($group->last_updated);
    }
}
