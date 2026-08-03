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

    public const int RECOVERY_TRANSIENT_GROWTH_DOUBLING_MINUTES = 1_440;

    public const int RECOVERY_DRAIN_MAX_HOLD_SAMPLES = 3;

    public const int RECOVERY_DRAIN_HOLD_MAX_SPACING_SECONDS = 90;

    public function __construct(
        private readonly ?bool $qualifiedSupplyStarvationEnabled = null,
        private readonly ?int $qualifiedSupplyStarvationDwellSeconds = null,
        private readonly ?int $qualifiedSupplyRecoverySamples = null,
        private readonly ?float $qualifiedSupplyGrowthMinPerMinute = null,
        private readonly ?int $qualifiedSupplyColdStartCooldownSeconds = null,
        private readonly ?int $ineffectiveBackfillLimitOverride = null,
    ) {}

    /**
     * Consecutive input-bearing permits without attributed output before a
     * backfill target is locked. Env-tunable (was a hard const of 2): a cold or
     * heavily-incomplete backlog needs several backfill passes per group before
     * collections complete enough to yield a release, so a limit of 2 locks
     * every group before it can produce anything. Raise it to tolerate that.
     */
    private function ineffectiveBackfillLimit(): int
    {
        return max(1, $this->ineffectiveBackfillLimitOverride
            ?? (int) $this->orchestratorConfig('ineffective_backfill_limit', self::INEFFECTIVE_BACKFILL_LIMIT));
    }

    public function decide(PipelineSnapshot $snapshot, ControlState $state, int $now): ControlDecision
    {
        $permitOutcomeAlreadyApplied = $snapshot->backfillPermitCompleted
            && $snapshot->backfillPermitGeneration > 0
            && in_array($snapshot->backfillPermitGeneration, $state->processedBackfillPermitGenerations, true);
        [$ineffectivePermits, $backfillLocked, $targetIneffectivePermits, $effectivenessReasons] = $this->applyPermitOutcome($snapshot, $state);
        if ($permitOutcomeAlreadyApplied) {
            $ineffectivePermits = $state->consecutiveIneffectiveBackfillPermits;
            $backfillLocked = $state->backfillLocked;
            $targetIneffectivePermits = $state->ineffectiveBackfillPermitsByTarget;
            $effectivenessReasons = ['backfill_permit_outcome_already_applied'];
        }
        $processedPermitGenerations = $state->processedBackfillPermitGenerations;
        if ($snapshot->backfillPermitCompleted
            && $snapshot->backfillPermitGeneration > 0
            && ! $permitOutcomeAlreadyApplied
        ) {
            $processedPermitGenerations = array_slice([
                ...$processedPermitGenerations,
                $snapshot->backfillPermitGeneration,
            ], -64);
        }

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
                processedBackfillPermitGenerations: $processedPermitGenerations,
                qualifiedSupplyStarved: $state->qualifiedSupplyStarved,
                qualifiedSupplyCandidateSince: $state->qualifiedSupplyCandidateSince,
                qualifiedSupplyStarvedSince: $state->qualifiedSupplyStarvedSince,
                qualifiedSupplyLastObservedAt: $state->qualifiedSupplyLastObservedAt,
                qualifiedSupplyRecoverySamples: $state->qualifiedSupplyRecoverySamples,
            );

            return new ControlDecision(
                profile: WorkerControlProfile::for(ControlProfile::FailSafe),
                backfillPermitted: false,
                reasons: [
                    ...$this->failSafeReasons($snapshot),
                    ...($snapshot->databaseCurrentWaits > 0 ? ['backfill_database_busy'] : []),
                    ...$effectivenessReasons,
                ],
                nextState: $nextState,
                transitioned: $transitioned,
            );
        }

        [$highSamples, $lowSamples, $pressureReason] = $this->pressureSamples($snapshot, $state);
        [$qualifiedSupply, $qualifiedSupplyReasons] = $this->qualifiedSupplyState($snapshot, $state);
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
                $processedPermitGenerations,
                $qualifiedSupply,
                $qualifiedSupplyReasons,
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
            processedBackfillPermitGenerations: $processedPermitGenerations,
            qualifiedSupplyStarved: $qualifiedSupply['starved'],
            qualifiedSupplyCandidateSince: $qualifiedSupply['candidate_since'],
            qualifiedSupplyStarvedSince: $qualifiedSupply['starved_since'],
            qualifiedSupplyLastObservedAt: $qualifiedSupply['last_observed_at'],
            qualifiedSupplyRecoverySamples: $qualifiedSupply['recovery_samples'],
        );
        $workerProfile = WorkerControlProfile::for($profile);
        $targetLocked = $snapshot->backfillGroup !== ''
            && (int) ($targetIneffectivePermits[$snapshot->backfillGroup] ?? 0) >= $this->ineffectiveBackfillLimit()
            && ! $snapshot->backfillTargetLockRetryDue;
        if ($snapshot->backfillTargetLockRetryDue) {
            $reasons[] = 'backfill_target_lock_retry_due';
        }
        // All health gates except the self-referential starvation gate. When
        // these are green but supply is starved, the pipeline is otherwise
        // healthy and only lacks a permit to produce the qualified output that
        // would clear starvation.
        $backfillHealthyCommon = $workerProfile->backfillEnabled
            && ! $transitioned
            && ! $snapshot->highPressure
            && $snapshot->lowPressure
            && ! $backfillLocked
            && ! $targetLocked;
        $backfillHealthy = $backfillHealthyCommon && $snapshot->backfillGatesPassed();
        // Cold-start uses the same gates minus currentGroupsAvailable (a
        // current-forward freshness signal that must not block backward-fill of
        // older missing parts).
        $coldStartHealthy = $backfillHealthyCommon && $snapshot->backfillColdStartGatesPassed();

        // Cold-start probe: break the self-referential starvation deadlock by
        // granting ONE bounded backfill permit per cooldown window while starved
        // but otherwise healthy. Every real safeguard (pressure, DB admission,
        // provider/cursor, eligible supply, locks) is still enforced via
        // $coldStartHealthy.
        $coldStartCooldown = $this->qualifiedSupplyColdStartCooldownSeconds
            ?? (int) $this->orchestratorConfig('qualified_supply_cold_start_cooldown_seconds', 900);
        $coldStartAt = $state->qualifiedSupplyColdStartAt;
        $coldStartPermit = false;
        if ($qualifiedSupply['starved'] && $coldStartHealthy && $coldStartCooldown > 0) {
            $lastColdStart = $state->qualifiedSupplyColdStartAt;
            if ($lastColdStart <= 0 || ($snapshot->observedAt - $lastColdStart) >= $coldStartCooldown) {
                $coldStartPermit = true;
                $coldStartAt = $snapshot->observedAt;
            }
        } elseif (! $qualifiedSupply['starved']) {
            // Reset the cold-start clock once starvation clears so the next
            // episode can probe immediately.
            $coldStartAt = 0;
        }

        $backfillPermitted = ($backfillHealthy && ! $qualifiedSupply['starved'])
            || $coldStartPermit;

        $nextState = new ControlState(
            profile: $nextState->profile,
            consecutiveHigh: $nextState->consecutiveHigh,
            consecutiveLow: $nextState->consecutiveLow,
            lastTransitionAt: $nextState->lastTransitionAt,
            cooldownUntil: $nextState->cooldownUntil,
            consecutiveIneffectiveBackfillPermits: $nextState->consecutiveIneffectiveBackfillPermits,
            backfillLocked: $nextState->backfillLocked,
            ineffectiveBackfillPermitsByTarget: $nextState->ineffectiveBackfillPermitsByTarget,
            failSafeCause: $nextState->failSafeCause,
            failSafeLastObservedAt: $nextState->failSafeLastObservedAt,
            processedBackfillPermitGenerations: $nextState->processedBackfillPermitGenerations,
            qualifiedSupplyStarved: $nextState->qualifiedSupplyStarved,
            qualifiedSupplyCandidateSince: $nextState->qualifiedSupplyCandidateSince,
            qualifiedSupplyStarvedSince: $nextState->qualifiedSupplyStarvedSince,
            qualifiedSupplyLastObservedAt: $nextState->qualifiedSupplyLastObservedAt,
            qualifiedSupplyRecoverySamples: $nextState->qualifiedSupplyRecoverySamples,
            qualifiedSupplyColdStartAt: $coldStartAt,
        );

        if ($transitioned) {
            $reasons[] = 'backfill_profile_settling';
        } elseif ($coldStartPermit) {
            $reasons[] = 'backfill_qualified_supply_cold_start';
        } elseif (! $backfillPermitted) {
            $reasons[] = $qualifiedSupply['starved']
                ? 'backfill_qualified_supply_starved'
                : $this->backfillDenialReason($workerProfile, $snapshot, $backfillLocked, $targetLocked);
        }

        return new ControlDecision(
            profile: $workerProfile,
            backfillPermitted: $backfillPermitted,
            reasons: [...$reasons, ...$qualifiedSupplyReasons, ...$effectivenessReasons],
            nextState: $nextState,
            transitioned: $transitioned,
        );
    }

    /**
     * @param  array<string, int>  $targetIneffectivePermits
     * @param  list<string>  $effectivenessReasons
     * @param  list<int>  $processedPermitGenerations
     * @param  array{starved:bool,candidate_since:int,starved_since:int,last_observed_at:int,recovery_samples:int}  $qualifiedSupply
     * @param  list<string>  $qualifiedSupplyReasons
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
        array $processedPermitGenerations,
        array $qualifiedSupply,
        array $qualifiedSupplyReasons,
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
            processedBackfillPermitGenerations: $processedPermitGenerations,
            qualifiedSupplyStarved: $qualifiedSupply['starved'],
            qualifiedSupplyCandidateSince: $qualifiedSupply['candidate_since'],
            qualifiedSupplyStarvedSince: $qualifiedSupply['starved_since'],
            qualifiedSupplyLastObservedAt: $qualifiedSupply['last_observed_at'],
            qualifiedSupplyRecoverySamples: $qualifiedSupply['recovery_samples'],
        );

        return new ControlDecision(
            profile: WorkerControlProfile::for($profile),
            backfillPermitted: false,
            reasons: [
                ...$reasons,
                ...$effectivenessReasons,
                ...$qualifiedSupplyReasons,
                ...($snapshot->databaseCurrentWaits > 0 ? ['backfill_database_busy'] : []),
                'backfill_disabled_by_profile',
            ],
            nextState: $nextState,
            transitioned: $recovered,
        );
    }

    private function strongRecoveryDrainSample(PipelineSnapshot $snapshot): bool
    {
        if (! $this->safeRecoveryDrainTrend($snapshot)) {
            return false;
        }

        foreach ($this->recoveryCoreStages($snapshot) as $stage) {
            $instant = $snapshot->backlogRatesPerMinute[$stage] ?? NAN;
            if ($instant > 0.0) {
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

        foreach ($this->recoveryCoreStages($snapshot) as $stage) {
            $instant = $snapshot->backlogRatesPerMinute[$stage] ?? NAN;
            $ewma = $snapshot->backlogEwmaPerMinute[$stage] ?? NAN;
            $backlog = match ($stage) {
                'parts' => $snapshot->partsBacklog,
                'binaries' => $snapshot->binariesBacklog,
                'collections' => $snapshot->collectionsBacklog,
                'collections_total' => $snapshot->physicalCollectionsBacklog(),
                default => throw new \LogicException('Unsupported recovery core stage: '.$stage),
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

    /** @return list<string> */
    private function recoveryCoreStages(PipelineSnapshot $snapshot): array
    {
        return [
            'parts',
            'binaries',
            array_key_exists('collections_total', $snapshot->backlogRatesPerMinute)
                ? 'collections_total'
                : 'collections',
        ];
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

        // Expiry/revocation only proves that the grant was not consumed. It is
        // not evidence about source yield, so preserve target strike history.
        if (! $snapshot->backfillPermitClaimed) {
            return [
                $state->consecutiveIneffectiveBackfillPermits,
                $state->backfillLocked,
                $state->ineffectiveBackfillPermitsByTarget,
                ['backfill_permit_unclaimed'],
            ];
        }

        $target = $snapshot->backfillPermitGroup;
        $targetCounts = $state->ineffectiveBackfillPermitsByTarget;
        if ($target !== '') {
            if ($snapshot->backfillPermitQualityFailure !== '') {
                $targetCounts[$target] = $this->ineffectiveBackfillLimit();

                return [
                    $this->ineffectiveBackfillLimit(),
                    $state->backfillLocked,
                    $targetCounts,
                    [$snapshot->backfillPermitQualityFailure],
                ];
            }
            if ($targetCounts === [] && $state->consecutiveIneffectiveBackfillPermits > 0) {
                $targetCounts[$target] = min(
                    $this->ineffectiveBackfillLimit(),
                    $state->consecutiveIneffectiveBackfillPermits,
                );
            }
            if ($snapshot->backfillPermitEffective) {
                unset($targetCounts[$target]);

                return [0, $state->backfillLocked, $targetCounts, ['backfill_permit_effective']];
            }

            if (! $snapshot->backfillPermitInputMoved) {
                return [
                    $state->consecutiveIneffectiveBackfillPermits,
                    $state->backfillLocked,
                    $targetCounts,
                    ['backfill_permit_no_input'],
                ];
            }

            if ($snapshot->backfillPermitContextProgress) {
                $targetCounts[$target] = max(0, (int) ($targetCounts[$target] ?? 0) - 1);
                if ($targetCounts[$target] === 0) {
                    unset($targetCounts[$target]);
                }

                return [
                    max(0, $state->consecutiveIneffectiveBackfillPermits - 1),
                    $state->backfillLocked,
                    $targetCounts,
                    ['backfill_permit_context_progress'],
                ];
            }

            $targetCounts[$target] = min(
                $this->ineffectiveBackfillLimit(),
                (int) ($targetCounts[$target] ?? 0) + 1,
            );
            $count = min($this->ineffectiveBackfillLimit(), $state->consecutiveIneffectiveBackfillPermits + 1);

            return [
                $count,
                $state->backfillLocked,
                $targetCounts,
                [$targetCounts[$target] >= $this->ineffectiveBackfillLimit()
                    ? 'backfill_target_locked_after_ineffective_permits'
                    : 'backfill_permit_ineffective'],
            ];
        }

        if ($snapshot->backfillPermitEffective) {
            return [0, $state->backfillLocked, $targetCounts, ['backfill_permit_effective']];
        }

        if (! $snapshot->backfillPermitInputMoved) {
            return [
                $state->consecutiveIneffectiveBackfillPermits,
                $state->backfillLocked,
                $targetCounts,
                ['backfill_permit_no_input'],
            ];
        }

        if ($snapshot->backfillPermitContextProgress) {
            return [
                max(0, $state->consecutiveIneffectiveBackfillPermits - 1),
                $state->backfillLocked,
                $targetCounts,
                ['backfill_permit_context_progress'],
            ];
        }

        $count = min($this->ineffectiveBackfillLimit(), $state->consecutiveIneffectiveBackfillPermits + 1);
        $locked = $state->backfillLocked || $count >= $this->ineffectiveBackfillLimit();

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

    /**
     * @return array{array{starved:bool,candidate_since:int,starved_since:int,last_observed_at:int,recovery_samples:int}, list<string>}
     */
    private function qualifiedSupplyState(PipelineSnapshot $snapshot, ControlState $state): array
    {
        if (! $this->qualifiedSupplyStarvationEnabled()) {
            return [[
                'starved' => false,
                'candidate_since' => 0,
                'starved_since' => 0,
                'last_observed_at' => 0,
                'recovery_samples' => 0,
            ], []];
        }

        $current = [
            'starved' => $state->qualifiedSupplyStarved,
            'candidate_since' => $state->qualifiedSupplyCandidateSince,
            'starved_since' => $state->qualifiedSupplyStarvedSince,
            'last_observed_at' => $state->qualifiedSupplyLastObservedAt,
            'recovery_samples' => $state->qualifiedSupplyRecoverySamples,
        ];
        if ($snapshot->observedAt <= $state->qualifiedSupplyLastObservedAt) {
            return [$current, $state->qualifiedSupplyStarved ? ['qualified_supply_starved'] : []];
        }

        // Queued collections and NZBs are unsettled work, not proof that opening
        // the raw-input valve produces useful output. Recover only from an
        // observed release yield or an effective, completed permit cohort.
        //
        // Three-state on purpose. A null yield means the sample window was
        // unusable, not that the pipeline produced nothing, so it must not be
        // read as evidence either way: treating it as unproductive reset the
        // recovery counter on every slow controller pass and could strand a
        // healthy pipeline in starvation indefinitely.
        $permitProductive = $snapshot->backfillPermitCompleted && $snapshot->backfillPermitEffective;
        $productive = $permitProductive || ($snapshot->releaseYieldPerMinute ?? 0.0) > 0.0;
        $yieldUnknown = ! $permitProductive && $snapshot->releaseYieldPerMinute === null;
        $minimumGrowth = max(0.0, $this->qualifiedSupplyGrowthMinPerMinute
            ?? (float) $this->orchestratorConfig('qualified_supply_growth_min_per_minute', 1.0));
        $growing = max(
            (float) ($snapshot->backlogEwmaPerMinute['parts'] ?? 0.0),
            (float) ($snapshot->backlogEwmaPerMinute['binaries'] ?? 0.0),
        ) >= $minimumGrowth
            && ($snapshot->schedulablePartsBacklog() > 0 || $snapshot->schedulableBinariesBacklog() > 0);

        $current['last_observed_at'] = $snapshot->observedAt;
        if ($state->qualifiedSupplyStarved) {
            if ($yieldUnknown) {
                // Hold the accumulated progress instead of discarding it; the
                // next measurable sample decides.
                return [$current, ['qualified_supply_starved', 'release_yield_unknown']];
            }
            if (! $productive) {
                $current['recovery_samples'] = 0;

                return [$current, ['qualified_supply_starved']];
            }

            $required = max(1, $this->qualifiedSupplyRecoverySamples
                ?? (int) $this->orchestratorConfig('qualified_supply_recovery_samples', 2));
            $current['recovery_samples'] = min($required, $state->qualifiedSupplyRecoverySamples + 1);
            if ($current['recovery_samples'] < $required) {
                return [$current, ['qualified_supply_recovery_pending']];
            }

            return [[
                'starved' => false,
                'candidate_since' => 0,
                'starved_since' => 0,
                'last_observed_at' => $snapshot->observedAt,
                'recovery_samples' => 0,
            ], ['qualified_supply_recovered']];
        }

        if ($productive || ! $growing) {
            $current['candidate_since'] = 0;
            $current['recovery_samples'] = 0;

            return [$current, []];
        }

        if ($yieldUnknown) {
            // Never open a starvation candidacy on an unmeasured sample: that
            // would let repeated stale readings accumulate dwell toward
            // starving a pipeline that may well be producing.
            return [$current, ['release_yield_unknown']];
        }

        $candidateSince = $state->qualifiedSupplyCandidateSince > 0
            ? $state->qualifiedSupplyCandidateSince
            : $snapshot->observedAt;
        $current['candidate_since'] = $candidateSince;
        $dwell = max(300, $this->qualifiedSupplyStarvationDwellSeconds
            ?? (int) $this->orchestratorConfig('qualified_supply_starvation_dwell_seconds', 900));
        if ($snapshot->observedAt - $candidateSince < $dwell) {
            return [$current, ['qualified_supply_starvation_candidate']];
        }

        $current['starved'] = true;
        $current['starved_since'] = $snapshot->observedAt;

        return [$current, ['qualified_supply_starved']];
    }

    private function qualifiedSupplyStarvationEnabled(): bool
    {
        return $this->qualifiedSupplyStarvationEnabled
            ?? (bool) $this->orchestratorConfig('qualified_supply_starvation_enabled', false);
    }

    private function orchestratorConfig(string $key, mixed $default): mixed
    {
        $container = Container::getInstance();

        return $container->bound('config')
            ? config('nntmux.orchestrator.'.$key, $default)
            : $default;
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
        // Same fail-closed outcome either way, but name which one it was: an
        // unanswered query and a genuine breach need opposite responses from
        // whoever reads these reasons.
        if (! $snapshot->databaseMemorySafe) {
            $reasons[] = $snapshot->databaseMemoryKnown ? 'database_memory_limit' : 'database_memory_unknown';
        }
        if (! $snapshot->databaseCpuSafe) {
            $reasons[] = $snapshot->databaseCpuKnown ? 'database_cpu_limit' : 'database_cpu_unknown';
        }
        if (! $snapshot->databaseWaitsSafe) {
            $reasons[] = 'database_wait_or_deadlock';
        }
        if (! $snapshot->storageSafe) {
            $reasons[] = $snapshot->storageKnown ? 'storage_floor' : 'storage_unknown';
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
        if ($snapshot->databaseCurrentWaits > 0) {
            return 'backfill_database_busy';
        }
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
        if (! $snapshot->eligibleBackfillSupply) {
            return 'backfill_no_eligible_supply';
        }
        if (! $snapshot->lowPressure) {
            return 'backfill_pipeline_not_drained';
        }

        return 'backfill_no_eligible_supply';
    }
}
