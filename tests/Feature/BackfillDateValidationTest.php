<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Backfill\BackfillConfig;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

final class BackfillDateValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            first_record INTEGER,
            first_record_postdate VARCHAR(64) NULL,
            last_updated VARCHAR(64) NULL
        )');
    }

    public function test_malformed_scan_date_uses_sane_bounded_fallback(): void
    {
        $this->insertGroup();
        $binaries = Mockery::mock(BinariesService::class);
        $binaries->shouldReceive('postdateOrNull')
            ->once()
            ->with(90, ['first' => 1, 'last' => 200])
            ->andReturn(1544546886);
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->once()->with('alt.test')->andReturn(['first' => 1, 'last' => 200]);

        $this->invokeUpdate($binaries, $nntp, ['firstArticleDate' => '0000-12-12 15:09:20']);

        $group = DB::table('usenet_groups')->find(1);
        self::assertSame(90, (int) $group->first_record);
        self::assertStringStartsWith('2018-12-11 16:48:06', $group->first_record_postdate);
    }

    public function test_missing_sane_evidence_advances_cursor_without_fabricating_a_date(): void
    {
        $this->insertGroup();
        $binaries = Mockery::mock(BinariesService::class);
        $binaries->shouldReceive('postdateOrNull')->once()->andReturnNull();
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->once()->andReturn(['first' => 1, 'last' => 200]);

        $this->invokeUpdate($binaries, $nntp, null);

        $group = DB::table('usenet_groups')->find(1);
        self::assertSame(90, (int) $group->first_record);
        self::assertNull($group->first_record_postdate);
        self::assertNotNull($group->last_updated);
    }

    private function insertGroup(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'first_record' => 100,
            'first_record_postdate' => null,
            'last_updated' => null,
        ]);
    }

    /** @param array<string, mixed>|null $scanResult */
    private function invokeUpdate(BinariesService $binaries, NNTPService $nntp, ?array $scanResult): void
    {
        $service = new BackfillService(new BackfillConfig, $binaries, $nntp);
        $method = new ReflectionMethod($service, 'updateGroupRecord');
        $method->invoke($service, ['id' => 1, 'name' => 'alt.test'], 90, $scanResult);
    }
}
