<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Expectation;
use Mockery\MockInterface;
use PDO;
use Tests\TestCase;

final class ReleaseDeletionCollectionCleanupTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-release-delete-test.sqlite';

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'APP_KEY' => getenv('APP_KEY'),
            'APP_TIMEZONE' => getenv('APP_TIMEZONE'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
            'VIEW_COMPILED_PATH' => getenv('VIEW_COMPILED_PATH'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('innerfileblacklist', ''),
            ('title', 'NNTmux Test'),
            ('home_link', '/')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
        $this->setEnvironmentValue('APP_TIMEZONE', 'UTC');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);
        $this->setEnvironmentValue('VIEW_COMPILED_PATH', __DIR__.'/../../storage/framework/views');

        $app = require __DIR__.'/../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.timezone' => 'UTC',
        ]);
        DB::purge();
        DB::reconnect();

        $this->createTables();
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
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

        /** @var NzbService&MockInterface $nzb */
        $nzb = Mockery::mock(NzbService::class);
        /** @var Expectation $nzbPathExpectation */
        $nzbPathExpectation = $nzb->shouldReceive('nzbPath');
        $nzbPathExpectation->once()->with('release-guid')->andReturn('');

        /** @var ReleaseImageService&MockInterface $releaseImage */
        $releaseImage = Mockery::mock(ReleaseImageService::class);
        /** @var Expectation $imageDeleteExpectation */
        $imageDeleteExpectation = $releaseImage->shouldReceive('delete');
        $imageDeleteExpectation->once()->with('release-guid');

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

    public function test_deleting_group_purges_only_primary_group_release_graph_and_crosspost_links(): void
    {
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.multimedia.erotica.cum-swapping'],
            ['id' => 2, 'name' => 'alt.binaries.other'],
        ]);
        DB::table('releases')->insert([
            ['id' => 1, 'guid' => 'delete-primary-guid', 'groups_id' => 1],
            ['id' => 2, 'guid' => 'keep-primary-guid', 'groups_id' => 2],
        ]);
        DB::table('releases_groups')->insert([
            ['releases_id' => 1, 'groups_id' => 1],
            ['releases_id' => 1, 'groups_id' => 2],
            ['releases_id' => 2, 'groups_id' => 1],
            ['releases_id' => 2, 'groups_id' => 2],
        ]);
        DB::table('collections')->insert([
            ['id' => 10, 'releases_id' => 1, 'groups_id' => 1],
            ['id' => 11, 'releases_id' => 2, 'groups_id' => 2],
        ]);
        DB::table('binaries')->insert([
            ['id' => 20, 'collections_id' => 10],
            ['id' => 21, 'collections_id' => 11],
        ]);
        DB::table('parts')->insert([
            ['id' => 30, 'binaries_id' => 20],
            ['id' => 31, 'binaries_id' => 21],
        ]);
        DB::table('missed_parts')->insert([
            ['id' => 40, 'groups_id' => 1],
            ['id' => 41, 'groups_id' => 2],
        ]);

        Search::shouldReceive('deleteRelease')->once()->with(1);

        /** @var NzbService&MockInterface $nzb */
        $nzb = Mockery::mock(NzbService::class);
        /** @var Expectation $nzbPathExpectation */
        $nzbPathExpectation = $nzb->shouldReceive('nzbPath');
        $nzbPathExpectation->once()->with('delete-primary-guid')->andReturn('');
        $this->app->instance(NzbService::class, $nzb);

        $this->assertTrue(UsenetGroup::deleteGroup(1));

        $this->assertSame(0, DB::table('usenet_groups')->where('id', 1)->count());
        $this->assertSame(1, DB::table('usenet_groups')->where('id', 2)->count());
        $this->assertSame(0, DB::table('releases')->where('id', 1)->count());
        $this->assertSame(1, DB::table('releases')->where('id', 2)->count());

        $this->assertSame(0, DB::table('collections')->where('id', 10)->count());
        $this->assertSame(1, DB::table('collections')->where('id', 11)->count());
        $this->assertSame(0, DB::table('binaries')->where('id', 20)->count());
        $this->assertSame(1, DB::table('binaries')->where('id', 21)->count());
        $this->assertSame(0, DB::table('parts')->where('id', 30)->count());
        $this->assertSame(1, DB::table('parts')->where('id', 31)->count());

        $this->assertSame(0, DB::table('missed_parts')->where('groups_id', 1)->count());
        $this->assertSame(1, DB::table('missed_parts')->where('groups_id', 2)->count());
        $this->assertSame(0, DB::table('releases_groups')->where('groups_id', 1)->count());
        $this->assertTrue(DB::table('releases_groups')->where([
            'releases_id' => 2,
            'groups_id' => 2,
        ])->exists());
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            backfill_target INTEGER DEFAULT 1,
            first_record INTEGER DEFAULT 0,
            first_record_postdate DATETIME NULL,
            last_record INTEGER DEFAULT 0,
            last_record_postdate DATETIME NULL,
            last_updated DATETIME NULL,
            active INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(40) NOT NULL,
            categories_id INTEGER NULL,
            nzbstatus INTEGER NULL,
            size INTEGER NULL,
            groups_id INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            releases_id INTEGER NULL,
            groups_id INTEGER DEFAULT 0
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
        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
            groups_id INTEGER
        )');
        DB::statement('CREATE TABLE releases_groups (
            releases_id INTEGER,
            groups_id INTEGER,
            PRIMARY KEY (releases_id, groups_id)
        )');
    }
}
