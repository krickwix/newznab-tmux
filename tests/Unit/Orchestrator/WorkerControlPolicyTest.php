<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
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
