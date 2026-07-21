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

    public function test_deleting_an_open_current_forward_release_records_disposition_and_quarantines_lineage(): void
    {
        config()->set('nntmux.orchestrator.current_forward_continuation_enabled', false);
        DB::table('current_forward_sources')->insert([
            'id' => 1,
            'state' => 'READY',
        ]);
        DB::table('current_forward_windows')->insert([
            'id' => 1,
            'source_id' => 1,
            'generation' => 41,
            'state' => 'CONTINUATION_PENDING',
            'chain_root_id' => 1,
        ]);
        DB::table('current_forward_windows')->insert([
            'id' => 2,
            'source_id' => 1,
            'generation' => 42,
            'state' => 'CLAIMED',
            'chain_root_id' => 1,
            'parent_window_id' => 1,
            'chain_ordinal' => 2,
        ]);
        DB::table('settings')->insert([
            ['name' => 'orchestrator_cf_permit', 'value' => '0'],
            ['name' => 'orchestrator_cf_claimed', 'value' => '42'],
            ['name' => 'orchestrator_cf_completed', 'value' => '41'],
            ['name' => 'orchestrator_cf_failed', 'value' => '0'],
            ['name' => 'orchestrator_cf_failure', 'value' => ''],
        ]);
        DB::table('releases')->insert([
            'id' => 2,
            'guid' => 'lineage-release-guid',
            'categories_id' => 5040,
            'nzbstatus' => -1,
            'size' => 123456,
        ]);
        DB::table('collections')->insert([
            'id' => 11,
            'releases_id' => 2,
        ]);
        DB::table('binaries')->insert([
            'id' => 21,
            'collections_id' => 11,
        ]);
        DB::table('parts')->insert([
            'id' => 31,
            'binaries_id' => 21,
        ]);
        DB::table('current_forward_window_objects')->insert([
            'window_id' => 1,
            'chain_root_id' => 1,
            'object_type' => 'RELEASE',
            'object_id' => 2,
            'parent_object_id' => 11,
        ]);
        DB::table('current_forward_object_owners')->insert([
            'object_type' => 'RELEASE',
            'object_id' => 2,
            'chain_root_id' => 1,
        ]);

        Search::shouldReceive('deleteRelease')->once()->with(2);
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('nzbPath')->once()->with('lineage-release-guid')->andReturn('');
        $releaseImage = Mockery::mock(ReleaseImageService::class);
        $releaseImage->shouldReceive('delete')->once()->with('lineage-release-guid');

        (new ReleaseManagementService)->deleteSingle(
            ['g' => 'lineage-release-guid', 'i' => 2, 'reason' => 'Executable'],
            $nzb,
            $releaseImage,
        );

        $this->assertDatabaseMissing('releases', ['id' => 2]);
        $this->assertDatabaseHas('current_forward_release_dispositions', [
            'release_id' => 2,
            'chain_root_id' => 1,
            'window_id' => 1,
            'parent_collection_id' => 11,
            'reason' => 'executable',
            'categories_id' => 5040,
            'nzbstatus' => -1,
            'size' => 123456,
        ]);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => 1,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_release_removed_executable',
        ]);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => 2,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_release_removed_executable',
        ]);
        $this->assertDatabaseHas('settings', [
            'name' => 'orchestrator_cf_failed',
            'value' => '42',
        ]);
        $this->assertDatabaseHas('settings', [
            'name' => 'orchestrator_cf_failure',
            'value' => 'current_forward_release_removed_executable',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'id' => 1,
            'state' => 'READY',
            'last_reason' => 'current_forward_release_removed_executable',
        ]);
        $this->assertDatabaseMissing('collections', ['id' => 11]);
        $this->assertDatabaseMissing('binaries', ['id' => 21]);
        $this->assertDatabaseMissing('parts', ['id' => 31]);
    }

    public function test_deletion_refuses_mismatched_id_and_guid_before_external_side_effects(): void
    {
        DB::table('releases')->insert([
            ['id' => 3, 'guid' => 'release-a'],
            ['id' => 4, 'guid' => 'release-b'],
        ]);
        Search::shouldReceive('deleteRelease')->never();
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldNotReceive('nzbPath');
        $releaseImage = Mockery::mock(ReleaseImageService::class);
        $releaseImage->shouldNotReceive('delete');

        try {
            (new ReleaseManagementService)->deleteSingle(
                ['g' => 'release-b', 'i' => 3],
                $nzb,
                $releaseImage,
            );
            self::fail('Mismatched release ID and GUID were accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('do not identify the same row', $exception->getMessage());
        }

        $this->assertDatabaseHas('releases', ['id' => 3, 'guid' => 'release-a']);
        $this->assertDatabaseHas('releases', ['id' => 4, 'guid' => 'release-b']);
        self::assertSame(0, DB::table('current_forward_release_dispositions')->count());
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(40) NOT NULL,
            categories_id INTEGER NULL,
            nzbstatus INTEGER NULL,
            size INTEGER NULL
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
        DB::statement('CREATE TABLE current_forward_sources (
            id INTEGER PRIMARY KEY,
            state VARCHAR(32) NOT NULL,
            last_reason VARCHAR(120) NULL,
            updated_at DATETIME NULL
        )');
        DB::statement('CREATE TABLE current_forward_windows (
            id INTEGER PRIMARY KEY,
            source_id INTEGER NOT NULL,
            generation INTEGER NULL,
            state VARCHAR(32) NOT NULL,
            chain_root_id INTEGER NULL,
            parent_window_id INTEGER NULL,
            chain_ordinal INTEGER NULL,
            continuation_deadline_at DATETIME NULL,
            failure_reason VARCHAR(120) NULL,
            settled_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        DB::statement('CREATE TABLE current_forward_continuation_observations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            window_id INTEGER NOT NULL,
            chain_root_id INTEGER NOT NULL,
            chain_ordinal INTEGER NOT NULL
        )');
        DB::statement('CREATE TABLE current_forward_window_objects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            window_id INTEGER NOT NULL,
            chain_root_id INTEGER NOT NULL,
            object_type VARCHAR(16) NOT NULL,
            object_id INTEGER NOT NULL,
            parent_object_id INTEGER NULL
        )');
        DB::statement('CREATE TABLE current_forward_object_owners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            object_type VARCHAR(16) NOT NULL,
            object_id INTEGER NOT NULL,
            chain_root_id INTEGER NOT NULL
        )');
        DB::statement('CREATE TABLE current_forward_release_dispositions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            release_id INTEGER NOT NULL UNIQUE,
            chain_root_id INTEGER NOT NULL,
            window_id INTEGER NOT NULL,
            parent_collection_id INTEGER NULL,
            reason VARCHAR(120) NOT NULL,
            categories_id INTEGER NULL,
            nzbstatus INTEGER NULL,
            size INTEGER NULL,
            disposed_at DATETIME NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        DB::statement('CREATE TABLE settings (
            name VARCHAR(255) PRIMARY KEY,
            value TEXT NULL
        )');
    }
}
