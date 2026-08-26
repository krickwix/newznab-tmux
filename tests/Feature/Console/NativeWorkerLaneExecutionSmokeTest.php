<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Distributed\NativeHashedFixNameRenamePrepassRunner;
use App\Services\Distributed\NativeWorkerCommitRunner;
use App\Services\Distributed\NativeWorkerLaneRunner;
use App\Services\Distributed\NativeWorkerPlanExporter;
use App\Services\Distributed\NativeWorkerShadowRunner;
use App\Services\NameFixing\NativeSearchSideEffectOutboxSync;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class NativeWorkerLaneExecutionSmokeTest extends TestCase
{
    private const MYSQL_DSN = 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true';
    private const REDIS_ADDR = 'redis:6379';
    private const LOCK_OWNER_NEEDLE = 'laravel_cache_lock:';

    private string $job;
    private string $case;
    private string $artisanMode;
    private string $artifactDir;
    private string $artisanScript;
    private string $artisanLog;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('NNTMUX_NATIVE_LANE_EXECUTION_SMOKE') !== '1') {
            $this->markTestSkipped('Set NNTMUX_NATIVE_LANE_EXECUTION_SMOKE=1 to run the PHP-orchestrated native lane smoke.');
        }

        $this->job = (string) getenv('NNTMUX_NATIVE_LANE_EXECUTION_SMOKE_JOB');
        if (! in_array($this->job, ['binaries', 'backfill', 'releases', 'per-group', 'removecrap', 'post-tv', 'post-movies', 'post-amazon', 'post-additional', 'fixnames', 'metadata-refresh', 'hashed-fixnames', 'irc'], true)) {
            $this->markTestSkipped('Set NNTMUX_NATIVE_LANE_EXECUTION_SMOKE_JOB to a supported native lane smoke job.');
        }
        $this->case = (string) (getenv('NNTMUX_NATIVE_LANE_EXECUTION_SMOKE_CASE') ?: 'default');
        $this->artisanMode = (string) (getenv('NNTMUX_NATIVE_LANE_EXECUTION_ARTISAN_MODE') ?: 'fake');

        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The PHP-orchestrated native lane smoke requires pdo_mysql.');
        }

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The PHP-orchestrated native lane smoke requires ext-redis.');
        }

        $this->artifactDir = storage_path('native-lane-execution-smoke');
        $this->artisanLog = $this->artifactDir.'/'.$this->job.'-'.$this->case.'-'.$this->artisanMode.'.log';
        $this->artisanScript = $this->artisanMode === 'real'
            ? base_path('artisan')
            : $this->artifactDir.'/fake-artisan.sh';

        $this->assertSmokeTargetsAreDisposable();
        $this->configureMariaDbConnection();
        $this->configureRedisLockStore();
        $this->configureArtisanLeafRunner();
        $this->configureNativeLaneRunner();
        $this->assertNativeLaneRunnerForwardsLeafStartupSmokeEnvironment();
        $this->forceReleaseWorkerLock();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->job) && extension_loaded('redis')) {
                $this->forceReleaseWorkerLock();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_php_worker_runs_selected_first_lane_through_native_binary_under_laravel_lock(): void
    {
        Artisan::shouldReceive('call')->never();

        $output = new BufferedOutput;
        $exitCode = $this->worker()->run($this->job, once: true, sleepOverride: null, lockSeconds: 42, output: $output);

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("native lane completed {$this->job}", $workerOutput);
        $this->assertStringNotContainsString('php artisan', $workerOutput);
        foreach ([self::MYSQL_DSN, self::REDIS_ADDR, self::LOCK_OWNER_NEEDLE, 'nntmux_database_', '--mysql-dsn', '--redis-addr', '--lock-owner'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workerOutput);
        }

        $this->assertEqualsCanonicalizing(
            $this->expectedFakeArtisanLines(),
            $this->artisanLines(),
            'Expected the native lane to invoke the complete Artisan queue.',
        );

        $this->assertWorkerLockReleased();
    }

    private function assertSmokeTargetsAreDisposable(): void
    {
        $this->assertSame('1', getenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB'), 'Refusing to use MariaDB without the destructive native-test guard.');
        $this->assertSame('1', getenv('NNTMUX_NATIVE_LANE_EXECUTION_SMOKE'), 'Refusing native lane execution without the PHP smoke guard.');
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

    private function configureArtisanLeafRunner(): void
    {
        if (! is_dir($this->artifactDir)) {
            mkdir($this->artifactDir, 0777, true);
        }

        putenv('NNTMUX_NATIVE_FAKE_ARTISAN_LOCK_KEY='.$this->lockRedisKey());
        $_ENV['NNTMUX_NATIVE_FAKE_ARTISAN_LOCK_KEY'] = $this->lockRedisKey();
        $_SERVER['NNTMUX_NATIVE_FAKE_ARTISAN_LOCK_KEY'] = $this->lockRedisKey();
        putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE='.($this->artisanMode === 'real' ? '1' : ''));
        putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG='.$this->artisanLog);
        $_ENV['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'] = $this->artisanMode === 'real' ? '1' : '';
        $_ENV['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'] = $this->artisanLog;
        $_SERVER['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'] = $this->artisanMode === 'real' ? '1' : '';
        $_SERVER['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'] = $this->artisanLog;

        if ($this->artisanMode === 'real') {
            $this->assertSame('1', getenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'));
            $this->assertSame($this->artisanLog, getenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'));
        }

        file_put_contents($this->artisanLog, '');
        if ($this->artisanMode !== 'real') {
            file_put_contents($this->artisanScript, $this->fakeArtisanScript());
            chmod($this->artisanScript, 0755);
        }
    }

    private function fakeArtisanScript(): string
    {
        return sprintf(
            <<<'PHP'
#!/usr/bin/env php
<?php

$owner = getenv('NNTMUX_NATIVE_LOCK_OWNER') ?: '';
$lockKey = getenv('NNTMUX_NATIVE_FAKE_ARTISAN_LOCK_KEY') ?: '';

if ($owner === '' || $lockKey === '') {
    fwrite(STDERR, "missing native lock owner or lock key\n");
    exit(23);
}

$redis = new Redis;
$redis->connect('redis', 6379);
$heldOwner = $redis->get($lockKey);

if ($heldOwner !== $owner) {
    fwrite(STDERR, "native lock owner mismatch\n");
    exit(24);
}

file_put_contents(%s, implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND | LOCK_EX);
exit(0);
PHP,
            var_export($this->artisanLog, true),
        );
    }

    private function configureNativeLaneRunner(): void
    {
        $binary = (string) getenv('NNTMUX_NATIVE_WORKER_BINARY');
        $this->assertNotSame('', $binary, 'NNTMUX_NATIVE_WORKER_BINARY must point at the bind-mounted native binary.');
        $this->assertTrue(is_executable($binary), sprintf('Native worker binary [%s] must be executable.', $binary));

        config([
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => false,
            'nntmux.native_worker_lane_execution_enabled' => true,
            'nntmux.native_worker_post_additional_deferred_execution_enabled' => true,
            'nntmux.native_worker_binary' => $binary,
            'nntmux.native_worker_mysql_dsn' => self::MYSQL_DSN,
            'nntmux.native_worker_redis_addr' => self::REDIS_ADDR,
            'nntmux.native_worker_lane_timeout_seconds' => 15,
            'nntmux.native_worker_artisan_binary' => PHP_BINARY,
            'nntmux.native_worker_artisan_script' => $this->artisanScript,
        ]);
    }

    private function assertNativeLaneRunnerForwardsLeafStartupSmokeEnvironment(): void
    {
        if ($this->artisanMode !== 'real') {
            return;
        }

        $method = new ReflectionMethod(NativeWorkerLaneRunner::class, 'environment');
        $environment = $method->invoke(new NativeWorkerLaneRunner, self::MYSQL_DSN, self::REDIS_ADDR, 'smoke-owner');

        $this->assertIsArray($environment);
        $this->assertSame('1', $environment['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'] ?? null);
        $this->assertSame($this->artisanLog, $environment['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'] ?? null);
    }

    private function worker(): DistributedJobWorker
    {
        /** @var TmuxMonitorService&MockInterface $monitor */
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->andReturn([]);
        $monitor->shouldReceive('collectStatistics')->andReturn($this->runVar());

        /** @var NativeWorkerShadowRunner&MockInterface $shadowRunner */
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        /** @var NativeWorkerCommitRunner&MockInterface $commitRunner */
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');

        /** @var NativeSearchSideEffectOutboxSync&MockInterface $outboxSync */
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        /** @var NativeHashedFixNameRenamePrepassRunner&MockInterface $renamePrepassRunner */
        $renamePrepassRunner = Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class);
        $renamePrepassRunner->shouldNotReceive('apply');

        return new DistributedJobWorker(
            new DistributedJobCatalog,
            $monitor,
            new NativeWorkerPlanExporter,
            $shadowRunner,
            $commitRunner,
            $outboxSync,
            $renamePrepassRunner,
            new NativeWorkerLaneRunner,
        );
    }

    /**
     * @return array{
     *     settings: array<string, mixed>,
     *     constants: array<string, mixed>,
     *     counts: array{now: array<string, mixed>},
     *     killswitch: array<string, mixed>
     * }
     */
    private function runVar(): array
    {
        return [
            'settings' => [
                'binaries_run' => 1,
                'backfill' => 1,
                'backfill_days' => 1,
                'bins_timer' => 60,
                'back_timer' => 600,
                'releases_run' => 1,
                'rel_timer' => 60,
                'seq_timer' => 60,
                'fix_names' => 1,
                'fix_timer' => 60,
                'metadata_refresh' => 1,
                'metadata_refresh_limit' => 7,
                'metadata_refresh_sleep_ms' => 11,
                'metadata_refresh_timer' => 60,
                'fix_crap_opt' => 'Custom',
                'fix_crap' => 'gibberish,executable,hashed,short,installbin,passwordurl,nzb,scr,passworded,sample,size,codec,blfiles,blacklist,par2only',
                'crap_timer' => 60,
                'post' => 3,
                'post_timer' => 60,
                'metadata_refresh_postprocess' => 0,
                'post_non' => 1,
                'processtvrage' => 1,
                'processanime' => 1,
                'processmovies' => 1,
                'post_timer_non' => 60,
                'post_amazon' => 1,
                'post_timer_amazon' => 60,
            ],
            'constants' => [
                'sequential' => $this->job === 'per-group' ? 2 : 0,
                'run_ircscraper' => $this->job === 'irc' ? 1 : 0,
            ],
            'counts' => [
                'now' => [
                    'backfill_groups_days' => 2,
                    'processrenames' => 3,
                    'other_hashed' => 101,
                    'work' => 4,
                    'processnfo' => 4,
                    'processtv' => 3,
                    'processanime' => 1,
                    'processmovies' => 2,
                    'processmusic' => 2,
                    'processbooks' => 2,
                    'processconsole' => 2,
                    'processgames' => 2,
                ],
            ],
            'killswitch' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function artisanLines(): array
    {
        $log = (string) file_get_contents($this->artisanLog);
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $log)),
            static fn (string $line): bool => $line !== '',
        ));
        sort($lines);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function expectedFakeArtisanLines(): array
    {
        $lines = match ($this->job) {
            'binaries' => [
                'binaries:part-repair alt.binaries.movies',
                'articles:get-range binaries alt.binaries.movies 1001 11000',
                'articles:get-range binaries alt.binaries.movies 11001 21000',
                'articles:get-range binaries alt.binaries.movies 21001 26000',
                'group:update-headers alt.binaries.small',
                'group:update-headers alt.binaries.new',
            ],
            'backfill' => $this->expectedBackfillFakeArtisanLines(),
            'releases' => [
                'releases:process 1',
                'releases:process 2',
            ],
            'per-group' => [
                'group:update-all 1',
                'group:update-all 2',
                'group:update-all 3',
                'group:update-all 4',
                'group:update-all 5',
            ],
            'removecrap' => [
                'releases:remove-crap --type=gibberish --time=4 --delete',
                'releases:remove-crap --type=executable --time=4 --delete',
                'releases:remove-crap --type=hashed --time=4 --delete',
                'releases:remove-crap --type=short --time=4 --delete',
                'releases:remove-crap --type=installbin --time=4 --delete',
                'releases:remove-crap --type=passwordurl --time=4 --delete',
                'releases:remove-crap --type=nzb --time=4 --delete',
                'releases:remove-crap --type=scr --time=4 --delete',
                'releases:remove-crap --type=passworded --time=4 --delete',
                'releases:remove-crap --type=sample --time=4 --delete',
                'releases:remove-crap --type=size --time=4 --delete',
                'releases:remove-crap --type=codec --time=4 --delete',
                'releases:remove-crap --type=blfiles --time=4 --delete',
                'releases:remove-crap --type=blacklist --time=4 --delete',
                'releases:remove-crap --type=par2only --time=4 --delete',
            ],
            'post-tv' => [
                'postprocess:tv-pipeline A 1 --mode=pipeline',
                'postprocess:tv-pipeline b 1 --mode=pipeline',
                'postprocess:guid anime c',
            ],
            'post-movies' => [
                'postprocess:guid movie m',
                'postprocess:guid movie n',
            ],
            'post-amazon' => [
                'postprocess:guid books B',
                'postprocess:guid books q',
                'postprocess:guid music M',
                'postprocess:guid music N',
                'postprocess:guid console C',
                'postprocess:guid console D',
                'postprocess:guid games G',
                'postprocess:guid games H',
            ],
            'post-additional' => [
                'postprocess:guid additional a',
                'postprocess:guid additional B',
                'postprocess:guid nfo N',
                'postprocess:guid nfo o',
            ],
            'fixnames' => [
                'releases:fix-names 3 --category=other --update --set-status --show',
                'releases:fix-names 4 --category=other --update --set-status --show',
                'releases:fix-names 5 --category=other --update --set-status --show',
                'releases:fix-names 6 --category=other --update --set-status --show',
                'releases:fix-names 6 --category=movies --limit=500 --update --set-status --show',
                'releases:fix-names 21 --category=other --limit=500 --update --set-status --show',
                'releases:fix-names 21 --category=movies --limit=500 --update --set-status --show',
                'releases:fix-names 8 --category=other --limit=50 --update --set-status --show',
                'releases:fix-names 7 --category=other --update --set-status --show',
                'releases:fix-names 9 --category=other --update --set-status --show',
                'releases:fix-names 11 --category=other --update --set-status --show',
                'releases:fix-names 13 --category=other --update --set-status --show',
                'releases:fix-names 15 --category=other --update --set-status --show',
                'releases:fix-names 17 --category=other --update --set-status --show',
                'releases:fix-names 19 --category=other --update --set-status --show',
            ],
            'metadata-refresh' => [
                'predb:refresh-external-metadata --source=all --limit=7 --sleep-ms=11',
                'releases:fix-names 20 --category=hashed --limit=500 --update --set-status --show',
                'releases:fix-names 16 --category=hashed --limit=500 --update --set-status --show',
            ],
            'hashed-fixnames' => [
                'releases:fix-names 4 --category=hashed --update --set-status --show',
                'releases:fix-names 6 --category=hashed --update --set-status --show',
                'releases:fix-names 21 --category=hashed --update --set-status --show',
                'releases:fix-names 18 --category=hashed --update --set-status --show',
                'releases:fix-names 10 --category=hashed --update --set-status --show',
                'releases:fix-names 14 --category=hashed --update --set-status --show',
                'releases:fix-names 16 --category=hashed --update --set-status --show',
                'releases:fix-names 20 --category=hashed --update --set-status --show',
                'releases:fix-names 12 --category=hashed --update --set-status --show',
                'releases:fix-names 8 --category=hashed --update --set-status --show',
            ],
            'irc' => [
                'irc:scrape',
            ],
            default => [],
        };

        sort($lines);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function expectedBackfillFakeArtisanLines(): array
    {
        $lines = [
            'articles:get-range backfill a.b.multimedia.movies 30000 49999',
            'articles:get-range backfill a.b.multimedia.vintage-film 2 104',
            'articles:get-range backfill a.b.multimedia.movies 10000 29999',
            'articles:get-range backfill a.b.multimedia.movies 1 9999',
        ];

        if ($this->case === 'day2-safe-date') {
            $lines[] = 'articles:get-range backfill a.b.target-reached 1 999';
        }

        return $lines;
    }

    private function assertWorkerLockReleased(): void
    {
        /** @phpstan-ignore-next-line method.notFound */
        $lock = Cache::store('redis')->lock($this->lockName(), 5);
        $this->assertTrue($lock->get(), 'The Laravel-held Redis worker lock should be released after the worker exits.');
        $lock->release();
    }

    private function forceReleaseWorkerLock(): void
    {
        /** @phpstan-ignore-next-line method.notFound */
        Cache::store('redis')->lock($this->lockName(), 5)->forceRelease();
    }

    private function lockName(): string
    {
        return 'nntmux:distributed-worker:'.$this->job;
    }

    private function lockRedisKey(): string
    {
        return 'nntmux_database_nntmux-cache-'.$this->lockName();
    }
}
