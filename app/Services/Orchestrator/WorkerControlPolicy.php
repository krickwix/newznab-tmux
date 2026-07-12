<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

final class WorkerControlPolicy
{
    public const int HIGH_SAMPLES_TO_DRAIN = 3;

    public const int LOW_SAMPLES_TO_FILL = 5;

    public const int MINIMUM_DWELL_SECONDS = 600;

    public const int TRANSITION_COOLDOWN_SECONDS = 1200;

    public const int INEFFECTIVE_BACKFILL_LIMIT = 2;

    public function decide(PipelineSnapshot $snapshot, ControlState $state, int $now): ControlDecision
    {
        [$ineffectivePermits, $backfillLocked, $effectivenessReasons] = $this->applyPermitOutcome($snapshot, $state);

        if (! $snapshot->telemetryIsValid() || ! $snapshot->hardSafetyPassed()) {
            $transitioned = $state->profile !== ControlProfile::FailSafe;
            $nextState = new ControlState(
                profile: ControlProfile::FailSafe,
                lastTransitionAt: $transitioned ? $now : $state->lastTransitionAt,
                cooldownUntil: $transitioned ? $now + self::TRANSITION_COOLDOWN_SECONDS : $state->cooldownUntil,
                consecutiveIneffectiveBackfillPermits: $ineffectivePermits,
                backfillLocked: $backfillLocked,
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
        );
        $workerProfile = WorkerControlProfile::for($profile);
        $backfillPermitted = $workerProfile->backfillEnabled
            && ! $backfillLocked
            && $snapshot->backfillGatesPassed();

        if (! $backfillPermitted) {
            $reasons[] = $this->backfillDenialReason($workerProfile, $snapshot, $backfillLocked);
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
     * @return array{int, bool, list<string>}
     */
    private function applyPermitOutcome(PipelineSnapshot $snapshot, ControlState $state): array
    {
        if (! $snapshot->backfillPermitCompleted) {
            return [$state->consecutiveIneffectiveBackfillPermits, $state->backfillLocked, []];
        }

        if ($snapshot->backfillPermitEffective) {
            return [0, $state->backfillLocked, ['backfill_permit_effective']];
        }

        if ($snapshot->backfillPermitClaimed && ! $snapshot->backfillPermitInputMoved) {
            return [
                0,
                $state->backfillLocked,
                ['backfill_permit_no_input'],
            ];
        }

        $count = min(self::INEFFECTIVE_BACKFILL_LIMIT, $state->consecutiveIneffectiveBackfillPermits + 1);
        $locked = $state->backfillLocked || $count >= self::INEFFECTIVE_BACKFILL_LIMIT;

        return [$count, $locked, [$locked ? 'backfill_locked_after_ineffective_permits' : 'backfill_permit_ineffective']];
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
    ): string {
        if (! $profile->backfillEnabled) {
            return 'backfill_disabled_by_profile';
        }
        if ($backfillLocked) {
            return 'backfill_locked';
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

        return 'backfill_no_eligible_supply';
    }
}
