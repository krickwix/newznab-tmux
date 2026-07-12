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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class WorkerOrchestratorTest extends TestCase
{
    public function test_configuration_clamps_the_observation_window_to_five_minutes(): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_PERMIT_OBSERVATION_SECONDS';
        $previous = getenv($key);
        putenv($key.'=299');
        $_ENV[$key] = '299';
        $_SERVER[$key] = '299';

        try {
            $configuration = require base_path('config/nntmux.php');

            self::assertSame(300, $configuration['orchestrator']['permit_observation_seconds']);
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

    #[DataProvider('cohortPostdateToleranceProvider')]
    public function test_configuration_bounds_the_cohort_postdate_tolerance(string $input, int $expected): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_BACKFILL_COHORT_POSTDATE_TOLERANCE_SECONDS';
        $previous = getenv($key);
        putenv($key.'='.$input);
        $_ENV[$key] = $input;
        $_SERVER[$key] = $input;

        try {
            $configuration = require base_path('config/nntmux.php');

            self::assertSame($expected, $configuration['orchestrator']['backfill_cohort_postdate_tolerance_seconds']);
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

    /** @return array<string, array{string, int}> */
    public static function cohortPostdateToleranceProvider(): array
    {
        return [
            'negative becomes exact' => ['-1', 0],
            'one hour remains bounded' => ['3600', 3600],
            'more than one day is capped' => ['86401', 86400],
        ];
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

    public function test_fill_atomically_issues_one_bounded_context_retry_for_the_selected_target(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.backfill_context_retry_quantity' => 50_000,
            'nntmux.orchestrator.backfill_max_quantity' => 200_000,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '0']);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.multipart',
            backfillCursor: 1_000_000,
            backfillYieldNzbsPer10k: 0.0,
            backfillYieldAttempts: 1,
            backfillLastCursorDelta: 10_000,
            backfillLastEffectiveAt: 0,
            backfillHistoryRecent: true,
            backfillTargetIneffectivePermits: 1,
            backfillRemainingArticles: 1_000_000,
            backfillSafeQuantity: 150_000,
        );
        $state = new ControlState(
            profile: ControlProfile::Fill,
            ineffectiveBackfillPermitsByTarget: ['alt.multipart' => 1],
        );
        $outcome = [
            'cursor' => 1_000_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ];
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class);
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturnNull();
        $store->shouldReceive('loadState')->once()->andReturn($state);
        $store->shouldReceive('beginPermitObservation')->once()->with(
            $snapshot,
            42,
            Mockery::type('int'),
            $outcome,
            50_000,
        );
        $store->shouldReceive('storeState')->once()->with(Mockery::type(ControlState::class));
        $store->shouldReceive('storeSnapshot')->once()->with($snapshot);
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->with(null)->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.multipart')->andReturn($outcome);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => $decision->backfillPermitted),
            Mockery::type('int'),
            true,
            'alt.multipart',
            false,
            50_000,
        )->andReturn(42);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertTrue($result['permit_granted']);
        self::assertSame(50_000, $result['backfill_target']['quantity']);
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
            backfillGroup: 'alt.current',
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
            static fn (ControlState $next): bool => $next->consecutiveIneffectiveBackfillPermits === 1
                && ($next->ineffectiveBackfillPermitsByTarget['alt.test'] ?? 0) === 1
                && ! isset($next->ineffectiveBackfillPermitsByTarget['alt.current']),
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

    public function test_a_claimed_permit_records_target_nzb_yield_after_attribution(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 1201, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.test', backfillCursor: 10_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 1,
            'releases' => 1,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbsForCohort')->once()->with(
            'alt.test',
            100,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn(3);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_effective', $result['reasons']);
        self::assertSame(3.0, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k']);
        self::assertSame(1, $store->backfillYieldHistory()['alt.test']['attempts']);
    }

    #[DataProvider('actionableFrontierCases')]
    public function test_a_claimed_permit_closes_only_after_exact_cohort_nzb_frontier_drains(
        int $eligibleNzbs,
        bool $closes,
    ): void {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 61, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            eligibleBackfillSupply: true,
            eligibleNzbs: $eligibleNzbs,
            backfillGroup: 'alt.next',
            backfillCursor: 30_000,
        );
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbsForCohort')->once()->andReturn(2);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->times($closes ? 1 : 0);
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        $closes
            ? self::assertContains('backfill_permit_effective', $result['reasons'])
            : self::assertNotContains('backfill_permit_effective', $result['reasons']);
        self::assertFalse($result['permit_granted']);
        self::assertSame($closes, $store->permitObservation() === null);
        self::assertSame($closes ? 2.0 : null, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k'] ?? null);
    }

    /** @return array<string, array{int, bool}> */
    public static function actionableFrontierCases(): array
    {
        return [
            'drained' => [0, true],
            'still actionable' => [2, false],
        ];
    }

    public function test_aggregate_group_progress_does_not_close_a_current_observation_without_exact_nzbs(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 61, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.next', backfillCursor: 30_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 1,
            'releases' => 1,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbsForCohort')->once()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldNotReceive('revokePermit');
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertNotContains('backfill_permit_effective', $result['reasons']);
        self::assertNotNull($store->permitObservation());
        self::assertSame([], $store->backfillYieldHistory());
    }

    public function test_timeout_uses_exact_cohort_nzbs_instead_of_aggregate_group_progress(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 1201, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.next', backfillCursor: 30_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 1,
            'releases' => 1,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbsForCohort')->once()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertNotContains('backfill_permit_ineffective', $result['reasons']);
        self::assertNotNull($store->permitObservation());
        self::assertSame([], $store->backfillYieldHistory());
    }

    public function test_a_completed_no_input_permit_closes_early_without_consuming_a_strike(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 61, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.next', backfillCursor: 30_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
        ]);
        $snapshots->shouldNotReceive('backfillCreatedNzbsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_no_input', $result['reasons']);
        self::assertNull($store->permitObservation());
        self::assertSame(1, $store->backfillYieldHistory()['alt.test']['attempts']);
        self::assertSame(0, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.test'] ?? 0);
        self::assertFalse($result['permit_granted']);
    }

    public function test_a_claimed_legacy_observation_closes_without_recording_yield(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
        ]);
        Cache::store('array')->forever('nntmux:orchestrator:permit-observation', [
            'generation' => 7,
            'issued_at' => time() - 1201,
            'parts' => 1,
            'binaries' => 2,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 20_000,
        ]);
        $store = new WorkerControlStateStore;
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.next', backfillCursor: 30_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 1,
            'releases' => 1,
            'release_high_watermark' => 101,
        ]);
        $snapshots->shouldNotReceive('backfillCreatedNzbsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_effective', $result['reasons']);
        self::assertNull($store->permitObservation());
        self::assertSame([], $store->backfillYieldHistory());
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

    #[DataProvider('claimGraceRevocationCases')]
    public function test_claim_grace_never_overrides_hard_safety_or_the_exact_expiry(
        int $ageSeconds,
        bool $databaseMemorySafe,
    ): void {
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
            databaseMemorySafe: $databaseMemorySafe,
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
            'issued_at' => time() - $ageSeconds,
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
            Mockery::type(ControlDecision::class),
            Mockery::type('int'),
            false,
            'alt.test',
            false,
        )->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertNotContains('backfill_permit_claim_grace', $result['reasons']);
    }

    /** @return array<string, array{int, bool}> */
    public static function claimGraceRevocationCases(): array
    {
        return [
            'hard database safety failure during grace' => [60, false],
            'soft denial at exact grace expiry' => [120, true],
        ];
    }
}
