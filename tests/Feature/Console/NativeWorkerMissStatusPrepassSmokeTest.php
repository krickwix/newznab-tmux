<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Distributed\NativeHashedFixNameRenamePrepassRunner;
use App\Services\Distributed\NativeWorkerCommitRunner;
use App\Services\Distributed\NativeWorkerLaneRunner;
use App\Services\Distributed\NativeWorkerPlanExporter;
use App\Services\Distributed\NativeWorkerShadowRunner;
use App\Services\NameFixing\NativeSearchSideEffectOutboxSync;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class NativeWorkerMissStatusPrepassSmokeTest extends TestCase
{
    private const LOCK_NAME = 'nntmux:distributed-worker:hashed-fixnames';
    private const MYSQL_DSN = 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true';
    private const REDIS_ADDR = 'redis:6379';
    private const LOCK_OWNER_NEEDLE = 'laravel_cache_lock:';

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NNTMUX_NATIVE_PREPASS_SMOKE') !== '1') {
            $this->markTestSkipped('Set NNTMUX_NATIVE_PREPASS_SMOKE=1 to run the PHP-orchestrated native prepass smoke.');
        }

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The PHP-orchestrated native prepass smoke requires pdo_mysql.');
        }

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The PHP-orchestrated native prepass smoke requires ext-redis.');
        }

        $this->assertSmokeTargetsAreDisposable();
        $this->configureMariaDbConnection();
        $this->configureRedisLockStore();
        $this->configureNativeCommitRunner();
        $this->forceReleaseWorkerLock();
    }

    protected function tearDown(): void
    {
        try {
            if (getenv('NNTMUX_NATIVE_PREPASS_SMOKE') === '1' && extension_loaded('redis')) {
                $this->forceReleaseWorkerLock();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_php_worker_commits_native_miss_statuses_syncs_outbox_and_releases_real_redis_lock(): void
    {
        $this->assertReleaseStatuses([
            100 => ['proc_crc32' => 0, 'proc_hash16k' => 0],
            102 => ['proc_crc32' => 0, 'proc_hash16k' => 0],
            300 => ['proc_crc32' => 0, 'proc_hash16k' => 0],
            301 => ['proc_crc32' => 0, 'proc_hash16k' => 0],
        ]);
        $this->assertSame(0, DB::table('native_worker_side_effects')->count());

        $output = new BufferedOutput;

        Search::shouldReceive('updateRelease')->once()->with(102);
        Search::shouldReceive('updateRelease')->once()->with(301);

        Artisan::shouldReceive('call')
            ->times(4)
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

        $worker = $this->worker();
        $firstExitCode = $worker->run('hashed-fixnames', once: true, sleepOverride: null, lockSeconds: 42, output: $output);
        $secondExitCode = $worker->run('hashed-fixnames', once: true, sleepOverride: null, lockSeconds: 42, output: $output);

        $workerOutput = $output->fetch();
        $this->assertSame(0, $firstExitCode);
        $this->assertSame(0, $secondExitCode);
        $this->assertStringContainsString('native miss-status prepass committed hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('native search outbox synced hashed-fixnames: seen=2 synced=2 failed=0 dead-lettered=0', $workerOutput);
        $this->assertStringContainsString('native search outbox synced hashed-fixnames: seen=0 synced=0 failed=0 dead-lettered=0', $workerOutput);
        $this->assertLessThan(
            strpos($workerOutput, 'php artisan releases:fix-names'),
            strpos($workerOutput, 'native miss-status prepass committed hashed-fixnames'),
        );
        foreach ([self::MYSQL_DSN, self::REDIS_ADDR, self::LOCK_OWNER_NEEDLE, 'nntmux_database_', '--mysql-dsn', '--redis-addr', '--lock-owner'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workerOutput);
        }

        $this->assertReleaseStatuses([
            100 => ['proc_crc32' => 0, 'proc_hash16k' => 0],
            102 => ['proc_crc32' => 1, 'proc_hash16k' => 0],
            300 => ['proc_crc32' => 0, 'proc_hash16k' => 0],
            301 => ['proc_crc32' => 0, 'proc_hash16k' => 1],
        ]);
        $this->assertNativeOutboxSyncedFor([102, 301]);
        $this->assertSame('Hash.Target.CRC.PreDB', DB::table('releases')->where('id', 100)->value('searchname'));
        $this->assertSame('Hash.Target.Par.Match', DB::table('releases')->where('id', 300)->value('searchname'));
        $this->assertWorkerLockReleased();
    }

    private function assertSmokeTargetsAreDisposable(): void
    {
        $this->assertSame('1', getenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB'), 'Refusing to use MariaDB without the destructive native-test guard.');
        $this->assertSame('1', getenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB'), 'Refusing committed native writes without the committed native-test guard.');
        $this->assertSame('1', getenv('NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED'), 'Refusing committed native writes without the PHP smoke guard.');
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

    private function configureNativeCommitRunner(): void
    {
        $binary = (string) getenv('NNTMUX_NATIVE_WORKER_BINARY');
        $this->assertNotSame('', $binary, 'NNTMUX_NATIVE_WORKER_BINARY must point at the bind-mounted native binary.');
        $this->assertTrue(is_executable($binary), sprintf('Native worker binary [%s] must be executable.', $binary));

        config([
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_commit_test_enabled' => true,
            'nntmux.native_worker_binary' => $binary,
            'nntmux.native_worker_mysql_dsn' => self::MYSQL_DSN,
            'nntmux.native_worker_redis_addr' => self::REDIS_ADDR,
            'nntmux.native_worker_commit_timeout_seconds' => 10,
            'nntmux.native_worker_search_outbox_limit' => 10,
            'nntmux.native_worker_search_outbox_max_attempts' => 2,
        ]);
    }

    private function worker(): DistributedJobWorker
    {
        $plan = $this->hashedFixnamesPlan();

        /** @var DistributedJobCatalog&MockInterface $catalog */
        $catalog = Mockery::mock(DistributedJobCatalog::class);
        /** @phpstan-ignore-next-line method.notFound */
        $catalog->shouldReceive('resolve')->with('hashed-fixnames', ['run' => 'var'])->andReturn($plan);

        /** @var TmuxMonitorService&MockInterface $monitor */
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->andReturn([]);
        $monitor->shouldReceive('collectStatistics')->andReturn(['run' => 'var']);

        /** @var NativeWorkerShadowRunner&MockInterface $shadowRunner */
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        return new DistributedJobWorker(
            $catalog,
            $monitor,
            new NativeWorkerPlanExporter,
            $shadowRunner,
            new NativeWorkerCommitRunner,
            new NativeSearchSideEffectOutboxSync,
            Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class),
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

    /**
     * @param  array<int, array{proc_crc32: int, proc_hash16k: int}>  $expected
     */
    private function assertReleaseStatuses(array $expected): void
    {
        foreach ($expected as $releaseId => $statuses) {
            $row = DB::table('releases')->where('id', $releaseId)->first(['proc_crc32', 'proc_hash16k']);

            $this->assertNotNull($row, "Expected release {$releaseId} to exist.");
            $this->assertSame($statuses['proc_crc32'], (int) $row->proc_crc32, "Unexpected proc_crc32 for release {$releaseId}.");
            $this->assertSame($statuses['proc_hash16k'], (int) $row->proc_hash16k, "Unexpected proc_hash16k for release {$releaseId}.");
        }
    }

    /**
     * @param  list<int>  $expectedReleaseIds
     */
    private function assertNativeOutboxSyncedFor(array $expectedReleaseIds): void
    {
        /** @var Collection<int, object{release_id: int|string, status: string, attempts: int|string, last_error_code: string|null}> $rows */
        $rows = DB::table('native_worker_side_effects')
            ->orderBy('release_id')
            ->get(['release_id', 'status', 'attempts', 'last_error_code']);

        $this->assertSame($expectedReleaseIds, $rows->pluck('release_id')->map(static fn ($id): int => (int) $id)->all());
        $this->assertSame(['synced', 'synced'], $rows->pluck('status')->all());
        $this->assertSame([1, 1], $rows->pluck('attempts')->map(static fn ($attempts): int => (int) $attempts)->all());
        $this->assertSame([null, null], $rows->pluck('last_error_code')->all());
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
