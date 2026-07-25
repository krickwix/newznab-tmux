<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\PostProcessService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class NzbContentsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.echocli' => false,
        ]);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            nfostatus INTEGER DEFAULT -1
        )');
        DB::statement('CREATE TABLE settings (
            name VARCHAR(255) PRIMARY KEY,
            value TEXT NULL
        )');
        DB::table('settings')->insert(['name' => 'lookuppar2', 'value' => '0']);
    }

    public function test_missing_nzb_artifact_stays_retryable_instead_of_becoming_no_nfo(): void
    {
        DB::table('releases')->insert(['id' => 70004, 'nfostatus' => NfoService::NFO_UNPROC]);

        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('nzbPath')->once()->with('missing-guid')->andReturnFalse();

        $service = new NzbContentsService(
            $nzb,
            Mockery::mock(NzbParserService::class),
            Mockery::mock(NNTPService::class),
            Mockery::mock(NfoService::class),
            (new \ReflectionClass(PostProcessService::class))->newInstanceWithoutConstructor(),
        );

        $this->assertFalse($service->getNfoFromNzb('missing-guid', 70004, 1, 'alt.test'));
        $this->assertSame(-2, (int) DB::table('releases')->where('id', 70004)->value('nfostatus'));
    }
}
