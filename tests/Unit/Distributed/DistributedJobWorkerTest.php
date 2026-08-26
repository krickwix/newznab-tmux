<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Facades\Search;
use App\Services\CollectionCleanupService;
use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Distributed\NativeHashedFixNameRenamePrepassResult;
use App\Services\Distributed\NativeHashedFixNameRenamePrepassRunner;
use App\Services\Distributed\NativeWorkerCommitResult;
use App\Services\Distributed\NativeWorkerCommitRunner;
use App\Services\Distributed\NativeWorkerLaneResult;
use App\Services\Distributed\NativeWorkerLaneRunner;
use App\Services\Distributed\NativeWorkerPlanExporter;
use App\Services\Distributed\NativeWorkerShadowResult;
use App\Services\Distributed\NativeWorkerShadowRunner;
use App\Services\NameFixing\NativeSearchSideEffectOutboxSync;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionClass;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class DistributedJobWorkerTest extends TestCase
{
    public function test_it_formats_array_valued_artisan_options(): void
    {
        $worker = (new ReflectionClass(DistributedJobWorker::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DistributedJobWorker::class, 'formatArguments');

        $this->assertSame(
            '--source=all --source=srrdb --limit=25 --update method',
            $method->invoke($worker, [
                '--source' => ['all', 'srrdb'],
                '--limit' => 25,
                '--update' => true,
                'method' => 'method',
            ]),
        );
    }

    public function test_native_shadow_validation_is_disabled_by_default(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        Artisan::shouldReceive('call')
            ->once()
            ->with('native:test', ['--limit' => 5], Mockery::type(BufferedOutput::class))
            ->andReturn(0);

        $exitCode = $this->worker($shadowRunner)->run(
            'metadata-refresh',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('native shadow', $output->fetch());
    }

    public function test_enabled_native_shadow_validation_runs_before_php_commands(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldReceive('dryRun')
            ->once()
            ->with(Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'metadata-refresh'
                && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:metadata-refresh'
                && str_ends_with($nativePlan['lock']['redis_key'], $nativePlan['lock']['name'])
                && $nativePlan['lock']['seconds'] === 42
                && $nativePlan['commands'][0]['command'] === 'native:test'))
            ->ordered()
            ->andReturn(new NativeWorkerShadowResult(
                successful: true,
                output: 'native worker dry-run',
                errorOutput: '',
                exitCode: 0,
            ));

        Artisan::shouldReceive('call')
            ->once()
            ->with('native:test', ['--limit' => 5], Mockery::type(BufferedOutput::class))
            ->ordered()
            ->andReturn(0);

        $exitCode = $this->worker($shadowRunner)->run(
            'metadata-refresh',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native shadow validated metadata-refresh', $output->fetch());
    }

    public function test_native_shadow_validation_failure_is_fail_open(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldReceive('dryRun')
            ->once()
            ->andReturn(new NativeWorkerShadowResult(
                successful: false,
                output: '',
                errorOutput: 'unsupported native worker job "metadata-refresh"',
                exitCode: 1,
            ));

        Artisan::shouldReceive('call')
            ->once()
            ->with('native:test', ['--limit' => 5], Mockery::type(BufferedOutput::class))
            ->andReturn(23);

        $exitCode = $this->worker($shadowRunner)->run(
            'metadata-refresh',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();

        $this->assertSame(23, $exitCode);
        $this->assertStringContainsString('native shadow failed for metadata-refresh', $workerOutput);
        $this->assertStringContainsString('unsupported native worker job "metadata-refresh"', $workerOutput);
        $this->assertStringContainsString('continuing with PHP worker', $workerOutput);
    }

    public function test_native_shadow_validation_failure_redacts_truncated_native_output_fragments(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => true,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldReceive('dryRun')
            ->once()
            ->andReturn(new NativeWorkerShadowResult(
                successful: false,
                output: '',
                errorOutput: implode(' ', [
                    'native stderr truncated near dsn nntmux:nntmux@tcp(mariadb',
                    'second fragment dsn nntmux:nnt',
                    '"DSN":"nntmux:nnt"',
                    '"address":"redis:1234"',
                    '"release_name":"Secret.Release"',
                    '"old_name":"Hash.Target.CRC.PreDB',
                    '"arguments":{"--mysql-dsn":"nntmux:nnt',
                ]),
                exitCode: 1,
            ));

        Artisan::shouldReceive('call')
            ->once()
            ->with('native:test', ['--limit' => 5], Mockery::type(BufferedOutput::class))
            ->andReturn(23);

        $exitCode = $this->worker($shadowRunner)->run(
            'metadata-refresh',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();

        $this->assertSame(23, $exitCode);
        $this->assertStringContainsString('native shadow failed for metadata-refresh', $workerOutput);
        $this->assertStringContainsString('continuing with PHP worker', $workerOutput);
        foreach ([
            'nntmux:nnt',
            'redis:1234',
            'Secret.Release',
            '"DSN"',
            '"address"',
            '"release_name"',
            'Hash.Target',
            '"old_name"',
            '"arguments"',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workerOutput);
        }
    }

    public function test_native_shadow_validation_does_not_run_when_php_lock_is_held_elsewhere(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => true,
        ]);

        $lock = Cache::store('array')->lock('nntmux:distributed-worker:metadata-refresh', 42);
        $this->assertTrue($lock->get());

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $artisanCalled = false;
        Artisan::shouldReceive('call')
            ->andReturnUsing(static function () use (&$artisanCalled): int {
                $artisanCalled = true;

                return 99;
            });

        try {
            $exitCode = $this->worker($shadowRunner)->run(
                'metadata-refresh',
                once: true,
                sleepOverride: null,
                lockSeconds: 42,
                output: $output,
            );
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('another worker holds nntmux:distributed-worker:metadata-refresh', $output->fetch());
    }

    public function test_native_lane_execution_is_disabled_by_default_for_first_workers(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Artisan::shouldReceive('call')
            ->once()
            ->with('multiprocessing:safe', ['type' => 'binaries'], Mockery::type(BufferedOutput::class))
            ->andReturn(0);

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->binariesPlan(),
            job: 'binaries',
        )->run(
            'binaries',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('native lane', $output->fetch());
    }

    public function test_enabled_native_lane_execution_runs_under_lock_and_skips_php_commands_for_binaries(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'binaries'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:binaries'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'multiprocessing:safe'
                    && $nativePlan['commands'][0]['arguments']['type'] === 'binaries'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerLaneResult(
                successful: true,
                output: '{"native_lane":{"commands":6,"failed":0}}',
                errorOutput: '',
                exitCode: 0,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->binariesPlan(),
            job: 'binaries',
        )->run(
            'binaries',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane completed binaries', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:safe', $workerOutput);
    }

    public function test_enabled_native_first_lane_commit_runs_under_lock_and_skips_php_commands_for_binaries(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_first_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'binaries'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:binaries'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'multiprocessing:safe'
                    && $nativePlan['commands'][0]['arguments']['type'] === 'binaries'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeFirstLaneCommitReport('binaries'),
                errorOutput: '',
                exitCode: 0,
            ));

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            laneRunner: $laneRunner,
            plan: $this->binariesPlan(),
            job: 'binaries',
        )->run(
            'binaries',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane commit completed binaries: writes=3', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:safe', $workerOutput);
        $this->assertStringNotContainsString('native lane completed binaries', $workerOutput);
    }

    public function test_enabled_native_lane_commit_syncs_search_for_committed_releases(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'releases'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:releases'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'multiprocessing:releases'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: json_encode([
                    'schema_version' => 1,
                    'mode' => 'shadow',
                    'dry_run' => false,
                    'native_worker' => [
                        'job' => 'releases',
                        'writes' => 4,
                    ],
                    'releases_write_commit' => [
                        'queue_entries' => 2,
                        'release_rows_affected' => 2,
                        'collection_rows_affected' => 2,
                        'rolled_back' => false,
                        'writes_committed' => 4,
                        'committed_release_ids' => [11, 10],
                    ],
                ], JSON_THROW_ON_ERROR),
                errorOutput: '',
                exitCode: 0,
            ));

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Search::shouldReceive('updateRelease')->once()->with(10);
        Search::shouldReceive('updateRelease')->once()->with(11);

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            laneRunner: $laneRunner,
            plan: $this->releasesPlan(),
            job: 'releases',
        )->run(
            'releases',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native release search synced releases: seen=2 synced=2 failed=0', $workerOutput);
        $this->assertStringContainsString('native lane commit completed releases: writes=4', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:releases', $workerOutput);
    }

    public function test_enabled_native_removecrap_lane_commit_deletes_search_for_deleted_releases(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'removecrap'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:removecrap'
                    && $nativePlan['commands'][0]['command'] === 'releases:remove-crap'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: json_encode([
                    'schema_version' => 1,
                    'mode' => 'shadow',
                    'dry_run' => false,
                    'native_worker' => [
                        'job' => 'removecrap',
                        'writes' => 6,
                    ],
                    'removecrap_write_commit' => [
                        'candidate_releases' => 2,
                        'candidate_rows' => 3,
                        'delete_commands' => 2,
                        'collection_rows_affected' => 2,
                        'release_file_rows_affected' => 2,
                        'release_rows_affected' => 2,
                        'rolled_back' => false,
                        'writes_committed' => 6,
                        'deleted_release_ids' => [200, 100],
                        'deleted_collection_ids' => [2, 1],
                        'release_file_cleanup_rows_enqueued' => 2,
                    ],
                ], JSON_THROW_ON_ERROR),
                errorOutput: '',
                exitCode: 0,
            ));

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Search::shouldReceive('deleteRelease')->once()->with(100);
        Search::shouldReceive('deleteRelease')->once()->with(200);

        $collectionCleanup = Mockery::mock(CollectionCleanupService::class);
        $collectionCleanup->shouldReceive('deleteCollectionsAndDescendants')
            ->once()
            ->with([1, 2], 'Native removecrap descendant cleanup', false)
            ->andReturn(0);
        $this->app->instance(CollectionCleanupService::class, $collectionCleanup);

        $this->createNativeWorkerSideEffectsTableForRemoveCrapCleanup();
        $this->insertNativeRemoveCrapFileCleanupSideEffect(100, 'guid-native-removecrap-100');
        $this->insertNativeRemoveCrapFileCleanupSideEffect(200, 'guid-native-removecrap-200');

        $nzbService = Mockery::mock(NzbService::class);
        $nzbService->shouldReceive('deleteNzb')->once()->with('guid-native-removecrap-100')->andReturn(true);
        $nzbService->shouldReceive('deleteNzb')->once()->with('guid-native-removecrap-200')->andReturn(false);
        $this->app->instance(NzbService::class, $nzbService);

        $releaseImageService = Mockery::mock(ReleaseImageService::class);
        $releaseImageService->shouldReceive('delete')->once()->with('guid-native-removecrap-100');
        $releaseImageService->shouldReceive('delete')->once()->with('guid-native-removecrap-200');
        $this->app->instance(ReleaseImageService::class, $releaseImageService);

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            laneRunner: $laneRunner,
            plan: $this->removeCrapPlan(),
            job: 'removecrap',
        )->run(
            'removecrap',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native removecrap search deleted removecrap: seen=2 synced=2 failed=0', $workerOutput);
        $this->assertStringContainsString('native removecrap collection descendants cleaned removecrap: seen=2 deleted=0 failed=0', $workerOutput);
        $this->assertStringContainsString('native removecrap release files cleaned removecrap: seen=2 cleaned=2 failed=0', $workerOutput);
        $this->assertStringContainsString('native lane commit completed removecrap: writes=6', $workerOutput);
        $this->assertStringNotContainsString('php artisan releases:remove-crap', $workerOutput);
        $this->assertSame(2, DB::table('native_worker_side_effects')->where('effect', 'release-file-cleanup')->where('status', 'synced')->count());
    }

    public function test_enabled_native_lane_commit_runs_under_lock_and_skips_php_commands_for_per_group(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'per-group'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:per-group'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'multiprocessing:update-per-group'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeLaneCommitReport('per-group', 'per_group_write_commit', 5),
                errorOutput: '',
                exitCode: 0,
            ));

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            laneRunner: $laneRunner,
            plan: $this->perGroupPlan(),
            job: 'per-group',
        )->run(
            'per-group',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane commit completed per-group: writes=5', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:update-per-group', $workerOutput);
        $this->assertStringNotContainsString('native lane completed per-group', $workerOutput);
    }

    public function test_legacy_first_lane_commit_switch_does_not_enable_per_group_commit(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_first_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_commit_enabled' => false,
            'nntmux.native_worker_lane_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitLaneWrites');

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Artisan::shouldReceive('call')
            ->once()
            ->with('multiprocessing:update-per-group', [], Mockery::type(BufferedOutput::class))
            ->andReturn(0);

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            laneRunner: $laneRunner,
            plan: $this->perGroupPlan(),
            job: 'per-group',
        )->run(
            'per-group',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('php artisan multiprocessing:update-per-group', $workerOutput);
        $this->assertStringContainsString('completed per-group', $workerOutput);
        $this->assertStringNotContainsString('native lane commit completed per-group', $workerOutput);
    }

    public function test_enabled_native_lane_commit_accepts_shared_postprocess_commit_report_key(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'post-tv'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:post-tv'
                    && $nativePlan['commands'][0]['command'] === 'multiprocessing:postprocess'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeLaneCommitReport('post-tv', 'postprocess_write_commit', 2),
                errorOutput: '',
                exitCode: 0,
            ));

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            laneRunner: $laneRunner,
            plan: $this->postTvPlan(),
            job: 'post-tv',
        )->run(
            'post-tv',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native postprocess search synced post-tv: seen=2 synced=2 failed=0', $workerOutput);
        $this->assertStringContainsString('native lane commit completed post-tv: writes=2', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:postprocess', $workerOutput);
    }

    public function test_enabled_native_postprocess_lane_commit_fails_when_php_search_sync_fails(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeLaneCommitReport('post-tv', 'postprocess_write_commit', 2),
                errorOutput: '',
                exitCode: 0,
            ));

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Search::shouldReceive('updateRelease')->once()->with(1)->andThrow(new \RuntimeException('search backend leaked credentials'));
        Search::shouldReceive('updateRelease')->never()->with(2);

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            laneRunner: $laneRunner,
            plan: $this->postTvPlan(),
            job: 'post-tv',
        )->run(
            'post-tv',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('native postprocess search synced post-tv: seen=2 synced=0 failed=1', $workerOutput);
        $this->assertStringNotContainsString('credentials', $workerOutput);
        $this->assertStringNotContainsString('native lane commit completed post-tv', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:postprocess', $workerOutput);
    }

    public function test_enabled_native_postprocess_lane_commit_requires_committed_release_ids(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: json_encode([
                    'schema_version' => 1,
                    'mode' => 'shadow',
                    'dry_run' => false,
                    'native_worker' => [
                        'job' => 'post-tv',
                        'writes' => 2,
                    ],
                    'postprocess_write_commit' => [
                        'queue_entries' => 1,
                        'rolled_back' => false,
                        'writes_committed' => 2,
                    ],
                ], JSON_THROW_ON_ERROR),
                errorOutput: '',
                exitCode: 0,
            ));

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Search::shouldReceive('updateRelease')->never();

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            laneRunner: $laneRunner,
            plan: $this->postTvPlan(),
            job: 'post-tv',
        )->run(
            'post-tv',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('native lane commit failed for post-tv', $workerOutput);
        $this->assertStringContainsString('committed_release_ids must be an array', $workerOutput);
        $this->assertStringNotContainsString('native lane commit completed post-tv', $workerOutput);
    }

    public function test_enabled_native_lane_commit_runs_deferred_post_additional_commands_with_deferred_guard(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
            'nntmux.native_worker_post_additional_deferred_execution_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'post-additional'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:post-additional'
                    && $nativePlan['commands'][0]['command'] === 'multiprocessing:postprocess'
                    && $nativePlan['commands'][0]['arguments']['type'] === 'add'
                    && $nativePlan['commands'][1]['command'] === 'multiprocessing:postprocess'
                    && $nativePlan['commands'][1]['arguments']['type'] === 'nfo'
                    && $nativePlan['commands'][2]['command'] === 'predb:refresh-external-metadata'
                    && $nativePlan['commands'][3]['command'] === 'releases:fix-names'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeLaneCommitReport('post-additional', 'postprocess_write_commit', 2),
                errorOutput: '',
                exitCode: 0,
            ));

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        Search::shouldReceive('updateRelease')->once()->with(1);
        Search::shouldReceive('updateRelease')->once()->with(2);

        Artisan::shouldReceive('call')
            ->once()
            ->with('predb:refresh-external-metadata', ['--source' => ['all'], '--limit' => 7, '--sleep-ms' => 250], Mockery::type(BufferedOutput::class))
            ->andReturn(0);
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

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            laneRunner: $laneRunner,
            plan: $this->postAdditionalPlan(),
            job: 'post-additional',
        )->run(
            'post-additional',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native postprocess search synced post-additional: seen=2 synced=2 failed=0', $workerOutput);
        $this->assertStringContainsString('native lane commit completed post-additional: writes=2', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:postprocess', $workerOutput);
        $this->assertStringContainsString('php artisan predb:refresh-external-metadata', $workerOutput);
        $this->assertStringContainsString('php artisan releases:fix-names', $workerOutput);
    }

    public function test_enabled_native_lane_execution_runs_under_lock_and_skips_php_commands_for_per_group(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
            'nntmux.native_worker_search_outbox_limit' => 25,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'per-group'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:per-group'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'multiprocessing:update-per-group'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerLaneResult(
                successful: true,
                output: '{"native_lane":{"commands":5,"failed":0}}',
                errorOutput: '',
                exitCode: 0,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->perGroupPlan(),
            job: 'per-group',
        )->run(
            'per-group',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane completed per-group', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:update-per-group', $workerOutput);
    }

    public function test_native_per_group_lane_failure_redacts_native_failure_command_argv(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(Mockery::type('array'), Mockery::on(static fn (string $owner): bool => $owner !== ''))
            ->andReturn(new NativeWorkerLaneResult(
                successful: false,
                output: '{"native_lane":{"commands":5,"succeeded":1,"failed":1,"exit_code":1,"failures":["php artisan group:update-all 42"]}}',
                errorOutput: '',
                exitCode: 1,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->perGroupPlan(),
            job: 'per-group',
        )->run(
            'per-group',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('native lane failed for per-group', $workerOutput);
        $this->assertStringNotContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('group:update-all', $workerOutput);
        $this->assertStringNotContainsString('42', $workerOutput);
    }

    public function test_enabled_native_lane_execution_runs_under_lock_and_skips_php_commands_for_post_tv(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'post-tv'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:post-tv'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'multiprocessing:postprocess'
                    && $nativePlan['commands'][0]['arguments']['type'] === 'tv'
                    && $nativePlan['commands'][1]['command'] === 'multiprocessing:postprocess'
                    && $nativePlan['commands'][1]['arguments']['type'] === 'ani'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerLaneResult(
                successful: true,
                output: '{"native_lane":{"commands":3,"failed":0}}',
                errorOutput: '',
                exitCode: 0,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->postTvPlan(),
            job: 'post-tv',
        )->run(
            'post-tv',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane completed post-tv', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:postprocess', $workerOutput);
    }

    public function test_enabled_native_lane_execution_runs_under_lock_and_skips_php_commands_for_removecrap(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'removecrap'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:removecrap'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'releases:remove-crap'
                    && $nativePlan['commands'][0]['arguments']['--type'] === 'gibberish'
                    && $nativePlan['commands'][0]['arguments']['--delete'] === true
                    && $nativePlan['commands'][1]['command'] === 'releases:remove-crap'
                    && $nativePlan['commands'][1]['arguments']['--type'] === 'executable'
                    && $nativePlan['commands'][1]['arguments']['--delete'] === true),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerLaneResult(
                successful: true,
                output: '{"native_lane":{"commands":2,"failed":0}}',
                errorOutput: '',
                exitCode: 0,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->removeCrapPlan(),
            job: 'removecrap',
        )->run(
            'removecrap',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane completed removecrap', $workerOutput);
        $this->assertStringNotContainsString('php artisan releases:remove-crap', $workerOutput);
    }

    public function test_enabled_native_lane_execution_runs_under_lock_and_skips_php_commands_for_fixnames(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'fixnames'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:fixnames'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'releases:fix-names'
                    && $nativePlan['commands'][0]['arguments']['method'] === '3'
                    && $nativePlan['commands'][0]['arguments']['--category'] === 'other'
                    && $nativePlan['commands'][1]['command'] === 'releases:fix-names'
                    && $nativePlan['commands'][1]['arguments']['method'] === '6'
                    && $nativePlan['commands'][1]['arguments']['--category'] === 'movies'
                    && $nativePlan['commands'][1]['arguments']['--limit'] === '500'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerLaneResult(
                successful: true,
                output: '{"native_lane":{"commands":2,"failed":0}}',
                errorOutput: '',
                exitCode: 0,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->fixNamesPlan(),
            job: 'fixnames',
        )->run(
            'fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane completed fixnames', $workerOutput);
        $this->assertStringNotContainsString('php artisan releases:fix-names', $workerOutput);
    }

    public function test_enabled_native_lane_commit_runs_regular_fixnames_status_commit_and_outbox_sync(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
            'nntmux.native_worker_search_outbox_limit' => 25,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'fixnames'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:fixnames'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'releases:fix-names'
                    && $nativePlan['commands'][0]['arguments']['method'] === '15'
                    && $nativePlan['commands'][1]['arguments']['method'] === '19'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeLaneCommitReport('fixnames', 'fixnames_write_commit', 2),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25, 'fixnames')
            ->andReturn([
                'search_updates_seen' => 2,
                'search_updates_synced' => 2,
                'search_updates_failed' => 0,
                'failed_release_ids' => [],
            ]);

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');
        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            outboxSync: $outboxSync,
            laneRunner: $laneRunner,
            plan: $this->fixNamesCommitPlan(),
            job: 'fixnames',
        )->run(
            'fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native search outbox synced fixnames: seen=2 synced=2 failed=0', $workerOutput);
        $this->assertStringContainsString('native lane commit completed fixnames: writes=2', $workerOutput);
        $this->assertStringNotContainsString('php artisan releases:fix-names', $workerOutput);
        $this->assertStringNotContainsString('native lane completed fixnames', $workerOutput);
    }

    public function test_enabled_native_fixnames_commit_runs_unsupported_methods_in_php(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
            'nntmux.native_worker_search_outbox_limit' => 25,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'fixnames'
                    && count($nativePlan['commands']) === 4
                    && $nativePlan['commands'][0]['arguments']['method'] === '3'
                    && $nativePlan['commands'][1]['arguments']['method'] === '15'
                    && $nativePlan['commands'][2]['arguments']['method'] === '19'
                    && $nativePlan['commands'][3]['arguments']['method'] === '6'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeLaneCommitReport('fixnames', 'fixnames_write_commit', 2),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25, 'fixnames')
            ->ordered()
            ->andReturn([
                'search_updates_seen' => 2,
                'search_updates_synced' => 2,
                'search_updates_failed' => 0,
                'failed_release_ids' => [],
            ]);

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');
        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', [
                'method' => '3',
                '--update' => true,
                '--category' => 'other',
                '--set-status' => true,
                '--show' => true,
            ], Mockery::type(BufferedOutput::class))
            ->ordered()
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', [
                'method' => '6',
                '--update' => true,
                '--category' => 'movies',
                '--set-status' => true,
                '--limit' => '500',
                '--show' => true,
            ], Mockery::type(BufferedOutput::class))
            ->ordered()
            ->andReturn(0);

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            outboxSync: $outboxSync,
            laneRunner: $laneRunner,
            plan: $this->fixNamesMixedCommitPlan(),
            job: 'fixnames',
        )->run(
            'fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native search outbox synced fixnames: seen=2 synced=2 failed=0', $workerOutput);
        $this->assertStringContainsString('native lane commit completed fixnames: writes=2', $workerOutput);
        $this->assertStringContainsString('php artisan releases:fix-names 3 --update --category=other --set-status --show', $workerOutput);
        $this->assertStringContainsString('php artisan releases:fix-names 6 --update --category=movies --set-status --limit=500 --show', $workerOutput);
        $this->assertStringNotContainsString('php artisan releases:fix-names 15', $workerOutput);
        $this->assertStringNotContainsString('php artisan releases:fix-names 19', $workerOutput);
        $this->assertStringContainsString('completed fixnames', $workerOutput);
        $this->assertLessThan(
            strpos($workerOutput, 'php artisan releases:fix-names 3'),
            strpos($workerOutput, 'native search outbox synced fixnames'),
        );
    }

    public function test_enabled_native_lane_commit_runs_metadata_refresh_predb_outbox_sync(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
            'nntmux.native_worker_search_outbox_limit' => 25,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'metadata-refresh'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:metadata-refresh'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeLaneCommitReport('metadata-refresh', 'metadata_refresh_write_commit', 12),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25, 'metadata-refresh')
            ->andReturn([
                'search_updates_seen' => 5,
                'search_updates_synced' => 5,
                'search_updates_failed' => 0,
                'failed_release_ids' => [],
                'failed_predb_ids' => [],
            ]);

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');
        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            outboxSync: $outboxSync,
            laneRunner: $laneRunner,
            plan: $this->metadataRefreshPlan(),
            job: 'metadata-refresh',
        )->run(
            'metadata-refresh',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native search outbox synced metadata-refresh: seen=5 synced=5 failed=0', $workerOutput);
        $this->assertStringContainsString('native lane commit completed metadata-refresh: writes=12', $workerOutput);
        $this->assertStringNotContainsString('php artisan predb:refresh-external-metadata', $workerOutput);
        $this->assertStringNotContainsString('native lane completed metadata-refresh', $workerOutput);
    }

    public function test_enabled_native_metadata_refresh_commit_runs_embedded_hashed_fixnames_in_php(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_lane_commit_enabled' => true,
            'nntmux.native_worker_lane_execution_enabled' => false,
            'nntmux.native_worker_search_outbox_limit' => 25,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitLaneWrites')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'metadata-refresh'
                    && count($nativePlan['commands']) === 3
                    && $nativePlan['commands'][0]['command'] === 'predb:refresh-external-metadata'
                    && $nativePlan['commands'][1]['command'] === 'releases:fix-names'
                    && $nativePlan['commands'][2]['command'] === 'releases:fix-names'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeLaneCommitReport('metadata-refresh', 'metadata_refresh_write_commit', 12),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25, 'metadata-refresh')
            ->ordered()
            ->andReturn([
                'search_updates_seen' => 5,
                'search_updates_synced' => 5,
                'search_updates_failed' => 0,
                'failed_release_ids' => [],
                'failed_predb_ids' => [],
            ]);

        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');
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
            ->ordered()
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
            ->ordered()
            ->andReturn(0);

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            commitRunner: $commitRunner,
            outboxSync: $outboxSync,
            laneRunner: $laneRunner,
            plan: $this->metadataRefreshPlanWithHashedCommands(),
            job: 'metadata-refresh',
        )->run(
            'metadata-refresh',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native search outbox synced metadata-refresh: seen=5 synced=5 failed=0', $workerOutput);
        $this->assertStringContainsString('native lane commit completed metadata-refresh: writes=12', $workerOutput);
        $this->assertStringContainsString('php artisan releases:fix-names 20 --update --category=hashed --set-status --limit=500 --show', $workerOutput);
        $this->assertStringContainsString('php artisan releases:fix-names 16 --update --category=hashed --set-status --limit=500 --show', $workerOutput);
        $this->assertStringContainsString('completed metadata-refresh', $workerOutput);
        $this->assertStringNotContainsString('php artisan predb:refresh-external-metadata', $workerOutput);
        $this->assertLessThan(
            strpos($workerOutput, 'php artisan releases:fix-names 20'),
            strpos($workerOutput, 'native search outbox synced metadata-refresh'),
        );
    }

    public function test_enabled_native_lane_execution_runs_under_lock_and_skips_php_commands_for_irc(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
            'nntmux.native_worker_search_outbox_limit' => 25,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'irc'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:irc'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'irc:scrape'
                    && $nativePlan['commands'][0]['arguments'] === []),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerLaneResult(
                successful: true,
                output: '{"native_lane":{"commands":1,"failed":0}}',
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25, 'irc')
            ->ordered()
            ->andReturn([
                'search_updates_seen' => 2,
                'search_updates_synced' => 2,
                'search_updates_failed' => 0,
                'failed_predb_ids' => [],
            ]);

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            outboxSync: $outboxSync,
            laneRunner: $laneRunner,
            plan: $this->ircPlan(),
            job: 'irc',
        )->run(
            'irc',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native search outbox synced irc: seen=2 synced=2 failed=0', $workerOutput);
        $this->assertStringContainsString('native lane completed irc', $workerOutput);
        $this->assertStringNotContainsString('php artisan irc:scrape', $workerOutput);
    }

    public function test_enabled_native_lane_execution_keeps_post_additional_in_php_without_deferred_guard(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
            'nntmux.native_worker_post_additional_deferred_execution_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldNotReceive('runLane');

        foreach ($this->postAdditionalPlan()['commands'] as $command) {
            Artisan::shouldReceive('call')
                ->once()
                ->with($command['command'], $command['arguments'], Mockery::type(BufferedOutput::class))
                ->andReturn(0);
        }

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->postAdditionalPlan(),
            job: 'post-additional',
        )->run(
            'post-additional',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('completed post-additional', $workerOutput);
        $this->assertStringNotContainsString('native lane', $workerOutput);
    }

    public function test_enabled_native_lane_execution_runs_under_lock_and_runs_deferred_post_additional_commands_with_deferred_guard(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
            'nntmux.native_worker_post_additional_deferred_execution_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'post-additional'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:post-additional'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'multiprocessing:postprocess'
                    && $nativePlan['commands'][0]['arguments']['type'] === 'add'
                    && $nativePlan['commands'][1]['command'] === 'multiprocessing:postprocess'
                    && $nativePlan['commands'][1]['arguments']['type'] === 'nfo'
                    && $nativePlan['commands'][2]['command'] === 'predb:refresh-external-metadata'
                    && $nativePlan['commands'][3]['command'] === 'releases:fix-names'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerLaneResult(
                successful: true,
                output: '{"native_lane":{"commands":4,"failed":0}}',
                errorOutput: '',
                exitCode: 0,
            ));

        Artisan::shouldReceive('call')
            ->once()
            ->with('predb:refresh-external-metadata', ['--source' => ['all'], '--limit' => 7, '--sleep-ms' => 250], Mockery::type(BufferedOutput::class))
            ->andReturn(0);
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

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->postAdditionalPlan(),
            job: 'post-additional',
        )->run(
            'post-additional',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane completed post-additional', $workerOutput);
        $this->assertStringNotContainsString('php artisan multiprocessing:postprocess', $workerOutput);
        $this->assertStringContainsString('php artisan predb:refresh-external-metadata', $workerOutput);
        $this->assertStringContainsString('php artisan releases:fix-names', $workerOutput);
    }

    public function test_native_lane_execution_failure_stops_without_php_fallback(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(Mockery::type('array'), Mockery::on(static fn (string $owner): bool => $owner !== ''))
            ->andReturn(new NativeWorkerLaneResult(
                successful: false,
                output: '',
                errorOutput: 'failed --mysql-dsn nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                exitCode: 17,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->binariesPlan(),
            job: 'binaries',
        )->run(
            'binaries',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(17, $exitCode);
        $this->assertStringContainsString('native lane failed for binaries', $workerOutput);
        $this->assertStringNotContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('nntmux:nntmux', $workerOutput);
    }

    public function test_native_lane_execution_failure_redacts_native_failure_command_argv(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(Mockery::type('array'), Mockery::on(static fn (string $owner): bool => $owner !== ''))
            ->andReturn(new NativeWorkerLaneResult(
                successful: false,
                output: '{"native_lane":{"commands":3,"succeeded":1,"failed":1,"exit_code":1,"failures":["php artisan articles:get-range binaries alt.binaries.private 1001 11000"]}}',
                errorOutput: '',
                exitCode: 1,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
            plan: $this->binariesPlan(),
            job: 'binaries',
        )->run(
            'binaries',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('native lane failed for binaries', $workerOutput);
        $this->assertStringNotContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('articles:get-range', $workerOutput);
        $this->assertStringNotContainsString('alt.binaries.private', $workerOutput);
        $this->assertStringNotContainsString('1001', $workerOutput);
    }

    public function test_enabled_native_lane_execution_runs_under_lock_and_skips_php_commands_for_metadata_refresh(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'metadata-refresh'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:metadata-refresh'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'native:test'
                    && $nativePlan['commands'][0]['arguments']['--limit'] === 5),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerLaneResult(
                successful: true,
                output: '{"native_lane":{"commands":1,"failed":0}}',
                errorOutput: '',
                exitCode: 0,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            shadowRunner: $shadowRunner,
            laneRunner: $laneRunner,
        )->run(
            'metadata-refresh',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane completed metadata-refresh', $workerOutput);
        $this->assertStringNotContainsString('php artisan native:test', $workerOutput);
    }

    public function test_enabled_native_lane_execution_runs_under_lock_and_skips_php_commands_for_hashed_fixnames(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_lane_execution_enabled' => true,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');
        $renamePrepassRunner = Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class);
        $renamePrepassRunner->shouldNotReceive('apply');
        $laneRunner = Mockery::mock(NativeWorkerLaneRunner::class);
        $laneRunner->shouldReceive('runLane')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'hashed-fixnames'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:hashed-fixnames'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['command'] === 'releases:fix-names'
                    && $nativePlan['commands'][0]['arguments']['method'] === '20'
                    && $nativePlan['commands'][0]['arguments']['--category'] === 'hashed'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->ordered()
            ->andReturn(new NativeWorkerLaneResult(
                successful: true,
                output: '{"native_lane":{"commands":1,"failed":0}}',
                errorOutput: '',
                exitCode: 0,
            ));

        Artisan::shouldReceive('call')->never();

        $exitCode = $this->worker(
            $shadowRunner,
            $commitRunner,
            $outboxSync,
            $renamePrepassRunner,
            $this->hashedPlan(),
            'hashed-fixnames',
            $laneRunner,
        )->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native lane completed hashed-fixnames', $workerOutput);
        $this->assertStringNotContainsString('php artisan releases:fix-names', $workerOutput);
    }

    public function test_native_hashed_fixnames_commit_prepass_is_disabled_by_default(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');
        $renamePrepassRunner = Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class);
        $renamePrepassRunner->shouldNotReceive('apply');

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(0);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, $renamePrepassRunner, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('native miss-status prepass', $output->fetch());
    }

    public function test_native_hashed_fixnames_rename_prepass_is_disabled_by_default(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => false,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');
        $renamePrepassRunner = Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class);
        $renamePrepassRunner->shouldNotReceive('apply');

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(0);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, $renamePrepassRunner, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('native rename prepass', $output->fetch());
    }

    public function test_enabled_native_hashed_fixnames_rename_prepass_runs_after_miss_status_and_before_php_commands(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        $renamePrepassRunner = Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class);
        $renamePrepassRunner->shouldReceive('apply')
            ->once()
            ->with(Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'hashed-fixnames'
                && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:hashed-fixnames'
                && $nativePlan['lock']['seconds'] === 42
                && $nativePlan['commands'][0]['arguments']['method'] === '20'))
            ->ordered()
            ->andReturn(new NativeHashedFixNameRenamePrepassResult(
                successful: true,
                releaseUpdatesSeen: 2,
                releaseUpdatesApplied: 2,
                releaseIds: [100, 300],
            ));

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->ordered()
            ->andReturn(0);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, $renamePrepassRunner, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native rename prepass applied hashed-fixnames: seen=2 applied=2 release_ids=100,300', $workerOutput);
        $this->assertLessThan(
            strpos($workerOutput, 'php artisan releases:fix-names'),
            strpos($workerOutput, 'native rename prepass applied hashed-fixnames'),
        );
    }

    public function test_native_hashed_fixnames_rename_prepass_failure_is_fail_open_and_redacted(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => true,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        $renamePrepassRunner = Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class);
        $renamePrepassRunner->shouldReceive('apply')
            ->once()
            ->andReturn(new NativeHashedFixNameRenamePrepassResult(
                successful: false,
                errorOutput: 'dsn=nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true redis=redis:6379',
                exitCode: 1,
            ));

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(23);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, $renamePrepassRunner, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(23, $exitCode);
        $this->assertStringContainsString('native rename prepass failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('nntmux:nntmux', $workerOutput);
        $this->assertStringNotContainsString('redis:6379', $workerOutput);
    }

    public function test_native_hashed_fixnames_rename_prepass_failure_redacts_structured_native_output(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => true,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        $renamePrepassRunner = Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class);
        $renamePrepassRunner->shouldReceive('apply')
            ->once()
            ->andReturn(new NativeHashedFixNameRenamePrepassResult(
                successful: false,
                errorOutput: implode(' ', [
                    '--mysql-dsn nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                    '--redis-addr redis:6379',
                    '--lock-owner native-lock-owner-secret',
                    '"redis_key":"nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames"',
                    '"old_name":"Hash.Target.CRC.PreDB"',
                    '"new_name":"Predb.Match.2026.1080p.BluRay.x264-GRP"',
                    '"filename":"secret.release.sample.mkv"',
                    '"arguments":{"--rename":true}',
                ]),
                exitCode: 1,
            ));

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(23);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, $renamePrepassRunner, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(23, $exitCode);
        $this->assertStringContainsString('native rename prepass failed for hashed-fixnames', $workerOutput);
        foreach ([
            'nntmux:nntmux',
            'redis:6379',
            'native-lock-owner-secret',
            'nntmux_database',
            '--mysql-dsn nntmux',
            '--redis-addr redis',
            '--lock-owner native',
            'Hash.Target',
            'Predb.Match',
            'secret.release.sample.mkv',
            '"arguments"',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workerOutput);
        }
    }

    public function test_native_hashed_fixnames_rename_prepass_failure_can_fail_closed(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => true,
            'nntmux.native_worker_hashed_fixnames_fail_closed_enabled' => true,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        $renamePrepassRunner = Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class);
        $renamePrepassRunner->shouldReceive('apply')
            ->once()
            ->andReturn(new NativeHashedFixNameRenamePrepassResult(
                successful: false,
                errorOutput: 'dsn=nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true redis=redis:6379',
                exitCode: 1,
            ));

        $artisanCalled = false;
        Artisan::shouldReceive('call')
            ->andReturnUsing(static function () use (&$artisanCalled): int {
                $artisanCalled = true;

                return 99;
            });

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, $renamePrepassRunner, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($artisanCalled);
        $this->assertStringContainsString('native rename prepass failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('stopping PHP worker', $workerOutput);
        $this->assertStringNotContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('nntmux:nntmux', $workerOutput);
        $this->assertStringNotContainsString('redis:6379', $workerOutput);
    }

    public function test_native_hashed_fixnames_fail_closed_stops_long_running_worker(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_rename_prepass_enabled' => true,
            'nntmux.native_worker_hashed_fixnames_fail_closed_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        $renamePrepassRunner = Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class);
        $renamePrepassRunner->shouldReceive('apply')
            ->once()
            ->andReturn(new NativeHashedFixNameRenamePrepassResult(
                successful: false,
                errorOutput: 'native rename failed',
                exitCode: 1,
            ));

        $artisanCalled = false;
        Artisan::shouldReceive('call')
            ->andReturnUsing(static function () use (&$artisanCalled): int {
                $artisanCalled = true;

                return 99;
            });

        $exitCode = $this->worker(
            $shadowRunner,
            $commitRunner,
            $outboxSync,
            $renamePrepassRunner,
            array_replace($this->hashedPlan(), ['sleep' => 1]),
            'hashed-fixnames',
        )->run(
            'hashed-fixnames',
            once: false,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($artisanCalled);
        $this->assertStringContainsString('native rename prepass failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('stopping PHP worker', $workerOutput);
        $this->assertStringNotContainsString('sleeping for', $workerOutput);
    }

    public function test_enabled_native_hashed_fixnames_commit_prepass_runs_under_held_lock_before_php_commands(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_search_outbox_limit' => 25,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->with(
                Mockery::on(static fn (array $nativePlan): bool => $nativePlan['job']['name'] === 'hashed-fixnames'
                    && $nativePlan['lock']['name'] === 'nntmux:distributed-worker:hashed-fixnames'
                    && $nativePlan['lock']['seconds'] === 42
                    && $nativePlan['commands'][0]['arguments']['method'] === '20'),
                Mockery::on(static fn (string $owner): bool => $owner !== ''),
            )
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeCommitReport(),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25)
            ->andReturn([
                'search_updates_seen' => 2,
                'search_updates_synced' => 2,
                'search_updates_failed' => 0,
                'failed_release_ids' => [],
            ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(0);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native miss-status prepass committed hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('native search outbox synced hashed-fixnames: seen=2 synced=2 failed=0', $workerOutput);
        $this->assertLessThan(
            strpos($workerOutput, 'php artisan releases:fix-names'),
            strpos($workerOutput, 'native miss-status prepass committed hashed-fixnames'),
        );
    }

    public function test_native_hashed_fixnames_commit_prepass_validates_successful_native_report_before_outbox_sync(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeCommitReport([
                    'dry_run' => true,
                    'native_worker' => [
                        'job' => 'metadata-refresh',
                        'writes' => 2,
                    ],
                ]),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(0);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native miss-status prepass failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('invalid native commit report', $workerOutput);
        $this->assertStringContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('metadata-refresh', $workerOutput);
    }

    public function test_native_hashed_fixnames_invalid_commit_report_can_fail_closed_before_outbox_sync(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_hashed_fixnames_fail_closed_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeCommitReport([
                    'dry_run' => true,
                    'native_worker' => [
                        'job' => 'metadata-refresh',
                        'writes' => 2,
                    ],
                ]),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');
        $artisanCalled = false;
        Artisan::shouldReceive('call')
            ->andReturnUsing(static function () use (&$artisanCalled): int {
                $artisanCalled = true;

                return 99;
            });

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($artisanCalled);
        $this->assertStringContainsString('native miss-status prepass failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('invalid native commit report', $workerOutput);
        $this->assertStringContainsString('stopping PHP worker', $workerOutput);
        $this->assertStringNotContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('metadata-refresh', $workerOutput);
    }

    public function test_native_hashed_fixnames_commit_prepass_is_lane_scoped(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');
        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldNotReceive('commitMissStatus');
        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        Artisan::shouldReceive('call')
            ->once()
            ->with('native:test', ['--limit' => 5], Mockery::type(BufferedOutput::class))
            ->andReturn(0);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync)->run(
            'metadata-refresh',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('native miss-status prepass', $output->fetch());
    }

    public function test_native_hashed_fixnames_commit_prepass_failure_is_fail_open(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: false,
                output: '',
                errorOutput: 'dsn=nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true redis=redis:6379',
                exitCode: 1,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(23);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(23, $exitCode);
        $this->assertStringContainsString('native miss-status prepass failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('nntmux:nntmux', $workerOutput);
        $this->assertStringNotContainsString('redis:6379', $workerOutput);
    }

    public function test_native_hashed_fixnames_commit_prepass_failure_redacts_structured_native_output(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: false,
                output: '',
                errorOutput: implode(' ', [
                    '--mysql-dsn nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                    '--redis-addr redis:6379',
                    '--lock-owner native-lock-owner-secret',
                    '"redis_key":"nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames"',
                    '"lock_owner":"native-lock-owner-secret"',
                    '"old_name":"Hash.Target.CRC.PreDB"',
                    '"new_name":"Predb.Match.2026.1080p.BluRay.x264-GRP"',
                    '"filename":"secret.release.sample.mkv"',
                    '"arguments":{"--set-status":true}',
                ]),
                exitCode: 1,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(23);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(23, $exitCode);
        $this->assertStringContainsString('native miss-status prepass failed for hashed-fixnames', $workerOutput);
        foreach ([
            'nntmux:nntmux',
            'redis:6379',
            'native-lock-owner-secret',
            'nntmux_database',
            '--mysql-dsn nntmux',
            '--redis-addr redis',
            '--lock-owner native',
            'Hash.Target',
            'Predb.Match',
            'secret.release.sample.mkv',
            '"arguments"',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workerOutput);
        }
    }

    public function test_native_hashed_fixnames_commit_prepass_failure_can_fail_closed(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_hashed_fixnames_fail_closed_enabled' => true,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: false,
                output: '',
                errorOutput: 'dsn=nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true redis=redis:6379',
                exitCode: 1,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldNotReceive('syncPending');
        $artisanCalled = false;
        Artisan::shouldReceive('call')
            ->andReturnUsing(static function () use (&$artisanCalled): int {
                $artisanCalled = true;

                return 99;
            });

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($artisanCalled);
        $this->assertStringContainsString('native miss-status prepass failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('stopping PHP worker', $workerOutput);
        $this->assertStringNotContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('nntmux:nntmux', $workerOutput);
        $this->assertStringNotContainsString('redis:6379', $workerOutput);
    }

    public function test_native_hashed_fixnames_outbox_failure_is_fail_open(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_search_outbox_limit' => 25,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeCommitReport(),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25)
            ->andReturn([
                'search_updates_seen' => 2,
                'search_updates_synced' => 1,
                'search_updates_failed' => 1,
                'failed_release_ids' => [102],
            ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(0);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('native search outbox synced hashed-fixnames: seen=2 synced=1 failed=1', $workerOutput);
        $this->assertStringContainsString('continuing with PHP worker', $workerOutput);
    }

    public function test_native_hashed_fixnames_outbox_exception_is_fail_open(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_search_outbox_limit' => 25,
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeCommitReport(),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25)
            ->andThrow(new \RuntimeException('redis:6379 unavailable'));

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(23);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(23, $exitCode);
        $this->assertStringContainsString('native search outbox sync failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('stopping PHP worker', $workerOutput);
        $this->assertStringNotContainsString('redis:6379', $workerOutput);
    }

    public function test_native_hashed_fixnames_outbox_exception_redacts_structured_native_output(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_search_outbox_limit' => 25,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeCommitReport(),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25)
            ->andThrow(new \RuntimeException(implode(' ', [
                'dsn nntmux:nnt',
                '--mysql-dsn nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                '--redis-addr redis:6379',
                '"address":"redis:1234"',
                '"release_name":"Secret.Release"',
                '"redis_key":"nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames"',
                '"filename":"secret.release.sample.mkv"',
                '"arguments":{"--sync":true}',
            ])));

        Artisan::shouldReceive('call')
            ->once()
            ->with('releases:fix-names', $this->hashedPlan()['commands'][0]['arguments'], Mockery::type(BufferedOutput::class))
            ->andReturn(23);

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(23, $exitCode);
        $this->assertStringContainsString('native search outbox sync failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('continuing with PHP worker', $workerOutput);
        foreach ([
            'nntmux:nnt',
            'redis:6379',
            'redis:1234',
            'Secret.Release',
            'nntmux_database',
            '--mysql-dsn nntmux',
            '--redis-addr redis',
            'secret.release.sample.mkv',
            '"address"',
            '"release_name"',
            '"arguments"',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workerOutput);
        }
    }

    public function test_native_hashed_fixnames_outbox_failure_can_fail_closed(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_hashed_fixnames_fail_closed_enabled' => true,
            'nntmux.native_worker_search_outbox_limit' => 25,
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeCommitReport(),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25)
            ->andReturn([
                'search_updates_seen' => 2,
                'search_updates_synced' => 1,
                'search_updates_failed' => 1,
                'search_updates_dead_lettered' => 1,
                'failed_release_ids' => [102],
                'dead_lettered_release_ids' => [301],
            ]);

        $artisanCalled = false;
        Artisan::shouldReceive('call')
            ->andReturnUsing(static function () use (&$artisanCalled): int {
                $artisanCalled = true;

                return 99;
            });

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($artisanCalled);
        $this->assertStringContainsString('native search outbox synced hashed-fixnames: seen=2 synced=1 failed=1 dead-lettered=1', $workerOutput);
        $this->assertStringContainsString('stopping PHP worker', $workerOutput);
        $this->assertStringNotContainsString('continuing with PHP worker', $workerOutput);
    }

    public function test_native_hashed_fixnames_outbox_exception_can_fail_closed(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.native_worker_shadow_enabled' => false,
            'nntmux.native_worker_hashed_fixnames_commit_enabled' => true,
            'nntmux.native_worker_hashed_fixnames_fail_closed_enabled' => true,
            'nntmux.native_worker_search_outbox_limit' => 25,
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $output = new BufferedOutput;
        $shadowRunner = Mockery::mock(NativeWorkerShadowRunner::class);
        $shadowRunner->shouldNotReceive('dryRun');

        $commitRunner = Mockery::mock(NativeWorkerCommitRunner::class);
        $commitRunner->shouldReceive('commitMissStatus')
            ->once()
            ->andReturn(new NativeWorkerCommitResult(
                successful: true,
                output: $this->nativeCommitReport(),
                errorOutput: '',
                exitCode: 0,
            ));

        $outboxSync = Mockery::mock(NativeSearchSideEffectOutboxSync::class);
        $outboxSync->shouldReceive('syncPending')
            ->once()
            ->with(25)
            ->andThrow(new \RuntimeException('redis:6379 unavailable'));

        $artisanCalled = false;
        Artisan::shouldReceive('call')
            ->andReturnUsing(static function () use (&$artisanCalled): int {
                $artisanCalled = true;

                return 99;
            });

        $exitCode = $this->worker($shadowRunner, $commitRunner, $outboxSync, null, $this->hashedPlan(), 'hashed-fixnames')->run(
            'hashed-fixnames',
            once: true,
            sleepOverride: null,
            lockSeconds: 42,
            output: $output,
        );

        $workerOutput = $output->fetch();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($artisanCalled);
        $this->assertStringContainsString('native search outbox sync failed for hashed-fixnames', $workerOutput);
        $this->assertStringContainsString('stopping PHP worker', $workerOutput);
        $this->assertStringNotContainsString('continuing with PHP worker', $workerOutput);
        $this->assertStringNotContainsString('redis:6379', $workerOutput);
    }

    private function worker(
        NativeWorkerShadowRunner $shadowRunner,
        ?NativeWorkerCommitRunner $commitRunner = null,
        ?NativeSearchSideEffectOutboxSync $outboxSync = null,
        ?NativeHashedFixNameRenamePrepassRunner $renamePrepassRunner = null,
        ?array $plan = null,
        string $job = 'metadata-refresh',
        ?NativeWorkerLaneRunner $laneRunner = null,
    ): DistributedJobWorker {
        $catalog = Mockery::mock(DistributedJobCatalog::class);
        $catalog->shouldReceive('resolve')
            ->once()
            ->with($job, ['run' => 'var'])
            ->andReturn($plan ?? $this->plan());

        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once();
        $monitor->shouldReceive('collectStatistics')->once()->andReturn(['run' => 'var']);

        return new DistributedJobWorker(
            $catalog,
            $monitor,
            new NativeWorkerPlanExporter,
            $shadowRunner,
            $commitRunner ?? Mockery::mock(NativeWorkerCommitRunner::class),
            $outboxSync ?? Mockery::mock(NativeSearchSideEffectOutboxSync::class),
            $renamePrepassRunner ?? Mockery::mock(NativeHashedFixNameRenamePrepassRunner::class),
            $laneRunner ?? Mockery::mock(NativeWorkerLaneRunner::class),
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
    private function plan(): array
    {
        return [
            'name' => 'metadata-refresh',
            'description' => 'Refresh external release-name evidence and run strong fix-name passes',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'native:test',
                    'arguments' => ['--limit' => 5],
                ],
            ],
            'sleep' => 900,
        ];
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
    private function metadataRefreshPlan(): array
    {
        return [
            'name' => 'metadata-refresh',
            'description' => 'Refresh external release-name evidence and run strong hashed fix-name passes',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'predb:refresh-external-metadata',
                    'arguments' => ['--source' => ['all'], '--limit' => 25, '--sleep-ms' => 250],
                ],
            ],
            'sleep' => 900,
        ];
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
    private function metadataRefreshPlanWithHashedCommands(): array
    {
        $plan = $this->metadataRefreshPlan();
        foreach (['20', '16'] as $method) {
            $plan['commands'][] = [
                'command' => 'releases:fix-names',
                'arguments' => [
                    'method' => $method,
                    '--update' => true,
                    '--category' => 'hashed',
                    '--set-status' => true,
                    '--limit' => 500,
                    '--show' => true,
                ],
            ];
        }

        return $plan;
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
    private function hashedPlan(): array
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
            ],
            'sleep' => 300,
        ];
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
    private function binariesPlan(): array
    {
        return [
            'name' => 'binaries',
            'description' => 'Download new headers for active groups',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'multiprocessing:safe',
                    'arguments' => ['type' => 'binaries'],
                ],
            ],
            'sleep' => 60,
        ];
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
    private function releasesPlan(): array
    {
        return [
            'name' => 'releases',
            'description' => 'Create and categorize releases from complete collections',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'multiprocessing:releases',
                    'arguments' => [],
                ],
            ],
            'sleep' => 300,
        ];
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
    private function perGroupPlan(): array
    {
        return [
            'name' => 'per-group',
            'description' => 'Run the per-group all-in-one processing worker',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'multiprocessing:update-per-group',
                    'arguments' => [],
                ],
            ],
            'sleep' => 300,
        ];
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
    private function postTvPlan(): array
    {
        return [
            'name' => 'post-tv',
            'description' => 'Run TV and anime post-processing',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'multiprocessing:postprocess',
                    'arguments' => ['type' => 'tv', 'renamed' => false],
                ],
                [
                    'command' => 'multiprocessing:postprocess',
                    'arguments' => ['type' => 'ani', 'renamed' => false],
                ],
            ],
            'sleep' => 300,
        ];
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
    private function removeCrapPlan(): array
    {
        return [
            'name' => 'removecrap',
            'description' => 'Remove configured unwanted releases',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'releases:remove-crap',
                    'arguments' => ['--type' => 'gibberish', '--time' => '4', '--delete' => true],
                ],
                [
                    'command' => 'releases:remove-crap',
                    'arguments' => ['--type' => 'executable', '--time' => '4', '--delete' => true],
                ],
            ],
            'sleep' => 300,
        ];
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
    private function postAdditionalPlan(): array
    {
        return [
            'name' => 'post-additional',
            'description' => 'Run additional and/or NFO post-processing',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'multiprocessing:postprocess',
                    'arguments' => ['type' => 'add'],
                ],
                [
                    'command' => 'multiprocessing:postprocess',
                    'arguments' => ['type' => 'nfo'],
                ],
                [
                    'command' => 'predb:refresh-external-metadata',
                    'arguments' => ['--source' => ['all'], '--limit' => 7, '--sleep-ms' => 250],
                ],
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '20',
                        '--update' => true,
                        '--category' => 'hashed',
                        '--set-status' => true,
                        '--limit' => 500,
                        '--show' => true,
                    ],
                ],
            ],
            'sleep' => 300,
        ];
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
    private function fixNamesPlan(): array
    {
        return [
            'name' => 'fixnames',
            'description' => 'Run release name fixing passes',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '3',
                        '--update' => true,
                        '--category' => 'other',
                        '--set-status' => true,
                        '--show' => true,
                    ],
                ],
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '6',
                        '--update' => true,
                        '--category' => 'movies',
                        '--set-status' => true,
                        '--limit' => '500',
                        '--show' => true,
                    ],
                ],
            ],
            'sleep' => 300,
        ];
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
    private function fixNamesCommitPlan(): array
    {
        return [
            'name' => 'fixnames',
            'description' => 'Run release name fixing passes',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '15',
                        '--update' => true,
                        '--category' => 'other',
                        '--set-status' => true,
                        '--show' => true,
                    ],
                ],
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '19',
                        '--update' => true,
                        '--category' => 'other',
                        '--set-status' => true,
                        '--show' => true,
                    ],
                ],
            ],
            'sleep' => 300,
        ];
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
    private function fixNamesMixedCommitPlan(): array
    {
        return [
            'name' => 'fixnames',
            'description' => 'Run release name fixing passes',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '3',
                        '--update' => true,
                        '--category' => 'other',
                        '--set-status' => true,
                        '--show' => true,
                    ],
                ],
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '15',
                        '--update' => true,
                        '--category' => 'other',
                        '--set-status' => true,
                        '--show' => true,
                    ],
                ],
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '19',
                        '--update' => true,
                        '--category' => 'other',
                        '--set-status' => true,
                        '--show' => true,
                    ],
                ],
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '6',
                        '--update' => true,
                        '--category' => 'movies',
                        '--set-status' => true,
                        '--limit' => '500',
                        '--show' => true,
                    ],
                ],
            ],
            'sleep' => 300,
        ];
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
    private function ircPlan(): array
    {
        return [
            'name' => 'irc',
            'description' => 'Run the IRC scraper',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [
                [
                    'command' => 'irc:scrape',
                    'arguments' => [],
                ],
            ],
            'sleep' => 300,
        ];
    }

    private function createNativeWorkerSideEffectsTableForRemoveCrapCleanup(): void
    {
        Schema::dropIfExists('native_worker_side_effects');
        Schema::create('native_worker_side_effects', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key')->unique();
            $table->string('job', 64);
            $table->string('effect', 64);
            $table->unsignedBigInteger('release_id');
            $table->string('status_column', 32);
            $table->string('status_reason', 64);
            $table->unsignedTinyInteger('status_value');
            $table->string('payload_text', 255)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
        });
    }

    private function insertNativeRemoveCrapFileCleanupSideEffect(int $releaseId, string $guid): void
    {
        DB::table('native_worker_side_effects')->insert([
            'operation_key' => "removecrap:release-file-cleanup:v1:{$releaseId}",
            'job' => 'removecrap',
            'effect' => 'release-file-cleanup',
            'release_id' => $releaseId,
            'status_column' => 'release_guid',
            'status_reason' => 'delete-release-files',
            'status_value' => 1,
            'payload_text' => $guid,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function nativeCommitReport(array $overrides = []): string
    {
        return json_encode(array_replace_recursive([
            'schema_version' => 1,
            'mode' => 'shadow',
            'dry_run' => false,
            'native_worker' => [
                'job' => 'hashed-fixnames',
                'writes' => 2,
            ],
            'hashed_fixnames' => [
                'write_commit' => [
                    'single_column_updates_committed' => 2,
                    'single_column_rows_affected' => 2,
                    'committed_release_ids' => [102, 301],
                    'lock_acquired' => true,
                    'lock_mode' => 'held',
                    'writes_committed' => 2,
                ],
            ],
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    private function nativeFirstLaneCommitReport(string $job, int $writes = 3): string
    {
        return $this->nativeLaneCommitReport($job, $job.'_write_commit', $writes);
    }

    private function nativeLaneCommitReport(string $job, string $commitKey, int $writes): string
    {
        $writeCommit = [
            'queue_entries' => 1,
            'rolled_back' => false,
            'writes_committed' => $writes,
        ];
        if ($commitKey === 'postprocess_write_commit') {
            $writeCommit['committed_release_ids'] = range(1, $writes);
        }
        if ($commitKey === 'fixnames_write_commit') {
            $writeCommit['single_column_updates_committed'] = $writes;
            $writeCommit['single_column_rows_affected'] = $writes;
            $writeCommit['search_updates_enqueued'] = $writes;
            $writeCommit['committed_release_ids'] = range(1, $writes);
        }
        if ($commitKey === 'releases_write_commit') {
            $releaseRows = intdiv($writes, 2);
            $writeCommit['release_rows_affected'] = $releaseRows;
            $writeCommit['committed_release_ids'] = range(1, $releaseRows);
        }

        return json_encode([
            'schema_version' => 1,
            'mode' => 'shadow',
            'dry_run' => false,
            'native_worker' => [
                'job' => $job,
                'writes' => $writes,
            ],
            $commitKey => $writeCommit,
        ], JSON_THROW_ON_ERROR);
    }
}
