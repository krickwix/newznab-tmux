<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Container\Container;

final class WorkerControlPolicy
{
    public const int HIGH_SAMPLES_TO_DRAIN = 3;

    public const int LOW_SAMPLES_TO_FILL = 5;

    public const int MINIMUM_DWELL_SECONDS = 600;

    public const int TRANSITION_COOLDOWN_SECONDS = 1200;

    public const int INEFFECTIVE_BACKFILL_LIMIT = 2;

    public const int TELEMETRY_RECOVERY_SAMPLES = 2;

    public const int HARD_RECOVERY_SAMPLES = 5;

    public const int RECOVERY_SAMPLE_MIN_SPACING_SECONDS = 30;

    public const int RECOVERY_DRAIN_SAMPLES_TO_ACCELERATE = 3;

    public const float RECOVERY_DRAIN_MIN_EWMA_PER_MINUTE = 5.0;

    public const int RECOVERY_TRANSIENT_GROWTH_DOUBLING_MINUTES = 1_440;

    public const int RECOVERY_DRAIN_MAX_HOLD_SAMPLES = 3;

    public const int RECOVERY_DRAIN_HOLD_MAX_SPACING_SECONDS = 90;

    private float $provenYieldOverrideThreshold;

    public function __construct(?float $provenYieldOverrideThreshold = null)
    {
        $container = Container::getInstance();
        $this->provenYieldOverrideThreshold = max(
            0.0,
            $provenYieldOverrideThreshold ?? ($container->bound('config')
                ? (float) config('nntmux.orchestrator.backfill_aggressive_explore_below_yield', 0.0)
                : 0.0),
        );
    }

