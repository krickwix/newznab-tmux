<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Manticoresearch\Client;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class NativeHashedFixNameResolvedApplySmokeTest extends TestCase
{
    private const DB_HOST = 'mariadb';

    private const DB_PORT = 3306;

    private const DB_DATABASE = 'nntmux_native_test';

    private const DB_USERNAME = 'nntmux';

    private const DB_PASSWORD = 'nntmux';

    private const MANTICORE_HOST = 'manticore';

    private const MANTICORE_PORT = 9308;

    private const MANTICORE_RELEASE_INDEX = 'releases_rt';

    private ?Client $manticoreClient = null;

    private string $nativeReportPath = '';

    private string $resolvedReportPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NNTMUX_NATIVE_RENAME_APPLY_SMOKE') !== '1') {
            $this->markTestSkipped('Set NNTMUX_NATIVE_RENAME_APPLY_SMOKE=1 to run the live rename-apply smoke.');
        }

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The live rename-apply smoke requires pdo_mysql.');
        }

        $this->assertSmokeTargetsAreDisposable();
        $this->configureMariaDbConnection();
        $this->configureSearchBackend();
        $this->nativeReportPath = $this->nativeReportPath();
        $this->assertNativeFixtureReady();
        $this->assertPhpSupportSchemaReady();
        $this->recreateManticoreIndexes();

        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->resolvedReportPath !== '' && is_file($this->resolvedReportPath)) {
            unlink($this->resolvedReportPath);
        }

        parent::tearDown();
    }

    public function test_it_applies_native_resolved_renames_through_release_update_events_and_search(): void
    {
        $resolvedReport = $this->resolveNativeReport($this->nativeReportPath);
        $resolvedUpdates = $this->resolvedUpdatesByReleaseId($resolvedReport);

        $this->assertSame(2, $resolvedReport['write_contract']['release_updates_resolved']);
        $this->assertSame(0, $resolvedReport['write_contract']['release_updates_blocked']);
        $this->assertSame([100, 300], array_keys($resolvedUpdates));

        $events = [];
        Event::listen(ReleaseNameFixed::class, static function (ReleaseNameFixed $event) use (&$events): void {
            $events[$event->releaseId] = [
                'release_id' => $event->releaseId,
                'old_name' => $event->oldName,
                'new_name' => $event->newName,
                'old_category_id' => $event->oldCategoryId,
                'group_id' => $event->groupId,
                'poster' => $event->poster,
            ];
        });

        $applyResult = $this->applyResolvedReport($this->resolvedReportPath);

        $this->assertSame('native-hashed-fixname-rename-apply', $applyResult['mode']);
        $this->assertSame(2, $applyResult['release_updates_applied']);
        $this->assertSame([100, 300], $applyResult['release_ids']);
        $eventIds = array_keys($events);
        sort($eventIds, SORT_NUMERIC);
        $this->assertSame([100, 300], $eventIds);

        $this->assertReleaseApplied(
            100,
            'Hash.Target.CRC.PreDB',
            'Predb.Match.2026.1080p.BluRay.x264-GRP',
            10,
            'proc_crc32',
            $resolvedUpdates,
            $events,
        );
        $this->assertReleaseApplied(
            300,
            'Hash.Target.Par.Match',
            'Known.Par.Release.2026.2160p.WEB.x265-GRP',
            88,
            'proc_hash16k',
            $resolvedUpdates,
            $events,
        );
    }

    private function assertSmokeTargetsAreDisposable(): void
    {
        $this->assertSame(
            '1',
            getenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB'),
            'Refusing to reset DB/search state without NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1.',
        );
        $this->assertTrue(
            $this->isAllowedNativeTestDatabase(self::DB_DATABASE),
            sprintf('Refusing to reset non-native-test database [%s].', self::DB_DATABASE),
        );
        $this->assertSame('mariadb', self::DB_HOST, 'The smoke must target the Compose MariaDB service.');
        $this->assertSame('manticore', self::MANTICORE_HOST, 'The smoke must target the Compose Manticore service.');
        $this->assertSame(9308, self::MANTICORE_PORT, 'The smoke must target Manticore HTTP port 9308.');
        $this->assertSame(
            'releases_rt',
            self::MANTICORE_RELEASE_INDEX,
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
            'database.connections.mysql.host' => self::DB_HOST,
            'database.connections.mysql.port' => self::DB_PORT,
            'database.connections.mysql.database' => self::DB_DATABASE,
            'database.connections.mysql.username' => self::DB_USERNAME,
            'database.connections.mysql.password' => self::DB_PASSWORD,
        ]);

        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    private function configureSearchBackend(): void
    {
        config([
            'search.default' => 'manticore',
            'search.drivers.manticore.host' => self::MANTICORE_HOST,
            'search.drivers.manticore.port' => self::MANTICORE_PORT,
            'search.drivers.manticore.indexes.releases' => self::MANTICORE_RELEASE_INDEX,
            'manticoresearch.host' => self::MANTICORE_HOST,
            'manticoresearch.port' => self::MANTICORE_PORT,
        ]);

        $this->app->forgetInstance(SearchService::class);
        $this->app->forgetInstance(ManticoreSearchDriver::class);
        Facade::clearResolvedInstance(SearchService::class);
        Facade::clearResolvedInstance(ManticoreSearchDriver::class);
        Search::clearResolvedInstance(SearchService::class);
    }

    private function nativeReportPath(): string
    {
        $input = (string) (getenv('NNTMUX_NATIVE_RENAME_APPLY_SMOKE_INPUT') ?: '');
        $this->assertNotSame('', $input, 'NNTMUX_NATIVE_RENAME_APPLY_SMOKE_INPUT must point to the native JSON report.');

        $path = str_starts_with($input, '/') ? $input : base_path($input);
        $this->assertFileExists($path, "Native JSON report [{$path}] does not exist.");

        return $path;
    }

    private function assertNativeFixtureReady(): void
    {
        foreach (['releases', 'release_files', 'predb', 'predb_crcs', 'par_hashes'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Native fixture table [{$table}] is missing.");
        }

        $this->assertSame('Hash.Target.CRC.PreDB', DB::table('releases')->where('id', 100)->value('searchname'));
        $this->assertSame('Hash.Target.Par.Match', DB::table('releases')->where('id', 300)->value('searchname'));
        $this->assertSame(20, (int) DB::table('releases')->where('id', 100)->value('categories_id'));
        $this->assertSame(20, (int) DB::table('releases')->where('id', 300)->value('categories_id'));
    }

    private function assertPhpSupportSchemaReady(): void
    {
        foreach (['settings', 'usenet_groups', 'movieinfo', 'videos'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "PHP support table [{$table}] is missing.");
        }

        foreach (['totalpart', 'grabs', 'passwordstatus', 'nzbstatus', 'haspreview', 'movieinfo_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('releases', $column), "Release support column [{$column}] is missing.");
        }

        foreach (['categorizeforeign', 'catwebdl', 'innerfileblacklist'] as $setting) {
            $this->assertTrue(
                DB::table('settings')->where('name', $setting)->exists(),
                "Setting [{$setting}] is missing.",
            );
        }

        $this->assertNotSame('', (string) DB::table('usenet_groups')->where('id', 1)->value('name'));
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
    private function resolveNativeReport(string $nativeReportPath): array
    {
        $this->resolvedReportPath = dirname($nativeReportPath).'/hashed-fixnames-resolved-'.bin2hex(random_bytes(6)).'.json';
        $output = new BufferedOutput();

        $exitCode = Artisan::call('nntmux:native-write-contract:resolve', [
            '--input' => $nativeReportPath,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(0, $exitCode, $captured);
        file_put_contents($this->resolvedReportPath, $captured);

        return $this->decodeJson($captured);
    }

    /**
     * @param  array<string, mixed>  $resolvedReport
     * @return array<int, array<string, mixed>>
     */
    private function resolvedUpdatesByReleaseId(array $resolvedReport): array
    {
        $updates = $resolvedReport['write_contract']['resolved_release_updates'] ?? [];
        $this->assertIsArray($updates);

        $byReleaseId = [];
        foreach ($updates as $update) {
            $this->assertIsArray($update);
            $byReleaseId[(int) ($update['release_id'] ?? 0)] = $update;
        }
        ksort($byReleaseId, SORT_NUMERIC);

        return $byReleaseId;
    }

    /**
     * @return array<string, mixed>
     */
    private function applyResolvedReport(string $resolvedReportPath): array
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('nntmux:native-hashed-fixnames:apply-renames', [
            '--input' => $resolvedReportPath,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(0, $exitCode, $captured);

        return $this->decodeJson($captured);
    }

    /**
     * @param  array<int, array<string, mixed>>  $resolvedUpdates
     * @param  array<int, array<string, mixed>>  $events
     */
    private function assertReleaseApplied(
        int $releaseId,
        string $oldName,
        string $newName,
        int $predbId,
        string $statusColumn,
        array $resolvedUpdates,
        array $events,
    ): void {
        $this->assertArrayHasKey($releaseId, $resolvedUpdates);
        $resolvedUpdate = $resolvedUpdates[$releaseId];
        $expectedCategoryId = $this->expectedCategoryId($resolvedUpdate);

        $this->assertSame($predbId, $this->intColumnValue($resolvedUpdate, 'predb_id'));
        $this->assertSame($newName, $this->stringColumnValue($resolvedUpdate, 'searchname'));
        $this->assertSame($expectedCategoryId, (int) ($resolvedUpdate['required_event']['new_category_id'] ?? 0));

        $event = $events[$releaseId] ?? null;
        $this->assertIsArray($event);
        $this->assertSame($oldName, $event['old_name']);
        $this->assertSame($newName, $event['new_name']);
        $this->assertSame(20, $event['old_category_id']);
        $this->assertSame(1, (int) $event['group_id']);
        $this->assertSame('poster@example', $event['poster']);

        $row = DB::table('releases')->where('id', $releaseId)->first();
        $this->assertNotNull($row);
        $this->assertSame($newName, (string) $row->searchname);
        $this->assertSame($expectedCategoryId, (int) $row->categories_id);
        $this->assertSame($predbId, (int) $row->predb_id);
        $this->assertSame(1, (int) $row->isrenamed);
        $this->assertSame(1, (int) $row->iscategorized);
        $this->assertSame(1, (int) $row->{$statusColumn});

        $indexedDocument = $this->indexedReleaseWithSearchName($releaseId, $newName);
        $this->assertSame((string) $expectedCategoryId, (string) $indexedDocument['categories_id']);
        $this->assertSame($newName, $indexedDocument['searchname']);
        $this->assertSame('poster@example', $indexedDocument['fromname']);
        $this->assertSame(1, (int) $indexedDocument['groups_id']);
        $this->assertGreaterThan(0, (int) $indexedDocument['size']);
        $this->assertGreaterThan(0, (int) $indexedDocument['postdate_ts']);
        $this->assertGreaterThan(0, (int) $indexedDocument['adddate_ts']);
        $this->assertSame(0, (int) $indexedDocument['passwordstatus']);
        $this->assertSame(0, (int) $indexedDocument['haspreview']);

        if ($releaseId === 100) {
            $this->assertStringContainsString('Predb.Match.2026.1080p.BluRay.x264-GRP.rar', (string) $indexedDocument['filename']);
        }
    }

    /**
     * @param  array<string, mixed>  $resolvedUpdate
     */
    private function expectedCategoryId(array $resolvedUpdate): int
    {
        $categoryResolution = $resolvedUpdate['category_resolution'] ?? [];
        $this->assertIsArray($categoryResolution);

        return (int) ($categoryResolution['categories_id'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $resolvedUpdate
     */
    private function intColumnValue(array $resolvedUpdate, string $columnName): int
    {
        return (int) $this->columnValue($resolvedUpdate, $columnName);
    }

    /**
     * @param  array<string, mixed>  $resolvedUpdate
     */
    private function stringColumnValue(array $resolvedUpdate, string $columnName): string
    {
        return (string) $this->columnValue($resolvedUpdate, $columnName);
    }

    /**
     * @param  array<string, mixed>  $resolvedUpdate
     */
    private function columnValue(array $resolvedUpdate, string $columnName): mixed
    {
        $columns = $resolvedUpdate['columns'] ?? [];
        $this->assertIsArray($columns);

        foreach ($columns as $column) {
            $this->assertIsArray($column);
            if (($column['column'] ?? null) === $columnName) {
                return $column['value'] ?? null;
            }
        }

        $this->fail("Resolved update is missing column [{$columnName}].");
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

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode(trim($json), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
