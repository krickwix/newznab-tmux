<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\FailSafeCause;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\WorkerControlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerControlPolicyTest extends TestCase
{
    public function test_the_same_input_always_produces_the_same_decision(): void
    {
        $policy = new WorkerControlPolicy;
        $snapshot = $this->snapshot(lowPressure: true);
        $state = new ControlState(
            profile: ControlProfile::Balanced,
            consecutiveLow: 2,
            lastTransitionAt: 1_000,
            cooldownUntil: 2_000,
        );

        self::assertEquals(
            $policy->decide($snapshot, $state, 10_000),
            $policy->decide($snapshot, $state, 10_000),
        );
    }

    #[DataProvider('hardSafetyBreachProvider')]
    public function test_a_hard_safety_breach_enters_fail_safe_immediately(array $override): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->snapshot(...$override),
            new ControlState(
                profile: ControlProfile::Fill,
                lastTransitionAt: 9_999,
                cooldownUntil: 99_999,
            ),
            10_000,
        );

        self::assertSame(ControlProfile::FailSafe, $decision->profile->profile);
        self::assertSame(ControlProfile::FailSafe, $decision->nextState->profile);
        self::assertFalse($decision->backfillPermitted);
        self::assertTrue($decision->transitioned);
    }

    public static function hardSafetyBreachProvider(): iterable
    {
        yield 'database memory' => [['databaseMemorySafe' => false]];
        yield 'database CPU' => [['databaseCpuSafe' => false]];
        yield 'database waits or deadlocks' => [['databaseWaitsSafe' => false]];
        yield 'storage' => [['storageSafe' => false]];
    }

    #[DataProvider('invalidTelemetryProvider')]
    public function test_stale_partial_or_contradictory_telemetry_fails_safe(array $override): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->snapshot(...$override),
            new ControlState(profile: ControlProfile::Balanced),
            10_000,
        );

        self::assertSame(ControlProfile::FailSafe, $decision->profile->profile);
        self::assertFalse($decision->backfillPermitted);
    }

    public static function invalidTelemetryProvider(): iterable
    {
        yield 'stale' => [['telemetryFresh' => false]];
        yield 'partial' => [['telemetryComplete' => false]];
        yield 'explicitly inconsistent' => [['telemetryConsistent' => false]];
        yield 'contradictory pressure' => [['highPressure' => true, 'lowPressure' => true]];
        yield 'negative backlog' => [['partsBacklog' => -1]];
    }

    public function test_three_consecutive_high_samples_are_required_to_move_one_rung_toward_drain(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(profile: ControlProfile::Balanced, lastTransitionAt: 1_000);

        foreach ([10_000, 10_060] as $now) {
            $decision = $policy->decide($this->snapshot(highPressure: true), $state, $now);
            self::assertSame(ControlProfile::Balanced, $decision->profile->profile);
            self::assertFalse($decision->transitioned);
            $state = $decision->nextState;
        }

        $decision = $policy->decide($this->snapshot(highPressure: true), $state, 10_120);

        self::assertSame(ControlProfile::Drain, $decision->profile->profile);
        self::assertTrue($decision->transitioned);
    }

    public function test_high_pressure_transition_into_fail_safe_records_a_telemetry_cause(): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->snapshot(highPressure: true),
            new ControlState(
                profile: ControlProfile::Drain,
                consecutiveHigh: 2,
                lastTransitionAt: 1_000,
            ),
            10_000,
        );

        self::assertSame(ControlProfile::FailSafe, $decision->profile->profile);
        self::assertSame(FailSafeCause::Telemetry, $decision->nextState->failSafeCause);
    }

    public function test_high_pressure_immediately_denies_new_backfill_supply_before_profile_transition(): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->greenBackfillSnapshot(highPressure: true, lowPressure: false),
            new ControlState(profile: ControlProfile::Fill, lastTransitionAt: 9_999),
            10_000,
        );

        self::assertFalse($decision->backfillPermitted);
        self::assertContains('backfill_high_pressure', $decision->reasons);
    }

    public function test_five_consecutive_low_samples_are_required_to_move_one_rung_toward_fill(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(profile: ControlProfile::Balanced, lastTransitionAt: 1_000);

        foreach ([10_000, 10_060, 10_120, 10_180] as $now) {
            $decision = $policy->decide($this->snapshot(lowPressure: true), $state, $now);
            self::assertSame(ControlProfile::Balanced, $decision->profile->profile);
            self::assertFalse($decision->transitioned);
            $state = $decision->nextState;
        }

        $decision = $policy->decide($this->snapshot(lowPressure: true), $state, 10_240);

        self::assertSame(ControlProfile::Fill, $decision->profile->profile);
        self::assertTrue($decision->transitioned);
    }

    public function test_minimum_dwell_blocks_an_otherwise_eligible_transition(): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->snapshot(highPressure: true),
            new ControlState(
                profile: ControlProfile::Balanced,
                consecutiveHigh: 2,
                lastTransitionAt: 1_000,
            ),
            1_299,
        );

        self::assertSame(ControlProfile::Balanced, $decision->profile->profile);
        self::assertFalse($decision->transitioned);
    }

    public function test_reversal_cooldown_blocks_a_move_back_toward_fill(): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->snapshot(lowPressure: true),
            new ControlState(
                profile: ControlProfile::Drain,
                consecutiveLow: 4,
                lastTransitionAt: 1_000,
                cooldownUntil: 10_001,
            ),
            10_000,
        );

        self::assertSame(ControlProfile::Drain, $decision->profile->profile);
        self::assertFalse($decision->transitioned);
    }

    public function test_telemetry_fail_safe_recovers_only_to_drain_after_two_distinct_safe_samples(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            lastTransitionAt: 10_000,
            cooldownUntil: 99_999,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeLastObservedAt: 100,
        );

        $first = $policy->decide($this->snapshot(lowPressure: true, observedAt: 130), $state, 10_060);
        self::assertSame(ControlProfile::FailSafe, $first->profile->profile);
        self::assertFalse($first->backfillPermitted);
        self::assertSame(1, $first->nextState->failSafeRecoverySamples);

        $second = $policy->decide($this->greenBackfillSnapshot(observedAt: 160), $first->nextState, 10_120);
        self::assertSame(ControlProfile::Drain, $second->profile->profile);
        self::assertTrue($second->transitioned);
        self::assertFalse($second->backfillPermitted);
        self::assertNull($second->nextState->failSafeCause);
        self::assertSame(10_120 + WorkerControlPolicy::TRANSITION_COOLDOWN_SECONDS, $second->nextState->cooldownUntil);
        self::assertContains('fail_safe_recovered_to_drain', $second->reasons);
    }

    public function test_duplicate_or_high_pressure_samples_do_not_advance_fail_safe_recovery(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeRecoverySamples: 1,
            failSafeLastObservedAt: 100,
        );

        $duplicate = $policy->decide($this->snapshot(lowPressure: true, observedAt: 100), $state, 10_000);
        self::assertSame(1, $duplicate->nextState->failSafeRecoverySamples);

        $high = $policy->decide($this->snapshot(highPressure: true, observedAt: 130), $state, 10_060);
        self::assertSame(ControlProfile::FailSafe, $high->profile->profile);
        self::assertSame(0, $high->nextState->failSafeRecoverySamples);
    }

    public function test_safe_high_pressure_marks_acceleration_only_while_all_core_backlogs_are_draining(): void
    {
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeLastObservedAt: 100,
        );
        $policy = new WorkerControlPolicy;
        $drainingRates = ['parts' => 0.0, 'binaries' => 0.0, 'collections' => 0.0, 'releases' => 0.0, 'nzbs' => 0.0];
        $drainingEwma = ['parts' => -5.0, 'binaries' => -6.0, 'collections' => -7.0, 'releases' => 0.0, 'nzbs' => 0.0];

        foreach ([130, 190] as $observedAt) {
            $warming = $policy->decide($this->snapshot(
                highPressure: true,
                observedAt: $observedAt,
                backlogRatesPerMinute: $drainingRates,
                backlogEwmaPerMinute: $drainingEwma,
                bodyRecoveryQueueBacklog: 1_000,
            ), $state, 10_000);
            self::assertNotContains('core_pipeline_draining', $warming->reasons);
            $state = $warming->nextState;
            if ($observedAt === 130) {
                $duplicate = $policy->decide($this->snapshot(
                    highPressure: true,
                    observedAt: 130,
                    backlogRatesPerMinute: $drainingRates,
                    backlogEwmaPerMinute: $drainingEwma,
                    bodyRecoveryQueueBacklog: 1_000,
                ), $state, 10_000);
                self::assertSame(1, $duplicate->nextState->recoveryDrainSamples);
                self::assertNotContains('core_pipeline_draining', $duplicate->reasons);
            }
        }
        $draining = $policy->decide($this->snapshot(
            highPressure: true,
            observedAt: 250,
            backlogRatesPerMinute: $drainingRates,
            backlogEwmaPerMinute: $drainingEwma,
            bodyRecoveryQueueBacklog: 1_000,
        ), $state, 10_000);
        self::assertContains('core_pipeline_draining', $draining->reasons);
        self::assertSame(3, $draining->nextState->recoveryDrainSamples);

        foreach (['releases', 'nzbs'] as $growingStage) {
            $rates = $drainingRates;
            $rates[$growingStage] = 0.1;
            $notDraining = $policy->decide($this->snapshot(
                highPressure: true,
                observedAt: 310,
                backlogRatesPerMinute: $rates,
                backlogEwmaPerMinute: $drainingEwma,
                bodyRecoveryQueueBacklog: 1_000,
            ), $draining->nextState, 10_000);

            self::assertNotContains('core_pipeline_draining', $notDraining->reasons, $growingStage);
            self::assertSame(0, $notDraining->nextState->recoveryDrainSamples, $growingStage);
        }

        foreach (['parts', 'binaries', 'collections'] as $growingStage) {
            $rates = $drainingRates;
            $rates[$growingStage] = 0.1;
            $transientPulse = $policy->decide($this->snapshot(
                highPressure: true,
                observedAt: 310,
                backlogRatesPerMinute: $rates,
                backlogEwmaPerMinute: $drainingEwma,
                bodyRecoveryQueueBacklog: 1_000,
            ), $draining->nextState, 10_000);

            self::assertContains('core_pipeline_draining', $transientPulse->reasons, $growingStage);
            self::assertSame(3, $transientPulse->nextState->recoveryDrainSamples, $growingStage);

            $lostDrainMargin = $drainingEwma;
            $lostDrainMargin[$growingStage] = 0.1;
            $unsafe = $policy->decide($this->snapshot(
                highPressure: true,
                observedAt: 310,
                backlogRatesPerMinute: $rates,
                backlogEwmaPerMinute: $lostDrainMargin,
                bodyRecoveryQueueBacklog: 1_000,
            ), $draining->nextState, 10_000);

            self::assertNotContains('core_pipeline_draining', $unsafe->reasons, $growingStage);
            self::assertSame(0, $unsafe->nextState->recoveryDrainSamples, $growingStage);
        }

        $nonFinite = $drainingEwma;
        $nonFinite['parts'] = NAN;
        $invalid = $policy->decide($this->snapshot(
            highPressure: true,
            observedAt: 310,
            backlogRatesPerMinute: $drainingRates,
            backlogEwmaPerMinute: $nonFinite,
            bodyRecoveryQueueBacklog: 1_000,
        ), $draining->nextState, 10_000);
        self::assertNotContains('core_pipeline_draining', $invalid->reasons);
        self::assertSame(0, $invalid->nextState->recoveryDrainSamples);
    }

    public function test_transient_core_ingestion_pulses_preserve_but_do_not_advance_recovery_drain_streak(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeLastObservedAt: 100,
        );
        $draining = ['parts' => 0.0, 'binaries' => 0.0, 'collections' => 0.0, 'releases' => 0.0, 'nzbs' => 0.0];
        $pulse = ['parts' => 4.0, 'binaries' => 2.0, 'collections' => 0.0, 'releases' => 0.0, 'nzbs' => 0.0];
        $ewma = ['parts' => -800.0, 'binaries' => -26.0, 'collections' => -25.0, 'releases' => 0.0, 'nzbs' => 0.0];

        foreach ([[130, $draining, 1, 0], [190, $pulse, 1, 1], [250, $draining, 2, 0], [310, $pulse, 2, 1], [370, $draining, 3, 0]] as [$observedAt, $rates, $expectedSamples, $expectedHoldSamples]) {
            $decision = $policy->decide($this->snapshot(
                highPressure: true,
                observedAt: $observedAt,
                backlogRatesPerMinute: $rates,
                backlogEwmaPerMinute: $ewma,
                bodyRecoveryQueueBacklog: 1_000,
            ), $state, 10_000);
            $state = $decision->nextState;
            self::assertSame($expectedSamples, $state->recoveryDrainSamples);
            self::assertSame($expectedHoldSamples, $state->recoveryDrainHoldSamples);
            if ($expectedSamples < 3) {
                self::assertNotContains('core_pipeline_draining', $decision->reasons);
            }
        }

        self::assertContains('core_pipeline_draining', $decision->reasons);
        self::assertSame(3, $state->recoveryDrainSamples);
    }

    public function test_bounded_repair_growth_holds_streak_for_at_most_three_samples(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeLastObservedAt: 100,
            recoveryDrainSamples: 3,
        );
        $rates = ['parts' => 802.0, 'binaries' => 6.0, 'collections' => 2.0, 'releases' => 0.0, 'nzbs' => 0.0];
        $backlogs = ['parts' => 192_000_000, 'binaries' => 89_000, 'collections' => 52_000];
        $ewma = [
            'parts' => $backlogs['parts'] * log(2.0) / WorkerControlPolicy::RECOVERY_TRANSIENT_GROWTH_DOUBLING_MINUTES,
            'binaries' => $backlogs['binaries'] * log(2.0) / WorkerControlPolicy::RECOVERY_TRANSIENT_GROWTH_DOUBLING_MINUTES,
            'collections' => $backlogs['collections'] * log(2.0) / WorkerControlPolicy::RECOVERY_TRANSIENT_GROWTH_DOUBLING_MINUTES,
            'releases' => 0.0,
            'nzbs' => 0.0,
        ];

        foreach ([130, 160, 190] as $expectedHoldSamples => $observedAt) {
            $bounded = $policy->decide($this->snapshot(
                partsBacklog: $backlogs['parts'],
                binariesBacklog: $backlogs['binaries'],
                collectionsBacklog: $backlogs['collections'],
                highPressure: true,
                observedAt: $observedAt,
                backlogRatesPerMinute: $rates,
                backlogEwmaPerMinute: $ewma,
                bodyRecoveryQueueBacklog: 10_000,
            ), $state, 10_000);
            $state = $bounded->nextState;
            self::assertSame(3, $state->recoveryDrainSamples);
            self::assertSame($expectedHoldSamples + 1, $state->recoveryDrainHoldSamples);
            self::assertContains('core_pipeline_draining', $bounded->reasons);
        }

        $expired = $policy->decide($this->snapshot(
            partsBacklog: $backlogs['parts'],
            binariesBacklog: $backlogs['binaries'],
            collectionsBacklog: $backlogs['collections'],
            highPressure: true,
            observedAt: 220,
            backlogRatesPerMinute: $rates,
            backlogEwmaPerMinute: $ewma,
            bodyRecoveryQueueBacklog: 10_000,
        ), $state, 10_000);
        self::assertSame(0, $expired->nextState->recoveryDrainSamples);
        self::assertSame(0, $expired->nextState->recoveryDrainHoldSamples);
        self::assertNotContains('core_pipeline_draining', $expired->reasons);

        $ewma['binaries'] = ($backlogs['binaries'] * log(2.0) / WorkerControlPolicy::RECOVERY_TRANSIENT_GROWTH_DOUBLING_MINUTES) + 0.001;
        $excessive = $policy->decide($this->snapshot(
            partsBacklog: $backlogs['parts'],
            binariesBacklog: $backlogs['binaries'],
            collectionsBacklog: $backlogs['collections'],
            highPressure: true,
            observedAt: 130,
            backlogRatesPerMinute: $rates,
            backlogEwmaPerMinute: $ewma,
            bodyRecoveryQueueBacklog: 10_000,
        ), new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeLastObservedAt: 100,
            recoveryDrainSamples: 1,
        ), 10_000);
        self::assertSame(0, $excessive->nextState->recoveryDrainSamples);

        $delayed = $policy->decide($this->snapshot(
            partsBacklog: $backlogs['parts'],
            binariesBacklog: $backlogs['binaries'],
            collectionsBacklog: $backlogs['collections'],
            highPressure: true,
            observedAt: 191,
            backlogRatesPerMinute: $rates,
            backlogEwmaPerMinute: array_replace($ewma, ['binaries' => 0.0]),
            bodyRecoveryQueueBacklog: 10_000,
        ), new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeLastObservedAt: 100,
            recoveryDrainSamples: 3,
        ), 10_000);
        self::assertSame(0, $delayed->nextState->recoveryDrainSamples);
        self::assertNotContains('core_pipeline_draining', $delayed->reasons);
    }

    public function test_stable_ineligible_nzb_backlog_does_not_permanently_block_recovery_drain(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeLastObservedAt: 100,
        );
        $rates = ['parts' => 0.0, 'binaries' => 0.0, 'collections' => 0.0, 'releases' => 0.0, 'nzbs' => 0.0];
        $ewma = ['parts' => -5.0, 'binaries' => -6.0, 'collections' => -7.0, 'releases' => 0.0, 'nzbs' => 0.5];

        foreach ([130, 190, 250] as $observedAt) {
            $decision = $policy->decide($this->snapshot(
                highPressure: true,
                observedAt: $observedAt,
                eligibleNzbs: 0,
                backlogRatesPerMinute: $rates,
                backlogEwmaPerMinute: $ewma,
                bodyRecoveryQueueBacklog: 1_000,
            ), $state, 10_000);
            $state = $decision->nextState;
        }
        self::assertContains('core_pipeline_draining', $decision->reasons);

        $actionable = $policy->decide($this->snapshot(
            highPressure: true,
            observedAt: 310,
            eligibleNzbs: 1,
            backlogRatesPerMinute: $rates,
            backlogEwmaPerMinute: $ewma,
            bodyRecoveryQueueBacklog: 1_000,
        ), $state, 10_000);
        self::assertNotContains('core_pipeline_draining', $actionable->reasons);
        self::assertSame(0, $actionable->nextState->recoveryDrainSamples);
    }

    public function test_hard_or_legacy_fail_safe_requires_five_safe_samples_and_the_latest_cooldown(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            cooldownUntil: 20_000,
            failSafeCause: FailSafeCause::Unknown,
            failSafeLastObservedAt: 100,
        );

        foreach ([130, 160, 190, 220, 250] as $observedAt) {
            $decision = $policy->decide($this->snapshot(lowPressure: true, observedAt: $observedAt), $state, 19_999);
            $state = $decision->nextState;
        }
        self::assertSame(ControlProfile::FailSafe, $decision->profile->profile);
        self::assertSame(5, $state->failSafeRecoverySamples);

        $recovered = $policy->decide($this->snapshot(lowPressure: true, observedAt: 280), $state, 20_000);
        self::assertSame(ControlProfile::Drain, $recovered->profile->profile);
        self::assertFalse($recovered->backfillPermitted);
    }

    public function test_a_hard_breach_during_telemetry_recovery_latches_hard_and_resets_the_streak(): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->snapshot(databaseMemorySafe: false, observedAt: 102),
            new ControlState(
                profile: ControlProfile::FailSafe,
                failSafeCause: FailSafeCause::Telemetry,
                failSafeRecoverySamples: 1,
                failSafeLastObservedAt: 101,
            ),
            10_000,
        );

        self::assertSame(FailSafeCause::Hard, $decision->nextState->failSafeCause);
        self::assertSame(0, $decision->nextState->failSafeRecoverySamples);
        self::assertSame(10_000 + WorkerControlPolicy::TRANSITION_COOLDOWN_SECONDS, $decision->nextState->cooldownUntil);
    }

    public function test_invalid_telemetry_cannot_downgrade_a_hard_or_legacy_fail_safe(): void
    {
        foreach ([FailSafeCause::Hard, FailSafeCause::Unknown] as $cause) {
            $decision = (new WorkerControlPolicy)->decide(
                $this->snapshot(telemetryFresh: false, observedAt: 102),
                new ControlState(
                    profile: ControlProfile::FailSafe,
                    failSafeCause: $cause,
                    failSafeRecoverySamples: 1,
                    failSafeLastObservedAt: 101,
                ),
                10_000,
            );

            self::assertSame($cause, $decision->nextState->failSafeCause);
            self::assertSame(0, $decision->nextState->failSafeRecoverySamples);
        }
    }

    public function test_an_explicit_database_wait_is_hard_even_when_prometheus_telemetry_is_invalid(): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->snapshot(telemetryFresh: false, databaseWaitsSafe: false, observedAt: 102),
            new ControlState(profile: ControlProfile::Fill),
            10_000,
        );

        self::assertSame(FailSafeCause::Hard, $decision->nextState->failSafeCause);
    }

    public function test_a_fresh_prometheus_breach_is_hard_even_when_backlog_telemetry_is_invalid(): void
    {
        foreach ([
            ['databaseMemorySafe' => false],
            ['databaseCpuSafe' => false],
            ['storageSafe' => false],
        ] as $override) {
            $decision = (new WorkerControlPolicy)->decide(
                $this->snapshot(...array_replace([
                    'telemetryComplete' => false,
                    'telemetryFresh' => true,
                    'observedAt' => 102,
                ], $override)),
                new ControlState(
                    profile: ControlProfile::FailSafe,
                    failSafeCause: FailSafeCause::Telemetry,
                    failSafeRecoverySamples: 1,
                    failSafeLastObservedAt: 101,
                ),
                10_000,
            );

            self::assertSame(FailSafeCause::Hard, $decision->nextState->failSafeCause);
            self::assertSame(0, $decision->nextState->failSafeRecoverySamples);
            self::assertSame(10_000 + WorkerControlPolicy::TRANSITION_COOLDOWN_SECONDS, $decision->nextState->cooldownUntil);
        }
    }

    public function test_equal_or_older_observations_do_not_advance_or_regress_the_recovery_watermark(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeRecoverySamples: 1,
            failSafeLastObservedAt: 100,
        );

        foreach ([100, 99] as $observedAt) {
            $decision = $policy->decide($this->snapshot(lowPressure: true, observedAt: $observedAt), $state, 10_000);
            self::assertSame(1, $decision->nextState->failSafeRecoverySamples);
            self::assertSame(100, $decision->nextState->failSafeLastObservedAt);
            $state = $decision->nextState;
        }
    }

    public function test_early_samples_do_not_slide_the_recovery_watermark_forever(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            failSafeRecoverySamples: 1,
            failSafeLastObservedAt: 100,
        );

        foreach ([110, 120] as $observedAt) {
            $decision = $policy->decide($this->snapshot(lowPressure: true, observedAt: $observedAt), $state, 10_000);
            self::assertSame(100, $decision->nextState->failSafeLastObservedAt);
            self::assertSame(ControlProfile::FailSafe, $decision->profile->profile);
            $state = $decision->nextState;
        }

        $recovered = $policy->decide($this->snapshot(lowPressure: true, observedAt: 130), $state, 10_030);
        self::assertSame(ControlProfile::Drain, $recovered->profile->profile);
    }

    public function test_a_transition_is_clamped_to_one_profile_rung(): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->snapshot(highPressure: true),
            new ControlState(
                profile: ControlProfile::Fill,
                consecutiveHigh: 99,
                lastTransitionAt: 1_000,
            ),
            10_000,
        );

        self::assertSame(ControlProfile::Balanced, $decision->profile->profile);
        self::assertNotSame(ControlProfile::Drain, $decision->profile->profile);
    }

    public function test_backfill_is_permitted_only_when_all_green_gates_are_open(): void
    {
        foreach ([ControlProfile::Balanced, ControlProfile::Fill] as $profile) {
            $decision = (new WorkerControlPolicy)->decide(
                $this->greenBackfillSnapshot(),
                new ControlState(profile: $profile, lastTransitionAt: 9_900),
                10_000,
            );

            self::assertTrue($decision->backfillPermitted, $profile->value);
            self::assertTrue($decision->profile->backfillEnabled, $profile->value);
        }
    }

    #[DataProvider('backfillDenialProvider')]
    public function test_backfill_is_denied_when_any_supply_gate_is_closed(array $override): void
    {
        $decision = (new WorkerControlPolicy)->decide(
            $this->greenBackfillSnapshot(...$override),
            new ControlState(profile: ControlProfile::Balanced),
            10_000,
        );

        self::assertFalse($decision->backfillPermitted);
        self::assertNotEmpty(array_filter(
            $decision->reasons,
            static fn (string $reason): bool => str_starts_with($reason, 'backfill_'),
        ));
    }

    public static function backfillDenialProvider(): iterable
    {
        yield 'provider unavailable' => [['providerAvailable' => false]];
        yield 'cursor exhausted' => [['cursorAvailable' => false]];
        yield 'no current groups' => [['currentGroupsAvailable' => false]];
        yield 'no eligible supply' => [['eligibleBackfillSupply' => false]];
        yield 'no safe capacity' => [['backfillSafeQuantity' => 0]];
    }

    public function test_target_ineffective_permits_do_not_globally_lock_other_targets(): void
    {
        $policy = new WorkerControlPolicy;
        $state = new ControlState(profile: ControlProfile::Balanced, lastTransitionAt: 9_900);
        $ineffective = $this->greenBackfillSnapshot(
            backfillPermitCompleted: true,
            backfillPermitEffective: false,
            backfillPermitGroup: 'alt.a',
            backfillGroup: 'alt.b',
        );

        $first = $policy->decide($ineffective, $state, 10_000);
        self::assertFalse($first->nextState->backfillLocked);

        $second = $policy->decide($ineffective, $first->nextState, 10_060);

        self::assertSame(2, $second->nextState->ineffectiveBackfillPermitsByTarget['alt.a']);
        self::assertFalse($second->nextState->backfillLocked);
        self::assertTrue($second->backfillPermitted);
        self::assertContains('backfill_target_locked_after_ineffective_permits', $second->reasons);
    }

    public function test_recent_proven_yield_at_threshold_overrides_target_ineffective_lock(): void
    {
        $state = new ControlState(
            profile: ControlProfile::Balanced,
            lastTransitionAt: 9_900,
            ineffectiveBackfillPermitsByTarget: ['alt.a' => 2],
        );
        $snapshot = $this->greenBackfillSnapshot(
            backfillGroup: 'alt.a',
            backfillYieldNzbsPer10k: 0.15,
            backfillHistoryRecent: true,
        );

        $decision = (new WorkerControlPolicy(provenYieldOverrideThreshold: 0.15))->decide($snapshot, $state, 10_000);

        self::assertTrue($decision->backfillPermitted);
        self::assertContains('backfill_target_lock_overridden_by_proven_yield', $decision->reasons);
    }

    #[DataProvider('nonProvenTargetLockProvider')]
    public function test_expired_or_below_threshold_history_remains_target_locked(float $yield, bool $recent): void
    {
        $state = new ControlState(
            profile: ControlProfile::Balanced,
            lastTransitionAt: 9_900,
            ineffectiveBackfillPermitsByTarget: ['alt.a' => 2],
        );
        $snapshot = $this->greenBackfillSnapshot(
            backfillGroup: 'alt.a',
            backfillYieldNzbsPer10k: $yield,
            backfillHistoryRecent: $recent,
        );

        $decision = (new WorkerControlPolicy(provenYieldOverrideThreshold: 0.15))->decide($snapshot, $state, 10_000);

        self::assertFalse($decision->backfillPermitted);
        self::assertContains('backfill_target_locked', $decision->reasons);
    }

    /** @return array<string, array{float, bool}> */
    public static function nonProvenTargetLockProvider(): array
    {
        return [
            'below threshold' => [0.149, true],
            'expired history' => [0.23, false],
        ];
    }

    public function test_a_legacy_strike_is_conservatively_seeded_to_the_observed_target(): void
    {
        $state = new ControlState(
            profile: ControlProfile::Balanced,
            consecutiveIneffectiveBackfillPermits: 1,
        );
        $ineffective = $this->greenBackfillSnapshot(
            backfillPermitCompleted: true,
            backfillPermitEffective: false,
            backfillPermitGroup: 'alt.a',
            backfillGroup: 'alt.b',
        );

        $decision = (new WorkerControlPolicy)->decide($ineffective, $state, 10_000);

        self::assertSame(2, $decision->nextState->ineffectiveBackfillPermitsByTarget['alt.a']);
        self::assertTrue($decision->backfillPermitted);
        self::assertContains('backfill_target_locked_after_ineffective_permits', $decision->reasons);
    }

    public function test_a_legacy_global_lock_remains_fail_closed(): void
    {
        $state = new ControlState(
            profile: ControlProfile::Balanced,
            consecutiveIneffectiveBackfillPermits: 2,
            backfillLocked: true,
        );

        $decision = (new WorkerControlPolicy)->decide($this->greenBackfillSnapshot(), $state, 10_000);

        self::assertTrue($decision->nextState->backfillLocked);
        self::assertFalse($decision->backfillPermitted);
    }

    public function test_a_no_input_permit_preserves_only_its_target_strike(): void
    {
        $state = new ControlState(
            profile: ControlProfile::Balanced,
            ineffectiveBackfillPermitsByTarget: ['alt.a' => 1, 'alt.b' => 1],
        );
        $noInput = $this->greenBackfillSnapshot(
            backfillPermitCompleted: true,
            backfillPermitEffective: false,
            backfillPermitClaimed: true,
            backfillPermitInputMoved: false,
            backfillPermitGroup: 'alt.a',
            backfillGroup: 'alt.b',
        );

        $decision = (new WorkerControlPolicy)->decide($noInput, $state, 10_000);

        self::assertSame(['alt.a' => 1, 'alt.b' => 1], $decision->nextState->ineffectiveBackfillPermitsByTarget);
        self::assertTrue($decision->backfillPermitted);
    }

    public function test_an_effective_permit_resets_only_its_target_strike(): void
    {
        $state = new ControlState(
            profile: ControlProfile::Balanced,
            ineffectiveBackfillPermitsByTarget: ['alt.a' => 1, 'alt.b' => 1],
        );
        $effective = $this->greenBackfillSnapshot(
            backfillPermitCompleted: true,
            backfillPermitEffective: true,
            backfillPermitGroup: 'alt.a',
            backfillGroup: 'alt.b',
        );

        $decision = (new WorkerControlPolicy)->decide($effective, $state, 10_000);

        self::assertSame(['alt.b' => 1], $decision->nextState->ineffectiveBackfillPermitsByTarget);
        self::assertTrue($decision->backfillPermitted);
    }

    public function test_a_claimed_no_input_probe_rotates_without_consuming_an_output_strike(): void
    {
        $state = new ControlState(
            profile: ControlProfile::Balanced,
            consecutiveIneffectiveBackfillPermits: 1,
        );
        $noInput = $this->greenBackfillSnapshot(
            backfillPermitCompleted: true,
            backfillPermitEffective: false,
            backfillPermitClaimed: true,
            backfillPermitInputMoved: false,
        );

        $decision = (new WorkerControlPolicy)->decide($noInput, $state, 10_000);

        self::assertSame(1, $decision->nextState->consecutiveIneffectiveBackfillPermits);
        self::assertFalse($decision->nextState->backfillLocked);
        self::assertContains('backfill_permit_no_input', $decision->reasons);
    }

    private function greenBackfillSnapshot(...$override): PipelineSnapshot
    {
        return $this->snapshot(...array_replace([
            'lowPressure' => true,
            'providerAvailable' => true,
            'cursorAvailable' => true,
            'currentGroupsAvailable' => true,
            'eligibleBackfillSupply' => true,
        ], $override));
    }

    private function snapshot(...$override): PipelineSnapshot
    {
        return new PipelineSnapshot(...array_replace([
            'partsBacklog' => 0,
            'binariesBacklog' => 0,
            'collectionsBacklog' => 0,
            'releasesBacklog' => 0,
            'nzbsBacklog' => 0,
            'telemetryFresh' => true,
            'telemetryComplete' => true,
            'telemetryConsistent' => true,
            'databaseMemorySafe' => true,
            'databaseCpuSafe' => true,
            'databaseWaitsSafe' => true,
            'storageSafe' => true,
            'highPressure' => false,
            'lowPressure' => false,
            'providerAvailable' => true,
            'cursorAvailable' => true,
            'currentGroupsAvailable' => true,
            'eligibleBackfillSupply' => false,
            'backfillPermitCompleted' => false,
            'backfillPermitEffective' => false,
        ], $override));
    }
}
