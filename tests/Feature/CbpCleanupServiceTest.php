<?php

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\ReleaseDuplicateFinder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\Expectation;
use PDO;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class CbpCleanupServiceTest extends TestCase
{
    private string $bootstrapDatabasePath;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->bootstrapDatabasePath = sys_get_temp_dir().'/nntmux-cbp-cleanup-test.sqlite';
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
        DB::connection()->getPdo()->sqliteCreateFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));
        DB::connection()->getPdo()->sqliteCreateFunction(
            'REGEXP',
            static function (?string $subject, ?string $pattern): int {
                if ($subject === null || $pattern === null || $pattern === '') {
                    return 0;
                }
                set_error_handler(static fn (): true => true);
                $ok = @preg_match($pattern, $subject);
                restore_error_handler();

                return $ok ? 1 : 0;
            },
            2
        );

        $this->createTables();
        $this->seedSettings();
    }

    protected function tearDown(): void
    {
        if (isset($this->bootstrapDatabasePath) && is_file($this->bootstrapDatabasePath)) {
            unlink($this->bootstrapDatabasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_retention_cleanup_deletes_parts_binaries_and_collections_without_fk_cascades(): void
    {
        DB::table('collections')->insert([
            'id' => 100,
            'subject' => 'Retention.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:123',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'retention-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 1000,
            'name' => 'Retention.Release.par2',
            'collections_id' => 100,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 1000,
            'number' => 1,
            'messageid' => '<retention-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_cleanup_retry_classifies_mariadb_record_changed_error_as_transient(): void
    {
        $service = new CollectionCleanupService;
        $method = new ReflectionMethod($service, 'isLockError');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, new QueryException(
            'mariadb',
            'DELETE FROM collections WHERE id IN (?)',
            [123],
            new \RuntimeException("SQLSTATE[HY000]: General error: 123 Got error 123 when reading table './nntmux/collections'")
        )));
    }

    public function test_descendant_cleanup_locks_collections_then_binaries_before_deleting_parts(): void
    {
        DB::table('collections')->insert([
            'id' => 102,
            'subject' => 'Lock.Order.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHour()->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHour()->format('Y-m-d H:i:s'),
            'added' => now()->subHour()->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:102',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'lock-order-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            [
                'id' => 1020,
                'name' => 'Lock.Order.Release.part02',
                'collections_id' => 102,
                'totalparts' => 1,
            ],
            [
                'id' => 1019,
                'name' => 'Lock.Order.Release.part01',
                'collections_id' => 102,
                'totalparts' => 1,
            ],
        ]);
        DB::table('parts')->insert([
            [
                'binaries_id' => 1020,
                'number' => 1,
                'messageid' => '<lock-order-2@example.com>',
                'partnumber' => 1,
                'size' => 10,
            ],
            [
                'binaries_id' => 1019,
                'number' => 1,
                'messageid' => '<lock-order-1@example.com>',
                'partnumber' => 1,
                'size' => 10,
            ],
        ]);

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $deleted = app(CollectionCleanupService::class)
            ->deleteCollectionsAndDescendants([102], 'Lock order test');

        $collectionLockIndex = array_find_key(
            $queries,
            static fn (string $query): bool => str_contains(strtolower($query), 'select "id" from "collections"')
                && str_contains(strtolower($query), 'order by "id" asc'),
        );
        $binaryLockIndex = array_find_key(
            $queries,
            static fn (string $query): bool => str_contains(strtolower($query), 'select "id" from "binaries"')
                && str_contains(strtolower($query), 'order by "id" asc'),
        );
        $partsDeleteIndex = array_find_key(
            $queries,
            static fn (string $query): bool => str_contains(strtolower($query), 'delete from "parts"'),
        );
        $binariesDelete = array_find(
            $queries,
            static fn (string $query): bool => str_contains(strtolower($query), 'delete from "binaries"'),
        );

        $this->assertSame(1, $deleted);
        $this->assertIsInt($collectionLockIndex, implode(PHP_EOL, $queries));
        $this->assertIsInt($binaryLockIndex, implode(PHP_EOL, $queries));
        $this->assertIsInt($partsDeleteIndex, implode(PHP_EOL, $queries));
        $this->assertLessThan($binaryLockIndex, $collectionLockIndex);
        $this->assertLessThan($partsDeleteIndex, $binaryLockIndex);
        $this->assertIsString($binariesDelete, implode(PHP_EOL, $queries));
        $this->assertStringContainsString('"id" in', strtolower($binariesDelete));
        $this->assertStringNotContainsString('"collections_id"', strtolower($binariesDelete));
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());

        $source = (string) file_get_contents(app_path('Services/CollectionCleanupService.php'));
        $this->assertSame(2, substr_count($source, '->lockForUpdate()'));
    }

    public function test_cleanup_retry_logs_a_transient_deadlock_before_recovering(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        /** @var Expectation $warning */
        $warning = $logger->shouldReceive('warning');
        $warning
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Transient collection cleanup lock error; retrying.'
                && $context['label'] === 'Retry visibility test'
                && $context['attempt'] === 1
                && $context['max_attempts'] === 5
                && $context['exception'] === QueryException::class);
        Log::swap($logger);
        $attempts = 0;
        $service = new CollectionCleanupService;
        $method = new ReflectionMethod($service, 'retryOnLockError');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            function () use (&$attempts): int {
                $attempts++;
                if ($attempts === 1) {
                    throw $this->transientDeadlockException();
                }

                return 7;
            },
            'Retry visibility test',
            false,
        );

        $this->assertSame(7, $result);
        $this->assertSame(2, $attempts);
    }

    public function test_cleanup_retry_logs_and_rethrows_after_exhaustion(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        /** @var Expectation $warning */
        $warning = $logger->shouldReceive('warning');
        $warning
            ->times(4)
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Transient collection cleanup lock error; retrying.'
                && $context['label'] === 'Retry exhaustion test'
                && $context['attempt'] >= 1
                && $context['attempt'] <= 4
                && $context['max_attempts'] === 5);
        /** @var Expectation $error */
        $error = $logger->shouldReceive('error');
        $error
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'Collection cleanup exhausted transient lock retries.'
                && $context['label'] === 'Retry exhaustion test'
                && $context['attempt'] === 5
                && $context['max_attempts'] === 5
                && $context['exception'] === QueryException::class);
        Log::swap($logger);
        $attempts = 0;
        $service = new CollectionCleanupService;
        $method = new ReflectionMethod($service, 'retryOnLockError');
        $method->setAccessible(true);

        try {
            $method->invoke(
                $service,
                function () use (&$attempts): int {
                    $attempts++;

                    throw $this->transientDeadlockException();
                },
                'Retry exhaustion test',
                false,
            );
            $this->fail('Expected the exhausted transient deadlock to be rethrown.');
        } catch (QueryException) {
            $this->assertSame(5, $attempts);
        }

    }

    public function test_retention_cleanup_preserves_payload_for_release_waiting_on_nzb(): void
    {
        DB::table('releases')->insert([
            'id' => 30,
            'name' => 'Pending.Nzb.Release',
            'searchname' => 'Pending.Nzb.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'guid' => str_repeat('a', 36),
            'leftguid' => 'a',
            'postdate' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);
        DB::table('collections')->insert([
            'id' => 101,
            'subject' => 'Pending.Nzb.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:101',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'pending-nzb-retention',
            'collection_regexes_id' => 0,
            'releases_id' => 30,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 1010,
            'name' => 'Pending.Nzb.Release.par2',
            'collections_id' => 101,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 1010,
            'number' => 1,
            'messageid' => '<pending-nzb-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 30)->value('nzbstatus'));
    }

    public function test_group_scoped_cleanup_deletes_only_requested_group_orphans(): void
    {
        DB::table('collections')->insert([
            [
                'id' => 110,
                'subject' => 'Scoped.Group.One.Orphan',
                'fromname' => 'poster@example.com',
                'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'xref' => 'alt.test:110',
                'groups_id' => 1,
                'totalfiles' => 1,
                'filesize' => 0,
                'filecheck' => CollectionFileCheckStatus::Default->value,
                'collectionhash' => 'scoped-orphan-group-1',
                'collection_regexes_id' => 0,
                'releases_id' => null,
                'noise' => '',
            ],
            [
                'id' => 120,
                'subject' => 'Scoped.Group.Two.Orphan',
                'fromname' => 'poster@example.com',
                'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'xref' => 'alt.other:120',
                'groups_id' => 2,
                'totalfiles' => 1,
                'filesize' => 0,
                'filecheck' => CollectionFileCheckStatus::Default->value,
                'collectionhash' => 'scoped-orphan-group-2',
                'collection_regexes_id' => 0,
                'releases_id' => null,
                'noise' => '',
            ],
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false, 1);

        $this->assertFalse(DB::table('collections')->where('id', 110)->exists());
        $this->assertTrue(DB::table('collections')->where('id', 120)->exists());
    }

    public function test_release_cleanup_resolves_group_name_scope(): void
    {
        $cleanup = new RecordingScopedCollectionCleanupService;

        $this->makeReleaseProcessingService($cleanup)->deleteCollections('alt.test');

        $this->assertSame(1, $cleanup->calls);
        $this->assertFalse($cleanup->lastEchoCli);
        $this->assertSame(1, $cleanup->lastGroupId);
    }

    public function test_group_scoped_cleanup_deletes_only_requested_group_retention_rows(): void
    {
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.other']);
        DB::table('collections')->insert([
            [
                'id' => 130,
                'subject' => 'Scoped.Group.One.Retention',
                'fromname' => 'poster@example.com',
                'date' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'dateadded' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'added' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'xref' => 'alt.test:130',
                'groups_id' => 1,
                'totalfiles' => 1,
                'filesize' => 500,
                'filecheck' => CollectionFileCheckStatus::Sized->value,
                'collectionhash' => 'scoped-retention-group-1',
                'collection_regexes_id' => 0,
                'releases_id' => null,
                'noise' => '',
            ],
            [
                'id' => 140,
                'subject' => 'Scoped.Group.Two.Retention',
                'fromname' => 'poster@example.com',
                'date' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'dateadded' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'added' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'xref' => 'alt.other:140',
                'groups_id' => 2,
                'totalfiles' => 1,
                'filesize' => 500,
                'filecheck' => CollectionFileCheckStatus::Sized->value,
                'collectionhash' => 'scoped-retention-group-2',
                'collection_regexes_id' => 0,
                'releases_id' => null,
                'noise' => '',
            ],
        ]);
        DB::table('binaries')->insert([
            ['id' => 1300, 'name' => 'Scoped.Group.One.Retention.par2', 'collections_id' => 130, 'totalparts' => 1],
            ['id' => 1400, 'name' => 'Scoped.Group.Two.Retention.par2', 'collections_id' => 140, 'totalparts' => 1],
        ]);
        DB::table('parts')->insert([
            ['binaries_id' => 1300, 'number' => 1, 'messageid' => '<retention-scope-1@example.com>', 'partnumber' => 1, 'size' => 10],
            ['binaries_id' => 1400, 'number' => 1, 'messageid' => '<retention-scope-2@example.com>', 'partnumber' => 1, 'size' => 10],
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false, 1);

        $this->assertFalse(DB::table('collections')->where('id', 130)->exists());
        $this->assertFalse(DB::table('binaries')->where('id', 1300)->exists());
        $this->assertFalse(DB::table('parts')->where('binaries_id', 1300)->exists());
        $this->assertTrue(DB::table('collections')->where('id', 140)->exists());
        $this->assertTrue(DB::table('binaries')->where('id', 1400)->exists());
        $this->assertTrue(DB::table('parts')->where('binaries_id', 1400)->exists());
    }

    public function test_group_scoped_cleanup_deletes_only_requested_group_missed_nzb_rows(): void
    {
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.other']);
        DB::table('releases')->insert([
            [
                'id' => 40,
                'name' => 'Scoped.Group.One.Nzb.Done',
                'searchname' => 'Scoped.Group.One.Nzb.Done',
                'totalpart' => 1,
                'groups_id' => 1,
                'adddate' => now()->format('Y-m-d H:i:s'),
                'guid' => str_repeat('d', 36),
                'leftguid' => 'd',
                'postdate' => now()->format('Y-m-d H:i:s'),
                'fromname' => 'poster@example.com',
                'size' => 500,
                'passwordstatus' => 0,
                'haspreview' => -1,
                'categories_id' => 1,
                'nfostatus' => -1,
                'nzbstatus' => 1,
                'isrenamed' => 1,
                'iscategorized' => 1,
                'predb_id' => 0,
                'source' => null,
            ],
            [
                'id' => 41,
                'name' => 'Scoped.Group.Two.Nzb.Done',
                'searchname' => 'Scoped.Group.Two.Nzb.Done',
                'totalpart' => 1,
                'groups_id' => 2,
                'adddate' => now()->format('Y-m-d H:i:s'),
                'guid' => str_repeat('e', 36),
                'leftguid' => 'e',
                'postdate' => now()->format('Y-m-d H:i:s'),
                'fromname' => 'poster@example.com',
                'size' => 500,
                'passwordstatus' => 0,
                'haspreview' => -1,
                'categories_id' => 1,
                'nfostatus' => -1,
                'nzbstatus' => 1,
                'isrenamed' => 1,
                'iscategorized' => 1,
                'predb_id' => 0,
                'source' => null,
            ],
        ]);
        DB::table('collections')->insert([
            [
                'id' => 150,
                'subject' => 'Scoped.Group.One.Nzb.Done',
                'fromname' => 'poster@example.com',
                'date' => now()->format('Y-m-d H:i:s'),
                'dateadded' => now()->format('Y-m-d H:i:s'),
                'added' => now()->format('Y-m-d H:i:s'),
                'xref' => 'alt.test:150',
                'groups_id' => 1,
                'totalfiles' => 1,
                'filesize' => 500,
                'filecheck' => CollectionFileCheckStatus::Inserted->value,
                'collectionhash' => 'scoped-missed-nzb-group-1',
                'collection_regexes_id' => 0,
                'releases_id' => 40,
                'noise' => '',
            ],
            [
                'id' => 160,
                'subject' => 'Scoped.Group.Two.Nzb.Done',
                'fromname' => 'poster@example.com',
                'date' => now()->format('Y-m-d H:i:s'),
                'dateadded' => now()->format('Y-m-d H:i:s'),
                'added' => now()->format('Y-m-d H:i:s'),
                'xref' => 'alt.other:160',
                'groups_id' => 2,
                'totalfiles' => 1,
                'filesize' => 500,
                'filecheck' => CollectionFileCheckStatus::Inserted->value,
                'collectionhash' => 'scoped-missed-nzb-group-2',
                'collection_regexes_id' => 0,
                'releases_id' => 41,
                'noise' => '',
            ],
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false, 1);

        $this->assertFalse(DB::table('collections')->where('id', 150)->exists());
        $this->assertTrue(DB::table('collections')->where('id', 160)->exists());
    }

    public function test_nzb_creation_cleans_up_collection_binary_and_parts_explicitly(): void
    {
        DB::table('releases')->insert([
            'id' => 1,
            'name' => 'Nzb.Release',
            'searchname' => 'Nzb.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('a', 36),
            'leftguid' => 'a',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        DB::table('collections')->insert([
            'id' => 200,
            'subject' => 'Nzb.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:200',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Inserted->value,
            'collectionhash' => 'nzb-hash',
            'collection_regexes_id' => 0,
            'releases_id' => 1,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 2000,
            'name' => 'Nzb.Release yEnc',
            'collections_id' => 200,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 2000,
            'number' => 1,
            'messageid' => '<nzb-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $written = app(NzbService::class)->writeNzbForReleaseId($release);

        $this->assertTrue($written);
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(1, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_nzb_creation_streams_large_part_sets_with_bounded_memory(): void
    {
        $guid = str_repeat('b', 36);
        DB::table('releases')->insert([
            'id' => 2,
            'name' => 'Large.Nzb.Release',
            'searchname' => 'Large.Nzb.Release',
            'totalpart' => 50000,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => $guid,
            'leftguid' => 'b',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 500000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);
        DB::table('collections')->insert([
            'id' => 210,
            'subject' => 'Large.Nzb.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHour()->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHour()->format('Y-m-d H:i:s'),
            'added' => now()->subHour()->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:210',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500000,
            'filecheck' => CollectionFileCheckStatus::Inserted->value,
            'collectionhash' => 'large-nzb-hash',
            'collection_regexes_id' => 0,
            'releases_id' => 2,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 2100,
            'name' => 'Large.Nzb.Release yEnc',
            'collections_id' => 210,
            'totalparts' => 50000,
        ]);

        for ($offset = 0; $offset < 50000; $offset += 1000) {
            $rows = [];
            for ($number = $offset + 1; $number <= $offset + 1000; $number++) {
                $rows[] = [
                    'binaries_id' => 2100,
                    'number' => $number,
                    'messageid' => '<large-'.$number.'@example.com>',
                    'partnumber' => $number,
                    'size' => 10,
                ];
            }
            DB::table('parts')->insert($rows);
        }

        $release = Release::query()->findOrFail(2);
        $baselineBytes = memory_get_usage(true);
        memory_reset_peak_usage();

        $written = app(NzbService::class)->writeNzbForReleaseId($release);
        $peakGrowthBytes = memory_get_peak_usage(true) - $baselineBytes;

        $this->assertTrue($written);
        $this->assertLessThan(32 * 1024 * 1024, $peakGrowthBytes);
        $nzbPath = app(NzbService::class)->nzbPath($guid);
        $this->assertIsString($nzbPath);
        $xml = gzdecode((string) file_get_contents($nzbPath));
        $this->assertIsString($xml);
        $this->assertStringContainsString('<segment bytes="10" number="50000">large-50000@example.com</segment>', $xml);
    }

    public function test_nzb_creation_uses_collection_group_when_xref_is_empty(): void
    {
        $guid = str_repeat('f', 36);
        DB::table('releases')->insert([
            'id' => 20,
            'name' => 'Empty.Xref.Release',
            'searchname' => 'Empty.Xref.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => $guid,
            'leftguid' => 'f',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);
        DB::table('collections')->insert([
            'id' => 201,
            'subject' => 'Empty.Xref.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => '',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Inserted->value,
            'collectionhash' => 'empty-xref-hash',
            'collection_regexes_id' => 0,
            'releases_id' => 20,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 2010,
            'name' => 'Empty.Xref.Release yEnc',
            'collections_id' => 201,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 2010,
            'number' => 1,
            'messageid' => '<empty-xref-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        $release = Release::query()->findOrFail(20);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $written = app(NzbService::class)->writeNzbForReleaseId($release);

        $this->assertTrue($written);
        $nzbPath = app(NzbService::class)->nzbPath($guid);
        $this->assertIsString($nzbPath);
        $this->assertStringContainsString('<group>alt.test</group>', (string) gzdecode((string) file_get_contents($nzbPath)));
        $this->assertSame(1, (int) DB::table('releases')->where('id', 20)->value('nzbstatus'));
    }

    public function test_release_creation_links_collection_group_when_xref_is_empty(): void
    {
        DB::table('collections')->insert([
            'id' => 202,
            'subject' => 'Source.Group.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => '',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'source-group-link-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);

        $service = new ReleaseCreationService(
            app(ReleaseCleaningService::class),
            app(CollectionCleanupService::class),
            app(ReleaseDuplicateFinder::class)
        );
        $result = $service->createReleases(null, 10, false);

        $releaseId = (int) DB::table('releases')->where('searchname', 'Source.Group.Release')->value('id');
        $this->assertSame(['added' => 1, 'dupes' => 0], $result);
        $this->assertGreaterThan(0, $releaseId);
        $this->assertTrue(DB::table('releases_groups')->where([
            'releases_id' => $releaseId,
            'groups_id' => 1,
        ])->exists());
    }

    public function test_release_creation_skips_unknown_xref_group_without_zero_group_link(): void
    {
        DB::table('collections')->insert([
            'id' => 203,
            'subject' => 'Unknown.Xref.Group.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'a.b.newgroup:203',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'unknown-xref-group-link-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);

        $service = new ReleaseCreationService(
            app(ReleaseCleaningService::class),
            app(CollectionCleanupService::class),
            app(ReleaseDuplicateFinder::class)
        );
        $result = $service->createReleases(null, 10, false);

        $releaseId = (int) DB::table('releases')->where('searchname', 'Unknown.Xref.Group.Release')->value('id');
        $this->assertSame(['added' => 1, 'dupes' => 0], $result);
        $this->assertGreaterThan(0, $releaseId);
        $this->assertFalse(DB::table('usenet_groups')->where('name', 'alt.binaries.newgroup')->exists());
        $this->assertSame(0, DB::table('releases_groups')->where('groups_id', 0)->count());
        $this->assertTrue(DB::table('releases_groups')->where([
            'releases_id' => $releaseId,
            'groups_id' => 1,
        ])->exists());
    }

    public function test_duplicate_release_path_cleans_up_collection_binary_and_parts(): void
    {
        DB::table('releases')->insert([
            'id' => 2,
            'name' => 'Duplicate.Release',
            'searchname' => 'Duplicate.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('b', 36),
            'leftguid' => 'b',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 1000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        DB::table('collections')->insert([
            'id' => 300,
            'subject' => 'Duplicate.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:300',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 1000,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'duplicate-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 3000,
            'name' => 'Duplicate.Release yEnc',
            'collections_id' => 300,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 3000,
            'number' => 1,
            'messageid' => '<duplicate-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        $service = new ReleaseCreationService(
            app(ReleaseCleaningService::class),
            app(CollectionCleanupService::class),
            app(ReleaseDuplicateFinder::class)
        );
        $result = $service->createReleases(null, 10, false);

        $this->assertSame(['added' => 0, 'dupes' => 1], $result);
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_release_duplicate_finder_matches_searchname_within_size_band(): void
    {
        DB::table('releases')->insert([
            'id' => 20,
            'name' => 'raw-obfuscated-a',
            'searchname' => 'Unified.Scene.S01E01.1080p',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('c', 36),
            'leftguid' => 'c',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster-a@example.com',
            'size' => 1_000_000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'raw-obfuscated-b',
            'Unified.Scene.S01E01.1080p',
            0,
            1_020_000
        );

        $this->assertNotNull($dup);
        $this->assertSame('searchname_match', $reason);
    }

    public function test_release_duplicate_finder_matches_predb_id_when_searchname_differs(): void
    {
        DB::table('releases')->insert([
            'id' => 21,
            'name' => 'old',
            'searchname' => 'Old Style Name',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('d', 36),
            'leftguid' => 'd',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'p@example.com',
            'size' => 2_000_000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 9001,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'new',
            'New Style Name',
            9001,
            2_050_000
        );

        $this->assertNotNull($dup);
        $this->assertSame('predb_id_match', $reason);
    }

    public function test_release_duplicate_finder_does_not_match_outside_size_tolerance(): void
    {
        config(['nntmux.release_dedupe_size_tolerance' => 0.05]);

        DB::table('releases')->insert([
            'id' => 22,
            'name' => 'x',
            'searchname' => 'Same.Search',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('e', 36),
            'leftguid' => 'e',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'p@example.com',
            'size' => 1_000_000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup] = $finder->findDuplicate('x', 'Same.Search', 0, 1_200_000);

        $this->assertNull($dup);
    }

    public function test_release_duplicate_finder_falls_back_to_name_when_searchname_empty(): void
    {
        DB::table('releases')->insert([
            'id' => 23,
            'name' => 'fallback.unique',
            'searchname' => '',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('f', 36),
            'leftguid' => 'f',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'p@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate('fallback.unique', '', 0, 500);

        $this->assertNotNull($dup);
        $this->assertSame('name_match_fallback', $reason);
    }

    private function transientDeadlockException(): QueryException
    {
        return new QueryException(
            'mariadb',
            'DELETE FROM parts WHERE binaries_id IN (?)',
            [1020],
            new \RuntimeException(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
                1213,
            ),
        );
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

    private function seedSettings(): void
    {
        $settings = [
            'partretentionhours' => '1',
            'nzbsplitlevel' => '1',
            'check_passworded_rars' => '0',
            'categorizeforeign' => '1',
            'catwebdl' => '1',
        ];

        foreach ($settings as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            description VARCHAR(255) NULL,
            backfill_target INTEGER DEFAULT 1,
            first_record INTEGER DEFAULT 0,
            last_record INTEGER DEFAULT 0,
            active INTEGER DEFAULT 0,
            backfill INTEGER DEFAULT 0,
            minsizetoformrelease VARCHAR(255) NULL,
            minfilestoformrelease VARCHAR(255) NULL
        )');
        DB::statement('CREATE TABLE categories (id INTEGER PRIMARY KEY, title VARCHAR(255), parent_categories_id INTEGER NULL)');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            searchname VARCHAR(255),
            totalpart INTEGER,
            groups_id INTEGER,
            adddate DATETIME NULL,
            guid VARCHAR(64),
            leftguid VARCHAR(1),
            postdate DATETIME NULL,
            fromname VARCHAR(255),
            size INTEGER,
            passwordstatus INTEGER,
            haspreview INTEGER,
            categories_id INTEGER,
            nfostatus INTEGER,
            nzbstatus INTEGER,
            isrenamed INTEGER,
            iscategorized INTEGER,
            predb_id INTEGER,
            source VARCHAR(255) NULL
        )');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            dateadded DATETIME NULL,
            added DATETIME NULL,
            xref TEXT,
            groups_id INTEGER,
            totalfiles INTEGER,
            filesize INTEGER,
            filecheck INTEGER,
            collectionhash VARCHAR(255),
            collection_regexes_id INTEGER,
            releases_id INTEGER NULL,
            noise VARCHAR(64)
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            collections_id INTEGER,
            totalparts INTEGER
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INTEGER,
            number INTEGER,
            messageid VARCHAR(255),
            partnumber INTEGER,
            size INTEGER
        )');
        DB::statement('CREATE TABLE release_naming_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INTEGER DEFAULT 1,
            ordinal INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE release_regexes (
            releases_id INTEGER,
            collection_regex_id INTEGER,
            naming_regex_id INTEGER,
            PRIMARY KEY (releases_id, collection_regex_id, naming_regex_id)
        )');
        DB::statement('CREATE TABLE releases_groups (
            releases_id INTEGER,
            groups_id INTEGER,
            PRIMARY KEY (releases_id, groups_id)
        )');
        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INTEGER DEFAULT 1,
            ordinal INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE predb (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255),
            filename VARCHAR(255)
        )');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        DB::table('categories')->insert(['id' => 1, 'title' => 'Misc', 'parent_categories_id' => null]);
    }

    private function makeReleaseProcessingService(CollectionCleanupService $cleanup): ReleaseProcessingService
    {
        $reflection = new ReflectionClass(ReleaseProcessingService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $echoCli = $reflection->getProperty('echoCLI');
        $echoCli->setValue($service, false);

        $collectionCleanupService = $reflection->getProperty('collectionCleanupService');
        $collectionCleanupService->setValue($service, $cleanup);

        return $service;
    }
}

final class RecordingScopedCollectionCleanupService extends CollectionCleanupService
{
    public int $calls = 0;

    public ?bool $lastEchoCli = null;

    public ?int $lastGroupId = null;

    public function deleteFinishedAndOrphans(bool $echoCLI, ?int $groupId = null): int
    {
        $this->calls++;
        $this->lastEchoCli = $echoCLI;
        $this->lastGroupId = $groupId;

        return 0;
    }
}
