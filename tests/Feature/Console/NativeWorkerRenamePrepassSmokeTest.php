<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Distributed\NativeHashedFixNameRenamePrepassRunner;
use App\Services\Distributed\NativeWorkerCommitRunner;
use App\Services\Distributed\NativeWorkerLaneRunner;
use App\Services\Distributed\NativeWorkerPlanExporter;
use App\Services\Distributed\NativeWorkerShadowRunner;
use App\Services\NameFixing\NativeSearchSideEffectOutboxSync;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Manticoresearch\Client;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class NativeWorkerRenamePrepassSmokeTest extends TestCase
{
    private const LOCK_NAME = 'nntmux:distributed-worker:hashed-fixnames';
    private const MYSQL_DSN = 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true';
    private const MANTICORE_RELEASE_INDEX = 'releases_rt';

    private ?Client $manticoreClient = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NNTMUX_NATIVE_RENAME_PREPASS_SMOKE') !== '1') {
            $this->markTestSkipped('Set NNTMUX_NATIVE_RENAME_PREPASS_SMOKE=1 to run the native rename prepass smoke.');
        }

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The native rename prepass smoke requires pdo_mysql.');
        }

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The native rename prepass smoke requires ext-redis.');
        }

        $this->assertSmokeTargetsAreDisposable();
        $this->configureMariaDbConnection();
        $this->configureRedisLockStore();
        $this->configureSearchBackend();
        $this->configureNativeRenamePrepass();
        $this->forceReleaseWorkerLock();
        $this->assertNativeFixtureReady();
        $this->assertPhpSupportSchemaReady();
        $this->recreateManticoreIndexes();
    }

    protected function tearDown(): void
    {
        try {
            if (getenv('NNTMUX_NATIVE_RENAME_PREPASS_SMOKE') === '1' && extension_loaded('redis')) {
                $this->forceReleaseWorkerLock();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_php_worker_applies_native_renames_under_lock_and_continues_php_lane(): void
    {
        $this->assertSame('Hash.Target.CRC.PreDB', DB::table('releases')->where('id', 100)->value('searchname'));
        $this->assertSame('Hash.Target.Par.Match', DB::table('releases')->where('id', 300)->value('searchname'));

        $events = [];
        Event::listen(ReleaseNameFixed::class, static function (ReleaseNameFixed $event) use (&$events): void {
            $events[$event->releaseId] = [
                'old_name' => $event->oldName,
                'new_name' => $event->newName,
                'old_category_id' => $event->oldCategoryId,
                'group_id' => $event->groupId,
                'poster' => $event->poster,
            ];
        });

        Artisan::shouldReceive('call')
            ->twice()
            ->with(
                'releases:fix-names',
                Mockery::on(static fn (array $arguments): bool => in_array($arguments['method'] ?? null, ['16', '20'], true)
                    && ($arguments['--update'] ?? null) === true
                    && ($arguments['--category'] ?? null) === 'hashed'
                    && ($arguments['--set-status'] ?? null) === true),
                Mockery::type(BufferedOutput::class),
            )
            ->andReturnUsing(function (): int {
                /** @phpstan-ignore-next-line method.notFound */
                $competingLock = Cache::store('redis')->lock(self::LOCK_NAME, 5);
                $this->assertFalse($competingLock->get(), 'The Laravel-held Redis worker lock should remain held during PHP continuation.');

                return 0;
            });

        $output = new BufferedOutput;
        $exitCode = $this->worker()->run('hashed-fixnames', once: true, sleepOverride: null, lockSeconds: 42, output: $output);

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native rename prepass applied hashed-fixnames: seen=2 applied=2 release_ids=100,300', $workerOutput);
        $this->assertLessThan(
            strpos($workerOutput, 'php artisan releases:fix-names'),
            strpos($workerOutput, 'native rename prepass applied hashed-fixnames'),
        );
        foreach ([self::MYSQL_DSN, '--mysql-dsn', 'nntmux_database_'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workerOutput);
        }

        $this->assertReleaseApplied(
            100,
            'Hash.Target.CRC.PreDB',
            'Predb.Match.2026.1080p.BluRay.x264-GRP',
            10,
            'proc_crc32',
            $events,
        );
        $this->assertReleaseApplied(
            300,
            'Hash.Target.Par.Match',
            'Known.Par.Release.2026.2160p.WEB.x265-GRP',
            88,
            'proc_hash16k',
            $events,
        );
        $this->assertWorkerLockReleased();
    }

    private function assertSmokeTargetsAreDisposable(): void
    {
        $this->assertSame('1', getenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB'), 'Refusing to use MariaDB without the destructive native-test guard.');
        $this->assertSame('1', getenv('NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED'), 'Refusing rename prepass without the PHP rename-apply guard.');
    }

    private function configureMariaDbConnection(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => 'mariadb',
            'database.connections.mysql.port' => 3306,
            'database.connections.mysql.database' => 'nntmux_native_test',
            'database.connections.mysql.username' => 'nntmux',
            'database.connections.mysql.password' => 'nntmux',
        ]);

        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    private function configureRedisLockStore(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.prefix' => 'nntmux-cache-',
            'cache.stores.redis.connection' => 'cache',
            'cache.stores.redis.lock_connection' => 'default',
            'database.redis.client' => 'phpredis',
            'database.redis.options.prefix' => 'nntmux_database_',
            'database.redis.default.host' => 'redis',
            'database.redis.default.username' => null,
            'database.redis.default.password' => null,
            'database.redis.default.port' => 6379,
            'database.redis.default.database' => 0,
            'database.redis.cache.host' => 'redis',
            'database.redis.cache.username' => null,
            'database.redis.cache.password' => null,
            'database.redis.cache.port' => 6379,
            'database.redis.cache.database' => 1,
            'nntmux.distributed_lock_store' => 'redis',
        ]);

        $this->app['cache']->forgetDriver('redis');
    }

    private function configureSearchBackend(): void
    {
        config([
            'search.default' => 'manticore',
            'search.drivers.manticore.host' => 'manticore',
            'search.drivers.manticore.port' => 9308,
            'search.drivers.manticore.indexes.releases' => self::MANTICORE_RELEASE_INDEX,
            'manticoresearch.host' => 'manticore',
            'manticoresearch.port' => 9308,
        ]);

        $this->app->forgetInstance(SearchService::class);
        $this->app->forgetInstance(ManticoreSearchDriver::class);
        Facade::clearResolvedInstance(SearchService::class);
        Facade::clearResolvedInstance(ManticoreSearchDriver::class);
        Search::clearResolvedInstance(SearchService::class);
    }

    private function configureNativeRenamePrepass(): void
    {
        $binary = (string) getenv('NNTMUX_NATIVE_WORKER_BINARY');
        $this->assertNotSame('', $binary, 'NNTMUX_NATIVE_WORKER_BINARY must point at the bind-mounted native binary.');
        $this->assertTrue(is_executable($binary), sprintf('Native worker binary [%s] must be executable.', $binary));

        config([
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => true,
            'nntmux.native_worker_rename_apply_test_enabled' => true,
            'nntmux.native_worker_binary' => $binary,
            'nntmux.native_worker_mysql_dsn' => self::MYSQL_DSN,
            'nntmux.native_worker_rename_prepass_timeout_seconds' => 10,
        ]);
    }

    private function worker(): DistributedJobWorker
    {
        /** @var DistributedJobCatalog&MockInterface $catalog */
        $catalog = Mockery::mock(DistributedJobCatalog::class);
        /** @phpstan-ignore-next-line method.notFound */
        $catalog->shouldReceive('resolve')->with('hashed-fixnames', ['run' => 'var'])->andReturn($this->hashedFixnamesPlan());

        /** @var TmuxMonitorService&MockInterface $monitor */
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->andReturn([]);
        $monitor->shouldReceive('collectStatistics')->andReturn(['run' => 'var']);

        /** @var NativeWorkerShadowRunner&MockInterface $shadowRunner */
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        /** @var NativeWorkerCommitRunner&MockInterface $commitRunner */
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');

        /** @var NativeSearchSideEffectOutboxSync&MockInterface $outboxSync */
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        return new DistributedJobWorker(
            $catalog,
            $monitor,
            new NativeWorkerPlanExporter,
            $shadowRunner,
            $commitRunner,
            $outboxSync,
            app(NativeHashedFixNameRenamePrepassRunner::class),
            Mockery::mock(NativeWorkerLaneRunner::class),
        );
    }

    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     enabled: bool,
     *     disabled_reason: string|null,
     *     commands: list<array{command: string, arguments: array<string, mixed>}>,
     *     sleep: int
     * }
     */
    private function hashedFixnamesPlan(): array
    {
        return [
            'name' => 'hashed-fixnames',
            'description' => 'Run full-history name fixing passes for Other > Hashed backlogs',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '20',
                        '--update' => true,
                        '--category' => 'hashed',
                        '--set-status' => true,
                        '--show' => true,
                    ],
                ],
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '16',
                        '--update' => true,
                        '--category' => 'hashed',
                        '--set-status' => true,
                        '--show' => true,
                    ],
                ],
            ],
            'sleep' => 300,
        ];
    }

    private function assertNativeFixtureReady(): void
    {
        foreach (['releases', 'release_files', 'predb', 'predb_crcs', 'par_hashes'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Native fixture table [{$table}] is missing.");
        }
    }

    private function assertPhpSupportSchemaReady(): void
    {
        foreach (['settings', 'usenet_groups', 'movieinfo', 'videos'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "PHP support table [{$table}] is missing.");
        }
    }

    private function recreateManticoreIndexes(): void
    {
        $exitCode = Artisan::call('manticore:create-indexes', [
            '--drop' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function assertReleaseApplied(
        int $releaseId,
        string $oldName,
        string $newName,
        int $predbId,
        string $statusColumn,
        array $events,
    ): void {
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
        $this->assertSame($predbId, (int) $row->predb_id);
        $this->assertSame(1, (int) $row->isrenamed);
        $this->assertSame(1, (int) $row->iscategorized);
        $this->assertSame(1, (int) $row->{$statusColumn});

        $indexedDocument = $this->indexedReleaseWithSearchName($releaseId, $newName);
        $this->assertSame((string) $row->categories_id, (string) $indexedDocument['categories_id']);
        $this->assertSame($newName, $indexedDocument['searchname']);
        $this->assertSame('poster@example', $indexedDocument['fromname']);
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
                ->table(self::MANTICORE_RELEASE_INDEX)
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

    private function assertWorkerLockReleased(): void
    {
        /** @phpstan-ignore-next-line method.notFound */
        $lock = Cache::store('redis')->lock(self::LOCK_NAME, 5);
        $this->assertTrue($lock->get(), 'The Laravel-held Redis worker lock should be released after the worker exits.');
        $lock->release();
    }

    private function forceReleaseWorkerLock(): void
    {
        /** @phpstan-ignore-next-line method.notFound */
        Cache::store('redis')->lock(self::LOCK_NAME, 5)->forceRelease();
    }
}