    public function decide(PipelineSnapshot $snapshot, ControlState $state, int $now): ControlDecision
    {
        [$ineffectivePermits, $backfillLocked, $targetIneffectivePermits, $effectivenessReasons] = $this->applyPermitOutcome($snapshot, $state);

        if (! $snapshot->telemetryIsValid() || ! $snapshot->hardSafetyPassed()) {
            $transitioned = $state->profile !== ControlProfile::FailSafe;
            $prometheusHardBreach = $snapshot->telemetryFresh && (
                ! $snapshot->databaseMemorySafe
                || ! $snapshot->databaseCpuSafe
                || ! $snapshot->storageSafe
            );
            $hardBreach = ! $snapshot->databaseWaitsSafe || $prometheusHardBreach;
            $cause = $hardBreach
                ? FailSafeCause::Hard
                : ($state->profile === ControlProfile::FailSafe && in_array($state->failSafeCause, [FailSafeCause::Hard, FailSafeCause::Unknown], true)
                    ? $state->failSafeCause
                    : FailSafeCause::Telemetry);
            $nextState = new ControlState(
                profile: ControlProfile::FailSafe,
                lastTransitionAt: $transitioned ? $now : $state->lastTransitionAt,
                cooldownUntil: $hardBreach
                    ? $now + self::TRANSITION_COOLDOWN_SECONDS
                    : ($transitioned ? $now + self::TRANSITION_COOLDOWN_SECONDS : $state->cooldownUntil),
                consecutiveIneffectiveBackfillPermits: $ineffectivePermits,
                backfillLocked: $backfillLocked,
                ineffectiveBackfillPermitsByTarget: $targetIneffectivePermits,
                failSafeCause: $cause,
                failSafeLastObservedAt: max($state->failSafeLastObservedAt, $snapshot->observedAt),
            );

            return new ControlDecision(
                profile: WorkerControlProfile::for(ControlProfile::FailSafe),
                backfillPermitted: false,
                reasons: [...$this->failSafeReasons($snapshot), ...$effectivenessReasons],
                nextState: $nextState,
                transitioned: $transitioned,
            );
        }

        [$highSamples, $lowSamples, $pressureReason] = $this->pressureSamples($snapshot, $state);
        $profile = $state->profile;
        $transitioned = false;
        $reasons = [$pressureReason];

        if ($profile === ControlProfile::FailSafe) {
            return $this->recoverFromFailSafe(
                $snapshot,
                $state,
                $now,
                $ineffectivePermits,
                $backfillLocked,
                $targetIneffectivePermits,
                $effectivenessReasons,
                $pressureReason,
            );
        }

        if ($this->mayTransition($state, $now)) {
            if ($highSamples >= self::HIGH_SAMPLES_TO_DRAIN) {
                $nextProfile = $profile->stepDown();
                $transitioned = $nextProfile !== $profile;
                $profile = $nextProfile;
                $reasons[] = $transitioned ? 'high_pressure_step_down' : 'high_pressure_floor';
            } elseif ($lowSamples >= self::LOW_SAMPLES_TO_FILL) {
                $nextProfile = $profile->stepUp();
                $transitioned = $nextProfile !== $profile;
                $profile = $nextProfile;
                $reasons[] = $transitioned ? 'low_pressure_step_up' : 'low_pressure_ceiling';
            }
        } elseif ($highSamples >= self::HIGH_SAMPLES_TO_DRAIN || $lowSamples >= self::LOW_SAMPLES_TO_FILL) {
            $reasons[] = 'transition_deferred_by_dwell_or_cooldown';
        }

        if ($transitioned) {
            $highSamples = 0;
            $lowSamples = 0;
        }

        $nextState = new ControlState(
            profile: $profile,
            consecutiveHigh: $highSamples,
            consecutiveLow: $lowSamples,
            lastTransitionAt: $transitioned ? $now : $state->lastTransitionAt,
            cooldownUntil: $transitioned ? $now + self::TRANSITION_COOLDOWN_SECONDS : $state->cooldownUntil,
            consecutiveIneffectiveBackfillPermits: $ineffectivePermits,
            backfillLocked: $backfillLocked,
            ineffectiveBackfillPermitsByTarget: $targetIneffectivePermits,
            failSafeCause: $profile === ControlProfile::FailSafe ? FailSafeCause::Telemetry : null,
            failSafeLastObservedAt: $profile === ControlProfile::FailSafe ? $snapshot->observedAt : 0,
        );
        $workerProfile = WorkerControlProfile::for($profile);
        $targetLockOverridden = $this->provenYieldOverrideThreshold > 0.0
            && $snapshot->backfillHistoryRecent
            && $snapshot->backfillYieldNzbsPer10k >= $this->provenYieldOverrideThreshold;
        $targetLocked = $snapshot->backfillGroup !== ''
            && (int) ($targetIneffectivePermits[$snapshot->backfillGroup] ?? 0) >= self::INEFFECTIVE_BACKFILL_LIMIT
            && ! $targetLockOverridden;
        if ($targetLockOverridden
            && $snapshot->backfillGroup !== ''
            && (int) ($targetIneffectivePermits[$snapshot->backfillGroup] ?? 0) >= self::INEFFECTIVE_BACKFILL_LIMIT
        ) {
            $reasons[] = 'backfill_target_lock_overridden_by_proven_yield';
        }
        $backfillPermitted = $workerProfile->backfillEnabled
            && ! $snapshot->highPressure
            && ! $backfillLocked
            && ! $targetLocked
            && $snapshot->backfillGatesPassed();

        if (! $backfillPermitted) {
            $reasons[] = $this->backfillDenialReason($workerProfile, $snapshot, $backfillLocked, $targetLocked);
        }

        return new ControlDecision(
            profile: $workerProfile,
            backfillPermitted: $backfillPermitted,
            reasons: [...$reasons, ...$effectivenessReasons],
            nextState: $nextState,
            transitioned: $transitioned,
        );
    }

