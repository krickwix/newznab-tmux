<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class BinariesPostdateValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, date VARCHAR(64))');
        DB::statement('CREATE TABLE binaries (id INTEGER PRIMARY KEY, collections_id INTEGER)');
        DB::statement('CREATE TABLE parts (binaries_id INTEGER, number INTEGER)');
    }

    public function test_postdate_ignores_malformed_local_and_xover_dates_until_a_sane_date_is_found(): void
    {
        DB::table('collections')->insert(['id' => 1, 'date' => '0000-12-12 15:09:20']);
        DB::table('binaries')->insert(['id' => 1, 'collections_id' => 1]);
        DB::table('parts')->insert(['binaries_id' => 1, 'number' => 1000]);

        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getXOVER')->once()->with('1000')->andReturn([
            ['Number' => 1000, 'Date' => '0000-12-12 15:09:20'],
        ]);
        $nntp->shouldReceive('getXOVER')->once()->with(Mockery::on(
            static fn (string $article): bool => (int) $article > 1000
        ))->andReturn([
            ['Number' => 1005, 'Date' => 'Tue, 11 Dec 2018 16:48:06 +0000'],
        ]);

        $service = new BinariesService(
            new BinariesConfig(echoCli: false),
            nntp: $nntp
        );

        self::assertSame(1544546886, $service->postdate(1000, ['first' => 1000, 'last' => 2000]));
    }
}
