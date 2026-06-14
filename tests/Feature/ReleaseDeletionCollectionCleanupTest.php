<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class ReleaseDeletionCollectionCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        $this->createTables();
    }

    public function test_deleting_release_removes_linked_collection_descendants(): void
    {
        DB::table('releases')->insert([
            'id' => 1,
            'guid' => 'release-guid',
        ]);
        DB::table('collections')->insert([
            'id' => 10,
            'releases_id' => 1,
        ]);
        DB::table('binaries')->insert([
            'id' => 20,
            'collections_id' => 10,
        ]);
        DB::table('parts')->insert([
            'id' => 30,
            'binaries_id' => 20,
        ]);

        Search::shouldReceive('deleteRelease')->once()->with(1);

        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('nzbPath')->once()->with('release-guid')->andReturn('');

        $releaseImage = Mockery::mock(ReleaseImageService::class);
        $releaseImage->shouldReceive('delete')->once()->with('release-guid');

        (new ReleaseManagementService)->deleteSingle(
            ['g' => 'release-guid', 'i' => 1],
            $nzb,
            $releaseImage
        );

        $this->assertSame(0, Release::query()->whereKey(1)->count());
        $this->assertSame(0, DB::table('collections')->where('id', 10)->count());
        $this->assertSame(0, DB::table('binaries')->where('id', 20)->count());
        $this->assertSame(0, DB::table('parts')->where('id', 30)->count());
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(40) NOT NULL
        )');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            releases_id INTEGER NULL
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            collections_id INTEGER
        )');
        DB::statement('CREATE TABLE parts (
            id INTEGER PRIMARY KEY,
            binaries_id INTEGER
        )');
    }
}
