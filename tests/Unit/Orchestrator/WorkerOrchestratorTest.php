<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\ControlDecision;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\WorkerControlPolicy;
use App\Services\Orchestrator\WorkerControlStateStore;
use App\Services\Orchestrator\WorkerOrchestrator;
use App\Services\Orchestrator\WorkerProfileApplier;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class WorkerOrchestratorTest extends TestCase
{
    public function test_configuration_clamps_the_observation_window_to_twenty_minutes(): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_PERMIT_OBSERVATION_SECONDS';
        $previous = getenv($key);
        putenv($key.'=901');
        $_ENV[$key] = '901';
        $_SERVER[$key] = '901';

        try {
            $configuration = require base_path('config/nntmux.php');

            self::assertSame(1200, $configuration['orchestrator']['permit_observation_seconds']);
        } finally {
            if ($previous === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$previous);
                $_ENV[$key] = $previous;
                $_SERVER[$key] = $previous;
            }
        }
    }

    public function test_active_no_backfill_mode_applies_profile_without_issuing_a_permit(): void
    {
        config(['nntmux.orchestrator.auto_backfill' => false]);
        $snapshot = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.test', backfillCursor: 123);
        $state = new ControlState(profile: ControlProfile::Balanced);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class);
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturnNull();
        $store->shouldReceive('loadState')->once()->andReturn($state);
        $store->shouldReceive('storeState')->once()->with(Mockery::type(ControlState::class));
        $store->shouldReceive('storeSnapshot')->once()->with($snapshot);
        $store->shouldReceive('storeDecision')->once()->with(Mockery::on(
            static fn (array $result): bool => $result['permit_granted'] === false,
        ));
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->with(null)->andReturn($snapshot);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => $decision->backfillPermitted),
            Mockery::type('int'),
            false,
            'alt.test',
            false,
        )->andReturn(1);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertTrue($result['applied']);
        self::assertFalse($result['permit_granted']);
    }

    public function test_redis_failure_before_lock_acquisition_persists_fail_closed_state(): void
    {
        $store = Mockery::mock(WorkerControlStateStore::class);
        $store->shouldReceive('leaderLock')->once()->andThrow(new RuntimeException('redis unavailable'));
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('failClosed')->once();
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            $store,
            $applier,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('redis unavailable');

        $orchestrator->runOnce(false);
    }

    public function test_an_expired_unconsumed_permit_is_revoked_and_counted_ineffective(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '7']);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 100,
        );
        $state = new ControlState(profile: ControlProfile::Balanced);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class);
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturn([
            'generation' => 7,
            'issued_at' => time() - 1201,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 100,
        ]);
        $store->shouldReceive('clearPermitObservation')->once();
        $store->shouldReceive('loadState')->once()->andReturn($state);
        $store->shouldReceive('storeState')->once()->with(Mockery::on(
            static fn (ControlState $next): bool => $next->consecutiveIneffectiveBackfillPermits === 1,
        ));
        $store->shouldReceive('storeSnapshot')->once();
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 100,
            'ready_collections' => 0,
            'releases' => 0,
        ]);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_ineffective', $result['reasons']);
        self::assertFalse($result['permit_granted']);
    }

    public function test_an_observation_stays_open_past_fifteen_minutes(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
        ]);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 90,
        );
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class);
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturn([
            'generation' => 7,
            'issued_at' => time() - 901,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 100,
        ]);
        $store->shouldReceive('loadState')->once()->andReturn(new ControlState(profile: ControlProfile::Balanced));
        $store->shouldReceive('storeState')->once();
        $store->shouldReceive('storeSnapshot')->once();
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldNotReceive('backfillOutcomeForGroup');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldNotReceive('revokePermit');
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertNotContains('backfill_permit_ineffective', $result['reasons']);
        self::assertFalse($result['permit_granted']);
    }

    public function test_a_revoked_permit_is_not_misattributed_as_a_worker_claim(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '0'],
        ]);
        $snapshot = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.test', backfillCursor: 90);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class);
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturn([
            'generation' => 7,
            'issued_at' => time() - 1201,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 100,
        ]);
        $store->shouldReceive('clearPermitObservation')->once();
        $store->shouldReceive('loadState')->once()->andReturn(new ControlState(profile: ControlProfile::Balanced));
        $store->shouldReceive('storeState')->once()->with(Mockery::on(
            static fn (ControlState $next): bool => $next->consecutiveIneffectiveBackfillPermits === 1,
        ));
        $store->shouldReceive('storeSnapshot')->once();
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->andReturn([
            'cursor' => 90,
            'ready_collections' => 1,
            'releases' => 1,
        ]);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_ineffective', $result['reasons']);
    }

    public function test_a_soft_supply_gate_preserves_an_unclaimed_permit_during_claim_grace(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.permit_claim_grace_seconds' => 120,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '7']);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            eligibleBackfillSupply: false,
            backfillGroup: 'alt.test',
            backfillCursor: 100,
        );
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class);
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturn([
            'generation' => 7,
            'issued_at' => time() - 60,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 100,
        ]);
        $store->shouldReceive('loadState')->once()->andReturn(new ControlState(profile: ControlProfile::Fill));
        $store->shouldReceive('storeState')->once();
        $store->shouldReceive('storeSnapshot')->once();
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldNotReceive('backfillOutcomeForGroup');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => ! $decision->backfillPermitted),
            Mockery::type('int'),
            false,
            'alt.test',
            true,
        )->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_claim_grace', $result['reasons']);
        self::assertFalse($result['permit_granted']);
    }
}
