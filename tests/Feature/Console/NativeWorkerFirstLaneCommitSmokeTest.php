<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Services\CollectionCleanupService;
use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Distributed\NativeHashedFixNameRenamePrepassRunner;
use App\Services\Distributed\NativeWorkerCommitRunner;
use App\Services\Distributed\NativeWorkerLaneRunner;
use App\Services\Distributed\NativeWorkerPlanExporter;
use App\Services\Distributed\NativeWorkerShadowRunner;
use App\Services\NameFixing\NativeSearchSideEffectOutboxSync;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use JsonException;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class NativeWorkerFirstLaneCommitSmokeTest extends TestCase
{
    private const MYSQL_DSN = 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true';

    private const REDIS_ADDR = 'redis:6379';

    private string $job;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NNTMUX_NATIVE_FIRST_LANE_COMMIT_SMOKE') !== '1'
            && getenv('NNTMUX_NATIVE_LANE_COMMIT_SMOKE') !== '1'
            && getenv('NNTMUX_NATIVE_REMOVECRAP_PRODUCTION_COMMIT_SMOKE') !== '1') {
            $this->markTestSkipped('Set NNTMUX_NATIVE_LANE_COMMIT_SMOKE=1 to run the PHP-orchestrated lane commit smoke.');
        }

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The PHP-orchestrated first-lane commit smoke requires pdo_mysql.');
        }

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The PHP-orchestrated first-lane commit smoke requires ext-redis.');
        }

        $this->job = $this->smokeJob();
        $this->assertSmokeTargetsAreDisposable();
        $this->configureMariaDbConnection();
        $this->configureRedisLockStore();
        $this->configureNativeCommitRunner();
        $this->forceReleaseWorkerLock();
    }

    protected function tearDown(): void
    {
        try {
            if ((getenv('NNTMUX_NATIVE_FIRST_LANE_COMMIT_SMOKE') === '1'
                || getenv('NNTMUX_NATIVE_LANE_COMMIT_SMOKE') === '1'
                || getenv('NNTMUX_NATIVE_REMOVECRAP_PRODUCTION_COMMIT_SMOKE') === '1')
                && extension_loaded('redis')) {
                $this->forceReleaseWorkerLock();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_php_worker_commits_native_first_lane_writes_and_skips_php_command_loop(): void
    {
        $this->assertNoCommittedRows($this->job);

        $this->expectPhpCommandLoop($this->job);
        $this->expectPhpOwnedSideEffects($this->job);

        $output = new BufferedOutput;
        $exitCode = $this->worker()->run($this->job, once: true, sleepOverride: null, lockSeconds: 42, output: $output);

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode, $workerOutput);
        $this->assertStringContainsString(sprintf('native lane commit completed %s: writes=', $this->job), $workerOutput);
        $this->assertCommandLoopOutput($this->job, $workerOutput);
        foreach ([self::MYSQL_DSN, self::REDIS_ADDR, 'nntmux_database_', '--mysql-dsn', '--redis-addr', '--lock-owner'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workerOutput);
        }

        $this->assertCommittedRows($this->job);
        $this->assertWorkerLockReleased();
    }

    private function smokeJob(): string
    {
        if ($this->isRemoveCrapProductionCommitSmoke()) {
            return 'removecrap';
        }

        $job = (string) (getenv('NNTMUX_NATIVE_LANE_COMMIT_SMOKE_JOB') ?: getenv('NNTMUX_NATIVE_FIRST_LANE_COMMIT_SMOKE_JOB') ?: 'binaries');
        $supported = $this->supportedJobs();

        $this->assertContains($job, $supported, sprintf(
            'Unsupported NNTMUX_NATIVE_FIRST_LANE_COMMIT_SMOKE_JOB [%s]; expected one of %s.',
            $job,
            implode(', ', $supported),
        ));

        return $job;
    }

    private function assertSmokeTargetsAreDisposable(): void
    {
        if ($this->isRemoveCrapProductionCommitSmoke()) {
            $this->assertStringContainsString('/nntmux_native_test?', self::MYSQL_DSN, 'Production opt-in smoke must still target the disposable native-test schema.');
            $this->assertNotSame('1', getenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB'), 'Production opt-in smoke should prove the native-test destructive guard is not required.');
            $this->assertNotSame('1', getenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB'), 'Production opt-in smoke should prove the native-test committed-write guard is not required.');
            $this->assertNotSame('1', getenv('NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED'), 'Production opt-in smoke should prove the PHP committed-test guard is not required.');

            return;
        }

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
        $overviewSample = $this->usesAcquisitionOverviewSample()
            ? (int) (getenv('NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE') ?: 0)
            : 0;
        if ($this->usesAcquisitionOverviewSample()) {
            $this->assertGreaterThan(0, $overviewSample, 'Acquisition commit smoke requires NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE.');
        }

        config([
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_first_lane_commit_enabled' => in_array($this->job, $this->firstLaneJobs(), true),
            'nntmux.native_worker_lane_commit_enabled' => ! in_array($this->job, $this->firstLaneJobs(), true),
            'nntmux.native_worker_lane_execution_enabled' => false,
            'nntmux.native_worker_post_additional_deferred_execution_enabled' => $this->job === 'post-additional',
            'nntmux.native_worker_commit_test_enabled' => ! $this->isRemoveCrapProductionCommitSmoke(),
            'nntmux.native_worker_removecrap_production_commit_enabled' => $this->isRemoveCrapProductionCommitSmoke(),
            'nntmux.native_worker_binary' => $binary,
            'nntmux.native_worker_mysql_dsn' => self::MYSQL_DSN,
            'nntmux.native_worker_redis_addr' => self::REDIS_ADDR,
            'nntmux.native_worker_commit_timeout_seconds' => 10,
            'nntmux.native_worker_binaries_max_messages' => 10000,
            'nntmux.native_worker_binaries_max_headers' => 25000,
            'nntmux.native_worker_backfill_qty' => 75000,
            'nntmux.native_worker_backfill_max_messages' => 20000,
            'nntmux.native_worker_backfill_threads' => 4,
            'nntmux.native_worker_backfill_groups' => 10,
            'nntmux.native_worker_backfill_days' => 1,
            'nntmux.native_worker_backfill_min_articles' => 100,
            'nntmux.native_worker_nntp_overview_sample' => $overviewSample,
        ]);
    }

    private function isRemoveCrapProductionCommitSmoke(): bool
    {
        return getenv('NNTMUX_NATIVE_REMOVECRAP_PRODUCTION_COMMIT_SMOKE') === '1';
    }

    private function worker(): DistributedJobWorker
    {
        /** @var DistributedJobCatalog&MockInterface $catalog */
        $catalog = Mockery::mock(DistributedJobCatalog::class);
        /** @phpstan-ignore-next-line method.notFound */
        $catalog->shouldReceive('resolve')->with($this->job, ['run' => 'var'])->andReturn($this->plan($this->job));

        /** @var TmuxMonitorService&MockInterface $monitor */
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->andReturn([]);
        $monitor->shouldReceive('collectStatistics')->andReturn(['run' => 'var']);

        /** @var NativeWorkerShadowRunner&MockInterface $shadowRunner */
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $outboxSync = $this->job === 'metadata-refresh'
            ? new NativeSearchSideEffectOutboxSync
            : Mockery::mock(NativeSearchSideEffectOutboxSync::class);

        return new DistributedJobWorker(
            $catalog,
            $monitor,
            new NativeWorkerPlanExporter,
            $shadowRunner,
            new NativeWorkerCommitRunner,
            $outboxSync,
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
    private function plan(string $job): array
    {
        $path = base_path("tests/Fixtures/native-worker/catalog/{$job}.json");
        $this->assertFileExists($path);

        try {
            $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail('Unable to decode native worker catalog fixture: '.$e->getMessage());
        }

        $jobMeta = $fixture['job'] ?? [];

        return [
            'name' => (string) ($jobMeta['name'] ?? $job),
            'description' => (string) ($jobMeta['description'] ?? $job),
            'enabled' => (bool) ($jobMeta['enabled'] ?? false),
            'disabled_reason' => $jobMeta['disabled_reason'] ?? null,
            'commands' => $fixture['commands'] ?? [],
            'sleep' => (int) ($jobMeta['sleep'] ?? 60),
        ];
    }

    private function planCommand(string $job): string
    {
        return (string) $this->plan($job)['commands'][0]['command'];
    }

    private function assertNoCommittedRows(string $job): void
    {
        match ($job) {
            'binaries' => $this->assertSame(0, DB::table('binaries')->whereIn('name', $this->binariesOverviewNames())->count()),
            'backfill' => $this->assertSame(0, DB::table('binaries')->whereIn('name', $this->backfillOverviewNames())->count()),
            'releases' => $this->assertSame(0, DB::table('releases')->where('name', 'Movie.Release.Native.2026')->count()),
            'per-group' => $this->assertSame(0, DB::table('usenet_groups')->whereNotNull('last_updated')->count()),
            'removecrap' => $this->assertSame(1, DB::table('releases')->where('id', 100)->count()),
            'metadata-refresh' => $this->assertSame(0, DB::table('predb')->whereIn('source', ['native-archive-crc', 'native-search-query'])->count()),
            'post-tv' => $this->assertSame(0, DB::table('releases')->where('anidbid', -2)->count()),
            'post-movies' => $this->assertSame(0, DB::table('releases')->where('movieinfo_id', 1)->count()),
            'post-amazon' => $this->assertSame(0, DB::table('releases')->where('musicinfo_id', -2)->orWhere('consoleinfo_id', -2)->count()),
            'post-additional' => $this->assertSame(0, DB::table('releases')->where('name', 'like', 'Additional.Release.%')->where('haspreview', 0)->where('passwordstatus', 0)->count()),
        };

        if ($job === 'binaries') {
            $this->assertSame(0, DB::table('binaries')->where('name', 'like', 'native-rehearsal:%')->count());
            $this->assertSame(0, DB::table('parts')->whereIn('messageid', $this->binariesOverviewMessageIds())->count());
        }
        if ($job === 'backfill') {
            $this->assertSame(0, DB::table('binaries')->where('name', 'like', 'native-backfill-rehearsal:%')->count());
            $this->assertSame(0, DB::table('parts')->whereIn('messageid', $this->backfillOverviewMessageIds())->count());
        }
        if ($job === 'releases') {
            $this->assertSame(0, DB::table('releases')->where('guid', 'like', 'native-release-rehearsal-%')->count());
            $this->assertSame(0, DB::table('collections')->whereNotNull('releases_id')->count());
        }
        if ($job === 'metadata-refresh') {
            $this->assertSame(1, DB::table('predb_crcs')->count());
        }
    }

    private function assertCommittedRows(string $job): void
    {
        match ($job) {
            'binaries' => $this->assertGreaterThan(0, DB::table('binaries')->whereIn('name', $this->binariesOverviewNames())->count()),
            'backfill' => $this->assertGreaterThan(0, DB::table('binaries')->whereIn('name', $this->backfillOverviewNames())->count()),
            'releases' => $this->assertGreaterThan(0, DB::table('releases')->where('name', 'Movie.Release.Native.2026')->where('searchname', 'Movie.Release.Native.2026')->where('fromname', 'poster@example.invalid')->count()),
            'per-group' => $this->assertGreaterThan(0, DB::table('usenet_groups')->whereNotNull('last_updated')->count()),
            'removecrap' => $this->assertSame(0, DB::table('releases')->where('id', 100)->count()),
            'metadata-refresh' => $this->assertGreaterThan(0, DB::table('predb')->whereIn('source', ['srrdb', 'predb-net', 'predb-ovh', 'xrel', 'xrel-p2p'])->count()),
            'post-tv' => $this->assertGreaterThan(0, DB::table('releases')->where('anidbid', -2)->count()),
            'post-movies' => $this->assertGreaterThan(0, DB::table('releases')->where('movieinfo_id', 1)->count()),
            'post-amazon' => $this->assertGreaterThan(0, DB::table('releases')->where('musicinfo_id', -2)->orWhere('consoleinfo_id', -2)->count()),
            'post-additional' => $this->assertGreaterThan(0, DB::table('releases')->where('name', 'like', 'Additional.Release.%')->where('haspreview', 0)->where('passwordstatus', 0)->count()),
        };

        if ($job === 'binaries') {
            $this->assertSame(0, DB::table('binaries')->where('name', 'like', 'native-rehearsal:%')->count());
            $this->assertGreaterThan(0, DB::table('parts')->whereIn('messageid', $this->binariesOverviewMessageIds())->count());
        }
        if ($job === 'backfill') {
            $this->assertSame(0, DB::table('binaries')->where('name', 'like', 'native-backfill-rehearsal:%')->count());
            $this->assertGreaterThan(0, DB::table('parts')->whereIn('messageid', $this->backfillOverviewMessageIds())->count());
        }
        if ($job === 'releases') {
            $this->assertSame(0, DB::table('releases')->where('guid', 'like', 'native-release-rehearsal-%')->count());
            $this->assertGreaterThan(0, DB::table('collections')->whereNotNull('releases_id')->count());
        }
        if ($job === 'metadata-refresh') {
            $this->assertSame(0, DB::table('predb')->where('source', 'native-archive-crc')->count());
            $this->assertSame(0, DB::table('predb')->where('source', 'native-search-query')->count());
            $this->assertGreaterThanOrEqual(1, DB::table('predb_crcs')->count());
        }
        if ($job === 'post-additional') {
            $this->assertGreaterThan(0, DB::table('releases')->where('nfostatus', 0)->where('name', 'like', 'NFO.Release.%')->count());
        }
    }

    private function expectPhpCommandLoop(string $job): void
    {
        if (! in_array($job, ['metadata-refresh', 'post-additional'], true)) {
            Artisan::shouldReceive('call')->never();

            return;
        }

        if ($job === 'post-additional') {
            Artisan::shouldReceive('call')
                ->once()
                ->with('predb:refresh-external-metadata', ['--source' => ['all'], '--limit' => 7, '--sleep-ms' => 250], Mockery::type(BufferedOutput::class))
                ->andReturn(0);
        }

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', [
                'method' => '20',
                '--update' => true,
                '--category' => 'hashed',
                '--set-status' => true,
                '--limit' => 500,
                '--show' => true,
            ], Mockery::type(BufferedOutput::class))
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', [
                'method' => '16',
                '--update' => true,
                '--category' => 'hashed',
                '--set-status' => true,
                '--limit' => 500,
                '--show' => true,
            ], Mockery::type(BufferedOutput::class))
            ->andReturn(0);
    }

    private function expectPhpOwnedSideEffects(string $job): void
    {
        if ($job === 'metadata-refresh') {
            Search::shouldReceive('insertPredb')
                ->zeroOrMoreTimes()
                ->with(Mockery::on(static fn (array $parameters): bool => isset($parameters['id'], $parameters['title'], $parameters['source'])
                    && is_int($parameters['id'])
                    && is_string($parameters['title'])
                    && is_string($parameters['source'])
                    && ($parameters['filename'] ?? null) === ''));

            return;
        }

        if ($job !== 'removecrap') {
            return;
        }

        Search::shouldReceive('deleteRelease')
            ->zeroOrMoreTimes()
            ->with(Mockery::type('int'));

        $collectionCleanup = Mockery::mock(CollectionCleanupService::class);
        $collectionCleanup->shouldReceive('deleteCollectionsAndDescendants')
            ->zeroOrMoreTimes()
            ->with(Mockery::type('array'), 'Native removecrap descendant cleanup', false)
            ->andReturn(0);
        $this->app->instance(CollectionCleanupService::class, $collectionCleanup);

        $nzbService = Mockery::mock(NzbService::class);
        $nzbService->shouldReceive('deleteNzb')
            ->zeroOrMoreTimes()
            ->with(Mockery::type('string'))
            ->andReturn(true);
        $this->app->instance(NzbService::class, $nzbService);

        $releaseImageService = Mockery::mock(ReleaseImageService::class);
        $releaseImageService->shouldReceive('delete')
            ->zeroOrMoreTimes()
            ->with(Mockery::type('string'));
        $this->app->instance(ReleaseImageService::class, $releaseImageService);
    }

    private function assertCommandLoopOutput(string $job, string $workerOutput): void
    {
        if ($job === 'metadata-refresh') {
            $this->assertStringNotContainsString('php artisan predb:refresh-external-metadata', $workerOutput);
            $this->assertStringContainsString('php artisan releases:fix-names 20', $workerOutput);
            $this->assertStringContainsString('php artisan releases:fix-names 16', $workerOutput);

            return;
        }

        if ($job === 'post-additional') {
            $this->assertStringNotContainsString('php artisan multiprocessing:postprocess', $workerOutput);
            $this->assertStringContainsString('php artisan predb:refresh-external-metadata', $workerOutput);
            $this->assertStringContainsString('php artisan releases:fix-names', $workerOutput);

            return;
        }

        $this->assertStringNotContainsString('php artisan '.$this->planCommand($job), $workerOutput);
    }

    /**
     * @return list<string>
     */
    private function supportedJobs(): array
    {
        return [
            'binaries',
            'backfill',
            'releases',
            'per-group',
            'removecrap',
            'metadata-refresh',
            'post-tv',
            'post-movies',
            'post-amazon',
            'post-additional',
        ];
    }

    /**
     * @return list<string>
     */
    private function firstLaneJobs(): array
    {
        return ['binaries', 'backfill', 'releases'];
    }

    private function forceReleaseWorkerLock(): void
    {
        $redis = new \Redis;
        $redis->connect('redis', 6379);
        $redis->del('nntmux_database_nntmux-cache-'.$this->lockName());
    }

    private function assertWorkerLockReleased(): void
    {
        $redis = new \Redis;
        $redis->connect('redis', 6379);
        $this->assertFalse($redis->exists('nntmux_database_nntmux-cache-'.$this->lockName()) > 0);
    }

    private function lockName(): string
    {
        return 'nntmux:distributed-worker:'.$this->job;
    }

    private function usesAcquisitionOverviewSample(): bool
    {
        return in_array($this->job, ['binaries', 'backfill'], true);
    }

    /**
     * @return list<string>
     */
    private function binariesOverviewNames(): array
    {
        return ['Movie.One', 'Movie.Two', 'Movie.Three', 'Movie.Four', 'Movie.Five', 'Movie.Six'];
    }

    /**
     * @return list<string>
     */
    private function binariesOverviewMessageIds(): array
    {
        return ['<1001@example.test>', '<1002@example.test>', '<11001@example.test>', '<11002@example.test>', '<21001@example.test>', '<21002@example.test>'];
    }

    /**
     * @return list<string>
     */
    private function backfillOverviewNames(): array
    {
        return ['Backfill.One', 'Backfill.Two', 'Backfill.Three', 'Backfill.Four', 'Backfill.Five', 'Backfill.Six', 'Vintage.One', 'Vintage.Two'];
    }

    /**
     * @return list<string>
     */
    private function backfillOverviewMessageIds(): array
    {
        return ['<30000@example.test>', '<30001@example.test>', '<10000@example.test>', '<10001@example.test>', '<1@example.test>', '<2b@example.test>', '<2@example.test>', '<3@example.test>'];
    }
}
