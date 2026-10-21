<?php

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Settings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class PurgeRetentionOverridesCommandTest extends TestCase
{
    private string $bootstrapDatabasePath;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->bootstrapDatabasePath = sys_get_temp_dir().'/nntmux-retention-purge-test.sqlite';
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
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            dateadded DATETIME NULL,
            groups_id INTEGER,
            filecheck INTEGER,
            releases_id INTEGER NULL
        )');
        DB::statement('CREATE TABLE binaries (id INTEGER PRIMARY KEY, collections_id INTEGER)');
        DB::statement('CREATE TABLE parts (binaries_id INTEGER, number INTEGER)');

        DB::table('settings')->insert(['name' => 'partretentionhours', 'value' => '96']);
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.teevee'],
            ['id' => 2, 'name' => 'alt.binaries.boneless'],
            ['id' => 3, 'name' => 'alt.binaries.moovee'],
        ]);
        // Settings memoizes across tests; drop it so this file's 96h is read.
        Settings::forgetMemoizedSettings();

        config(['nntmux.collection_retention_hours_overrides' => [
            'alt.binaries.teevee' => 24,
            'alt.binaries.boneless' => 24,
        ]]);
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

    public function test_drains_every_expired_incomplete_across_batches_and_groups(): void
    {
        foreach (range(1, 7) as $id) {
            $this->collection($id, groupId: 1, ageHours: 30);
        }
        $this->collection(20, groupId: 2, ageHours: 48);
        $this->collection(30, groupId: 1, ageHours: 2);
        $this->collection(31, groupId: 1, ageHours: 30, filecheck: CollectionFileCheckStatus::Sized);
        $this->collection(32, groupId: 1, ageHours: 30, releasesId: 9);
        $this->collection(40, groupId: 3, ageHours: 30);

        $this->artisan('nntmux:purge-retention-overrides', ['--batch' => 3, '--pause-ms' => 0])
            ->expectsOutputToContain('Deleted 8 collections')
            ->assertSuccessful();

        $this->assertSame([30, 31, 32, 40], DB::table('collections')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame(0, DB::table('binaries')->where('collections_id', '<=', 20)->count());
        $this->assertSame(0, DB::table('parts')->where('binaries_id', '<=', 2000)->count());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->collection(1, groupId: 1, ageHours: 30);

        $this->artisan('nntmux:purge-retention-overrides', ['--dry-run' => true])
            ->expectsOutputToContain('group 1: first batch holds 1 expired collections')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('collections')->count());
    }

    public function test_exits_cleanly_without_an_applicable_override(): void
    {
        config(['nntmux.collection_retention_hours_overrides' => []]);
        $this->collection(1, groupId: 1, ageHours: 30);

        $this->artisan('nntmux:purge-retention-overrides')
            ->expectsOutputToContain('No retention override')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('collections')->count());
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
