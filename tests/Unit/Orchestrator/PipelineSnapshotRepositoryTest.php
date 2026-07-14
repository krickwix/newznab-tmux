<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Models\Settings;
use App\Services\Nzb\NzbBacklogCreationService;
use App\Services\Orchestrator\BodyRecoverySourceCriteria;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\PrometheusSafetySignalProvider;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class PipelineSnapshotRepositoryTest extends TestCase
{
    public function test_legacy_snapshot_establishes_a_blocked_row_lock_baseline_until_a_clean_window_completes(): void
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'databaseContentionTelemetry');

        $baseline = $method->invoke($repository, 24, 0, 100, [
            'schema_version' => 2,
            'database_deadlocks' => 24,
            'database_current_waits' => 0,
            'observed_at' => 880,
        ], 1_000);

        self::assertTrue($baseline['database_waits_safe']);
        self::assertTrue($baseline['database_row_lock_admission_blocked']);
        self::assertFalse($baseline['database_admission_safe']);
        self::assertSame(1_000, $baseline['database_row_lock_window_started_at']);
        self::assertSame(100, $baseline['database_row_lock_window_start_count']);

        $clean = $method->invoke($repository, 24, 0, 106, [
            'schema_version' => 3,
            'database_deadlocks' => 24,
            'database_current_waits' => 0,
            'database_row_lock_waits' => 100,
            'database_row_lock_window_started_at' => 1_000,
            'database_row_lock_window_start_count' => 100,
            'database_row_lock_admission_blocked' => true,
            'database_row_lock_hard_breach_at' => 0,
            'database_current_wait_started_at' => 0,
            'database_admission_safe' => false,
            'observed_at' => 1_000,
        ], 1_120);

        self::assertSame(3.0, $clean['database_row_lock_window_rate']);
        self::assertFalse($clean['database_row_lock_admission_blocked']);
        self::assertTrue($clean['database_admission_safe']);
    }

    public function test_stale_schema_v3_snapshot_reestablishes_a_blocked_fresh_baseline(): void
    {
        config()->set('nntmux.orchestrator.snapshot_max_age_seconds', 180);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'databaseContentionTelemetry');

        $telemetry = $method->invoke($repository, 24, 0, 110, [
            'schema_version' => 3,
            'database_deadlocks' => 24,
            'database_current_waits' => 0,
            'database_row_lock_waits' => 100,
            'database_row_lock_window_started_at' => 900,
            'database_row_lock_window_start_count' => 100,
            'database_row_lock_admission_blocked' => false,
            'database_row_lock_hard_breach_at' => 0,
            'database_current_wait_started_at' => 0,
            'database_admission_safe' => true,
            'observed_at' => 1_000,
        ], 1_181);

        self::assertTrue($telemetry['database_waits_safe']);
        self::assertSame(0, $telemetry['database_row_lock_delta']);
        self::assertSame(1_181, $telemetry['database_row_lock_window_started_at']);
        self::assertSame(110, $telemetry['database_row_lock_window_start_count']);
        self::assertTrue($telemetry['database_row_lock_admission_blocked']);
        self::assertFalse($telemetry['database_admission_safe']);
    }

    public function test_row_lock_hysteresis_blocks_at_four_and_only_reopens_after_a_complete_clean_window(): void
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'databaseContentionTelemetry');
        $previous = [
            'schema_version' => 3,
            'database_deadlocks' => 24,
            'database_current_waits' => 0,
            'database_row_lock_waits' => 100,
            'database_row_lock_window_started_at' => 1_000,
            'database_row_lock_window_start_count' => 100,
            'database_row_lock_admission_blocked' => false,
            'database_row_lock_hard_breach_at' => 0,
            'database_current_wait_started_at' => 0,
            'database_admission_safe' => true,
            'observed_at' => 1_000,
        ];

        $blocked = $method->invoke($repository, 24, 0, 101, $previous, 1_015);
        self::assertSame(4.0, $blocked['database_row_lock_instant_rate']);
        self::assertTrue($blocked['database_row_lock_admission_blocked']);
        self::assertFalse($blocked['database_admission_safe']);

        $previous = array_merge($previous, $blocked, [
            'database_row_lock_waits' => 101,
            'observed_at' => 1_015,
        ]);
        $stillBlocked = $method->invoke($repository, 24, 0, 107, $previous, 1_120);
        self::assertSame(3.5, $stillBlocked['database_row_lock_window_rate']);
        self::assertTrue($stillBlocked['database_row_lock_admission_blocked']);

        $previous = array_merge($previous, $stillBlocked, [
            'database_row_lock_waits' => 107,
            'observed_at' => 1_120,
        ]);
        $reopened = $method->invoke($repository, 24, 0, 113, $previous, 1_240);
        self::assertSame(3.0, $reopened['database_row_lock_window_rate']);
        self::assertFalse($reopened['database_row_lock_admission_blocked']);
        self::assertTrue($reopened['database_admission_safe']);
    }

    public function test_row_lock_hard_breaches_cover_window_burst_instant_deadlock_current_wait_and_missing_metrics(): void
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'databaseContentionTelemetry');
        $previous = [
            'schema_version' => 3,
            'database_deadlocks' => 24,
            'database_current_waits' => 1,
            'database_row_lock_waits' => 100,
            'database_row_lock_window_started_at' => 1_000,
            'database_row_lock_window_start_count' => 100,
            'database_row_lock_admission_blocked' => false,
            'database_row_lock_hard_breach_at' => 0,
            'database_current_wait_started_at' => 1_090,
            'database_admission_safe' => true,
            'observed_at' => 1_105,
        ];

        $window = $method->invoke($repository, 24, 0, 112, array_merge($previous, [
            'database_current_waits' => 0,
            'database_current_wait_started_at' => 0,
            'observed_at' => 1_100,
        ]), 1_120);
        self::assertFalse($window['database_waits_safe'], 'Six waits/minute over a complete window is hard.');

        $burst = $method->invoke($repository, 24, 0, 112, array_merge($previous, [
            'database_current_waits' => 0,
            'database_current_wait_started_at' => 0,
        ]), 1_150);
        self::assertFalse($burst['database_waits_safe'], 'Twelve waits inside sixty seconds is hard.');

        $instant = $method->invoke($repository, 24, 0, 105, array_merge($previous, [
            'database_current_waits' => 0,
            'database_current_wait_started_at' => 0,
            'observed_at' => 1_100,
        ]), 1_110);
        self::assertFalse($instant['database_waits_safe'], 'Thirty waits/minute instantaneously is hard.');

        self::assertFalse($method->invoke($repository, 25, 0, 100, $previous, 1_120)['database_waits_safe']);
        self::assertFalse($method->invoke($repository, 24, 1, 100, $previous, 1_120)['database_waits_safe']);
        self::assertFalse($method->invoke($repository, null, 0, 100, $previous, 1_120)['database_waits_safe']);
        self::assertFalse($method->invoke($repository, 24, null, 100, $previous, 1_120)['database_waits_safe']);
        self::assertFalse($method->invoke($repository, 24, 0, null, $previous, 1_120)['database_waits_safe']);
    }

    public function test_hard_breach_cooldown_requires_a_post_cooldown_clean_window_before_admission(): void
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'databaseContentionTelemetry');
        $previous = [
            'schema_version' => 3,
            'database_deadlocks' => 24,
            'database_current_waits' => 0,
            'database_row_lock_waits' => 100,
            'database_row_lock_window_started_at' => 500,
            'database_row_lock_window_start_count' => 100,
            'database_row_lock_admission_blocked' => true,
            'database_row_lock_hard_breach_at' => 100,
            'database_current_wait_started_at' => 0,
            'database_admission_safe' => false,
            'observed_at' => 500,
        ];

        $duringCooldown = $method->invoke($repository, 24, 0, 100, $previous, 620);
        self::assertTrue($duringCooldown['database_row_lock_admission_blocked']);
        self::assertFalse($duringCooldown['database_admission_safe']);

        $previous = array_merge($previous, $duringCooldown, ['observed_at' => 620]);
        $afterCooldown = $method->invoke($repository, 24, 0, 100, $previous, 740);
        self::assertFalse($afterCooldown['database_row_lock_admission_blocked']);
        self::assertTrue($afterCooldown['database_admission_safe']);
    }

    public function test_database_admission_requires_the_control_profile_to_be_stable_for_two_minutes(): void
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'databaseProfileStable');

        self::assertFalse($method->invoke($repository, new ControlState(lastTransitionAt: 1_000), 1_119));
        self::assertTrue($method->invoke($repository, new ControlState(lastTransitionAt: 1_000), 1_120));
        self::assertTrue($method->invoke($repository, ControlState::initial(), 1_000));
    }

    public function test_current_database_waits_block_backfill_even_before_persistent_fail_safe(): void
    {
        $snapshot = new PipelineSnapshot(
            partsBacklog: 1,
            binariesBacklog: 1,
            collectionsBacklog: 1,
            releasesBacklog: 0,
            nzbsBacklog: 0,
            databaseWaitsSafe: true,
            databaseCurrentWaits: 1,
            eligibleBackfillSupply: true,
            backfillSafeQuantity: 10_000,
        );

        self::assertFalse($snapshot->backfillGatesPassed());
        self::assertSame(1, $snapshot->withPermitOutcome(true, false)->databaseCurrentWaits);
    }

    public function test_database_admission_safety_is_preserved_by_permit_outcome_and_required_by_backfill(): void
    {
        $snapshot = new PipelineSnapshot(
            1,
            1,
            1,
            0,
            0,
            eligibleBackfillSupply: true,
            backfillSafeQuantity: 10_000,
            databaseAdmissionSafe: false,
            databaseRowLockWaits: 88,
            databaseRowLockDelta: 2,
            databaseRowLockInstantRate: 4.5,
            databaseRowLockWindowStartedAt: 100,
            databaseRowLockWindowStartCount: 80,
            databaseRowLockWindowRate: 3.5,
            databaseRowLockAdmissionBlocked: true,
            databaseRowLockHardBreachAt: 90,
            databaseCurrentWaitStartedAt: 75,
        );

        self::assertFalse($snapshot->backfillGatesPassed());
        $outcome = $snapshot->withPermitOutcome(true, true);
        self::assertFalse($outcome->databaseAdmissionSafe);
        self::assertSame(88, $outcome->databaseRowLockWaits);
        self::assertSame(2, $outcome->databaseRowLockDelta);
        self::assertSame(4.5, $outcome->databaseRowLockInstantRate);
        self::assertSame(100, $outcome->databaseRowLockWindowStartedAt);
        self::assertSame(80, $outcome->databaseRowLockWindowStartCount);
        self::assertSame(3.5, $outcome->databaseRowLockWindowRate);
        self::assertTrue($outcome->databaseRowLockAdmissionBlocked);
        self::assertSame(90, $outcome->databaseRowLockHardBreachAt);
        self::assertSame(75, $outcome->databaseCurrentWaitStartedAt);
    }

    public function test_body_recovery_source_backlog_uses_the_exact_bounded_reconciliation_contract(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('COUNT(*)', $sql);
                self::assertStringContainsString('c.filecheck = 0', $sql);
                self::assertStringContainsString('c.releases_id IS NULL', $sql);
                self::assertStringContainsString("c.subject LIKE '[PRiVATE]%[newzNZB]%'", $sql);
                self::assertStringContainsString('c.collection_regexes_id IN (?)', $sql);
                self::assertStringNotContainsString('b.currentparts <= ?', $sql);
                self::assertStringNotContainsString('b.totalparts >= ?', $sql);
                self::assertStringContainsString('c.totalfiles > 1', $sql);
                self::assertStringContainsString('NOT EXISTS', $sql);
                self::assertStringContainsString('b2.collections_id = c.id', $sql);
                self::assertStringNotContainsString('c.dateadded < ?', $sql);
                self::assertStringContainsString('c.groups_id IN (?, ?)', $sql);
                self::assertSame([11, 22, -20], $bindings);

                return true;
            })
            ->andReturn((object) ['backlog' => 4321, 'oldest_age' => 7200]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'bodyRecoverySourceSnapshot');

        self::assertSame(['backlog' => 4321, 'oldest_age' => 7200], $method->invoke(
            $repository,
            new BodyRecoverySourceCriteria([11, 22], [-20], 2, 10, '2026-07-12 16:00:00'),
        ));
    }

    public function test_body_recovery_identity_is_stable_while_work_eligibility_keeps_dynamic_bounds(): void
    {
        $criteria = new BodyRecoverySourceCriteria([11], [-20], 2, 10, '2026-07-12 16:00:00');
        $identity = $criteria->identityPredicate();
        $eligibility = $criteria->eligibilityPredicate();

        self::assertStringNotContainsString('dateadded < ?', $identity['sql']);
        self::assertStringNotContainsString('currentparts <= ?', $identity['sql']);
        self::assertSame([11, -20], $identity['bindings']);
        self::assertStringContainsString('dateadded < ?', $eligibility['sql']);
        self::assertStringContainsString('currentparts <= ?', $eligibility['sql']);
        self::assertStringContainsString('totalparts >= ?', $eligibility['sql']);
        self::assertSame([11, -20, '2026-07-12 16:00:00', 2, 10], $eligibility['bindings']);
    }

    public function test_safe_backfill_quantity_is_zero_when_any_stage_lacks_one_quantum_of_headroom(): void
    {
        config()->set('nntmux.orchestrator.backfill_headroom_fraction', 0.10);
        config()->set('nntmux.orchestrator.high_watermarks', [
            'parts' => 300_000_000,
            'binaries' => 1_000_000,
            'collections' => 100_000,
        ]);
        config()->set('nntmux.orchestrator.backfill_growth_per_10k', [
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ]);
        $state = Mockery::mock(WorkerControlStateStore::class);
        $state->shouldReceive('backfillGrowthFor')->once()->with('')->andReturn([
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ]);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'safeBackfillQuantity');

        $quantity = $method->invoke($repository, [
            'parts' => 186_000_000,
            'binaries' => 95_000,
            'collections' => 99_500,
            'releases' => 0,
            'nzbs' => 0,
        ]);

        self::assertSame(0, $quantity);
    }

    #[DataProvider('permitHandoffTargetSafetyCases')]
    public function test_permit_handoff_target_safety_requires_the_exact_pinned_candidate_and_capacity(
        ?array $observation,
        array $candidates,
        bool $expected,
    ): void {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'permitHandoffTargetSafe');

        self::assertSame($expected, $method->invoke($repository, $candidates, $observation));
    }

    /** @return array<string, array{array<string, mixed>|null, list<array<string, mixed>>, bool}> */
    public static function permitHandoffTargetSafetyCases(): array
    {
        $observation = [
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 20_000,
            'backfill_quantity' => 10_000,
        ];
        $candidate = [
            'name' => 'alt.test',
            'cursor' => 20_000,
            'cursor_postdate' => '2026-07-13 04:12:34',
            'remaining_articles' => 20_000,
            'safe_quantity' => 10_000,
        ];

        return [
            'exact pinned candidate remains safe' => [$observation, [$candidate], true],
            'candidate capacity fell below pinned quantity' => [$observation, [[...$candidate, 'safe_quantity' => 9_999]], false],
            'candidate cursor moved unexpectedly' => [$observation, [[...$candidate, 'cursor' => 19_999]], false],
            'only a different group is safe' => [$observation, [[...$candidate, 'name' => 'alt.other']], false],
            'no permit observation exists' => [null, [$candidate], false],
        ];
    }

    public function test_release_or_nzb_headroom_can_independently_close_backfill_admission(): void
    {
        config()->set('nntmux.orchestrator.backfill_headroom_fraction', 0.20);
        config()->set('nntmux.orchestrator.high_watermarks', [
            'parts' => 300_000_000,
            'binaries' => 1_000_000,
            'collections' => 20_000,
            'collections_total' => 80_000,
            'releases' => 20_000,
            'nzbs' => 12_000,
        ]);
        config()->set('nntmux.orchestrator.backfill_growth_per_10k', [
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
            'releases' => 100,
            'nzbs' => 100,
        ]);
        $state = Mockery::mock(WorkerControlStateStore::class);
        $state->shouldReceive('backfillGrowthFor')->twice()->with('')->andReturn([
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ]);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'safeBackfillQuantity');
        $base = [
            'parts' => 100_000_000,
            'binaries' => 100_000,
            'collections' => 5_000,
            'collections_total' => 30_000,
            'releases' => 100,
            'nzbs' => 100,
        ];

        self::assertSame(0, $method->invoke($repository, [...$base, 'releases' => 19_999]));
        self::assertSame(0, $method->invoke($repository, [...$base, 'nzbs' => 11_999]));
    }

    public function test_zero_capacity_candidates_are_removed_before_yield_ranking(): void
    {
        config()->set('nntmux.orchestrator.backfill_headroom_fraction', 0.20);
        config()->set('nntmux.orchestrator.high_watermarks', [
            'parts' => 300_000_000,
            'binaries' => 1_000_000,
            'collections' => 20_000,
            'collections_total' => 80_000,
        ]);
        $state = Mockery::mock(WorkerControlStateStore::class);
        $state->shouldReceive('backfillGrowthFor')->once()->with('alt.unsafe')->andReturn([
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 5_000,
        ]);
        $state->shouldReceive('backfillGrowthFor')->once()->with('alt.safe')->andReturn([
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ]);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'safeBackfillCandidates');
        $candidates = [
            ['name' => 'alt.unsafe', 'cursor' => 20_000, 'cursor_postdate' => '2026-01-02 00:00:00', 'remaining_articles' => 20_000],
            ['name' => 'alt.safe', 'cursor' => 30_000, 'cursor_postdate' => '2026-01-03 00:00:00', 'remaining_articles' => 30_000],
        ];

        self::assertSame([[
            ...$candidates[1],
            'safe_quantity' => 20_000,
        ]], $method->invoke($repository, $candidates, [
            'parts' => 192_000_000,
            'binaries' => 98_000,
            'collections' => 9_000,
            'collections_total' => 57_500,
            'releases' => 12,
            'nzbs' => 6_794,
        ]));
    }

    public function test_repository_wires_the_durable_context_repeat_into_target_selection(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.backfill_probe_groups' => ['alt.untried', 'alt.repeat'],
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_yield_ttl_seconds' => 600,
        ]);
        Cache::store('array')->flush();
        $state = new WorkerControlStateStore;
        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.repeat',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2009-01-03 00:00:00',
        ], ['cursor_postdate' => '2009-01-02 00:00:00'], 10_000, 1_999_999_800));
        $state->markBackfillContextRepeat('alt.repeat', 1_999_999_900, 7);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'selectBackfillTarget');

        $target = $method->invoke($repository, [
            ['name' => 'alt.untried', 'cursor' => 30_000, 'cursor_postdate' => '2009-01-03 00:00:00', 'remaining_articles' => 30_000, 'safe_quantity' => 30_000],
            ['name' => 'alt.repeat', 'cursor' => 40_000, 'cursor_postdate' => '2009-01-02 00:00:00', 'remaining_articles' => 40_000, 'safe_quantity' => 40_000],
        ], [], ControlState::initial(), 2_000_000_000);

        self::assertSame('alt.repeat', $target['name'] ?? null);
        self::assertSame(10_000, $target['safe_quantity'] ?? null);
    }

    public function test_repository_rejects_all_supply_when_pending_context_candidate_cursor_drifted(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.backfill_probe_groups' => ['alt.repeat', 'alt.safe'],
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_yield_ttl_seconds' => 600,
        ]);
        Cache::store('array')->flush();
        $state = new WorkerControlStateStore;
        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.repeat',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2009-01-03 00:00:00',
        ], ['cursor_postdate' => '2009-01-02 00:00:00'], 10_000, 1_999_999_800));
        $state->markBackfillContextRepeat('alt.repeat', 1_999_999_900, 7);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'selectBackfillTarget');

        $target = $method->invoke($repository, [
            ['name' => 'alt.repeat', 'cursor' => 40_000, 'cursor_postdate' => '2009-01-02 00:00:01', 'remaining_articles' => 40_000, 'safe_quantity' => 40_000],
            ['name' => 'alt.safe', 'cursor' => 30_000, 'cursor_postdate' => '2009-01-01 00:00:00', 'remaining_articles' => 30_000, 'safe_quantity' => 30_000],
        ], [], ControlState::initial(), 2_000_000_000);

        self::assertNull($target);
    }

    public function test_repository_blocks_unrelated_supply_while_delayed_attribution_is_pending(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.backfill_probe_groups' => ['alt.pending', 'alt.safe'],
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
        ]);
        Cache::store('array')->flush();
        $state = new WorkerControlStateStore;
        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.pending',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, 1_000));
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'selectBackfillTarget');

        $target = $method->invoke($repository, [
            ['name' => 'alt.pending', 'cursor' => 40_000, 'cursor_postdate' => '2026-01-03 00:00:00', 'remaining_articles' => 40_000, 'safe_quantity' => 40_000],
            ['name' => 'alt.safe', 'cursor' => 30_000, 'cursor_postdate' => '2026-01-02 00:00:00', 'remaining_articles' => 30_000, 'safe_quantity' => 30_000],
        ], [], ControlState::initial(), 2_000_000_000);

        self::assertNull($target);
    }

    public function test_repository_allows_only_one_exact_fenced_continuation_when_multiple_groups_are_pending(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.backfill_probe_groups' => ['alt.one', 'alt.two', 'alt.safe'],
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_yield_ttl_seconds' => 600,
        ]);
        Cache::store('array')->flush();
        $state = new WorkerControlStateStore;
        foreach ([7 => 'alt.one', 8 => 'alt.two'] as $generation => $group) {
            self::assertTrue($state->queueBackfillDelayedAttribution([
                'generation' => $generation,
                'backfill_group' => $group,
                'backfill_quantity' => 10_000,
                'release_high_watermark' => 100,
                'backfill_cursor_postdate' => '2009-01-03 00:00:00',
            ], ['cursor_postdate' => '2009-01-02 00:00:00'], 10_000, 1_999_999_800));
        }
        $state->markBackfillContextRepeat('alt.one', 1_999_999_900, 7);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'selectBackfillTarget');
        $candidates = [
            ['name' => 'alt.one', 'cursor' => 40_000, 'cursor_postdate' => '2009-01-02 00:00:00', 'remaining_articles' => 40_000, 'safe_quantity' => 40_000],
            ['name' => 'alt.two', 'cursor' => 50_000, 'cursor_postdate' => '2009-01-02 00:00:00', 'remaining_articles' => 50_000, 'safe_quantity' => 50_000],
            ['name' => 'alt.safe', 'cursor' => 30_000, 'cursor_postdate' => '2009-01-01 00:00:00', 'remaining_articles' => 30_000, 'safe_quantity' => 30_000],
        ];

        $target = $method->invoke($repository, $candidates, [], ControlState::initial(), 2_000_000_000);

        self::assertSame('alt.one', $target['name'] ?? null);
        self::assertSame(10_000, $target['safe_quantity'] ?? null);

        self::assertTrue($state->clearBackfillContextRepeat('alt.one'));
        self::assertNull($method->invoke($repository, $candidates, [], ControlState::initial(), 2_000_000_001));
    }

    public function test_repository_blocks_all_supply_for_invalid_pending_context_markers(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.backfill_probe_groups' => ['alt.pending', 'alt.safe'],
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_yield_ttl_seconds' => 600,
        ]);
        Cache::store('array')->flush();
        $state = new WorkerControlStateStore;
        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.pending',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2009-01-03 00:00:00',
        ], ['cursor_postdate' => '2009-01-02 00:00:00'], 10_000, 1_999_999_800));
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'selectBackfillTarget');
        $candidates = [
            ['name' => 'alt.pending', 'cursor' => 40_000, 'cursor_postdate' => '2009-01-02 00:00:00', 'remaining_articles' => 40_000, 'safe_quantity' => 40_000],
            ['name' => 'alt.safe', 'cursor' => 30_000, 'cursor_postdate' => '2009-01-01 00:00:00', 'remaining_articles' => 30_000, 'safe_quantity' => 30_000],
        ];
        $markers = [
            ['group' => 'alt.pending', 'marked_at' => 'invalid', 'generation' => 7],
            ['group' => 'alt.pending', 'marked_at' => 1_999_999_900, 'generation' => 99],
            ['group' => 'alt.pending', 'marked_at' => 1_999_999_400, 'generation' => 7],
        ];

        foreach ($markers as $marker) {
            Cache::store('array')->forever('nntmux:orchestrator:backfill-context-repeat', $marker);

            self::assertNull($method->invoke($repository, $candidates, [], ControlState::initial(), 2_000_000_000));
        }
    }

    public function test_repository_blocks_fallback_after_pending_chain_reaches_three_window_cap(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.backfill_probe_groups' => ['alt.pending', 'alt.safe'],
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_yield_ttl_seconds' => 600,
            'nntmux.orchestrator.backfill_context_max_chain_windows' => 3,
        ]);
        Cache::store('array')->flush();
        $state = new WorkerControlStateStore;
        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.pending',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2009-01-04 00:00:00',
        ], ['cursor_postdate' => '2009-01-03 00:00:00'], 10_000, 1_999_999_700));
        $state->markBackfillContextRepeat('alt.pending', 1_999_999_750, 7);
        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 8,
            'backfill_group' => 'alt.pending',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 101,
            'backfill_cursor_postdate' => '2009-01-03 00:00:00',
        ], ['cursor_postdate' => '2009-01-02 00:00:00'], 10_000, 1_999_999_800, contextContinuation: true));
        $state->markBackfillContextRepeat('alt.pending', 1_999_999_850, 8);
        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 9,
            'backfill_group' => 'alt.pending',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 102,
            'backfill_cursor_postdate' => '2009-01-02 00:00:00',
        ], ['cursor_postdate' => '2009-01-01 00:00:00'], 10_000, 1_999_999_900, contextContinuation: true));
        $state->markBackfillContextRepeat('alt.pending', 1_999_999_950, 9);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'selectBackfillTarget');

        $target = $method->invoke($repository, [
            ['name' => 'alt.pending', 'cursor' => 40_000, 'cursor_postdate' => '2009-01-01 00:00:00', 'remaining_articles' => 40_000, 'safe_quantity' => 40_000],
            ['name' => 'alt.safe', 'cursor' => 30_000, 'cursor_postdate' => '2008-12-31 00:00:00', 'remaining_articles' => 30_000, 'safe_quantity' => 30_000],
        ], [], ControlState::initial(), 2_000_000_000);

        self::assertNull($target);
        self::assertFalse($state->backfillDelayedAttributionCanContinue('alt.pending', 2_000_000_000));
    }

    public function test_repository_allows_only_the_marked_pending_chain_with_one_remaining_range(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.backfill_probe_groups' => ['alt.pending', 'alt.safe'],
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_yield_ttl_seconds' => 600,
        ]);
        Cache::store('array')->flush();
        $state = new WorkerControlStateStore;
        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.pending',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2009-01-03 03:04:05',
        ], ['cursor_postdate' => '2009-01-02 03:04:05'], 10_000, 1_999_999_800));
        $state->markBackfillContextRepeat('alt.pending', 1_999_999_900, 7);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'selectBackfillTarget');
        $candidates = [
            ['name' => 'alt.pending', 'cursor' => 40_000, 'cursor_postdate' => '2009-01-02 03:04:05', 'remaining_articles' => 40_000, 'safe_quantity' => 40_000],
            ['name' => 'alt.safe', 'cursor' => 30_000, 'cursor_postdate' => '2009-01-01 03:04:05', 'remaining_articles' => 30_000, 'safe_quantity' => 30_000],
        ];
        $controlState = new ControlState(
            profile: ControlProfile::Balanced,
            ineffectiveBackfillPermitsByTarget: ['alt.pending' => 1],
        );

        $continuation = $method->invoke($repository, $candidates, [], $controlState, 2_000_000_000);

        self::assertSame('alt.pending', $continuation['name'] ?? null);
        self::assertSame(10_000, $continuation['safe_quantity'] ?? null);

        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 8,
            'backfill_group' => 'alt.pending',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 101,
            'backfill_cursor_postdate' => '2009-01-02 03:04:05',
        ], ['cursor_postdate' => '2009-01-01 03:04:05'], 10_000, 2_000_000_001, contextContinuation: true));

        $afterMerge = $method->invoke($repository, $candidates, [], $controlState, 2_000_000_002);

        self::assertNull($afterMerge);

        self::assertTrue($state->completeBackfillDelayedAttribution(7));
        $state->markBackfillContextRepeat('alt.pending', 2_000_000_003);

        $afterSettlementCrash = $method->invoke($repository, $candidates, [], $controlState, 2_000_000_004);

        self::assertSame('alt.safe', $afterSettlementCrash['name'] ?? null);
    }

    public function test_repository_excludes_an_otherwise_eligible_terminal_group_with_pending_attribution(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.backfill_probe_groups' => ['alt.terminal'],
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_terminal_min_attempts' => 3,
            'nntmux.orchestrator.backfill_terminal_min_yield' => 1.0,
        ]);
        Cache::store('array')->flush();
        $state = new WorkerControlStateStore;
        self::assertTrue($state->queueBackfillDelayedAttribution([
            'generation' => 8,
            'backfill_group' => 'alt.terminal',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, 1_000));
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'selectBackfillTarget');

        $target = $method->invoke($repository, [[
            'name' => 'alt.terminal',
            'cursor' => 16_389,
            'cursor_postdate' => '2008-10-24 01:12:31',
            'remaining_articles' => 16_387,
            'safe_quantity' => 50_000,
        ]], [
            'alt.terminal' => [
                'attempts' => 54,
                'ewma_nzbs_per_10k' => 1.442194,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_998_000,
                'last_cursor_delta' => 80_000,
            ],
        ], ControlState::initial(), 2_000_000_000);

        self::assertNull($target);
    }

    public function test_legacy_snapshot_resets_new_collection_split_rates_without_a_fake_drain(): void
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'rates');
        [$rates, $ewma] = $method->invoke($repository, [
            'parts' => 190_000_000,
            'binaries' => 80_000,
            'collections' => 8_000,
            'collections_total' => 48_000,
            'recovery_sources' => 40_000,
            'releases' => 12,
            'nzbs' => 6_791,
        ], [
            'parts' => 189_999_000,
            'binaries' => 80_100,
            'collections' => 49_000,
            'observed_at' => time() - 60,
            'ewma_collections' => 123.0,
        ]);

        self::assertSame(0.0, $rates['collections']);
        self::assertSame(0.0, $rates['collections_total']);
        self::assertSame(0.0, $rates['recovery_sources']);
        self::assertSame(0.0, $ewma['collections']);
        self::assertEquals(1000.0, $rates['parts']);
    }

    public function test_stale_or_future_previous_snapshots_cannot_influence_rates_or_ewma(): void
    {
        config(['nntmux.orchestrator.snapshot_max_age_seconds' => 180]);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'rates');

        foreach ([time() - 181, time() + 1] as $observedAt) {
            [$rates, $ewma] = $method->invoke($repository, ['parts' => 200], [
                'parts' => 100,
                'ewma_parts' => 99.0,
                'observed_at' => $observedAt,
            ]);

            self::assertSame(0.0, $rates['parts']);
            self::assertSame(0.0, $ewma['parts']);
        }
    }

    public function test_backfill_candidates_are_bounded_to_fresh_current_valid_ranges(): void
    {
        config()->set('nntmux.orchestrator.backfill_probe_groups', ['alt.backfill-only', 'alt.active']);

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('g.backfill = 1', $sql);
                self::assertStringContainsString('BINARY g.name IN (?, ?)', $sql);
                self::assertStringContainsString('g.active = 1', $sql);
                self::assertStringContainsString('g.active = 0', $sql);
                self::assertStringContainsString('s.updated >= NOW() - INTERVAL 10 MINUTE', $sql);
                self::assertStringContainsString('CAST(s.last_record AS SIGNED) - CAST(g.last_record AS SIGNED) <= 10000', $sql);
                self::assertStringContainsString('CAST(g.last_record AS SIGNED) BETWEEN CAST(s.first_record AS SIGNED) AND CAST(s.last_record AS SIGNED)', $sql);
                self::assertStringContainsString('CAST(g.first_record AS SIGNED) <= CAST(g.last_record AS SIGNED) + 1', $sql);
                self::assertStringContainsString('CAST(g.first_record AS SIGNED) - CAST(s.first_record AS SIGNED) > 10000', $sql);
                self::assertStringContainsString("g.first_record_postdate >= '2000-01-01'", $sql);
                self::assertStringContainsString("g.last_record_postdate >= '2000-01-01'", $sql);
                self::assertStringContainsString('CAST(g.last_record AS SIGNED) < 9223372036854775807', $sql);
                self::assertStringContainsString('LIMIT 16', $sql);
                self::assertSame(['alt.backfill-only', 'alt.active'], $bindings);

                return true;
            })
            ->andReturn([(object) [
                'name' => 'alt.test',
                'backfill_cursor' => 100_000,
                'cursor_postdate' => '2020-01-01 00:00:00',
                'remaining_articles' => 90_000,
            ]]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([[
            'name' => 'alt.test',
            'cursor' => 100_000,
            'cursor_postdate' => '2020-01-01 00:00:00',
            'remaining_articles' => 90_000,
        ]], $repository->backfillCandidates());
    }

    public function test_backfill_candidate_remaining_articles_are_capped_at_the_configured_source_stop_cursor(): void
    {
        config()->set('nntmux.orchestrator.backfill_probe_groups', ['alt.test']);
        config()->set('nntmux.orchestrator.backfill_stop_cursors', 'alt.test:60000');
        DB::shouldReceive('select')->once()->andReturn([(object) [
            'name' => 'alt.test',
            'backfill_cursor' => 100_000,
            'cursor_postdate' => '2020-01-01 00:00:00',
            'remaining_articles' => 90_000,
        ]]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([[
            'name' => 'alt.test',
            'cursor' => 100_000,
            'cursor_postdate' => '2020-01-01 00:00:00',
            'remaining_articles' => 50_000,
        ]], $repository->backfillCandidates());
    }

    public function test_backfill_candidate_stop_cursor_fails_closed_once_reached_or_when_invalid(): void
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'remainingArticlesWithinStopCursor');

        config()->set('nntmux.orchestrator.backfill_stop_cursors', 'alt.test:60000');
        self::assertSame(0, $method->invoke($repository, 'alt.test', 60_000, 50_000));

        config()->set('nntmux.orchestrator.backfill_stop_cursors', 'alt.test:not-a-cursor');
        self::assertSame(0, $method->invoke($repository, 'alt.test', 100_000, 90_000));
    }

    public function test_backfill_candidates_fail_closed_without_an_explicit_allowlist(): void
    {
        config()->set('nntmux.orchestrator.backfill_probe_groups', []);
        DB::shouldReceive('select')->never();

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([], $repository->backfillCandidates());
    }

    public function test_backfill_candidates_trim_deduplicate_and_bind_exact_allowlist_values(): void
    {
        config()->set('nntmux.orchestrator.backfill_probe_groups', [
            ' alt.exact ',
            'alt.exact',
            "alt.quote'bound",
            '',
        ]);
        DB::shouldReceive('select')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('BINARY g.name IN (?, ?)', $sql);
                self::assertSame(['alt.exact', "alt.quote'bound"], $bindings);

                return true;
            })
            ->andReturn([]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([], $repository->backfillCandidates());
    }

    public function test_backfill_candidates_fail_closed_when_allowlist_exceeds_retained_history_bound(): void
    {
        config()->set('nntmux.orchestrator.backfill_probe_groups', array_map(
            static fn (int $index): string => 'alt.probe.'.$index,
            range(1, 17),
        ));
        DB::shouldReceive('select')->never();

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([], $repository->backfillCandidates());
    }

    public function test_pending_cohort_query_matches_collection_and_binary_completion_gates(): void
    {
        config()->set('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 14_400);
        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        Settings::query()->updateOrCreate(['name' => 'completionpercent'], ['value' => '94']);
        DB::shouldReceive('scalar')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('c.filecheck IN (0, 1, 2, 3, 10, 15, 16)', $sql);
                self::assertStringContainsString('GROUP BY c.id, c.totalfiles, c.filecheck, c.dateadded', $sql);
                self::assertStringContainsString('COALESCE(NULLIF(c.totalfiles, 0), MAX(NULLIF(b.filenumber, 0)), 0) > 0', $sql);
                self::assertStringContainsString('COUNT(DISTINCT CASE WHEN b.filenumber > 0 THEN b.filenumber ELSE b.id END)', $sql);
                self::assertStringContainsString('WHEN b.totalparts > 0 AND b.currentparts >= CEIL(b.totalparts * ? / 100) THEN 1', $sql);
                self::assertStringContainsString('END) >= GREATEST(1, CEIL(COALESCE(NULLIF(c.totalfiles, 0), MAX(NULLIF(b.filenumber, 0)), 0) * ? / 100))', $sql);
                self::assertStringContainsString('c.filecheck IN (0, 1, 10)', $sql);
                self::assertStringContainsString('c.dateadded < DATE_SUB(NOW(), INTERVAL ? HOUR)', $sql);
                self::assertSame(2, substr_count(
                    $sql,
                    'COUNT(DISTINCT CASE WHEN b.filenumber > 0 THEN b.filenumber ELSE b.id END)',
                ));
                self::assertStringContainsString('END) = COUNT(b.id)', $sql);
                self::assertStringContainsString('c.filecheck = 3', $sql);
                self::assertSame([
                    'alt.test',
                    '2026-07-13 04:13:35',
                    '2026-07-13 04:12:34',
                    14_400,
                    '2026-07-13 04:13:35',
                    '2026-07-13 04:12:34',
                    14_400,
                    94,
                    94,
                    94,
                    2,
                    94,
                    94,
                ], $bindings);

                return true;
            })
            ->andReturn(0);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame(0, $repository->backfillPendingCollectionsForCohort(
            'alt.test',
            '2026-07-13 04:13:35',
            '2026-07-13 04:12:34',
        ));
    }

    public function test_pending_cohort_completion_uses_the_release_pipeline_zero_fallback(): void
    {
        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'backfillCompletionPercent');

        Settings::query()->updateOrCreate(['name' => 'completionpercent'], ['value' => '0']);
        self::assertSame(100, $method->invoke($repository));

        Settings::query()->updateOrCreate(['name' => 'completionpercent'], ['value' => '94']);
        self::assertSame(94, $method->invoke($repository));
    }

    public function test_group_outcome_uses_a_mariadb_safe_cursor_alias(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('AS backfill_cursor', $sql);
                self::assertDoesNotMatchRegularExpression('/\bAS cursor(?:\s|,|$)/i', $sql);
                self::assertStringContainsString('g.active AS group_active', $sql);
                self::assertStringContainsString('AS raw_collections', $sql);
                self::assertStringContainsString('AS raw_binaries', $sql);
                self::assertStringContainsString('AS partial_collections', $sql);
                self::assertStringContainsString('AS complete_binaries', $sql);
                self::assertSame(['alt.test'], $bindings);

                return true;
            })
            ->andReturn((object) [
                'backfill_cursor' => 12345,
                'cursor_postdate' => '2026-01-02 03:04:05',
                'ready_collections' => 6,
                'releases' => 7,
                'release_high_watermark' => 8,
                'group_active' => 0,
                'raw_collections' => 11,
                'raw_binaries' => 12,
                'partial_collections' => 9,
                'complete_binaries' => 10,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([
            'cursor' => 12345,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 6,
            'releases' => 7,
            'release_high_watermark' => 8,
            'group_active' => 0,
            'raw_collections' => 11,
            'raw_binaries' => 12,
            'partial_collections' => 9,
            'complete_binaries' => 10,
        ], $repository->backfillOutcomeForGroup('alt.test'));
    }

    public function test_completed_nzbs_for_a_carried_release_cohort_are_bounded_by_id_and_postdate(): void
    {
        DB::shouldReceive('scalar')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('r.id > ?', $sql);
                self::assertStringContainsString('r.id <= ?', $sql);
                self::assertStringContainsString('r.nzbstatus = 1', $sql);
                self::assertSame(['alt.test', 100, 103, '2026-01-02 03:04:05', '2026-01-01 03:04:05', 3600, '2026-01-02 03:04:05', '2026-01-01 03:04:05', 3600], $bindings);

                return true;
            })
            ->andReturn(2);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame(2, $repository->backfillCompletedNzbsForReleaseCohort(
            'alt.test',
            100,
            103,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }

    public function test_carried_cohort_category_counts_keep_the_release_id_upper_bound(): void
    {
        config()->set('nntmux.orchestrator.backfill_min_payload_bytes', 104857600);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('r.id > ?', $sql);
                self::assertStringContainsString('r.id <= ?', $sql);
                self::assertStringContainsString('c.root_categories_id NOT IN (1, 2000, 5000)', $sql);
                self::assertStringContainsString('c.id NOT IN (2999, 5999)', $sql);
                self::assertStringContainsString('c.id IN (2999, 5999)', $sql);
                self::assertSame(6, substr_count($sql, "LOWER(COALESCE(r.name, '')) REGEXP"));
                self::assertSame(6, substr_count($sql, "LOWER(COALESCE(r.searchname, '')) REGEXP"));
                self::assertSame(2, substr_count($sql, 'r.size >= ?'));
                $episode = '(^|[^[:alnum:]])s[0-9]{1,2}[ ._-]*e[0-9]{1,3}([^[:alnum:]]|$)';
                $dateRange = '(^|[^[:alnum:]])(0?[1-9]|[12][0-9]|3[01])\.-(0?[1-9]|[12][0-9]|3[01])\.(0?[1-9]|1[0-2])\.[0-9]{2}([^[:alnum:]]|$)';
                $completeSeries = '(^|[^[:alnum:]])komplett[ ._-]+abenteuerserie[ ._-]+(19|20)[0-9]{2}([^[:alnum:]]|$).*(avi|mkv|mp4|mpeg|xvid|divx|h\\.?26[45])([^[:alnum:]]|$)';
                self::assertSame([
                    $episode, $episode, 0, $dateRange, $dateRange, 0, $completeSeries, $completeSeries, 104857600,
                    $episode, $episode, 0, $dateRange, $dateRange, 0, $completeSeries, $completeSeries, 104857600,
                    'alt.test', 100, 103,
                    '2026-01-02 03:04:05', '2026-01-01 03:04:05', 3600,
                    '2026-01-02 03:04:05', '2026-01-01 03:04:05', 3600,
                ], $bindings);

                return true;
            })
            ->andReturn((object) [
                'target_count' => 0,
                'non_target_count' => 1,
                'uncategorized_count' => 0,
                'target_bytes' => 0,
                'non_target_bytes' => 100,
                'uncategorized_bytes' => 0,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([
            'target' => 0,
            'non_target' => 1,
            'uncategorized' => 0,
        ], $repository->backfillCompletedNzbCategoryCountsForReleaseCohort(
            'alt.test',
            100,
            103,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }

    #[DataProvider('tvEpisodeIdentityProvider')]
    public function test_tv_other_episode_identity_is_conservative_and_null_safe(
        ?string $name,
        ?string $searchName,
        bool $expected,
    ): void {
        $reflection = new ReflectionClass(PipelineSnapshotRepository::class);
        $pattern = $reflection->getReflectionConstant('BACKFILL_TV_EPISODE_PATTERN')?->getValue();

        self::assertIsString($pattern);
        self::assertSame(
            $expected,
            preg_match('/'.$pattern.'/i', $name ?? '') === 1
                || preg_match('/'.$pattern.'/i', $searchName ?? '') === 1,
        );
    }

    /** @return iterable<string, array{?string, ?string, bool}> */
    public static function tvEpisodeIdentityProvider(): iterable
    {
        yield 'search name SxxExx' => [null, 'Show.S01E02.1080p', true];
        yield 'release name only with separators' => ['Show S1 E2', null, true];
        yield 'nullable search name' => ['Another.Show.S12E103.WEB', null, true];
        yield 'unsafe one-x form' => ['Show.1x02.1080p', null, false];
        yield 'embedded token without boundaries' => ['XS01E01Y', null, false];
        yield 'token split across fields' => ['Show.S01', 'E02.1080p', false];
        yield 'both fields null' => [null, null, false];
        yield 'TV Other without an episode token' => ['Show Season One Pack', null, false];
    }

    #[DataProvider('tvDateRangeIdentityProvider')]
    public function test_tv_other_date_range_identity_requires_a_valid_explicit_day_range(
        string $name,
        bool $expected,
    ): void {
        $reflection = new ReflectionClass(PipelineSnapshotRepository::class);
        $pattern = $reflection->getReflectionConstant('BACKFILL_TV_DATE_RANGE_PATTERN')?->getValue();

        self::assertIsString($pattern);
        self::assertSame($expected, preg_match('/'.$pattern.'/i', $name) === 1);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function tvDateRangeIdentityProvider(): iterable
    {
        yield 'observed Unter uns batch' => ['Unter uns 6.-9.7.26 by Uwealex01', true];
        yield 'observed Alles was zaehlt batch' => ['Alles was zaehlt 6.-10.7.26', true];
        yield 'single date is not a batch' => ['Release 9.7.26', false];
        yield 'missing dotted range separator' => ['Software 6-9.7.26', false];
        yield 'invalid first day' => ['Show 36.-9.7.26', false];
        yield 'invalid second day' => ['Show 6.-39.7.26', false];
        yield 'invalid month' => ['Show 6.-9.17.26', false];
        yield 'four digit year is outside observed identity' => ['Show 6.-9.7.2026', false];
    }

    public function test_group_cohort_nzb_count_is_bounded_by_release_id_and_tolerates_provider_date_disorder(): void
    {
        config()->set('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);

        DB::shouldReceive('scalar')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('r.id > ?', $sql);
                self::assertStringContainsString('r.nzbstatus = 1', $sql);
                self::assertStringContainsString('r.postdate BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)', $sql);
                self::assertStringContainsString('AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)', $sql);
                self::assertSame([
                    'alt.test',
                    123,
                    '2026-01-02 03:04:05',
                    '2026-01-01 03:04:05',
                    3600,
                    '2026-01-02 03:04:05',
                    '2026-01-01 03:04:05',
                    3600,
                ], $bindings);

                return true;
            })
            ->andReturn(3);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame(3, $repository->backfillCreatedNzbsForCohort(
            'alt.test',
            123,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }

    public function test_group_cohort_completed_nzbs_are_split_into_target_wrong_and_uncategorized_roots(): void
    {
        config()->set('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);
        config()->set('nntmux.orchestrator.backfill_min_payload_bytes', 104857600);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('AS target', $sql);
                self::assertStringContainsString('AS non_target', $sql);
                self::assertStringContainsString('AS uncategorized', $sql);
                self::assertStringContainsString('LEFT JOIN categories c ON c.id = r.categories_id', $sql);
                self::assertStringContainsString('c.root_categories_id IN (2000, 5000)', $sql);
                self::assertStringContainsString('c.root_categories_id NOT IN (1, 2000, 5000)', $sql);
                self::assertStringContainsString('c.root_categories_id = 1', $sql);
                self::assertStringContainsString('c.id NOT IN (2999, 5999)', $sql);
                self::assertStringContainsString('c.id IN (2999, 5999)', $sql);
                self::assertSame(6, substr_count($sql, "LOWER(COALESCE(r.name, '')) REGEXP"));
                self::assertSame(6, substr_count($sql, "LOWER(COALESCE(r.searchname, '')) REGEXP"));
                self::assertStringContainsString('r.id > ?', $sql);
                self::assertStringContainsString('r.nzbstatus = 1', $sql);
                self::assertSame(2, substr_count($sql, 'r.size >= ?'));
                $episode = '(^|[^[:alnum:]])s[0-9]{1,2}[ ._-]*e[0-9]{1,3}([^[:alnum:]]|$)';
                $dateRange = '(^|[^[:alnum:]])(0?[1-9]|[12][0-9]|3[01])\.-(0?[1-9]|[12][0-9]|3[01])\.(0?[1-9]|1[0-2])\.[0-9]{2}([^[:alnum:]]|$)';
                $completeSeries = '(^|[^[:alnum:]])komplett[ ._-]+abenteuerserie[ ._-]+(19|20)[0-9]{2}([^[:alnum:]]|$).*(avi|mkv|mp4|mpeg|xvid|divx|h\\.?26[45])([^[:alnum:]]|$)';
                self::assertSame([
                    $episode, $episode, 0, $dateRange, $dateRange, 0, $completeSeries, $completeSeries, 104857600,
                    $episode, $episode, 0, $dateRange, $dateRange, 0, $completeSeries, $completeSeries, 104857600,
                    'alt.test', 123,
                    '2026-01-02 03:04:05', '2026-01-01 03:04:05', 3600,
                    '2026-01-02 03:04:05', '2026-01-01 03:04:05', 3600,
                ], $bindings);

                return true;
            })
            ->andReturn((object) [
                'target_count' => 2,
                'non_target_count' => 1,
                'uncategorized_count' => 3,
                'target_bytes' => 900,
                'non_target_bytes' => 100,
                'uncategorized_bytes' => 300,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([
            'target' => 2,
            'non_target' => 1,
            'uncategorized' => 3,
        ], $repository->backfillCreatedNzbCategoryCountsForCohort(
            'alt.test',
            123,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }

    public function test_group_cohort_created_releases_are_classified_before_their_nzbs_complete(): void
    {
        config()->set('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);
        config()->set('nntmux.orchestrator.backfill_min_payload_bytes', 104857600);
        config()->set('nntmux.orchestrator.backfill_tv_date_range_groups', ['alt.other']);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('AS target', $sql);
                self::assertStringContainsString('AS non_target', $sql);
                self::assertStringContainsString('AS uncategorized', $sql);
                self::assertStringNotContainsString('r.nzbstatus = 1', $sql);
                self::assertSame('alt.test', $bindings[18] ?? null);
                self::assertSame(123, $bindings[19] ?? null);
                self::assertSame(0, $bindings[2] ?? null);
                self::assertSame(0, $bindings[5] ?? null);
                self::assertSame(0, $bindings[11] ?? null);
                self::assertSame(0, $bindings[14] ?? null);

                return true;
            })
            ->andReturn((object) [
                'target_count' => 4,
                'non_target_count' => 0,
                'uncategorized_count' => 0,
                'target_bytes' => 4_000,
                'non_target_bytes' => 0,
                'uncategorized_bytes' => 0,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([
            'target' => 4,
            'non_target' => 0,
            'uncategorized' => 0,
        ], $repository->backfillCreatedReleaseCategoryCountsForCohort(
            'alt.test',
            123,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }

    public function test_group_cohort_category_bytes_use_the_same_payload_identity_and_completed_nzb_fence(): void
    {
        config()->set('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);
        config()->set('nntmux.orchestrator.backfill_min_payload_bytes', 104857600);
        config()->set('nntmux.orchestrator.backfill_tv_date_range_groups', ['alt.test']);
        config()->set('nntmux.orchestrator.backfill_tv_complete_series_groups', ['alt.test']);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('THEN classified.size ELSE 0 END', $sql);
                self::assertStringContainsString('AS target_count', $sql);
                self::assertStringContainsString('AS target_bytes', $sql);
                self::assertStringContainsString('r.nzbstatus = 1', $sql);
                self::assertStringNotContainsString('r.id <= ?', $sql);
                self::assertSame('alt.test', $bindings[18] ?? null);
                self::assertSame(123, $bindings[19] ?? null);
                self::assertSame(1, $bindings[2] ?? null);
                self::assertSame(1, $bindings[5] ?? null);
                self::assertSame(1, $bindings[11] ?? null);
                self::assertSame(1, $bindings[14] ?? null);

                return true;
            })
            ->andReturn((object) [
                'target_count' => 1,
                'non_target_count' => 1,
                'uncategorized_count' => 0,
                'target_bytes' => 3_867_698_952,
                'non_target_bytes' => 324_000_760,
                'uncategorized_bytes' => 0,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([
            'target' => 3_867_698_952,
            'non_target' => 324_000_760,
            'uncategorized' => 0,
        ], $repository->backfillCreatedNzbCategoryBytesForCohort(
            'alt.test',
            123,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }

    public function test_created_release_category_bytes_do_not_require_completed_nzbs(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('THEN classified.size ELSE 0 END', $sql);
                self::assertStringNotContainsString('r.nzbstatus = 1', $sql);
                self::assertSame('alt.test', $bindings[18] ?? null);
                self::assertSame(123, $bindings[19] ?? null);

                return true;
            })
            ->andReturn((object) [
                'target_count' => 1,
                'non_target_count' => 1,
                'uncategorized_count' => 0,
                'target_bytes' => 900,
                'non_target_bytes' => 100,
                'uncategorized_bytes' => 0,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame(
            ['target' => 900, 'non_target' => 100, 'uncategorized' => 0],
            $repository->backfillCreatedReleaseCategoryBytesForCohort(
                'alt.test',
                123,
                '2026-01-02 03:04:05',
                '2026-01-01 03:04:05',
            ),
        );
    }

    public function test_carried_release_category_bytes_are_generation_bounded(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('r.nzbstatus = 1', $sql);
                self::assertStringContainsString('r.id <= ?', $sql);
                self::assertSame('alt.test', $bindings[18] ?? null);
                self::assertSame(100, $bindings[19] ?? null);
                self::assertSame(103, $bindings[20] ?? null);

                return true;
            })
            ->andReturn((object) [
                'target_count' => 1,
                'non_target_count' => 1,
                'uncategorized_count' => 0,
                'target_bytes' => 900,
                'non_target_bytes' => 100,
                'uncategorized_bytes' => 0,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame(
            ['target' => 900, 'non_target' => 100, 'uncategorized' => 0],
            $repository->backfillCompletedNzbCategoryBytesForReleaseCohort(
                'alt.test',
                100,
                103,
                '2026-01-02 03:04:05',
                '2026-01-01 03:04:05',
            ),
        );

        self::assertSame(
            ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
            $repository->backfillCompletedNzbCategoryBytesForReleaseCohort(
                'alt.test',
                103,
                103,
                '2026-01-02 03:04:05',
                '2026-01-01 03:04:05',
            ),
        );
    }

    public function test_atomic_carried_release_quality_returns_counts_and_bytes_with_one_upper_bounded_query(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('AS target_count', $sql);
                self::assertStringContainsString('AS target_bytes', $sql);
                self::assertStringContainsString('r.id <= ?', $sql);
                self::assertSame(103, $bindings[20] ?? null);

                return true;
            })
            ->andReturn((object) [
                'target_count' => 3,
                'non_target_count' => 1,
                'uncategorized_count' => 0,
                'target_bytes' => 3_867_698_952,
                'non_target_bytes' => 324_000_760,
                'uncategorized_bytes' => 0,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([
            'counts' => ['target' => 3, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 3_867_698_952, 'non_target' => 324_000_760, 'uncategorized' => 0],
        ], $repository->backfillCompletedNzbCategoryQualityForReleaseCohort(
            'alt.test',
            100,
            103,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }

    public function test_release_backlog_only_counts_collections_from_processable_groups(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/Services/Orchestrator/PipelineSnapshotRepository.php');

        self::assertIsString($source);
        self::assertStringContainsString(
            '(SELECT COUNT(*) FROM collections c INNER JOIN usenet_groups g ON g.id = c.groups_id WHERE c.filecheck = 3 AND (g.active = 1 OR g.backfill = 1)) AS releases_backlog',
            $source,
        );
        self::assertStringNotContainsString(
            '(SELECT COUNT(*) FROM collections WHERE filecheck = 3) AS releases_backlog',
            $source,
        );
    }

    public function test_group_cohort_release_count_uses_the_same_exact_attribution_window_without_requiring_an_nzb(): void
    {
        config()->set('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);

        DB::shouldReceive('scalar')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('r.id > ?', $sql);
                self::assertStringNotContainsString('r.nzbstatus = 1', $sql);
                self::assertStringContainsString('r.postdate BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)', $sql);
                self::assertStringContainsString('AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)', $sql);
                self::assertSame([
                    'alt.test',
                    123,
                    '2026-01-02 03:04:05',
                    '2026-01-01 03:04:05',
                    3600,
                    '2026-01-02 03:04:05',
                    '2026-01-01 03:04:05',
                    3600,
                ], $bindings);

                return true;
            })
            ->andReturn(2);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame(2, $repository->backfillCreatedReleasesForCohort(
            'alt.test',
            123,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }

    public function test_body_recovery_queue_excludes_ordinary_and_exhausted_missed_parts(): void
    {
        config()->set('nntmux.body_preamble_deobfuscate_groups', 'alt.test');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
            groups_id INTEGER,
            attempts INTEGER,
            recovery_kind VARCHAR(32)
        )');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        DB::table('missed_parts')->insert([
            ['id' => 1, 'groups_id' => 1, 'attempts' => 0, 'recovery_kind' => 'body_preamble'],
            ['id' => 2, 'groups_id' => 1, 'attempts' => 0, 'recovery_kind' => null],
            ['id' => 3, 'groups_id' => 1, 'attempts' => 3, 'recovery_kind' => 'body_preamble'],
        ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'bodyRecoveryQueueBacklog');

        self::assertSame(1, $method->invoke($repository));
    }
}
