<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRemoverService;
use App\Services\Releases\ReleaseManagementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class ReleaseRemoverPar2OnlyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction(
            'REGEXP',
            static function (?string $pattern, ?string $subject): int {
                if ($pattern === null || $subject === null || $pattern === '') {
                    return 0;
                }

                set_error_handler(static fn (): true => true);
                $ok = @preg_match('~'.$pattern.'~', $subject);
                restore_error_handler();

                return $ok === 1 ? 1 : 0;
            },
            2
        );

        $this->createTables();
    }

    public function test_par2_searchname_without_release_files_is_not_deleted_before_post_processing(): void
    {
        DB::table('releases')->insert([
            'id' => 5987,
            'guid' => '532f279a-b05b-4978-bff0-936de949092a',
            'searchname' => 'RSdE2G1730664047EVHy9241103QfG.vol83+13.par2',
            'ishashed' => 0,
            'isrenamed' => 0,
            'adddate' => now()->format('Y-m-d H:i:s'),
        ]);

        $releaseManagement = Mockery::mock(ReleaseManagementService::class);
        $releaseManagement->shouldNotReceive('deleteSingleWithService');

        $service = new ReleaseRemoverService(
            releaseManagement: $releaseManagement,
            nzb: Mockery::mock(NzbService::class),
            nzbParser: Mockery::mock(NzbParserService::class),
            releaseImage: Mockery::mock(ReleaseImageService::class)
        );

        $this->assertTrue($service->removeCrap(true, 'full', 'par2only'));
    }

    public function test_par2_searchname_with_only_par2_release_files_is_deleted(): void
    {
        DB::table('releases')->insert([
            'id' => 1,
            'guid' => 'par2-only-guid',
            'searchname' => 'Only.Repair.Files.vol001+02.par2',
            'ishashed' => 0,
            'isrenamed' => 0,
            'adddate' => now()->format('Y-m-d H:i:s'),
        ]);
        DB::table('release_files')->insert([
            'id' => 1,
            'releases_id' => 1,
            'name' => 'Only.Repair.Files.vol001+02.par2',
        ]);

        $releaseManagement = Mockery::mock(ReleaseManagementService::class);
        $releaseManagement
            ->shouldReceive('deleteSingleWithService')
            ->once()
            ->with(['g' => 'par2-only-guid', 'i' => 1], Mockery::any(), Mockery::any());

        $service = new ReleaseRemoverService(
            releaseManagement: $releaseManagement,
            nzb: Mockery::mock(NzbService::class),
            nzbParser: Mockery::mock(NzbParserService::class),
            releaseImage: Mockery::mock(ReleaseImageService::class)
        );

        $this->assertTrue($service->removeCrap(true, 'full', 'par2only'));
    }

    public function test_par2_searchname_with_content_release_file_is_not_deleted(): void
    {
        DB::table('releases')->insert([
            'id' => 2,
            'guid' => 'content-guid',
            'searchname' => 'Movie.Release.vol001+02.par2',
            'ishashed' => 0,
            'isrenamed' => 0,
            'adddate' => now()->format('Y-m-d H:i:s'),
        ]);
        DB::table('release_files')->insert([
            'id' => 2,
            'releases_id' => 2,
            'name' => 'Movie.Release.mkv',
        ]);

        $releaseManagement = Mockery::mock(ReleaseManagementService::class);
        $releaseManagement->shouldNotReceive('deleteSingleWithService');

        $service = new ReleaseRemoverService(
            releaseManagement: $releaseManagement,
            nzb: Mockery::mock(NzbService::class),
            nzbParser: Mockery::mock(NzbParserService::class),
            releaseImage: Mockery::mock(ReleaseImageService::class)
        );

        $this->assertTrue($service->removeCrap(true, 'full', 'par2only'));
    }

    public function test_recent_hashed_release_is_not_deleted_before_post_processing_has_time_to_run(): void
    {
        DB::table('releases')->insert([
            'id' => 3,
            'guid' => 'fresh-hashed-guid',
            'searchname' => '[205/205] - "EKfaF21722054145d4Jim2407274qCtk.vol84+14.par2"',
            'ishashed' => 0,
            'isrenamed' => 0,
            'adddate' => Carbon::now()->format('Y-m-d H:i:s'),
            'nfostatus' => 0,
            'iscategorized' => 1,
            'rarinnerfilecount' => 0,
            'categories_id' => 2999,
            'nzbstatus' => 0,
        ]);

        $releaseManagement = Mockery::mock(ReleaseManagementService::class);
        $releaseManagement->shouldNotReceive('deleteSingleWithService');

        $service = new ReleaseRemoverService(
            releaseManagement: $releaseManagement,
            nzb: Mockery::mock(NzbService::class),
            nzbParser: Mockery::mock(NzbParserService::class),
            releaseImage: Mockery::mock(ReleaseImageService::class)
        );

        $this->assertTrue($service->removeCrap(true, 'full', 'hashed'));
    }

    public function test_old_hashed_release_without_post_processing_evidence_is_deleted(): void
    {
        DB::table('releases')->insert([
            'id' => 4,
            'guid' => 'old-hashed-guid',
            'searchname' => '[205/205] - "EKfaF21722054145d4Jim2407274qCtk.vol84+14.par2"',
            'ishashed' => 0,
            'isrenamed' => 0,
            'adddate' => Carbon::now()->subMinutes(31)->format('Y-m-d H:i:s'),
            'nfostatus' => 0,
            'iscategorized' => 1,
            'rarinnerfilecount' => 0,
            'categories_id' => 2999,
            'nzbstatus' => 0,
        ]);

        $releaseManagement = Mockery::mock(ReleaseManagementService::class);
        $releaseManagement
            ->shouldReceive('deleteSingleWithService')
            ->once()
            ->with(['g' => 'old-hashed-guid', 'i' => 4], Mockery::any(), Mockery::any());

        $service = new ReleaseRemoverService(
            releaseManagement: $releaseManagement,
            nzb: Mockery::mock(NzbService::class),
            nzbParser: Mockery::mock(NzbParserService::class),
            releaseImage: Mockery::mock(ReleaseImageService::class)
        );

        $this->assertTrue($service->removeCrap(true, 'full', 'hashed'));
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(40) NOT NULL,
            searchname VARCHAR(255) NOT NULL,
            ishashed INTEGER NOT NULL DEFAULT 0,
            isrenamed INTEGER NOT NULL DEFAULT 0,
            nfostatus INTEGER NOT NULL DEFAULT 0,
            iscategorized INTEGER NOT NULL DEFAULT 0,
            rarinnerfilecount INTEGER NOT NULL DEFAULT 0,
            categories_id INTEGER NOT NULL DEFAULT 10,
            nzbstatus INTEGER NOT NULL DEFAULT 0,
            adddate DATETIME NULL
        )');
        DB::statement('CREATE TABLE release_files (
            id INTEGER PRIMARY KEY,
            releases_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL
        )');
    }
}
