<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\FailSafeCause;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class WorkerControlStateStoreTest extends TestCase
{
    public function test_leader_lease_is_bounded_to_two_minutes_minimum(): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_LEADER_LOCK_SECONDS';
        $previous = getenv($key);
        putenv($key.'=30');
        $_ENV[$key] = '30';
        $_SERVER[$key] = '30';

        try {
            $configuration = require base_path('config/nntmux.php');

            self::assertSame(120, $configuration['orchestrator']['leader_lock_seconds']);
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

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
        ]);
        Cache::store('array')->flush();
    }

    public function test_it_round_trips_all_control_state_fields(): void
    {
        $store = new WorkerControlStateStore;
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            consecutiveHigh: 3,
            consecutiveLow: 5,
            lastTransitionAt: 1_000,
            cooldownUntil: 2_000,
            consecutiveIneffectiveBackfillPermits: 1,
            backfillLocked: true,
            ineffectiveBackfillPermitsByTarget: ['alt.a' => 2, 'alt.b' => 1],
            failSafeCause: FailSafeCause::Telemetry,
            failSafeRecoverySamples: 1,
            failSafeLastObservedAt: 999,
            recoveryDrainSamples: 2,
            recoveryDrainHoldSamples: 1,
        );

        $store->storeState($state);

        self::assertEquals($state, $store->loadState());
    }

    public function test_a_legacy_fail_safe_state_without_recovery_metadata_loads_as_unknown(): void
    {
        Cache::store('array')->forever('nntmux:orchestrator:state', [
            'profile' => ControlProfile::FailSafe->value,
            'cooldown_until' => 2_000,
        ]);

        $state = (new WorkerControlStateStore)->loadState();

        self::assertSame(FailSafeCause::Unknown, $state->failSafeCause);
        self::assertSame(0, $state->failSafeRecoverySamples);
        self::assertSame(0, $state->recoveryDrainHoldSamples);
    }

    public function test_it_round_trips_the_snapshot_projection_used_for_delta_calculation(): void
    {
        $store = new WorkerControlStateStore;
        $store->storeSnapshot(new PipelineSnapshot(
            partsBacklog: 11,
            binariesBacklog: 22,
            collectionsBacklog: 33,
            releasesBacklog: 44,
            nzbsBacklog: 55,
            databaseDeadlocks: 6,
            databaseCurrentWaits: 3,
            observedAt: 7,
        ));

        self::assertSame([
            'schema_version' => 2,
            'parts' => 11,
            'binaries' => 22,
            'collections' => 33,
            'collections_total' => 33,
            'recovery_sources' => 0,
            'releases' => 44,
            'nzbs' => 55,
            'database_deadlocks' => 6,
            'database_current_waits' => 3,
            'observed_at' => 7,
        ], $store->previousSnapshot());
    }

    public function test_it_round_trips_and_clears_a_backfill_permit_observation(): void
    {
        $store = new WorkerControlStateStore;
        $store->beginPermitObservation(new PipelineSnapshot(
            partsBacklog: 11,
            binariesBacklog: 22,
            collectionsBacklog: 33,
            releasesBacklog: 44,
            nzbsBacklog: 55,
            readyCollections: 66,
            releaseTotal: 77,
            backfillGroup: 'alt.test',
            backfillCursor: 12345,
        ), generation: 8, now: 9, outcome: [
            'cursor' => 12345,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 66,
            'releases' => 77,
            'release_high_watermark' => 88,
        ]);

        self::assertSame([
            'schema_version' => 2,
            'generation' => 8,
            'issued_at' => 9,
            'parts' => 11,
            'binaries' => 22,
            'baseline_backlogs' => ['parts' => 11, 'binaries' => 22, 'collections' => 33],
            'peak_backlogs' => ['parts' => 11, 'binaries' => 22, 'collections' => 33],
            'ready_collections' => 66,
            'release_total' => 77,
            'release_high_watermark' => 88,
            'baseline_deadlocks' => 0,
            'safety_clean' => true,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 12345,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
            'backfill_quantity' => 10000,
        ], $store->permitObservation());

        $store->clearPermitObservation();

        self::assertNull($store->permitObservation());
    }

    public function test_permit_completion_observation_is_generation_fenced_and_idempotent(): void
    {
        $store = new WorkerControlStateStore;
        $store->beginPermitObservation(new PipelineSnapshot(
            partsBacklog: 1,
            binariesBacklog: 2,
            collectionsBacklog: 3,
            releasesBacklog: 0,
            nzbsBacklog: 0,
            backfillGroup: 'alt.test',
            backfillCursor: 20_000,
        ), generation: 7, now: 100, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 10,
        ]);

        self::assertArrayNotHasKey('completed_observed_at', $store->observePermitCompletion(8, 1_000) ?? []);
        self::assertSame(1_000, $store->observePermitCompletion(7, 1_000)['completed_observed_at'] ?? null);
        self::assertSame(1_000, $store->observePermitCompletion(7, 2_000)['completed_observed_at'] ?? null);
    }

    public function test_incomplete_release_cohort_is_consumed_only_by_the_next_same_group_permit(): void
    {
        $store = new WorkerControlStateStore;
        $store->rememberIncompleteReleaseCohort([
            'backfill_group' => 'alt.test',
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], 103, '2026-01-01 03:04:05', 1_000);

        $store->beginPermitObservation(new PipelineSnapshot(
            1, 2, 3, 4, 5, backfillGroup: 'alt.other', backfillCursor: 10_000,
        ), 8, 1_001, [
            'cursor' => 10_000,
            'cursor_postdate' => '2025-12-31 03:04:05',
            'ready_collections' => 0,
            'releases' => 3,
            'release_high_watermark' => 103,
        ]);

        self::assertArrayNotHasKey('prior_release_cohort', $store->permitObservation() ?? []);

        $store->clearPermitObservation();
        $store->beginPermitObservation(new PipelineSnapshot(
            1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 10_000,
        ), 9, 1_002, [
            'cursor' => 10_000,
            'cursor_postdate' => '2025-12-30 03:04:05',
            'ready_collections' => 0,
            'releases' => 3,
            'release_high_watermark' => 103,
        ]);

        self::assertSame([
            'id_low_exclusive' => 100,
            'id_high_inclusive' => 103,
            'cursor_start_postdate' => '2026-01-02 03:04:05',
            'cursor_end_postdate' => '2026-01-01 03:04:05',
        ], $store->permitObservation()['prior_release_cohort'] ?? null);

        $store->clearPermitObservation();
        $store->beginPermitObservation(new PipelineSnapshot(
            1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 10_000,
        ), 10, 1_003, [
            'cursor' => 10_000,
            'cursor_postdate' => '2025-12-30 03:04:05',
            'ready_collections' => 0,
            'releases' => 3,
            'release_high_watermark' => 103,
        ]);

        self::assertArrayNotHasKey('prior_release_cohort', $store->permitObservation() ?? []);
    }

    public function test_permit_observation_retains_peak_backlogs_after_later_drain(): void
    {
        $store = new WorkerControlStateStore;
        $store->beginPermitObservation(new PipelineSnapshot(
            partsBacklog: 11,
            binariesBacklog: 22,
            collectionsBacklog: 33,
            releasesBacklog: 44,
            nzbsBacklog: 55,
            backfillGroup: 'alt.test',
        ), generation: 8, now: 9, outcome: [
            'cursor' => 12345,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 66,
            'releases' => 77,
            'release_high_watermark' => 88,
        ]);

        $store->updatePermitObservationPeaks(new PipelineSnapshot(15, 20, 50, 44, 55));
        $observation = $store->updatePermitObservationPeaks(new PipelineSnapshot(12, 30, 40, 44, 55));

        self::assertSame([
            'parts' => 11,
            'binaries' => 22,
            'collections' => 33,
        ], $observation['baseline_backlogs'] ?? null);
        self::assertSame([
            'parts' => 15,
            'binaries' => 30,
            'collections' => 50,
        ], $observation['peak_backlogs'] ?? null);
    }

    public function test_growth_safety_rejects_invalid_telemetry_and_conflicting_pressure(): void
    {
        $store = new WorkerControlStateStore;
        $store->beginPermitObservation(new PipelineSnapshot(
            -1, 2, 3, 4, 5,
            highPressure: true,
            lowPressure: true,
            backfillGroup: 'alt.test',
        ), 8, 9, [
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 10,
        ]);

        self::assertFalse($store->permitObservation()['safety_clean'] ?? true);
    }

    public function test_exact_completed_permits_learn_conservative_backlog_growth_without_lowering_static_priors(): void
    {
        config()->set('nntmux.orchestrator.backfill_growth_per_10k', [
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ]);
        $store = new WorkerControlStateStore;
        $observation = [
            'schema_version' => 2,
            'generation' => 1,
            'safety_clean' => true,
            'backfill_quantity' => 20_000,
            'baseline_backlogs' => ['parts' => 100, 'binaries' => 200, 'collections' => 300],
            'peak_backlogs' => ['parts' => 20_100, 'binaries' => 1_400, 'collections' => 2_100],
        ];

        self::assertTrue($store->recordBackfillGrowth('alt.test', $observation, 20_000, 20_000));
        self::assertSame([
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ], $store->backfillGrowthFor('alt.other'));

        self::assertTrue($store->recordBackfillGrowth('alt.test', [...$observation, 'generation' => 2], 20_000, 20_000));
        self::assertSame([
            'parts' => 12_500,
            'binaries' => 750,
            'collections' => 1_125,
        ], $store->backfillGrowthFor('alt.test'));
        self::assertSame([
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ], $store->backfillGrowthFor('alt.other'));
    }

    public function test_growth_learning_rejects_partial_or_legacy_observations(): void
    {
        $store = new WorkerControlStateStore;
        $observation = [
            'schema_version' => 2,
            'generation' => 1,
            'safety_clean' => true,
            'backfill_quantity' => 20_000,
            'baseline_backlogs' => ['parts' => 100, 'binaries' => 200, 'collections' => 300],
            'peak_backlogs' => ['parts' => 20_100, 'binaries' => 1_400, 'collections' => 2_100],
        ];

        self::assertFalse($store->recordBackfillGrowth('alt.test', $observation, 10_000, 20_000));
        self::assertFalse($store->recordBackfillGrowth('alt.test', ['schema_version' => 1], 20_000, 20_000));
        self::assertFalse($store->recordBackfillGrowth('alt.test', [
            ...$observation,
            'safety_clean' => false,
        ], 20_000, 20_000));
        self::assertFalse($store->recordBackfillGrowth('alt.test', [
            ...$observation,
            'baseline_backlogs' => ['parts' => -1, 'binaries' => 200, 'collections' => 300],
        ], 20_000, 20_000));
    }

    public function test_mature_target_growth_uses_a_doubled_observed_envelope_with_a_prior_floor(): void
    {
        config()->set('nntmux.orchestrator.backfill_growth_per_10k', [
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ]);
        config()->set('nntmux.orchestrator.backfill_growth_learning_min_samples', 12);
        config()->set('nntmux.orchestrator.backfill_growth_learning_safety_multiplier', 2.0);
        config()->set('nntmux.orchestrator.backfill_growth_learning_prior_floor_fraction', 0.25);
        $store = new WorkerControlStateStore;
        $observation = [
            'schema_version' => 2,
            'generation' => 1,
            'safety_clean' => true,
            'backfill_quantity' => 20_000,
            'baseline_backlogs' => ['parts' => 100, 'binaries' => 200, 'collections' => 300],
            'peak_backlogs' => [
                'parts' => 100 + 23_386,
                'binaries' => 200 + 218,
                'collections' => 300 + 16,
            ],
        ];

        for ($sample = 0; $sample < 11; $sample++) {
            self::assertTrue($store->recordBackfillGrowth('alt.test', [
                ...$observation,
                'generation' => $sample + 1,
            ], 20_000, 20_000));
        }
        self::assertSame([
            'parts' => 14_617,
            'binaries' => 500,
            'collections' => 1_000,
        ], $store->backfillGrowthFor('alt.test'));

        $twelfth = [...$observation, 'generation' => 12];
        self::assertTrue($store->recordBackfillGrowth('alt.test', $twelfth, 20_000, 20_000));
        for ($replay = 0; $replay < 12; $replay++) {
            self::assertTrue($store->recordBackfillGrowth('alt.test', $twelfth, 20_000, 20_000));
        }

        self::assertSame([
            'parts' => 23_386,
            'binaries' => 218,
            'collections' => 250,
        ], $store->backfillGrowthFor('alt.test'));
        self::assertSame([
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ], $store->backfillGrowthFor('alt.other'));
        $history = Cache::store('array')->get('nntmux:orchestrator:backfill-growth');
        self::assertCount(12, $history['targets']['alt.test']['recent_samples'] ?? []);
        self::assertSame(12, $history['targets']['alt.test']['samples'] ?? null);
        self::assertSame(12, $history['global']['samples'] ?? null);

        self::assertTrue($store->recordBackfillGrowth('alt.test', [
            ...$observation,
            'generation' => 13,
            'peak_backlogs' => [
                'parts' => 2_000_100,
                'binaries' => 200,
                'collections' => 300,
            ],
        ], 20_000, 20_000));
        self::assertSame([
            'parts' => 1_250_000,
            'binaries' => 500,
            'collections' => 1_000,
        ], $store->backfillGrowthFor('alt.test'));
        $history = Cache::store('array')->get('nntmux:orchestrator:backfill-growth');
        self::assertCount(1, $history['targets']['alt.test']['recent_samples'] ?? []);
    }

    public function test_future_or_malformed_growth_samples_never_enable_the_learned_override(): void
    {
        config()->set('nntmux.orchestrator.backfill_growth_per_10k', [
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ]);
        $sample = [
            'schema_version' => 1,
            'observed_at' => time() + 60,
            'requested_quantity' => 20_000,
            'cursor_delta' => 20_000,
            'safety_clean' => true,
            'parts' => 1,
            'binaries' => 1,
            'collections' => 1,
        ];
        Cache::store('array')->forever('nntmux:orchestrator:backfill-growth', [
            'global' => ['samples' => 0, 'growth' => []],
            'targets' => [
                'alt.test' => [
                    'samples' => 12,
                    'growth' => [],
                    'recent_samples' => array_map(
                        static fn (int $generation): array => [...$sample, 'generation' => $generation],
                        range(1, 12),
                    ),
                ],
            ],
        ]);

        self::assertSame([
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ], (new WorkerControlStateStore)->backfillGrowthFor('alt.test'));
    }

    public function test_missing_state_uses_the_conservative_initial_profile(): void
    {
        self::assertEquals(ControlState::initial(), (new WorkerControlStateStore)->loadState());
        self::assertNull((new WorkerControlStateStore)->previousSnapshot());
    }

    public function test_it_publishes_the_last_decision_for_metrics(): void
    {
        $decision = ['mode' => 'shadow', 'profile' => 'drain', 'observed_at' => 123];

        (new WorkerControlStateStore)->storeDecision($decision);

        self::assertSame($decision, Cache::store('array')->get(WorkerControlStateStore::DECISION_KEY));
    }

    public function test_it_records_cursor_normalized_target_nzb_yield(): void
    {
        $store = new WorkerControlStateStore;

        $store->recordBackfillYield('alt.test', cursorDelta: 10_000, nzbCreatedDelta: 5, now: 1_000);
        $store->recordBackfillYield('alt.test', cursorDelta: 10_000, nzbCreatedDelta: 0, now: 2_000);

        self::assertSame([
            'alt.test' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 2.5,
                'last_attempt_at' => 2_000,
                'last_effective_at' => 1_000,
                'last_cursor_delta' => 10_000,
            ],
        ], $store->backfillYieldHistory());
    }

    public function test_zero_cursor_movement_never_scores_output(): void
    {
        $store = new WorkerControlStateStore;

        $store->recordBackfillYield('alt.test', cursorDelta: 0, nzbCreatedDelta: 5, now: 1_000);

        self::assertSame(0.0, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k']);
        self::assertSame(0, $store->backfillYieldHistory()['alt.test']['last_effective_at']);
        self::assertSame(0, $store->backfillYieldHistory()['alt.test']['last_cursor_delta']);
    }

    public function test_marking_a_retry_attempt_consumes_its_cooldown_without_incrementing_outcomes(): void
    {
        $store = new WorkerControlStateStore;
        $store->recordBackfillYield('alt.test', cursorDelta: 10_000, nzbCreatedDelta: 0, now: 1_000);

        $store->markBackfillTargetAttempted('alt.test', 2_000);

        self::assertSame([
            'attempts' => 1,
            'ewma_nzbs_per_10k' => 0.0,
            'last_attempt_at' => 2_000,
            'last_effective_at' => 0,
            'last_cursor_delta' => 10_000,
        ], $store->backfillYieldHistory()['alt.test']);
    }

    public function test_yield_history_is_bounded_to_the_sixteen_most_recent_groups(): void
    {
        $store = new WorkerControlStateStore;

        for ($group = 1; $group <= 17; $group++) {
            $store->recordBackfillYield('alt.test.'.$group, 10_000, 1, $group);
        }

        $history = $store->backfillYieldHistory();
        self::assertCount(16, $history);
        self::assertArrayNotHasKey('alt.test.1', $history);
        self::assertArrayHasKey('alt.test.17', $history);
    }
}
