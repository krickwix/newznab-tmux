<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\BackfillPermitGate;
use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class DistributedJobWorkerTest extends TestCase
{
    public function test_it_formats_array_valued_artisan_options(): void
    {
        $worker = (new ReflectionClass(DistributedJobWorker::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DistributedJobWorker::class, 'formatArguments');
        $method->setAccessible(true);

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

    public function test_it_records_successful_locked_worker_run(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->once()->with('test:work', [], Mockery::type(BufferedOutput::class))->andReturn(0);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('releases')->andReturn(100.0);
        $telemetry->shouldReceive('finishRun')->once()->with('releases', 'success', 100.0);

        $worker = new DistributedJobWorker(
            new DistributedJobCatalog,
            Mockery::mock(TmuxMonitorService::class),
            $telemetry,
        );
        $method = new ReflectionMethod($worker, 'runLockedPlan');

        $result = $method->invoke($worker, [
            'name' => 'releases',
            'description' => 'test release processing',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [['command' => 'test:work', 'arguments' => []]],
            'sleep' => 1,
        ], 60, new BufferedOutput);

        self::assertSame(0, $result['exit_code']);
        self::assertTrue($result['completed']);
    }

    public function test_successful_backfill_marks_the_claimed_generation_complete(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->once()->with('test:work', [], Mockery::type(BufferedOutput::class))->andReturn(0);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('backfill')->andReturn(100.0);
        $telemetry->shouldReceive('finishRun')->once()->with('backfill', 'success', 100.0);
        $gate = Mockery::mock(BackfillPermitGate::class);
        $gate->shouldReceive('claimGeneration')->once()->andReturn(17);
        $gate->shouldReceive('complete')->once()->with(17)->andReturnTrue();

        $worker = new DistributedJobWorker(
            new DistributedJobCatalog,
            Mockery::mock(TmuxMonitorService::class),
            $telemetry,
            $gate,
        );
        $method = new ReflectionMethod($worker, 'runLockedPlan');

        $result = $method->invoke($worker, [
            'name' => 'backfill',
            'description' => 'test backfill',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [['command' => 'test:work', 'arguments' => []]],
            'sleep' => 60,
        ], 60, new BufferedOutput);

        self::assertSame(0, $result['exit_code']);
        self::assertTrue($result['completed']);
    }

    public function test_backfill_refreshes_control_settings_before_resolving_each_plan(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->once()->with(
            'multiprocessing:safe',
            ['type' => 'backfill'],
            Mockery::type(BufferedOutput::class),
        )->andReturn(0);

        $staleRunVar = [
            'constants' => ['sequential' => 0],
            'settings' => [
                'backfill' => 1,
                'back_timer' => 20,
                'orchestrator_mode' => 'active',
                'orchestrator_lease_until' => time() + 600,
                'orchestrator_bf_paused' => 1,
                'orchestrator_bf_permit' => 0,
            ],
            'counts' => ['now' => ['backfill_groups_days' => 1, 'collections_table' => 0]],
            'killswitch' => [],
        ];
        $freshRunVar = $staleRunVar;
        $freshRunVar['settings']['orchestrator_bf_paused'] = 0;
        $freshRunVar['settings']['orchestrator_bf_permit'] = 17;

        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once();
        $monitor->shouldReceive('collectStatistics')->once()->andReturn($staleRunVar);
        $monitor->shouldReceive('refreshBackfillControlSettings')->once()->andReturn($freshRunVar);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('backfill')->andReturn(100.0);
        $telemetry->shouldReceive('finishRun')->once()->with('backfill', 'success', 100.0);
        $gate = Mockery::mock(BackfillPermitGate::class);
        $gate->shouldReceive('claimGeneration')->once()->andReturn(17);
        $gate->shouldReceive('complete')->once()->with(17)->andReturnTrue();

        $exitCode = (new DistributedJobWorker(
            new DistributedJobCatalog,
            $monitor,
            $telemetry,
            $gate,
        ))->run('backfill', true, null, 60, new BufferedOutput);

        self::assertSame(0, $exitCode);
    }

    public function test_current_forward_refreshes_its_exact_permit_before_resolving_each_plan(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->once()->with(
            'articles:get-range',
            [
                'mode' => 'binaries',
                'group' => 'alt.test',
                'first' => 101,
                'last' => 10_100,
                '--current-forward-generation' => 17,
            ],
            Mockery::type(BufferedOutput::class),
        )->andReturn(0);

        $staleRunVar = [
            'constants' => ['sequential' => 0],
            'settings' => [
                'orchestrator_mode' => 'active',
                'orchestrator_lease_until' => time() + 600,
            ],
            'counts' => ['now' => []],
            'killswitch' => [],
        ];
        $freshRunVar = $staleRunVar;
        $freshRunVar['settings'] += [
            'orchestrator_cf_permit' => 17,
            'orchestrator_cf_group' => 'alt.test',
            'orchestrator_cf_first' => 101,
            'orchestrator_cf_last' => 10_100,
        ];

        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once();
        $monitor->shouldReceive('collectStatistics')->once()->andReturn($staleRunVar);
        $monitor->shouldReceive('refreshCurrentForwardControlSettings')->once()->andReturn($freshRunVar);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('current-forward')->andReturn(100.0);
        $telemetry->shouldReceive('finishRun')->once()->with('current-forward', 'success', 100.0);

        $exitCode = (new DistributedJobWorker(
            new DistributedJobCatalog,
            $monitor,
            $telemetry,
        ))->run('current-forward', true, null, 60, new BufferedOutput);

        self::assertSame(0, $exitCode);
    }

    #[DataProvider('nzbPostPassSleepProvider')]
    public function test_nzb_post_pass_sleep_only_accelerates_a_saturated_batch_with_a_fresh_active_lease(
        string $mode,
        int $leaseUntil,
        bool $controlsFresh,
        int $selected,
        int $created,
        int $expectedSleep,
        int $refreshes,
    ): void {
        config([
            'nntmux.distributed_nzb_limit' => 5,
            'nntmux.distributed_nzb_sleep' => 60,
            'nntmux.distributed_nzb_terminal_stale_enabled' => false,
        ]);
        $runVar = [
            'nzb_controls_fresh' => $controlsFresh,
            'constants' => ['sequential' => 0],
            'settings' => [
                'orchestrator_mode' => $mode,
                'orchestrator_lease_until' => $leaseUntil,
                'orchestrator_nzb_timer' => 60,
                'orchestrator_nzb_limit' => 5,
            ],
            'counts' => ['now' => []],
            'killswitch' => [],
        ];
        $plan = (new DistributedJobCatalog)->resolve('nzb-backlog', $runVar);
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $refreshes === 0
            ? $monitor->shouldNotReceive('refreshNzbControlSettings')
            : $monitor->shouldReceive('refreshNzbControlSettings')->once()->andReturn($runVar);
        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $worker = new DistributedJobWorker(new DistributedJobCatalog, $monitor, $telemetry);
        $method = new ReflectionMethod($worker, 'sleepAfterSaturatedNzbPass');

        self::assertSame($expectedSleep, $method->invoke(
            $worker,
            $plan,
            [
                'exit_code' => 0,
                'completed' => true,
                'nzb_batch_before' => ['selected' => 0, 'created' => 0],
                'nzb_batch_after' => ['selected' => $selected, 'created' => $created],
            ],
            (int) $plan['sleep'],
        ));
    }

    public static function nzbPostPassSleepProvider(): array
    {
        return [
            'active saturated successful batch retries within twenty seconds' => ['active', time() + 600, true, 5, 5, 20, 1],
            'active saturated batch with a failed write keeps idle timer' => ['active', time() + 600, true, 5, 4, 60, 0],
            'active partial batch keeps idle timer' => ['active', time() + 600, true, 4, 4, 60, 0],
            'stale lease keeps fail-safe timer' => ['active', time() - 1, true, 5, 5, 180, 1],
            'incomplete control refresh keeps original timer' => ['active', time() + 600, false, 5, 5, 60, 1],
        ];
    }

    public function test_lock_contention_never_samples_or_marks_an_nzb_batch_complete(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        $heldLock = Cache::store('array')->lock('nntmux:distributed-worker:nzb-backlog', 60);
        self::assertTrue($heldLock->get());

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('recordRunOutcome')->once()->with('nzb-backlog', 'lock_contended');
        $telemetry->shouldNotReceive('snapshot');
        $worker = new DistributedJobWorker(
            new DistributedJobCatalog,
            Mockery::mock(TmuxMonitorService::class),
            $telemetry,
        );
        $method = new ReflectionMethod($worker, 'runLockedPlan');
        $result = $method->invoke($worker, [
            'name' => 'nzb-backlog',
            'description' => 'test NZB backlog',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [[
                'command' => 'nntmux:nzb-create-backlog',
                'arguments' => ['--limit' => 5, '--order' => 'desc'],
            ]],
            'sleep' => 60,
        ], 60, new BufferedOutput);

        self::assertSame(0, $result['exit_code']);
        self::assertFalse($result['completed']);
        self::assertNull($result['nzb_batch_before']);
        self::assertNull($result['nzb_batch_after']);
        $heldLock->release();
    }
}
