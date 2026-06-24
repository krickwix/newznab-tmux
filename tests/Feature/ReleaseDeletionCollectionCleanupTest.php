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
