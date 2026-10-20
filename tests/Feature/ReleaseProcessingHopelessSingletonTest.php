<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PurgeHopelessCollections;
use App\Enums\CollectionFileCheckStatus;
use App\Services\CollectionsCleaningService;
use App\Services\ReleaseProcessingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Early timeout for single-part collections of per-article obfuscated posts.
 * Runs on SQLite, and also on MariaDB when NNTMUX_TEST_MARIADB_DSN
 * (mysql://user:pass@host:port/db) is set.
 */
final class ReleaseProcessingHopelessSingletonTest extends TestCase
{
    private bool $mariadb = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.release_hopeless_singleton_age_hours' => 6,
            'nntmux.release_hopeless_singleton_min_parts' => 50,
        ]);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction(
            'GREATEST',
            static fn (int|float $left, int|float $right): int|float => max($left, $right),
            2
        );
        $this->createTables();
    }

    protected function tearDown(): void
    {
        if ($this->mariadb) {
            $this->dropTables();
        }
        parent::tearDown();
    }

    public function test_full_pass_deletes_only_hopeless_singletons(): void
    {
        $this->seedScenario();

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $this->assertScenarioOutcome();
    }

    public function test_disabled_by_default(): void
    {
        config(['nntmux.release_hopeless_singleton_age_hours' => 0]);
        $this->seedCollection(1, hoursOld: 30);
        $this->seedBinary(10, 1, totalParts: 6655, parts: 1);

        $this->runStage(0);

        $this->assertTrue(DB::table('collections')->where('id', 1)->exists());
    }

    public function test_group_scope_leaves_other_groups(): void
    {
        $this->seedCollection(1, hoursOld: 7, groupId: 5);
        $this->seedBinary(10, 1, totalParts: 6655, parts: 1);
        $this->seedCollection(2, hoursOld: 7, groupId: 6);
        $this->seedBinary(20, 2, totalParts: 6655, parts: 1);

        $this->runStage(5);

        $this->assertSame([2], DB::table('collections')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    public function test_a_cursor_past_every_collection_wraps(): void
    {
        // Ids restart after a truncate; the stored cursor still points past them.
        Cache::store('array')->forever('nntmux:release-pump:hopeless:5', 7_765_673);
        $this->seedCollection(1, hoursOld: 7);
        $this->seedBinary(10, 1, totalParts: 6655, parts: 1);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        (new ReflectionProperty($service, 'cooperativeSlice'))->setValue($service, true);
        $stage = new ReflectionMethod($service, 'processHopelessSingletonCollections');

        $stage->invoke($service, 5);
        $this->assertSame(0, (int) Cache::store('array')->get('nntmux:release-pump:hopeless:5'));
        $this->assertTrue(DB::table('collections')->where('id', 1)->exists());

        $stage->invoke($service, 5);
        $this->assertFalse(DB::table('collections')->where('id', 1)->exists());
    }

    public function test_purge_command_applies_the_same_test(): void
    {
        $this->seedScenario();

        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120])->assertSuccessful();

        $this->assertScenarioOutcome();
    }

    public function test_purge_command_uses_its_own_shorter_age(): void
    {
        $this->seedScenario();

        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 30])->assertSuccessful();

        // Collection 2 is one hour old: past the lane's 30 minutes, still
        // short of the release stage's 6 hours.
        $this->assertSame(
            [3, 4, 5, 7, 8],
            DB::table('collections')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
    }

    public function test_purge_command_resumes_from_its_cursor_and_wraps_once(): void
    {
        $this->seedScenario();
        $key = PurgeHopelessCollections::CURSOR_KEY.':0';
        Cache::store('array')->forever($key, 5);

        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120, '--window' => 100])
            ->assertSuccessful();

        // Ids 6-8 first, then the wrap covers 1-5.
        $this->assertScenarioOutcome();
        $this->assertSame(0, (int) Cache::store('array')->get($key));
    }

    public function test_purge_sweep_stops_at_the_newest_collection_past_the_age(): void
    {
        $this->seedScenario();
        // Recent hopeless-shaped collections above the ceiling (id 8).
        foreach (range(9, 12) as $id) {
            $this->seedCollection($id, hoursOld: 0);
            $this->seedBinary($id * 10, $id, totalParts: 6655, parts: 1);
        }
        $key = PurgeHopelessCollections::CURSOR_KEY.':0';

        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120, '--window' => 4])
            ->expectsOutputToContain('Scanned 8 collections, deleted 2 hopeless (age >= 120 min); cursor 0 of 8.')
            ->assertSuccessful();

        $this->assertSame([2, 3, 4, 5, 7, 8, 9, 10, 11, 12], $this->collectionIds());
        $this->assertSame(0, (int) Cache::store('array')->get($key));
    }

    public function test_purge_command_wraps_a_cursor_left_above_the_ceiling(): void
    {
        $this->seedScenario();
        $key = PurgeHopelessCollections::CURSOR_KEY.':0';
        Cache::store('array')->forever($key, 50);

        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120, '--window' => 100])
            ->assertSuccessful();

        $this->assertScenarioOutcome();
        $this->assertSame(0, (int) Cache::store('array')->get($key));
    }

    public function test_purge_command_skips_the_sweep_when_nothing_is_old_enough(): void
    {
        $this->seedCollection(1, hoursOld: 0);
        $this->seedBinary(10, 1, totalParts: 6655, parts: 1);
        $key = PurgeHopelessCollections::CURSOR_KEY.':0';
        Cache::store('array')->forever($key, 7);

        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120])
            ->expectsOutputToContain('No collection is older than 120 min yet.')
            ->assertSuccessful();

        $this->assertSame([1], $this->collectionIds());
        $this->assertSame(7, (int) Cache::store('array')->get($key));
    }

    public function test_purge_command_is_off_at_age_zero(): void
    {
        config(['nntmux.hopeless_purge_age_minutes' => 0]);
        $this->seedScenario();

        $this->artisan('nntmux:purge-hopeless-collections')->assertSuccessful();

        $this->assertSame(8, DB::table('collections')->count());
    }

    public function test_purge_command_lowers_the_part_threshold_for_generic_matches_only(): void
    {
        $this->seedScenario();
        $this->seedGenericSmallFiles();

        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120, '--generic-min-parts' => 2])
            ->assertSuccessful();

        // 9 is one part of a 10-part file that only matched the generic
        // regex; 3 is the same shape behind a group regex and stays, as do
        // 10 (two parts arrived) and 11 (a single-part file is complete).
        $this->assertSame(
            [2, 3, 4, 5, 7, 8, 10, 11],
            DB::table('collections')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
        $this->assertFalse(DB::table('parts')->where('binaries_id', 90)->exists());
    }

    public function test_generic_threshold_is_off_by_default_and_never_used_by_the_release_stage(): void
    {
        config(['nntmux.hopeless_purge_generic_min_parts' => 2]);
        $this->seedScenario();
        $this->seedGenericSmallFiles();

        $this->runStage(0);
        $this->assertTrue(DB::table('collections')->where('id', 9)->exists());

        config(['nntmux.hopeless_purge_generic_min_parts' => 0]);
        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120])->assertSuccessful();
        $this->assertTrue(DB::table('collections')->where('id', 9)->exists());
    }

    public function test_shards_partition_the_candidates_without_overlap(): void
    {
        foreach (range(1, 9) as $id) {
            $this->seedCollection($id, hoursOld: 7);
            $this->seedBinary($id * 10, $id, totalParts: 6655, parts: 1);
        }

        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120, '--shard' => '0/3'])
            ->assertSuccessful();
        $this->assertSame([1, 2, 4, 5, 7, 8], $this->collectionIds());

        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120, '--shard' => '1/3'])
            ->assertSuccessful();
        $this->assertSame([2, 5, 8], $this->collectionIds());

        config(['nntmux.hopeless_purge_shard' => '2/3']);
        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120])->assertSuccessful();
        $this->assertSame([], $this->collectionIds());
    }

    public function test_shards_keep_independent_cursors(): void
    {
        foreach (range(1, 6) as $id) {
            $this->seedCollection($id, hoursOld: 7);
            $this->seedBinary($id * 10, $id, totalParts: 6655, parts: 1);
        }
        $store = Cache::store('array');
        $unsharded = PurgeHopelessCollections::CURSOR_KEY.':0';
        $shardZero = $unsharded.':shard-0-of-2';
        $shardOne = $unsharded.':shard-1-of-2';
        $store->forever($unsharded, 99);
        $store->forever($shardOne, 3);

        // Shard 0 starts from its own (empty) cursor, not the unsharded one.
        $this->artisan('nntmux:purge-hopeless-collections', [
            '--age-minutes' => 120,
            '--window' => 100,
            '--shard' => '0/2',
        ])->assertSuccessful();

        $this->assertSame([1, 3, 5], $this->collectionIds());
        $this->assertSame(0, (int) $store->get($shardZero));
        $this->assertSame(3, (int) $store->get($shardOne));
        $this->assertSame(99, (int) $store->get($unsharded));
    }

    public function test_invalid_shards_are_rejected_before_any_delete(): void
    {
        $this->seedScenario();

        foreach (['3/3', '1/0', '0/17', 'one/2', '-1/2', '1/2/3'] as $shard) {
            $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120, '--shard' => $shard])
                ->assertFailed();
        }
        config(['nntmux.hopeless_purge_shard' => '2/2']);
        $this->artisan('nntmux:purge-hopeless-collections', ['--age-minutes' => 120])->assertFailed();

        $this->assertSame(8, DB::table('collections')->count());
    }

    public function test_mariadb_sql(): void
    {
        $dsn = (string) env('NNTMUX_TEST_MARIADB_DSN', '');
        if ($dsn === '') {
            $this->markTestSkipped('NNTMUX_TEST_MARIADB_DSN is not set.');
        }
        $parts = parse_url($dsn);
        config([
            'database.default' => 'mariadb_t',
            'database.connections.mariadb_t' => [
                'driver' => 'mariadb', 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? 3306,
                'database' => ltrim($parts['path'] ?? '/t', '/'), 'username' => $parts['user'] ?? 'root',
                'password' => $parts['pass'] ?? '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '', 'strict' => true,
            ],
        ]);
        DB::purge('mariadb_t');
        $this->mariadb = true;
        $this->dropTables();
        $this->createTables();
        $this->seedScenario();

        $this->runStage(0);

        $this->assertScenarioOutcome();
    }

    private function seedScenario(): void
    {
        // Hopeless: one binary, one part of a 6655-part file, first seen 7h ago.
        $this->seedCollection(1, hoursOld: 7);
        $this->seedBinary(10, 1, totalParts: 6655, parts: 1);
        // Too young.
        $this->seedCollection(2, hoursOld: 1);
        $this->seedBinary(20, 2, totalParts: 6655, parts: 1);
        // Small file: one part may be most of it.
        $this->seedCollection(3, hoursOld: 7);
        $this->seedBinary(30, 3, totalParts: 10, parts: 1);
        // A second part arrived.
        $this->seedCollection(4, hoursOld: 7);
        $this->seedBinary(40, 4, totalParts: 6655, parts: 2);
        // Two binaries, one of them with many parts.
        $this->seedCollection(5, hoursOld: 7, totalFiles: 0);
        $this->seedBinary(50, 5, totalParts: 6655, parts: 1);
        $this->seedBinary(51, 5, totalParts: 60, parts: 5);
        // Hopeless: the part total was also recorded as totalfiles.
        $this->seedCollection(6, hoursOld: 7, totalFiles: 10898);
        $this->seedBinary(60, 6, totalParts: 10898, parts: 1);
        // currentparts lags the parts table: never trust it alone.
        $this->seedCollection(7, hoursOld: 7);
        $this->seedBinary(70, 7, totalParts: 6655, parts: 3, currentParts: 1);
        // Multi-file collection with two one-part binaries: files are joining.
        $this->seedCollection(8, hoursOld: 7, totalFiles: 30);
        $this->seedBinary(80, 8, totalParts: 70, parts: 1);
        $this->seedBinary(81, 8, totalParts: 70, parts: 1);
    }

    private function seedGenericSmallFiles(): void
    {
        $generic = CollectionsCleaningService::REGEX_GENERIC_MATCH;
        $this->seedCollection(9, hoursOld: 7, regexId: $generic);
        $this->seedBinary(90, 9, totalParts: 10, parts: 1);
        $this->seedCollection(10, hoursOld: 7, regexId: $generic);
        $this->seedBinary(100, 10, totalParts: 10, parts: 2);
        $this->seedCollection(11, hoursOld: 7, regexId: $generic);
        $this->seedBinary(110, 11, totalParts: 1, parts: 1);
    }

    private function assertScenarioOutcome(): void
    {
        $this->assertSame(
            [2, 3, 4, 5, 7, 8],
            DB::table('collections')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
        foreach ([[1, 10], [6, 60]] as [$collectionId, $binaryId]) {
            $this->assertFalse(DB::table('binaries')->where('collections_id', $collectionId)->exists());
            $this->assertFalse(DB::table('parts')->where('binaries_id', $binaryId)->exists());
        }
    }

    /** @return list<int> */
    private function collectionIds(): array
    {
        return DB::table('collections')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function runStage(int $groupId): void
    {
        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        (new ReflectionMethod($service, 'processHopelessSingletonCollections'))->invoke($service, $groupId);
    }

    private function seedCollection(int $id, int $hoursOld, int $totalFiles = 1, int $groupId = 5, int $regexId = 0): void
    {
        $at = now()->subHours($hoursOld)->format('Y-m-d H:i:s');
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => '"Rand'.$id.'Subject" yEnc',
            'fromname' => 'Rand <r'.$id.'@example.com>',
            'date' => $at,
            'dateadded' => $at,
            'added' => $at,
            'xref' => 'alt.binaries.hdtv.x264:'.$id,
            'groups_id' => $groupId,
            'totalfiles' => $totalFiles,
            'filesize' => 0,
            'filecheck' => CollectionFileCheckStatus::Default->value,
            'collectionhash' => 'collection-'.$id,
            'collection_regexes_id' => $regexId,
            'releases_id' => null,
            'noise' => '',
        ]);
    }

    private function seedBinary(int $id, int $collectionId, int $totalParts, int $parts, ?int $currentParts = null): void
    {
        DB::table('binaries')->insert([
            'id' => $id,
            'name' => '"Rand'.$collectionId.'Subject" yEnc',
            'collections_id' => $collectionId,
            'currentparts' => $currentParts ?? $parts,
            'totalparts' => $totalParts,
            'partcheck' => 0,
            'filenumber' => $id % 10 + 1,
            'partsize' => 100 * $parts,
        ]);
        for ($p = 1; $p <= $parts; $p++) {
            DB::table('parts')->insert([
                'binaries_id' => $id,
                'messageid' => "m{$id}-{$p}@example",
                'number' => $id * 1000 + $p,
                'partnumber' => $p * 37,
                'size' => 100,
            ]);
        }
    }

    private function dropTables(): void
    {
        foreach (['parts', 'binaries', 'collections', 'settings', 'usenet_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createTables(): void
    {
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->unique();
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('subject')->default('');
            $table->string('fromname')->default('');
            $table->dateTime('date')->nullable();
            $table->dateTime('dateadded')->nullable();
            $table->dateTime('added')->nullable();
            $table->text('xref')->nullable();
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedInteger('totalfiles')->default(0);
            $table->unsignedBigInteger('filesize')->default(0);
            $table->tinyInteger('filecheck')->default(0);
            $table->string('collectionhash')->default('0');
            $table->integer('collection_regexes_id')->default(0);
            $table->integer('releases_id')->nullable();
            $table->string('noise', 64)->default('');
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name', 1000)->default('');
            $table->unsignedInteger('collections_id')->default(0);
            $table->unsignedInteger('currentparts')->default(0);
            $table->unsignedInteger('totalparts')->default(0);
            $table->tinyInteger('partcheck')->default(0);
            $table->unsignedInteger('filenumber')->default(0);
            $table->unsignedBigInteger('partsize')->default(0);
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('binaries_id');
            $table->string('messageid')->default('');
            $table->unsignedBigInteger('number');
            $table->unsignedInteger('partnumber')->default(0);
            $table->unsignedInteger('size')->default(0);
            $table->primary(['binaries_id', 'number']);
        });

        foreach ([
            'maxnzbsprocessed' => '1000',
            'delaytime' => '12',
            'crossposttime' => '2',
            'completionpercent' => '94',
            'collection_timeout' => '96',
            'maxsizetoformrelease' => '0',
            'minsizetoformrelease' => '0',
            'minfilestoformrelease' => '1',
            'releaseretentiondays' => '0',
            'deletepasswordedrelease' => '0',
            'miscotherretentionhours' => '0',
            'mischashedretentionhours' => '0',
            'partretentionhours' => '24',
            'last_run_time' => '',
        ] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }
}