    /**
     * @param  array<string, int>  $targetIneffectivePermits
     * @param  list<string>  $effectivenessReasons
     */
    private function recoverFromFailSafe(
        PipelineSnapshot $snapshot,
        ControlState $state,
        int $now,
        int $ineffectivePermits,
        bool $backfillLocked,
        array $targetIneffectivePermits,
        array $effectivenessReasons,
        string $pressureReason,
    ): ControlDecision {
        $cause = $state->failSafeCause ?? FailSafeCause::Unknown;
        $distinctSample = $snapshot->observedAt - $state->failSafeLastObservedAt >= self::RECOVERY_SAMPLE_MIN_SPACING_SECONDS;
        $recoverySamples = $snapshot->highPressure
            ? 0
            : ($distinctSample ? $state->failSafeRecoverySamples + 1 : $state->failSafeRecoverySamples);
        $requiredSamples = $cause === FailSafeCause::Telemetry
            ? self::TELEMETRY_RECOVERY_SAMPLES
            : self::HARD_RECOVERY_SAMPLES;
        $cooldownSatisfied = $cause === FailSafeCause::Telemetry || $now >= $state->cooldownUntil;
        $recovered = $recoverySamples >= $requiredSamples && $cooldownSatisfied;
        $profile = $recovered ? ControlProfile::Drain : ControlProfile::FailSafe;
        $strongRecoveryDrain = $distinctSample && $this->strongRecoveryDrainSample($snapshot);
        $safeRecoveryPulse = $distinctSample
            && $snapshot->observedAt - $state->failSafeLastObservedAt <= self::RECOVERY_DRAIN_HOLD_MAX_SPACING_SECONDS
            && $state->recoveryDrainSamples > 0
            && $state->recoveryDrainHoldSamples < self::RECOVERY_DRAIN_MAX_HOLD_SAMPLES
            && $this->safeRecoveryDrainTrend($snapshot);
        $recoveryDrainSamples = match (true) {
            $recovered => 0,
            ! $distinctSample => $state->recoveryDrainSamples,
            $strongRecoveryDrain => min(
                self::RECOVERY_DRAIN_SAMPLES_TO_ACCELERATE,
                $state->recoveryDrainSamples + 1,
            ),
            $safeRecoveryPulse => $state->recoveryDrainSamples,
            default => 0,
        };
        $recoveryDrainHoldSamples = match (true) {
            $recovered => 0,
            ! $distinctSample => $state->recoveryDrainHoldSamples,
            $strongRecoveryDrain => 0,
            $safeRecoveryPulse => $state->recoveryDrainHoldSamples + 1,
            default => 0,
        };
        $reasons = [$pressureReason, $recovered ? 'fail_safe_recovered_to_drain' : 'fail_safe_recovery_pending'];
        if ($recoveryDrainSamples >= self::RECOVERY_DRAIN_SAMPLES_TO_ACCELERATE) {
            $reasons[] = 'core_pipeline_draining';
        }
        $nextState = new ControlState(
            profile: $profile,
            lastTransitionAt: $recovered ? $now : $state->lastTransitionAt,
            cooldownUntil: $recovered ? $now + self::TRANSITION_COOLDOWN_SECONDS : $state->cooldownUntil,
            consecutiveIneffectiveBackfillPermits: $ineffectivePermits,
            backfillLocked: $backfillLocked,
            ineffectiveBackfillPermitsByTarget: $targetIneffectivePermits,
            failSafeCause: $recovered ? null : $cause,
            failSafeRecoverySamples: $recovered ? 0 : min($requiredSamples, $recoverySamples),
            failSafeLastObservedAt: $distinctSample ? $snapshot->observedAt : $state->failSafeLastObservedAt,
            recoveryDrainSamples: $recoveryDrainSamples,
            recoveryDrainHoldSamples: $recoveryDrainHoldSamples,
        );

        return new ControlDecision(
            profile: WorkerControlProfile::for($profile),
            backfillPermitted: false,
            reasons: [...$reasons, ...$effectivenessReasons, 'backfill_disabled_by_profile'],
            nextState: $nextState,
            transitioned: $recovered,
        );
    }

