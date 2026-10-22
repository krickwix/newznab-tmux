<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Models\Settings;
use App\Services\Orchestrator\ControlDecision;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\FreeAdaptiveBackfillGovernor;
use App\Services\Orchestrator\FreeAdaptiveState;
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
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The orchestrator grant is the single enforcement point of the free_adaptive
 * delay: the idle-lane permit waits for last_done_at + delay, the operator's
 * manual grant does not, and free_run is untouched.
 */
final class FreeAdaptiveOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.auto_current_forward' => false,
            'nntmux.orchestrator.free_run' => false,
            'nntmux.orchestrator.backfill_fill_quantity' => 100_000,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '0']);
        Settings::forgetCachedSettings();
    }

    private function snapshot(ControlProfile $pinned): PipelineSnapshot
    {
        return new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 30_000,
            backfillRemainingArticles: 10_505,
            backfillSafeQuantity: 10_000,
            profileOverride: $pinned,
        );
    }

    /** @return WorkerControlStateStore&MockInterface */
    private function store(PipelineSnapshot $snapshot, ControlProfile $stored): WorkerControlStateStore
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturnNull();
        $store->shouldReceive('loadState')->once()->andReturn(new ControlState(profile: $stored));
        $store->shouldReceive('storeSnapshot')->once()->with($snapshot);
        // Neither free-run-family profile opens a permit observation.
        $store->shouldReceive('beginPermitObservation')->never();

        return $store;
    }

    private function snapshots(PipelineSnapshot $snapshot): PipelineSnapshotRepository
    {
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->with(null)->andReturn($snapshot);

        return $snapshots;
    }

    /** A governor that reports $state and records how it was called. */
    private function governor(FreeAdaptiveState $state, bool $expectEntering = false): FreeAdaptiveBackfillGovernor
    {
        $governor = Mockery::mock(FreeAdaptiveBackfillGovernor::class);
        $governor->shouldReceive('tick')->once()->with(
            Mockery::type(PipelineSnapshot::class),
            $expectEntering,
            Mockery::type('int'),
        )->andReturn($state);

        return $governor;
    }

    private function applierExpecting(bool $grant): WorkerProfileApplier
    {
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $isFreeAdaptive = static fn (ControlDecision $decision): bool => $decision->backfillPermitted
            && $decision->profile->profile === ControlProfile::FreeAdaptive;
        if ($grant) {
            $applier->shouldReceive('apply')->once()->with(
                Mockery::on($isFreeAdaptive), Mockery::type('int'), true, 'alt.test', false, 10_000, 10_000,
            )->andReturn(8);
        } else {
            $applier->shouldReceive('apply')->once()->with(
                Mockery::on($isFreeAdaptive), Mockery::type('int'), false, 'alt.test', false,
            )->andReturn(8);
        }

        return $applier;
    }

    public function test_no_permit_is_granted_before_the_delay_has_elapsed(): void
    {
        $snapshot = $this->snapshot(ControlProfile::FreeAdaptive);
        $store = $this->store($snapshot, ControlProfile::FreeAdaptive);
        $store->shouldReceive('markBackfillTargetAttempted')->never();
        $closed = new FreeAdaptiveState(300, time() - 1_000, time(), time() - 10, 'hold');

        $result = (new WorkerOrchestrator(
            $this->snapshots($snapshot), new WorkerControlPolicy, $store, $this->applierExpecting(false),
            freeAdaptive: $this->governor($closed),
        ))->runOnce(false);

        self::assertFalse($result['permit_granted']);
        self::assertSame(300, $result['free_adaptive']['delay_seconds']);
        self::assertGreaterThan(0, $result['free_adaptive']['seconds_until_eligible']);
        self::assertContains('free_adaptive_delay_300s', $result['reasons']);
        self::assertContains('free_adaptive_hold', $result['reasons']);
    }

    public function test_a_permit_is_granted_once_the_delay_has_elapsed(): void
    {
        $snapshot = $this->snapshot(ControlProfile::FreeAdaptive);
        $store = $this->store($snapshot, ControlProfile::FreeAdaptive);
        $store->shouldReceive('markBackfillTargetAttempted')->once()->with('alt.test', Mockery::type('int'));
        $open = new FreeAdaptiveState(300, time() - 1_000, time(), time() - 301, 'hold');

        $result = (new WorkerOrchestrator(
            $this->snapshots($snapshot), new WorkerControlPolicy, $store, $this->applierExpecting(true),
            freeAdaptive: $this->governor($open),
        ))->runOnce(false);

        self::assertTrue($result['permit_granted']);
        self::assertSame(0, $result['free_adaptive']['seconds_until_eligible']);
    }

    public function test_an_unclaimed_permit_is_never_overwritten_even_with_the_gate_open(): void
    {
        DB::table('settings')->where('name', 'orchestrator_bf_permit')->update(['value' => '7']);
        Settings::forgetCachedSettings();
        $snapshot = $this->snapshot(ControlProfile::FreeAdaptive);
        $store = $this->store($snapshot, ControlProfile::FreeAdaptive);
        $open = new FreeAdaptiveState(0, 0, time(), 0, 'decrease');

        $result = (new WorkerOrchestrator(
            $this->snapshots($snapshot), new WorkerControlPolicy, $store, $this->applierExpecting(false),
            freeAdaptive: $this->governor($open),
        ))->runOnce(false);

        self::assertFalse($result['permit_granted']);
    }

    public function test_the_operator_grant_bypasses_a_closed_gate(): void
    {
        $snapshot = $this->snapshot(ControlProfile::FreeAdaptive);
        $store = $this->store($snapshot, ControlProfile::FreeAdaptive);
        $store->shouldReceive('markBackfillTargetAttempted')->once()->with('alt.test', Mockery::type('int'));
        $closed = new FreeAdaptiveState(3_600, time(), time(), time(), 'increase');

        $result = (new WorkerOrchestrator(
            $this->snapshots($snapshot), new WorkerControlPolicy, $store, $this->applierExpecting(true),
            freeAdaptive: $this->governor($closed),
        ))->runOnce(false, true);

        self::assertTrue($result['permit_granted']);
    }

    /** Entering from free_run is a transition, so the governor resets to the initial delay. */
    public function test_entering_from_free_run_tells_the_governor_it_is_entering(): void
    {
        $snapshot = $this->snapshot(ControlProfile::FreeAdaptive);
        $store = $this->store($snapshot, ControlProfile::FreeRun);
        $store->shouldReceive('markBackfillTargetAttempted')->never();
        $entered = new FreeAdaptiveState(120, time(), time(), time() - 30, 'entered');

        $result = (new WorkerOrchestrator(
            $this->snapshots($snapshot), new WorkerControlPolicy, $store, $this->applierExpecting(false),
            freeAdaptive: $this->governor($entered, expectEntering: true),
        ))->runOnce(false);

        self::assertFalse($result['permit_granted']);
        self::assertContains('free_adaptive_entered', $result['reasons']);
    }

    public function test_free_run_never_consults_the_governor_and_still_grants_every_cycle(): void
    {
        $governor = Mockery::mock(FreeAdaptiveBackfillGovernor::class);
        $governor->shouldReceive('tick')->never();

        foreach ([1, 2] as $cycle) {
            Settings::forgetCachedSettings();
            $snapshot = $this->snapshot(ControlProfile::FreeRun);
            $store = $this->store($snapshot, ControlProfile::FreeRun);
            $store->shouldReceive('markBackfillTargetAttempted')->once();
            $applier = Mockery::mock(WorkerProfileApplier::class);
            $applier->shouldReceive('apply')->once()->with(
                Mockery::on(static fn (ControlDecision $d): bool => $d->profile->profile === ControlProfile::FreeRun),
                Mockery::type('int'), true, 'alt.test', false, 10_000, 10_000,
            )->andReturn(8 + $cycle);

            $result = (new WorkerOrchestrator(
                $this->snapshots($snapshot), new WorkerControlPolicy, $store, $applier,
                freeAdaptive: $governor,
            ))->runOnce(false);

            self::assertTrue($result['permit_granted'], 'cycle '.$cycle);
            self::assertNull($result['free_adaptive'], 'cycle '.$cycle);
            self::assertEmpty(preg_grep('/^free_adaptive_/', $result['reasons']), 'cycle '.$cycle);
        }
    }
}
