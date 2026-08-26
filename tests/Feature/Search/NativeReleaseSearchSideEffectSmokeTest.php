<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Facades\Search;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use App\Support\ReleaseSearchIndexSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Manticoresearch\Client;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class NativeReleaseSearchSideEffectSmokeTest extends TestCase
{
    private ?Client $manticoreClient = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NNTMUX_NATIVE_MANTICORE_SMOKE') !== '1') {
            $this->markTestSkipped('Set NNTMUX_NATIVE_MANTICORE_SMOKE=1 to run the live Manticore smoke.');
        }

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The live Manticore smoke requires pdo_mysql.');
        }

        $this->assertSmokeTargetsAreDisposable();
        $this->configureMariaDbConnection();
        $this->configureSearchBackend();
        $this->rebuildDatabaseSchema();
        $this->recreateManticoreIndexes();
    }

    public function test_release_search_index_sync_updates_manticore_after_database_side_release_mutation(): void
    {
        $releaseId = 91_001;

        DB::table('movieinfo')->insert([
            'id' => 5001,
            'tmdbid' => 7001,
            'traktid' => 8001,
        ]);
        DB::table('videos')->insert([
            'id' => 6001,
            'tvdb' => 9001,
            'tvmaze' => 9002,
            'tvrage' => 9003,
        ]);
        DB::table('releases')->insert([
            'id' => $releaseId,
            'name' => 'Old.Hash.Name',
            'searchname' => 'Old.Hash.Name',
            'fromname' => 'old@example.invalid',
            'categories_id' => 20,
            'size' => 12_345_678,
            'postdate' => '2026-06-15 09:00:00',
            'adddate' => '2026-06-15 10:00:00',
            'totalpart' => 42,
            'grabs' => 3,
            'passwordstatus' => 0,
            'groups_id' => 101,
            'nzbstatus' => 1,
            'haspreview' => 0,
            'imdbid' => 'tt7654321',
            'videos_id' => 6001,
            'movieinfo_id' => 5001,
        ]);
        DB::table('release_files')->insert([
            ['releases_id' => $releaseId, 'name' => 'old.sample.mkv'],
            ['releases_id' => $releaseId, 'name' => 'old.sample.nfo'],
        ]);

        ReleaseSearchIndexSync::forIds([$releaseId]);

        $initialDocument = $this->indexedReleaseWithSearchName($releaseId, 'Old.Hash.Name');
        $this->assertSame('20', (string) $initialDocument['categories_id']);
        $this->assertSame('old@example.invalid', $initialDocument['fromname']);

        DB::table('releases')
            ->where('id', $releaseId)
            ->update([
                'name' => 'Resolved.Release.2026.1080p-GRP',
                'searchname' => 'Resolved.Release.2026.1080p-GRP',
                'fromname' => 'native-worker@example.invalid',
                'categories_id' => 5040,
            ]);
        DB::table('release_files')
            ->where('releases_id', $releaseId)
            ->delete();
        DB::table('release_files')->insert([
            ['releases_id' => $releaseId, 'name' => 'resolved.sample.mkv'],
        ]);

        $syncResult = $this->syncNativeCommitReport($releaseId);
        $this->assertSame([$releaseId], $syncResult['release_ids']);
        $this->assertSame(1, $syncResult['search_updates_synced']);

        $updatedDocument = $this->indexedReleaseWithSearchName($releaseId, 'Resolved.Release.2026.1080p-GRP');
        $this->assertSame('5040', (string) $updatedDocument['categories_id']);
        $this->assertSame('native-worker@example.invalid', $updatedDocument['fromname']);
        $this->assertStringContainsString('resolved.sample.mkv', (string) $updatedDocument['filename']);
        $this->assertStringNotContainsString('old.sample.mkv', (string) $updatedDocument['filename']);
        $this->assertStringNotContainsString('old.sample.nfo', (string) $updatedDocument['filename']);
        $this->assertSame('tt7654321', $updatedDocument['imdbid']);
    }

    public function test_pending_native_search_outbox_updates_manticore_after_database_side_release_mutation(): void
    {
        $releaseId = 91_002;

        DB::table('movieinfo')->insert([
            'id' => 5002,
            'tmdbid' => 7002,
            'traktid' => 8002,
        ]);
        DB::table('videos')->insert([
            'id' => 6002,
            'tvdb' => 9011,
            'tvmaze' => 9012,
            'tvrage' => 9013,
        ]);
        DB::table('releases')->insert([
            'id' => $releaseId,
            'name' => 'Outbox.Old.Hash.Name',
            'searchname' => 'Outbox.Old.Hash.Name',
            'fromname' => 'old-outbox@example.invalid',
            'categories_id' => 20,
            'size' => 22_345_678,
            'postdate' => '2026-06-15 09:00:00',
            'adddate' => '2026-06-15 10:00:00',
            'totalpart' => 42,
            'grabs' => 3,
            'passwordstatus' => 0,
            'groups_id' => 101,
            'nzbstatus' => 1,
            'haspreview' => 0,
            'imdbid' => 'tt7654322',
            'videos_id' => 6002,
            'movieinfo_id' => 5002,
        ]);
        DB::table('release_files')->insert([
            ['releases_id' => $releaseId, 'name' => 'outbox.old.sample.mkv'],
        ]);

        ReleaseSearchIndexSync::forIds([$releaseId]);

        $initialDocument = $this->indexedReleaseWithSearchName($releaseId, 'Outbox.Old.Hash.Name');
        $this->assertSame('20', (string) $initialDocument['categories_id']);
        $this->assertSame('old-outbox@example.invalid', $initialDocument['fromname']);

        DB::table('releases')
            ->where('id', $releaseId)
            ->update([
                'name' => 'Outbox.Resolved.Release.2026.1080p-GRP',
                'searchname' => 'Outbox.Resolved.Release.2026.1080p-GRP',
                'fromname' => 'native-outbox@example.invalid',
                'categories_id' => 5040,
            ]);
        DB::table('release_files')
            ->where('releases_id', $releaseId)
            ->delete();
        DB::table('release_files')->insert([
            ['releases_id' => $releaseId, 'name' => 'outbox.resolved.sample.mkv'],
        ]);

        $this->createNativeWorkerSideEffectsTable();
        $this->insertNativeSearchSideEffect($releaseId, 'proc_crc32', 'crc-miss');

        $syncResult = $this->syncPendingNativeSearchOutbox();
        $this->assertSame([$releaseId], $syncResult['release_ids']);
        $this->assertSame(1, $syncResult['search_updates_synced']);

        $outboxRow = DB::table('native_worker_side_effects')->where('release_id', $releaseId)->first();
        $this->assertSame('synced', $outboxRow->status);
        $this->assertSame(1, (int) $outboxRow->attempts);
        $this->assertNull($outboxRow->available_at);
        $this->assertNull($outboxRow->last_error_code);
        $this->assertNotNull($outboxRow->processed_at);

        $updatedDocument = $this->indexedReleaseWithSearchName($releaseId, 'Outbox.Resolved.Release.2026.1080p-GRP');
        $this->assertSame('5040', (string) $updatedDocument['categories_id']);
        $this->assertSame('native-outbox@example.invalid', $updatedDocument['fromname']);
        $this->assertStringContainsString('outbox.resolved.sample.mkv', (string) $updatedDocument['filename']);
        $this->assertStringNotContainsString('outbox.old.sample.mkv', (string) $updatedDocument['filename']);
        $this->assertSame('tt7654322', $updatedDocument['imdbid']);
    }

    private function assertSmokeTargetsAreDisposable(): void
    {
        $dbName = (string) env('DB_DATABASE', '');
        $manticoreHost = (string) env('MANTICORESEARCH_HOST', '');
        $manticorePort = (int) env('MANTICORESEARCH_PORT', 0);
        $manticoreReleaseIndex = (string) env('MANTICORESEARCH_INDEX_RELEASES', 'releases_rt');

        $this->assertSame(
            '1',
            getenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB'),
            'Refusing to reset DB/search state without NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1.',
        );
        $this->assertTrue(
            $this->isAllowedNativeTestDatabase($dbName),
            sprintf('Refusing to reset non-native-test database [%s].', $dbName),
        );
        $this->assertSame('mariadb', (string) env('DB_HOST', ''), 'The smoke must target the Compose MariaDB service.');
        $this->assertSame('manticore', $manticoreHost, 'The smoke must target the Compose Manticore service.');
        $this->assertSame(9308, $manticorePort, 'The smoke must target Manticore HTTP port 9308.');
        $this->assertSame(
            'releases_rt',
            $manticoreReleaseIndex,
            'manticore:create-indexes creates the default releases_rt index for this smoke.',
        );
    }

    private function isAllowedNativeTestDatabase(string $database): bool
    {
        return $database === 'nntmux_native_test'
            || str_starts_with($database, 'nntmux_native_test_')
            || str_ends_with($database, '_native_test');
    }

    private function configureMariaDbConnection(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => env('DB_HOST', 'mariadb'),
            'database.connections.mysql.port' => (int) env('DB_PORT', 3306),
            'database.connections.mysql.database' => env('DB_DATABASE', 'nntmux_native_test'),
            'database.connections.mysql.username' => env('DB_USERNAME', 'nntmux'),
            'database.connections.mysql.password' => env('DB_PASSWORD', 'nntmux'),
        ]);

        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    private function configureSearchBackend(): void
    {
        $host = env('MANTICORESEARCH_HOST', 'manticore');
        $port = (int) env('MANTICORESEARCH_PORT', 9308);

        config([
            'search.default' => 'manticore',
            'search.drivers.manticore.host' => $host,
            'search.drivers.manticore.port' => $port,
            'search.drivers.manticore.indexes.releases' => env('MANTICORESEARCH_INDEX_RELEASES', 'releases_rt'),
            'manticoresearch.host' => $host,
            'manticoresearch.port' => $port,
        ]);

        $this->app->forgetInstance(SearchService::class);
        $this->app->forgetInstance(ManticoreSearchDriver::class);
        Facade::clearResolvedInstance(SearchService::class);
        Facade::clearResolvedInstance(ManticoreSearchDriver::class);
        Search::clearResolvedInstance(SearchService::class);
    }

    private function rebuildDatabaseSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (['native_worker_side_effects', 'release_files', 'releases', 'movieinfo', 'videos', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });

        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'innerfileblacklist', 'value' => ''],
        ]);

        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedInteger('tmdbid')->default(0);
            $table->unsignedInteger('traktid')->default(0);
        });

        Schema::create('videos', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedInteger('tvdb')->default(0);
            $table->unsignedInteger('tvmaze')->default(0);
            $table->unsignedInteger('tvrage')->default(0);
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('searchname');
            $table->string('fromname')->nullable();
            $table->unsignedInteger('categories_id')->default(0);
            $table->unsignedBigInteger('size')->default(0);
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
            $table->integer('totalpart')->default(0);
            $table->integer('grabs')->default(0);
            $table->integer('passwordstatus')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->integer('nzbstatus')->default(0);
            $table->integer('haspreview')->default(0);
            $table->string('imdbid', 32)->default('');
            $table->unsignedBigInteger('videos_id')->default(0);
            $table->unsignedBigInteger('movieinfo_id')->default(0);
        });

        Schema::create('release_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id');
            $table->string('name');
            $table->index('releases_id');
        });
    }

    private function createNativeWorkerSideEffectsTable(): void
    {
        Schema::create('native_worker_side_effects', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key')->unique();
            $table->string('job', 64);
            $table->string('effect', 64);
            $table->unsignedBigInteger('release_id');
            $table->string('status_column', 32);
            $table->string('status_reason', 64);
            $table->unsignedTinyInteger('status_value');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
        });
    }

    private function insertNativeSearchSideEffect(int $releaseId, string $statusColumn, string $statusReason): void
    {
        DB::table('native_worker_side_effects')->insert([
            'operation_key' => "hashed-fixnames:miss-status:v1:{$releaseId}:{$statusColumn}:1:{$statusReason}",
            'job' => 'hashed-fixnames',
            'effect' => 'release-search-sync',
            'release_id' => $releaseId,
            'status_column' => $statusColumn,
            'status_reason' => $statusReason,
            'status_value' => 1,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recreateManticoreIndexes(): void
    {
        $exitCode = Artisan::call('manticore:create-indexes', [
            '--drop' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    /**
     * @return array<string, mixed>
     */
    private function syncNativeCommitReport(int $releaseId): array
    {
        $reportPath = tempnam(sys_get_temp_dir(), 'native-search-sync-');
        if ($reportPath === false) {
            $this->fail('Failed to create native search sync temp report.');
        }

        try {
            file_put_contents(
                $reportPath,
                json_encode($this->nativeCommitReport($releaseId), JSON_THROW_ON_ERROR),
            );

            $output = new BufferedOutput();
            $exitCode = Artisan::call('nntmux:native-search-side-effects:sync', [
                '--input' => $reportPath,
            ], $output);
            $captured = $output->fetch();

            $this->assertSame(0, $exitCode, $captured);

            return json_decode(trim($captured), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            if (is_file($reportPath)) {
                unlink($reportPath);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function syncPendingNativeSearchOutbox(): array
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('nntmux:native-search-side-effects:sync', [
            '--pending-outbox' => true,
            '--limit' => 10,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(0, $exitCode, $captured);

        $this->assertStringNotContainsString('nntmux_database', $captured);
        $this->assertStringNotContainsString('Outbox.Old.Hash.Name', $captured);
        $this->assertStringNotContainsString('Outbox.Resolved.Release', $captured);
        $this->assertStringNotContainsString('native-outbox@example.invalid', $captured);

        return json_decode(trim($captured), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function nativeCommitReport(int $releaseId): array
    {
        return [
            'schema_version' => 1,
            'mode' => 'shadow',
            'dry_run' => false,
            'native_worker' => [
                'job' => 'hashed-fixnames',
                'writes' => 1,
            ],
            'hashed_fixnames' => [
                'write_commit' => [
                    'single_column_updates_attempted' => 1,
                    'single_column_updates_committed' => 1,
                    'single_column_updates_skipped' => 0,
                    'single_column_updates_blocked' => 0,
                    'single_column_rows_affected' => 1,
                    'release_updates_blocked' => 0,
                    'blocked_release_ids' => [],
                    'blocked_status_release_ids' => [],
                    'committed_release_ids' => [$releaseId],
                    'skipped_release_ids' => [],
                    'lock_acquired' => true,
                    'writes_committed' => 1,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function indexedReleaseWithSearchName(int $releaseId, string $searchName): array
    {
        $deadline = microtime(true) + 5.0;
        $lastDocument = null;

        do {
            $hit = $this->manticoreClient()
                ->table($this->releaseIndexName())
                ->getDocumentById($releaseId);

            if ($hit !== null) {
                $lastDocument = $hit->getData();
                if (($lastDocument['searchname'] ?? null) === $searchName) {
                    return $lastDocument;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $this->fail(sprintf(
            'Release %d was not indexed with searchname [%s]. Last document: %s',
            $releaseId,
            $searchName,
            json_encode($lastDocument, JSON_THROW_ON_ERROR),
        ));
    }

    private function manticoreClient(): Client
    {
        if ($this->manticoreClient === null) {
            $this->manticoreClient = new Client([
                'host' => config('manticoresearch.host'),
                'port' => config('manticoresearch.port'),
            ]);
        }

        return $this->manticoreClient;
    }

    private function releaseIndexName(): string
    {
        return (string) config('search.drivers.manticore.indexes.releases', 'releases_rt');
    }
}