    private function strongRecoveryDrainSample(PipelineSnapshot $snapshot): bool
    {
        if (! $this->safeRecoveryDrainTrend($snapshot)) {
            return false;
        }

        foreach (['parts', 'binaries', 'collections'] as $stage) {
            $instant = $snapshot->backlogRatesPerMinute[$stage] ?? NAN;
            $ewma = $snapshot->backlogEwmaPerMinute[$stage] ?? NAN;
            if ($instant > 0.0 || $ewma > -self::RECOVERY_DRAIN_MIN_EWMA_PER_MINUTE) {
                return false;
            }
        }

        return true;
    }

    private function safeRecoveryDrainTrend(PipelineSnapshot $snapshot): bool
    {
        if (! $snapshot->highPressure || $snapshot->bodyRecoveryQueueBacklog <= 0) {
            return false;
        }

        foreach (['parts', 'binaries', 'collections'] as $stage) {
            $instant = $snapshot->backlogRatesPerMinute[$stage] ?? NAN;
            $ewma = $snapshot->backlogEwmaPerMinute[$stage] ?? NAN;
            $backlog = match ($stage) {
                'parts' => $snapshot->partsBacklog,
                'binaries' => $snapshot->binariesBacklog,
                'collections' => $snapshot->collectionsBacklog,
            };
            $maximumEwma = $backlog * log(2.0) / self::RECOVERY_TRANSIENT_GROWTH_DOUBLING_MINUTES;
            if (! is_finite($instant) || ! is_finite($ewma)
                || $ewma > $maximumEwma) {
                return false;
            }
        }
        foreach (['releases', 'nzbs'] as $stage) {
            $instant = $snapshot->backlogRatesPerMinute[$stage] ?? NAN;
            $ewma = $snapshot->backlogEwmaPerMinute[$stage] ?? NAN;
            $ineligibleNzbBacklog = $stage === 'nzbs' && $snapshot->eligibleNzbs === 0;
            if (! is_finite($instant) || ! is_finite($ewma)
                || $instant > 0.0
                || (! $ineligibleNzbBacklog && $ewma > 0.0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{int, bool, array<string, int>, list<string>}
     */
    private function applyPermitOutcome(PipelineSnapshot $snapshot, ControlState $state): array
    {
        if (! $snapshot->backfillPermitCompleted) {
            return [
                $state->consecutiveIneffectiveBackfillPermits,
                $state->backfillLocked,
                $state->ineffectiveBackfillPermitsByTarget,
                [],
            ];
        }

        $target = $snapshot->backfillPermitGroup;
        $targetCounts = $state->ineffectiveBackfillPermitsByTarget;
        if ($target !== '') {
            if ($targetCounts === [] && $state->consecutiveIneffectiveBackfillPermits > 0) {
                $targetCounts[$target] = min(
                    self::INEFFECTIVE_BACKFILL_LIMIT,
                    $state->consecutiveIneffectiveBackfillPermits,
                );
            }
            if ($snapshot->backfillPermitEffective) {
                unset($targetCounts[$target]);

                return [0, $state->backfillLocked, $targetCounts, ['backfill_permit_effective']];
            }

            if ($snapshot->backfillPermitClaimed && ! $snapshot->backfillPermitInputMoved) {
                return [
                    $state->consecutiveIneffectiveBackfillPermits,
                    $state->backfillLocked,
                    $targetCounts,
                    ['backfill_permit_no_input'],
                ];
            }

            $targetCounts[$target] = min(
                self::INEFFECTIVE_BACKFILL_LIMIT,
                (int) ($targetCounts[$target] ?? 0) + 1,
            );
            $count = min(self::INEFFECTIVE_BACKFILL_LIMIT, $state->consecutiveIneffectiveBackfillPermits + 1);

            return [
                $count,
                $state->backfillLocked,
                $targetCounts,
                [$targetCounts[$target] >= self::INEFFECTIVE_BACKFILL_LIMIT
                    ? 'backfill_target_locked_after_ineffective_permits'
                    : 'backfill_permit_ineffective'],
            ];
        }

        if ($snapshot->backfillPermitEffective) {
            return [0, $state->backfillLocked, $targetCounts, ['backfill_permit_effective']];
        }

        if ($snapshot->backfillPermitClaimed && ! $snapshot->backfillPermitInputMoved) {
            return [
                $state->consecutiveIneffectiveBackfillPermits,
                $state->backfillLocked,
                $targetCounts,
                ['backfill_permit_no_input'],
            ];
        }

        $count = min(self::INEFFECTIVE_BACKFILL_LIMIT, $state->consecutiveIneffectiveBackfillPermits + 1);
        $locked = $state->backfillLocked || $count >= self::INEFFECTIVE_BACKFILL_LIMIT;

        return [$count, $locked, $targetCounts, [$locked ? 'backfill_locked_after_ineffective_permits' : 'backfill_permit_ineffective']];
    }

    /**
     * @return array{int, int, string}
     */
    private function pressureSamples(PipelineSnapshot $snapshot, ControlState $state): array
    {
        if ($snapshot->highPressure) {
            return [min(self::HIGH_SAMPLES_TO_DRAIN, $state->consecutiveHigh + 1), 0, 'high_pressure_sample'];
        }

        if ($snapshot->lowPressure) {
            return [0, min(self::LOW_SAMPLES_TO_FILL, $state->consecutiveLow + 1), 'low_pressure_sample'];
        }

        return [0, 0, 'neutral_pressure'];
    }

    private function mayTransition(ControlState $state, int $now): bool
    {
        $dwellSatisfied = $state->lastTransitionAt === 0
            || $now - $state->lastTransitionAt >= self::MINIMUM_DWELL_SECONDS;

        return $dwellSatisfied && $now >= $state->cooldownUntil;
    }

    /**
     * @return list<string>
     */
    private function failSafeReasons(PipelineSnapshot $snapshot): array
    {
        $reasons = [];

        if (! $snapshot->telemetryFresh) {
            $reasons[] = 'telemetry_stale';
        }
        if (! $snapshot->telemetryComplete) {
            $reasons[] = 'telemetry_incomplete';
        }
        if (! $snapshot->telemetryConsistent || ($snapshot->highPressure && $snapshot->lowPressure)) {
            $reasons[] = 'telemetry_inconsistent';
        }
        if (! $snapshot->databaseMemorySafe) {
            $reasons[] = 'database_memory_limit';
        }
        if (! $snapshot->databaseCpuSafe) {
            $reasons[] = 'database_cpu_limit';
        }
        if (! $snapshot->databaseWaitsSafe) {
            $reasons[] = 'database_wait_or_deadlock';
        }
        if (! $snapshot->storageSafe) {
            $reasons[] = 'storage_floor';
        }
        if ($reasons === []) {
            $reasons[] = 'invalid_backlog_telemetry';
        }

        return $reasons;
    }

    private function backfillDenialReason(
        WorkerControlProfile $profile,
        PipelineSnapshot $snapshot,
        bool $backfillLocked,
        bool $targetLocked,
    ): string {
        if (! $profile->backfillEnabled) {
            return 'backfill_disabled_by_profile';
        }
        if ($snapshot->highPressure) {
            return 'backfill_high_pressure';
        }
        if ($backfillLocked) {
            return 'backfill_locked';
        }
        if ($targetLocked) {
            return 'backfill_target_locked';
        }
        if (! $snapshot->providerAvailable) {
            return 'backfill_provider_unavailable';
        }
        if (! $snapshot->cursorAvailable) {
            return 'backfill_cursor_exhausted';
        }
        if (! $snapshot->currentGroupsAvailable) {
            return 'backfill_no_current_groups';
        }
        if ($snapshot->backfillSafeQuantity < 10_000) {
            return 'backfill_no_safe_capacity';
        }

        return 'backfill_no_eligible_supply';
    }
}
