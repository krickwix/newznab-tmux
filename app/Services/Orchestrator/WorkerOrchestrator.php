<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkerOrchestrator
{
    public function __construct(
        private readonly PipelineSnapshotRepository $snapshots,
        private readonly WorkerControlPolicy $policy,
        private readonly WorkerControlStateStore $store,
        private readonly WorkerProfileApplier $applier,
    ) {}

    /** @return array<string, mixed> */
    public function runOnce(bool $shadow, bool $grantPermit = false): array
    {
        $lock = null;
        $acquired = false;
        $shadowQualityFailure = null;
        try {
            $lock = $this->store->leaderLock();
            if (! $lock->get()) {
                return ['leader' => false, 'applied' => false, 'reason' => 'leader_lock_contended'];
            }
            $acquired = true;
            $previous = $this->store->previousSnapshot();
            $snapshot = $this->snapshots->capture($previous);
            $permitObservation = $this->store->permitObservation();
            if ((int) ($permitObservation['schema_version'] ?? 0) === 2) {
                $permitObservation = $this->store->updatePermitObservationPeaks($snapshot);
            }
            if ($grantPermit && $permitObservation !== null) {
                return [
                    'leader' => true,
                    'applied' => false,
                    'reason' => 'backfill_permit_observation_in_progress',
                ];
            }
            $observationSeconds = (int) config('nntmux.orchestrator.permit_observation_seconds', 1200);
            $observationExpired = $permitObservation !== null
                && time() - (int) $permitObservation['issued_at'] >= $observationSeconds;
            $hasCohortBaseline = $permitObservation !== null
                && array_key_exists('release_high_watermark', $permitObservation)
                && array_key_exists('backfill_cursor_postdate', $permitObservation);
            $permitClaimed = $permitObservation !== null
                && ($observationExpired || $hasCohortBaseline)
                && (int) Settings::settingValue('orchestrator_bf_claimed') === (int) $permitObservation['generation'];
            $permitCompleted = $permitClaimed
                && $hasCohortBaseline
                && (int) Settings::settingValue('orchestrator_bf_completed') === (int) $permitObservation['generation'];
            if ($permitCompleted && ! isset($permitObservation['completed_observed_at'])) {
                $permitObservation = $this->store->observePermitCompletion(
                    (int) $permitObservation['generation'],
                    time(),
                ) ?? $permitObservation;
            }
            if ($permitObservation !== null && ($observationExpired || ($permitClaimed && $hasCohortBaseline))) {
                $observedGroup = (string) ($permitObservation['backfill_group'] ?? '');
                $outcome = $observedGroup === ''
                    ? ['cursor' => 0, 'cursor_postdate' => '', 'ready_collections' => 0, 'releases' => 0, 'release_high_watermark' => 0, 'group_active' => 1, 'partial_collections' => 0, 'complete_binaries' => 0]
                    : $this->snapshots->backfillOutcomeForGroup($observedGroup);
                $cursorMoved = $outcome['cursor'] > 0
                    && $outcome['cursor'] < (int) ($permitObservation['backfill_cursor'] ?? 0);
                $contextProgress = $permitClaimed
                    && $this->hasBackfillOnlyContextProgress($permitObservation, $outcome, $cursorMoved);
                $produced = $outcome['ready_collections'] > (int) ($permitObservation['ready_collections'] ?? 0)
                    || $outcome['releases'] > (int) ($permitObservation['release_total'] ?? 0);
                $cohortNzbCategories = ['target' => 0, 'non_target' => 0, 'uncategorized' => 0];
                $currentCohortNzbs = 0;
                $cohortReleases = 0;
                if ($permitClaimed && $hasCohortBaseline && ($observationExpired || $cursorMoved)) {
                    $cohortReleases = $this->snapshots->backfillCreatedReleasesForCohort(
                        $observedGroup,
                        (int) $permitObservation['release_high_watermark'],
                        (string) $permitObservation['backfill_cursor_postdate'],
                        (string) ($outcome['cursor_postdate'] ?? ''),
                    );
                    $cohortNzbCategories = $this->snapshots->backfillCreatedNzbCategoryCountsForCohort(
                        $observedGroup,
                        (int) $permitObservation['release_high_watermark'],
                        (string) $permitObservation['backfill_cursor_postdate'],
                        (string) ($outcome['cursor_postdate'] ?? ''),
                    );
                    $currentCohortNzbs = array_sum($cohortNzbCategories);
                    $priorReleaseCohort = $permitObservation['prior_release_cohort'] ?? null;
                    if (is_array($priorReleaseCohort)) {
                        $carriedNzbCategories = $this->snapshots->backfillCompletedNzbCategoryCountsForReleaseCohort(
                            $observedGroup,
                            (int) ($priorReleaseCohort['id_low_exclusive'] ?? 0),
                            (int) ($priorReleaseCohort['id_high_inclusive'] ?? 0),
                            (string) ($priorReleaseCohort['cursor_start_postdate'] ?? ''),
                            (string) ($priorReleaseCohort['cursor_end_postdate'] ?? ''),
                        );
                        foreach (array_keys($cohortNzbCategories) as $category) {
                            $cohortNzbCategories[$category] += $carriedNzbCategories[$category];
                        }
                    }
                }
                $cohortNzbs = array_sum($cohortNzbCategories);
                $cohortQuality = $this->classifyCohortNzbQuality($cohortNzbCategories, $permitObservation, time());
                $cursorDelta = max(0, (int) ($permitObservation['backfill_cursor'] ?? 0) - (int) $outcome['cursor']);
                $zeroOutputContextReady = $this->zeroOutputContextReady(
                    $permitObservation,
                    $outcome,
                    $snapshot,
                    $permitClaimed,
                    $permitCompleted,
                    $cursorDelta,
                    $cohortReleases,
                    $cohortNzbs,
                    time(),
                );
                $closeObservation = $cohortQuality['failure'] !== null
                    || (! $cohortQuality['hold'] && (
                        $observationExpired
                        || ($permitCompleted && $cursorMoved && $cohortQuality['productive'] > 0 && $snapshot->eligibleNzbs === 0)
                        || ($permitCompleted && ! $cursorMoved)
                        || $zeroOutputContextReady
                    ));
                if ($shadow && $cohortQuality['failure'] !== null) {
                    $shadowQualityFailure = $cohortQuality['failure'];
                    $closeObservation = false;
                }
                if ($cohortQuality['failure'] === null
                    && $observationExpired
                    && $permitClaimed
                    && $hasCohortBaseline
                    && ! $permitCompleted
                ) {
                    $closeObservation = false;
                    if (! $shadow) {
                        $this->applier->revokePermit();
                    }
                }
                if ($closeObservation && ! $shadow) {
                    $cohortQuality['failure'] === null
                        ? $this->applier->revokePermit()
                        : $this->applier->qualityLockBackfillTarget($observedGroup, $cohortQuality['failure']);
                }
                if ($closeObservation && ! $shadow && $permitClaimed && $hasCohortBaseline) {
                    if ($this->shouldRememberIncompleteReleaseCohort(
                        $permitObservation,
                        $permitCompleted,
                        $cursorMoved,
                        $cursorDelta,
                        $cohortReleases,
                        $currentCohortNzbs,
                    )) {
                        $this->store->rememberIncompleteReleaseCohort(
                            $permitObservation,
                            (int) ($outcome['release_high_watermark'] ?? 0),
                            (string) ($outcome['cursor_postdate'] ?? ''),
                            time(),
                        );
                    }
                    $this->store->recordBackfillYield(
                        $observedGroup,
                        cursorDelta: $cursorDelta,
                        nzbCreatedDelta: $cohortQuality['productive'],
                        now: time(),
                    );
                    if ($permitCompleted) {
                        $this->store->recordBackfillGrowth(
                            $observedGroup,
                            $permitObservation,
                            $cursorDelta,
                            (int) ($permitObservation['backfill_quantity'] ?? 0),
                        );
                    }
                    $requestedQuantity = max(0, (int) ($permitObservation['backfill_quantity'] ?? 0));
                    $isInputBearingClose = $cursorMoved && $cursorDelta > 0;
                    $contextRepeat = $isInputBearingClose
                        ? $this->store->backfillContextRepeat(time())
                        : null;
                    $isExistingContextRepeat = $isInputBearingClose
                        && (string) ($contextRepeat['group'] ?? '') === $observedGroup;
                    if ($isExistingContextRepeat) {
                        $this->store->clearBackfillContextRepeat($observedGroup);
                    } elseif ($contextProgress
                        && $isInputBearingClose
                        && $cohortReleases === 0
                        && $cohortNzbs === 0
                        && $cohortQuality['productive'] === 0
                        && $cohortQuality['failure'] === null
                        && $requestedQuantity >= 10_000
                        && $cursorDelta === $requestedQuantity
                    ) {
                        $this->store->markBackfillContextRepeat($observedGroup, time());
                    }
                }
                if ($closeObservation && ! $shadow) {
                    $snapshot = $snapshot->withPermitOutcome(
                        completed: true,
                        effective: $permitClaimed && $cursorMoved && ($hasCohortBaseline ? $cohortQuality['productive'] > 0 : $produced),
                        claimed: $permitClaimed,
                        inputMoved: $cursorMoved,
                        contextProgress: $contextProgress,
                        group: $observedGroup,
                        qualityFailure: $cohortQuality['failure'] ?? '',
                    );
                    $this->store->clearPermitObservation();
                }
            }
            $state = $this->store->loadState();
            $decision = $this->policy->decide($snapshot, $state, time());
            $generation = null;
            $autoGrant = ! $shadow
                && (bool) config('nntmux.orchestrator.auto_backfill', false)
                && $decision->backfillPermitted
                && $permitObservation === null
                && (int) Settings::settingValue('orchestrator_bf_permit') === 0;
            $issuePermit = $grantPermit || $autoGrant;
            $backfillQuantity = $decision->profile->quantityForYield(
                $snapshot->backfillYieldNzbsPer10k,
                $snapshot->backfillRemainingArticles,
                $snapshot->backfillSafeQuantity,
                $snapshot->backfillYieldAttempts,
                $snapshot->backfillLastCursorDelta,
                $snapshot->backfillLastEffectiveAt,
                $snapshot->backfillHistoryRecent,
                $snapshot->backfillTargetIneffectivePermits,
                $snapshot->backfillTargetLockRetryDue,
            );
            $preserveUnclaimedPermit = ! $shadow
                && $permitObservation !== null
                && time() - (int) $permitObservation['issued_at'] < (int) config('nntmux.orchestrator.permit_claim_grace_seconds', 120)
                && (int) Settings::settingValue('orchestrator_bf_permit') === (int) $permitObservation['generation']
                && in_array('backfill_no_eligible_supply', $decision->reasons, true);
            if (! $shadow) {
                $generation = $issuePermit
                    ? $this->applier->apply(
                        $decision,
                        time(),
                        true,
                        $snapshot->backfillGroup,
                        $preserveUnclaimedPermit,
                        $backfillQuantity,
                    )
                    : $this->applier->apply(
                        $decision,
                        time(),
                        false,
                        $snapshot->backfillGroup,
                        $preserveUnclaimedPermit,
                    );
                if ($issuePermit && $decision->backfillPermitted) {
                    $this->store->beginPermitObservation(
                        $snapshot,
                        $generation,
                        time(),
                        $this->snapshots->backfillOutcomeForGroup($snapshot->backfillGroup),
                        $backfillQuantity,
                    );
                    if ($snapshot->backfillTargetLockRetryDue) {
                        $this->store->markBackfillTargetAttempted($snapshot->backfillGroup, time());
                    }
                }
            }
            $this->store->storeState($decision->nextState);
            $this->store->storeSnapshot($snapshot);

            $result = [
                'leader' => true,
                'mode' => $shadow ? 'shadow' : 'active',
                'applied' => ! $shadow,
                'generation' => $generation,
                'profile' => $decision->profile->profile->value,
                'backfill_permitted' => $decision->backfillPermitted,
                'permit_granted' => ! $shadow && $issuePermit && $decision->backfillPermitted,
                'reasons' => [
                    ...$decision->reasons,
                    ...($preserveUnclaimedPermit ? ['backfill_permit_claim_grace'] : []),
                    ...($shadowQualityFailure === null ? [] : [$shadowQualityFailure, 'backfill_quality_shadow_observation']),
                ],
                'backlogs' => [
                    'parts' => $snapshot->partsBacklog,
                    'binaries' => $snapshot->binariesBacklog,
                    'collections' => $snapshot->collectionsBacklog,
                    'collections_total' => $snapshot->physicalCollectionsBacklog(),
                    'recovery_sources' => $snapshot->bodyRecoverySourceBacklog,
                    'releases' => $snapshot->releasesBacklog,
                    'nzbs' => $snapshot->nzbsBacklog,
                ],
                'collection_backlogs' => [
                    'total' => $snapshot->physicalCollectionsBacklog(),
                    'ordinary' => $snapshot->collectionsBacklog,
                    'body_recovery_sources' => $snapshot->bodyRecoverySourceBacklog,
                ],
                'storage_available_bytes' => $snapshot->storageAvailableBytes,
                'observed_at' => $snapshot->observedAt,
                'eligible_nzbs' => $snapshot->eligibleNzbs,
                'body_recovery_queue' => $snapshot->bodyRecoveryQueueBacklog,
                'pressure' => $snapshot->highPressure ? 'high' : ($snapshot->lowPressure ? 'low' : 'neutral'),
                'rates_per_minute' => $snapshot->backlogRatesPerMinute,
                'ewma_per_minute' => $snapshot->backlogEwmaPerMinute,
                'oldest_age_seconds' => [
                    'binaries' => $snapshot->oldestBinaryAgeSeconds,
                    'collections' => $snapshot->oldestCollectionAgeSeconds,
                    'recovery_sources' => $snapshot->oldestBodyRecoverySourceAgeSeconds,
                    'releases' => $snapshot->oldestReleaseAgeSeconds,
                    'nzbs' => $snapshot->oldestNzbAgeSeconds,
                ],
                'backfill_target' => [
                    'group' => $snapshot->backfillGroup,
                    'cursor' => $snapshot->backfillCursor,
                    'yield_nzbs_per_10k' => $snapshot->backfillYieldNzbsPer10k,
                    'quantity' => $issuePermit && $decision->backfillPermitted ? $backfillQuantity : 0,
                    'safe_quantity' => $snapshot->backfillSafeQuantity,
                ],
            ];
            $this->store->storeDecision($result);
            Log::info('NNTmux worker orchestrator decision', $result);

            return $result;
        } catch (Throwable $error) {
            Log::error('NNTmux worker orchestrator failed closed', ['error' => $error->getMessage()]);
            if (! $shadow) {
                try {
                    $this->applier->failClosed();
                } catch (Throwable $failClosedError) {
                    Log::critical('NNTmux worker orchestrator could not persist fail-safe state', [
                        'error' => $failClosedError->getMessage(),
                    ]);
                }
            }
            throw $error;
        } finally {
            if ($acquired && $lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable $releaseError) {
                    Log::critical('NNTmux worker orchestrator leader lock release failed', [
                        'error' => $releaseError->getMessage(),
                    ]);
                    if (! $shadow) {
                        $this->applier->failClosed();
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $observation */
    private function shouldRememberIncompleteReleaseCohort(
        array $observation,
        bool $permitCompleted,
        bool $cursorMoved,
        int $cursorDelta,
        int $cohortReleases,
        int $cohortNzbs,
    ): bool {
        $quantity = (int) ($observation['backfill_quantity'] ?? 0);

        return $permitCompleted
            && $cursorMoved
            && $quantity >= 10_000
            && $cursorDelta === $quantity
            && $cohortReleases > 0
            && $cohortNzbs === 0;
    }

    /**
     * @param  array{target: int, non_target: int, uncategorized: int}  $counts
     * @param  array<string, mixed>  $observation
     * @return array{productive: int, hold: bool, failure: string|null}
     */
    private function classifyCohortNzbQuality(array $counts, array $observation, int $now): array
    {
        if ($counts['non_target'] > 0) {
            return [
                'productive' => 0,
                'hold' => false,
                'failure' => 'backfill_permit_wrong_category',
            ];
        }

        if ($counts['uncategorized'] > 0) {
            $completedAt = (int) ($observation['completed_observed_at'] ?? 0);
            $graceSeconds = (int) config('nntmux.orchestrator.backfill_incomplete_release_grace_seconds', 600);
            $graceExpired = $completedAt > 0 && $now - $completedAt >= $graceSeconds;

            return [
                'productive' => 0,
                'hold' => ! $graceExpired,
                'failure' => $graceExpired ? 'backfill_permit_uncategorized_after_grace' : null,
            ];
        }

        return [
            'productive' => max(0, $counts['target']),
            'hold' => false,
            'failure' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $observation
     * @param  array<string, mixed>  $outcome
     */
    private function zeroOutputContextReady(
        array $observation,
        array $outcome,
        PipelineSnapshot $snapshot,
        bool $permitClaimed,
        bool $permitCompleted,
        int $cursorDelta,
        int $cohortReleases,
        int $cohortNzbs,
        int $now,
    ): bool {
        $completedAt = (int) ($observation['completed_observed_at'] ?? 0);
        $graceSeconds = $cohortReleases > 0
            ? (int) config('nntmux.orchestrator.backfill_incomplete_release_grace_seconds', 600)
            : (int) config('nntmux.orchestrator.backfill_zero_output_grace_seconds', 300);
        $quantity = (int) ($observation['backfill_quantity'] ?? 0);

        return $permitClaimed
            && $permitCompleted
            && $completedAt > 0
            && $now - $completedAt >= $graceSeconds
            && $quantity >= 10_000
            && $cursorDelta === $quantity
            && $cohortNzbs === 0
            && (int) ($outcome['ready_collections'] ?? 0) <= (int) ($observation['ready_collections'] ?? 0)
            && $snapshot->eligibleNzbs === 0
            && $snapshot->telemetryIsValid()
            && $snapshot->hardSafetyPassed()
            && ! $snapshot->highPressure
            && $snapshot->databaseCurrentWaits === 0;
    }

    /**
     * @param  array<string, mixed>  $observation
     * @param  array<string, mixed>  $outcome
     */
    private function hasBackfillOnlyContextProgress(array $observation, array $outcome, bool $cursorMoved): bool
    {
        if (! $cursorMoved
            || ! array_key_exists('backfill_group_active', $observation)
            || (int) $observation['backfill_group_active'] !== 0
            || (int) ($outcome['group_active'] ?? 1) !== 0
        ) {
            return false;
        }

        return (int) ($outcome['partial_collections'] ?? 0) > (int) ($observation['partial_collections'] ?? 0)
            || (int) ($outcome['complete_binaries'] ?? 0) > (int) ($observation['complete_binaries'] ?? 0);
    }
}
