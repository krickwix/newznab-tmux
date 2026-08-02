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
use ReflectionProperty;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class DistributedJobWorkerTest extends TestCase
{
    public function test_current_forward_hard_timeout_precedes_claim_recovery(): void
    {
        config([
            'nntmux.distributed_current_forward_max_run_seconds' => 600,
            'nntmux.orchestrator.current_forward_claim_timeout_seconds' => 900,
        ]);
        $worker = (new ReflectionClass(DistributedJobWorker::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DistributedJobWorker::class, 'executionAlarmSeconds');

        self::assertSame(600, $method->invoke($worker, 'current-forward'));
        self::assertSame(0, $method->invoke($worker, 'releases'));
        self::assertLessThan(900, $method->invoke($worker, 'current-forward'));
    }

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
        $telemetry->shouldReceive('startRun')->once()->with('releases', null, 60)->andReturn(100.0);
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

    public function test_a_terminating_worker_refuses_to_acquire_a_fresh_lock(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->never();

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->never();

        $worker = new DistributedJobWorker(
            new DistributedJobCatalog,
            Mockery::mock(TmuxMonitorService::class),
            $telemetry,
        );

        $terminating = new ReflectionProperty(DistributedJobWorker::class, 'terminating');
        $terminating->setValue($worker, true);

        $output = new BufferedOutput;
        $result = (new ReflectionMethod($worker, 'runLockedPlan'))->invoke($worker, [
            'name' => 'releases',
            'description' => 'test release processing',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [['command' => 'test:work', 'arguments' => []]],
            'sleep' => 1,
        ], 900, $output);

        self::assertSame(0, $result['exit_code']);
        self::assertStringContainsString('shutting down, refusing to acquire', $output->fetch());
        // The lock must be left untouched so the replacement pod can take it.
        self::assertTrue(
            Cache::store('array')->lock('nntmux:distributed-worker:releases', 900)->get(),
            'A terminating worker must not leave a lock behind.',
        );
    }

    public function test_a_running_worker_still_acquires_the_lock(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->once()->with('test:work', [], Mockery::type(BufferedOutput::class))->andReturn(0);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('releases', null, 900)->andReturn(100.0);
        $telemetry->shouldReceive('finishRun')->once()->with('releases', 'success', 100.0);

        $worker = new DistributedJobWorker(
            new DistributedJobCatalog,
            Mockery::mock(TmuxMonitorService::class),
            $telemetry,
        );

        $output = new BufferedOutput;
        $result = (new ReflectionMethod($worker, 'runLockedPlan'))->invoke($worker, [
            'name' => 'releases',
            'description' => 'test release processing',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [['command' => 'test:work', 'arguments' => []]],
            'sleep' => 1,
        ], 900, $output);

        self::assertSame(0, $result['exit_code']);
        self::assertTrue($result['completed']);
        self::assertStringNotContainsString('refusing to acquire', $output->fetch());
    }

    public function test_successful_backfill_marks_the_claimed_generation_complete(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->once()->with('test:work', [], Mockery::type(BufferedOutput::class))->andReturn(0);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('backfill', null, 60)->andReturn(100.0);
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

    public function test_failed_backfill_marks_the_claimed_generation_failed(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->once()->andReturn(1);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('backfill', null, 60)->andReturn(100.0);
        $telemetry->shouldReceive('finishRun')->once()->with('backfill', 'failure', 100.0);
        $gate = Mockery::mock(BackfillPermitGate::class);
        $gate->shouldReceive('claimGeneration')->once()->andReturn(17);
        $gate->shouldReceive('complete')->never();
        $gate->shouldReceive('fail')->once()->with(17, Mockery::type('string'))->andReturnTrue();

        $worker = new DistributedJobWorker(
            new DistributedJobCatalog,
            Mockery::mock(TmuxMonitorService::class),
            $telemetry,
            $gate,
        );
        $method = new ReflectionMethod($worker, 'runLockedPlan');

        $result = $method->invoke($worker, [
            'name' => 'backfill',
            'description' => 'test failed backfill',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [['command' => 'test:work', 'arguments' => []]],
            'sleep' => 60,
        ], 60, new BufferedOutput);

        self::assertSame(1, $result['exit_code']);
        self::assertFalse($result['completed']);
    }

    public function test_successful_backfill_without_complete_receipts_fails_the_generation(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->once()->andReturn(0);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('backfill', null, 60)->andReturn(100.0);
        $telemetry->shouldReceive('finishRun')->once()->with('backfill', 'failure', 100.0);
        $gate = Mockery::mock(BackfillPermitGate::class);
        $gate->shouldReceive('claimGeneration')->once()->andReturn(17);
        $gate->shouldReceive('complete')->once()->with(17)->andReturnFalse();
        $gate->shouldReceive('fail')->once()->with(17, Mockery::type('string'))->andReturnTrue();

        $worker = new DistributedJobWorker(
            new DistributedJobCatalog,
            Mockery::mock(TmuxMonitorService::class),
            $telemetry,
            $gate,
        );
        $method = new ReflectionMethod($worker, 'runLockedPlan');

        $result = $method->invoke($worker, [
            'name' => 'backfill',
            'description' => 'test backfill without completed receipts',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [['command' => 'test:work', 'arguments' => []]],
            'sleep' => 60,
        ], 60, new BufferedOutput);

        self::assertSame(1, $result['exit_code']);
        self::assertFalse($result['completed']);
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
        $monitor->shouldReceive('refreshBackfillControlSettings')->twice()->andReturn($freshRunVar);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('backfill', null, 60)->andReturn(100.0);
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

    public function test_backfill_without_a_permit_uses_only_the_narrow_control_preflight(): void
    {
        $runVar = $this->managedBackfillRunVar(permit: 0);
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once();
        $monitor->shouldReceive('refreshBackfillControlSettings')->once()->andReturn($runVar);
        $monitor->shouldNotReceive('collectStatistics');

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('recordRunOutcome')->once()->with('backfill', 'disabled');
        $gate = Mockery::mock(BackfillPermitGate::class);
        $gate->shouldNotReceive('claimGeneration');
        Artisan::shouldReceive('call')->never();

        $exitCode = (new DistributedJobWorker(
            new DistributedJobCatalog,
            $monitor,
            $telemetry,
            $gate,
        ))->run('backfill', true, null, 60, new BufferedOutput);

        self::assertSame(0, $exitCode);
    }

    public function test_unmanaged_disabled_backfill_still_refreshes_full_statistics(): void
    {
        $runVar = $this->unmanagedBackfillRunVar(enabled: false);
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once();
        $monitor->shouldReceive('refreshBackfillControlSettings')->twice()->andReturn($runVar);
        $monitor->shouldReceive('collectStatistics')->once()->andReturn($runVar);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('recordRunOutcome')->once()->with('backfill', 'disabled');
        $gate = Mockery::mock(BackfillPermitGate::class);
        $gate->shouldNotReceive('claimGeneration');
        Artisan::shouldReceive('call')->never();

        $exitCode = (new DistributedJobWorker(
            new DistributedJobCatalog,
            $monitor,
            $telemetry,
            $gate,
        ))->run('backfill', true, null, 60, new BufferedOutput);

        self::assertSame(0, $exitCode);
    }

    public function test_backfill_revalidates_a_preflight_permit_after_collecting_statistics(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        $runVar = $this->managedBackfillRunVar(permit: 17);
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once();
        $monitor->shouldReceive('refreshBackfillControlSettings')->once()->andReturn($runVar)->ordered();
        $monitor->shouldReceive('collectStatistics')->once()->andReturn($runVar)->ordered();
        $monitor->shouldReceive('refreshBackfillControlSettings')->once()->andReturn($runVar)->ordered();

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('backfill', null, 60)->andReturn(100.0);
        $telemetry->shouldReceive('finishRun')->once()->with('backfill', 'success', 100.0);
        $gate = Mockery::mock(BackfillPermitGate::class);
        $gate->shouldReceive('claimGeneration')->once()->andReturn(17);
        $gate->shouldReceive('complete')->once()->with(17)->andReturnTrue();
        Artisan::shouldReceive('call')->once()->with(
            'multiprocessing:safe',
            ['type' => 'backfill'],
            Mockery::type(BufferedOutput::class),
        )->andReturn(0);

        $exitCode = (new DistributedJobWorker(
            new DistributedJobCatalog,
            $monitor,
            $telemetry,
            $gate,
        ))->run('backfill', true, null, 60, new BufferedOutput);

        self::assertSame(0, $exitCode);
    }

    public function test_backfill_does_not_run_when_a_preflight_permit_is_revoked_during_revalidation(): void
    {
        $permitted = $this->managedBackfillRunVar(permit: 17);
        $revoked = $this->managedBackfillRunVar(permit: 0);
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once();
        $monitor->shouldReceive('refreshBackfillControlSettings')->once()->andReturn($permitted)->ordered();
        $monitor->shouldReceive('collectStatistics')->once()->andReturn($permitted)->ordered();
        $monitor->shouldReceive('refreshBackfillControlSettings')->once()->andReturn($revoked)->ordered();

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('recordRunOutcome')->once()->with('backfill', 'disabled');
        $gate = Mockery::mock(BackfillPermitGate::class);
        $gate->shouldNotReceive('claimGeneration');
        Artisan::shouldReceive('call')->never();

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
        $telemetry->shouldReceive('startRun')->once()->with('current-forward', null, 60)->andReturn(100.0);
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

    /** @return array<string, mixed> */
    private function managedBackfillRunVar(int $permit): array
    {
        return [
            'constants' => ['sequential' => 0],
            'settings' => [
                'backfill' => 1,
                'back_timer' => 600,
                'orchestrator_mode' => 'active',
                'orchestrator_lease_until' => time() + 600,
                'orchestrator_back_timer' => 10,
                'orchestrator_bf_paused' => $permit > 0 ? 0 : 1,
                'orchestrator_bf_permit' => $permit,
            ],
            'counts' => ['now' => ['backfill_groups_days' => 1, 'collections_table' => 0]],
            'killswitch' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function unmanagedBackfillRunVar(bool $enabled): array
    {
        return [
            'constants' => ['sequential' => 0],
            'settings' => [
                'backfill' => $enabled ? 1 : 0,
                'back_timer' => 600,
            ],
            'counts' => ['now' => ['backfill_groups_days' => 1, 'collections_table' => 0]],
            'killswitch' => [],
        ];
    }
}
