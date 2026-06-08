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
}
