<?php

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Settings;
use App\Services\CollectionCleanupService;
use App\Services\ReleaseProcessingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class CollectionRetentionOverrideTest extends TestCase
{
    private string $bootstrapDatabasePath;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->bootstrapDatabasePath = sys_get_temp_dir().'/nntmux-retention-override-test.sqlite';
        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (is_file($this->bootstrapDatabasePath)) {
            unlink($this->bootstrapDatabasePath);
        }

        $pdo = new PDO('sqlite:'.$this->bootstrapDatabasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('categorizeforeign', '0'), ('catwebdl', '0'), ('innerfileblacklist', '')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->bootstrapDatabasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, nzbstatus INTEGER)');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            dateadded DATETIME NULL,
            groups_id INTEGER,
            filecheck INTEGER,
            releases_id INTEGER NULL
        )');
        DB::statement('CREATE TABLE binaries (id INTEGER PRIMARY KEY, collections_id INTEGER)');
        DB::statement('CREATE TABLE parts (binaries_id INTEGER, number INTEGER)');

        foreach (['partretentionhours' => '96', 'nzbsplitlevel' => '1', 'check_passworded_rars' => '0', 'categorizeforeign' => '1', 'catwebdl' => '1'] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
        // Settings memoizes across tests; drop it so this file's 96h is read
        // here and does not leak into the next test class.
        Settings::forgetMemoizedSettings();
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.teevee'],
            ['id' => 2, 'name' => 'alt.binaries.moovee'],
        ]);

        config(['nntmux.collection_retention_hours_overrides' => ['alt.binaries.teevee' => 24]]);
    }

    protected function tearDown(): void
    {
        if (isset($this->bootstrapDatabasePath) && is_file($this->bootstrapDatabasePath)) {
            unlink($this->bootstrapDatabasePath);
        }
        Settings::forgetMemoizedSettings();

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_release_slice_expires_an_overridden_groups_incompletes_early(): void
    {
        $this->collection(10, groupId: 1, ageHours: 30);
        $this->collection(11, groupId: 1, ageHours: 30, filecheck: CollectionFileCheckStatus::CompleteCollection);
        $this->collection(12, groupId: 1, ageHours: 12);
        $this->collection(13, groupId: 2, ageHours: 30);

        (new ReleaseProcessingService)->deleteCollectionsSlice('', 10);

        $this->assertRemaining([12, 13]);
        $this->assertSame(0, DB::table('binaries')->whereIn('collections_id', [10, 11])->count());
        $this->assertSame(0, DB::table('parts')->whereIn('binaries_id', [1000, 1100])->count());
    }

    public function test_override_keeps_complete_and_released_collections(): void
    {
        DB::table('releases')->insert(['id' => 7, 'nzbstatus' => 0]);
        $this->collection(20, groupId: 1, ageHours: 30, filecheck: CollectionFileCheckStatus::Sized);
        $this->collection(21, groupId: 1, ageHours: 30, releasesId: 7);

        (new ReleaseProcessingService)->deleteCollectionsSlice('', 10);
        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertRemaining([20, 21]);
    }

    public function test_full_cleanup_applies_the_override_alongside_global_retention(): void
    {
        $this->collection(30, groupId: 1, ageHours: 30);
        $this->collection(31, groupId: 2, ageHours: 30);
        $this->collection(32, groupId: 2, ageHours: 100);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertRemaining([31]);
    }

    public function test_group_scoped_slice_leaves_other_groups_overrides_alone(): void
    {
        $this->collection(40, groupId: 1, ageHours: 30);

        (new ReleaseProcessingService)->deleteCollectionsSlice(2, 10);

        $this->assertRemaining([40]);
    }

    public function test_override_not_shorter_than_global_retention_is_ignored(): void
    {
        config(['nntmux.collection_retention_hours_overrides' => ['alt.binaries.teevee' => 96]]);
        $this->collection(50, groupId: 1, ageHours: 30);

        (new ReleaseProcessingService)->deleteCollectionsSlice('', 10);
        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertRemaining([50]);
    }

    private function collection(
        int $id,
        int $groupId,
        int $ageHours,
        CollectionFileCheckStatus $filecheck = CollectionFileCheckStatus::Default,
        ?int $releasesId = null,
    ): void {
        DB::table('collections')->insert([
            'id' => $id,
            'dateadded' => now()->subHours($ageHours)->format('Y-m-d H:i:s'),
            'groups_id' => $groupId,
            'filecheck' => $filecheck->value,
            'releases_id' => $releasesId,
        ]);
        DB::table('binaries')->insert(['id' => $id * 100, 'collections_id' => $id]);
        DB::table('parts')->insert(['binaries_id' => $id * 100, 'number' => 1]);
    }

    /** @param  list<int>  $ids */
    private function assertRemaining(array $ids): void
    {
        $this->assertSame($ids, DB::table('collections')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
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
}
