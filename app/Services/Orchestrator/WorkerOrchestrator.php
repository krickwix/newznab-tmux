<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;
use App\Services\Distributed\CurrentForwardPermitGate;
use App\Services\Metrics\DistributedWorkerTelemetry;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkerOrchestrator
{
    /** @var list<string> */
    private const array CLAIM_GRACE_SUPPLY_REASONS = [
        'backfill_no_eligible_supply',
        'backfill_provider_unavailable',
    ];

    public function __construct(
        private readonly PipelineSnapshotRepository $snapshots,
        private readonly WorkerControlPolicy $policy,
        private readonly WorkerControlStateStore $store,
        private readonly WorkerProfileApplier $applier,
        private readonly DistributedWorkerTelemetry $workerTelemetry = new DistributedWorkerTelemetry,
        private readonly AdaptiveWorkerControlPlanner $adaptiveControls = new AdaptiveWorkerControlPlanner,
        private readonly ?CurrentForwardPermitGate $currentForwardPermits = null,
    ) {}

    /** @return array<string, mixed> */
    public function runOnce(bool $shadow, bool $grantPermit = false, bool $grantCurrentForwardPermit = false): array
    {
        $lock = null;
        $acquired = false;
        $shadowQualityFailure = null;
        $abandonedPermit = false;
        $delayedAttributionQueued = false;
        $delayedAttributionSettled = null;
        $delayedAttributionEarlyQualityLock = false;
        $currentForward = [
            'granted' => false,
            'reason' => $grantCurrentForwardPermit ? 'current_forward_not_evaluated' : 'not_requested',
            'generation' => 0,
            'group' => '',
            'first' => 0,
            'last' => 0,
            'stop' => 0,
        ];
        try {
            $lock = $this->store->leaderLock();
            if (! $lock->get()) {
                return ['leader' => false, 'applied' => false, 'reason' => 'leader_lock_contended'];
            }
            $acquired = true;
            $previous = $this->store->previousSnapshot();
            $snapshot = $this->snapshots->capture($previous);
            $permitObservation = $this->store->permitObservation();
            if (! $shadow
                && $permitObservation === null
                && (int) Settings::settingValue('orchestrator_bf_permit') === 0
            ) {
                [$snapshot, $delayedAttributionSettled] = $this->lockFailedImmatureDelayedAttribution($snapshot, time());
                $delayedAttributionEarlyQualityLock = str_starts_with(
                    (string) ($delayedAttributionSettled['result'] ?? ''),
                    'backfill_permit_',
                );
                if ($delayedAttributionSettled === null) {
                    [$snapshot, $delayedAttributionSettled] = $this->settleMatureDelayedAttribution($snapshot, time());
                }
            }
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
                    ? ['cursor' => 0, 'cursor_postdate' => '', 'ready_collections' => 0, 'releases' => 0, 'release_high_watermark' => 0, 'group_active' => 1, 'raw_collections' => 0, 'raw_binaries' => 0, 'partial_collections' => 0, 'complete_binaries' => 0]
                    : $this->snapshots->backfillOutcomeForGroup($observedGroup);
                $cursorMoved = $outcome['cursor'] > 0
                    && $outcome['cursor'] < (int) ($permitObservation['backfill_cursor'] ?? 0);
                $strongContextProgress = $permitClaimed
                    && $this->hasBackfillOnlyContextProgress($permitObservation, $outcome, $cursorMoved);
                $produced = $outcome['ready_collections'] > (int) ($permitObservation['ready_collections'] ?? 0)
                    || $outcome['releases'] > (int) ($permitObservation['release_total'] ?? 0);
                $cohortNzbCategories = ['target' => 0, 'non_target' => 0, 'uncategorized' => 0];
                $cohortNzbBytes = ['target' => 0, 'non_target' => 0, 'uncategorized' => 0];
                $currentCohortNzbs = 0;
                $cohortReleases = 0;
                if ($permitClaimed && $hasCohortBaseline && ($observationExpired || $cursorMoved)) {
                    $cohortReleases = $this->snapshots->backfillCreatedReleasesForCohort(
                        $observedGroup,
                        (int) $permitObservation['release_high_watermark'],
                        (string) $permitObservation['backfill_cursor_postdate'],
                        (string) ($outcome['cursor_postdate'] ?? ''),
                    );
                    $cohortNzbQuality = $this->backfillCreatedNzbCategoryQualityForCohort(
                        $observedGroup,
                        (int) $permitObservation['release_high_watermark'],
                        (string) $permitObservation['backfill_cursor_postdate'],
                        (string) ($outcome['cursor_postdate'] ?? ''),
                    );
                    $cohortNzbCategories = $cohortNzbQuality['counts'];
                    $cohortNzbBytes = $cohortNzbQuality['bytes'];
                    $currentCohortNzbs = array_sum($cohortNzbCategories);
                    $priorReleaseCohort = $permitObservation['prior_release_cohort'] ?? null;
                    if (is_array($priorReleaseCohort)) {
                        $carriedNzbQuality = $this->backfillCompletedNzbCategoryQualityForReleaseCohort(
                            $observedGroup,
                            (int) ($priorReleaseCohort['id_low_exclusive'] ?? 0),
                            (int) ($priorReleaseCohort['id_high_inclusive'] ?? 0),
                            (string) ($priorReleaseCohort['cursor_start_postdate'] ?? ''),
                            (string) ($priorReleaseCohort['cursor_end_postdate'] ?? ''),
                        );
                        foreach (array_keys($cohortNzbCategories) as $category) {
                            $cohortNzbCategories[$category] += $carriedNzbQuality['counts'][$category];
                            $cohortNzbBytes[$category] += $carriedNzbQuality['bytes'][$category];
                        }
                    }
                }
                $cohortNzbs = array_sum($cohortNzbCategories);
                $cohortQuality = $this->classifyCohortNzbQuality(
                    $cohortNzbCategories,
                    $permitObservation,
                    time(),
                    $cohortNzbBytes,
                );
                $cursorDelta = max(0, (int) ($permitObservation['backfill_cursor'] ?? 0) - (int) $outcome['cursor']);
                $requestedQuantity = max(0, (int) ($permitObservation['backfill_quantity'] ?? 0));
                $expectedCursorDelta = $this->expectedCursorDelta($permitObservation);
                $contextRepeat = ! $shadow && $permitClaimed && $permitCompleted && $cursorMoved
                    ? $this->store->backfillContextRepeat(time())
                    : null;
                $sameGroupContextRepeat = (string) ($contextRepeat['group'] ?? '') === $observedGroup;
                $attributionGenerationRole = ! $shadow && $permitClaimed && $permitCompleted
                    ? $this->store->backfillDelayedAttributionGenerationRole(
                        $observedGroup,
                        (int) ($permitObservation['generation'] ?? 0),
                    )
                    : null;
                $rootAttributionReplay = $attributionGenerationRole === 'root';
                $continuationAttributionReplay = $attributionGenerationRole === 'continuation';
                $consumingContextRepeat = $sameGroupContextRepeat && $attributionGenerationRole !== 'root';
                $rawContextProgress = $attributionGenerationRole === null
                    && $this->hasRawBackfillOnlyContextProgress(
                        $permitObservation,
                        $outcome,
                        $permitClaimed,
                        $permitCompleted,
                        $cursorDelta,
                        $requestedQuantity,
                        $cohortReleases,
                        $cohortNzbs,
                        $cohortQuality,
                    );
                $contextProgress = $strongContextProgress || $rawContextProgress;
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
                $exactCompletedCohort = $permitClaimed
                    && $permitCompleted
                    && $cursorMoved
                    && $requestedQuantity >= 10_000
                    && $expectedCursorDelta > 0
                    && $cursorDelta === $expectedCursorDelta;
                $pendingContextContinuation = ! $shadow
                    && $attributionGenerationRole === null
                    && $consumingContextRepeat
                    && $this->store->backfillDelayedAttributionCanContinue($observedGroup, time());
                $deferAttribution = $exactCompletedCohort
                    && ($pendingContextContinuation
                        || $rootAttributionReplay
                        || $continuationAttributionReplay
                        || ($cohortQuality['productive'] === 0 && $cohortQuality['failure'] === null));
                if ($deferAttribution && ! $shadow) {
                    $delayedAttributionQueued = $this->store->queueBackfillDelayedAttribution(
                        $permitObservation,
                        $outcome,
                        $cursorDelta,
                        time(),
                        contextContinuation: $pendingContextContinuation || $continuationAttributionReplay,
                    );
                }
                $zeroOutputContextProgress = $cohortReleases === 0
                    && $cohortNzbs === 0;
                $productiveContinuationContextProgress = $pendingContextContinuation
                    && $cohortReleases > 0
                    && $currentCohortNzbs === $cohortReleases
                    && $cohortNzbCategories['target'] === $currentCohortNzbs
                    && $cohortNzbCategories['non_target'] === 0
                    && $cohortNzbCategories['uncategorized'] === 0
                    && $cohortQuality['productive'] > 0
                    && $cohortQuality['failure'] === null;
                $queuedContextProgress = $delayedAttributionQueued
                    && ! $rootAttributionReplay
                    && ! $continuationAttributionReplay
                    && $contextProgress
                    && ($zeroOutputContextProgress || $productiveContinuationContextProgress)
                    && $requestedQuantity === 10_000
                    && $expectedCursorDelta === 10_000
                    && $cursorDelta === 10_000;
                if (! $shadow && $queuedContextProgress) {
                    $this->store->markBackfillContextRepeat(
                        $observedGroup,
                        time(),
                        (int) ($permitObservation['generation'] ?? 0),
                    );
                } elseif (! $shadow
                    && $delayedAttributionQueued
                    && $consumingContextRepeat
                    && $attributionGenerationRole === null
                ) {
                    $this->store->clearBackfillContextRepeat($observedGroup);
                }
                $queuedContextQualityFailure = $delayedAttributionQueued
                    && ($pendingContextContinuation || $rootAttributionReplay || $continuationAttributionReplay)
                    && $cohortQuality['failure'] !== null;
                $closeObservation = $cohortQuality['failure'] !== null
                    || (! $cohortQuality['hold'] && (
                        $observationExpired
                        || ($permitCompleted && $cursorMoved && $cohortQuality['productive'] > 0 && $snapshot->eligibleNzbs === 0)
                        || ($permitCompleted && ! $cursorMoved)
                        || $zeroOutputContextReady
                    ))
                    || ($deferAttribution && $delayedAttributionQueued);
                if ($deferAttribution && ($shadow || ! $delayedAttributionQueued)) {
                    $closeObservation = false;
                }
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
                    $abandonedPermit = ! $shadow
                        && ! $cohortQuality['hold']
                        && $this->backfillRunFinishedAfterPermitIssue($permitObservation);
                    $closeObservation = $abandonedPermit;
                    if (! $shadow && ! $abandonedPermit) {
                        $this->applier->revokePermit();
                    }
                }
                if ($closeObservation && ! $shadow) {
                    $cohortQuality['failure'] === null
                        ? $this->applier->revokePermit()
                        : $this->applier->qualityLockBackfillTarget($observedGroup, $cohortQuality['failure']);
                }
                if ($closeObservation && ! $shadow && ! $abandonedPermit && ! $delayedAttributionQueued && $permitClaimed && $hasCohortBaseline) {
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
                    $isInputBearingClose = $cursorMoved && $cursorDelta > 0;
                    $isExistingContextRepeat = $isInputBearingClose
                        && $consumingContextRepeat;
                    if ($isExistingContextRepeat) {
                        $this->store->clearBackfillContextRepeat($observedGroup);
                    } elseif ($contextProgress
                        && $isInputBearingClose
                        && $cohortReleases === 0
                        && $cohortNzbs === 0
                        && $cohortQuality['productive'] === 0
                        && $cohortQuality['failure'] === null
                        && $requestedQuantity === 10_000
                        && $expectedCursorDelta === 10_000
                        && $cursorDelta === 10_000
                    ) {
                        $this->store->markBackfillContextRepeat(
                            $observedGroup,
                            time(),
                            (int) ($permitObservation['generation'] ?? 0),
                        );
                    }
                }
                if ($closeObservation
                    && ! $shadow
                    && ! $abandonedPermit
                    && (! $delayedAttributionQueued || $queuedContextProgress || $queuedContextQualityFailure)
                ) {
                    $snapshot = $snapshot->withPermitOutcome(
                        completed: true,
                        effective: $permitClaimed && $cursorMoved && ($hasCohortBaseline ? $cohortQuality['productive'] > 0 : $produced),
                        claimed: $permitClaimed,
                        inputMoved: $cursorMoved,
                        contextProgress: $contextProgress,
                        group: $observedGroup,
                        qualityFailure: $cohortQuality['failure'] ?? '',
                        generation: (int) ($permitObservation['generation'] ?? 0),
                    );
                }
                if ($closeObservation && ! $shadow) {
                    $this->store->clearPermitObservation();
                }
            }
            $state = $this->store->loadState();
            $decision = $this->policy->decide($snapshot, $state, time());
            $decision = $this->adaptiveControls->plan(
                $decision,
                $snapshot,
                backfillAttributionPending: $this->store->pendingBackfillDelayedAttributionGroups() !== [],
            );
            $generation = null;
            $autoGrant = ! $shadow
                && (bool) config('nntmux.orchestrator.auto_backfill', false)
                && $decision->backfillPermitted
                && $permitObservation === null
                && $delayedAttributionSettled === null
                && (int) Settings::settingValue('orchestrator_bf_permit') === 0;
            $issuePermit = ($grantPermit || $autoGrant) && $delayedAttributionSettled === null;
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
                && $this->safeToFinishPermitHandoff($snapshot, $decision);
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
                if ($grantCurrentForwardPermit) {
                    if ($permitObservation !== null || $this->store->pendingBackfillDelayedAttributionGroups() !== []) {
                        $currentForward['reason'] = 'backfill_attribution_in_progress';
                    } else {
                        $currentForward = ($this->currentForwardPermits ?? app(CurrentForwardPermitGate::class))
                            ->issue($snapshot, (int) $generation);
                    }
                }
            }
            $this->store->storeState($decision->nextState);
            if ($delayedAttributionSettled !== null) {
                $this->store->completeBackfillDelayedAttribution((int) $delayedAttributionSettled['generation']);
            }
            $this->store->storeSnapshot($snapshot);

            $result = [
                'leader' => true,
                'mode' => $shadow ? 'shadow' : 'active',
                'applied' => ! $shadow,
                'generation' => $generation,
                'profile' => $decision->profile->profile->value,
                'worker_controls' => [
                    'binaries_sleep_seconds' => $decision->profile->binariesSleepSeconds,
                    'backfill_sleep_seconds' => $decision->profile->backfillSleepSeconds,
                    'releases_sleep_seconds' => $decision->profile->releasesSleepSeconds,
                    'nzb_sleep_seconds' => $decision->profile->nzbSleepSeconds,
                    'nzb_batch_size' => $decision->profile->nzbBatchSize,
                ],
                'backfill_permitted' => $decision->backfillPermitted,
                'permit_granted' => ! $shadow && $issuePermit && $decision->backfillPermitted,
                'current_forward' => $currentForward,
                'reasons' => [
                    ...$decision->reasons,
                    ...($preserveUnclaimedPermit ? ['backfill_permit_claim_grace'] : []),
                    ...($abandonedPermit ? ['backfill_permit_abandoned_after_worker_exit'] : []),
                    ...($delayedAttributionQueued ? ['backfill_delayed_attribution_queued'] : []),
                    ...($delayedAttributionSettled === null ? [] : ['backfill_delayed_attribution_settled']),
                    ...($delayedAttributionEarlyQualityLock ? ['backfill_delayed_attribution_early_quality_lock'] : []),
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
                'delayed_attribution' => [
                    'pending_groups' => $this->store->pendingBackfillDelayedAttributionGroups(),
                    'settled_generation' => $delayedAttributionSettled['generation'] ?? null,
                    'settled_group' => $delayedAttributionSettled['group'] ?? null,
                    'settled_result' => $delayedAttributionSettled['result'] ?? null,
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

    /** @return array{PipelineSnapshot, array{generation: int, group: string, result: string}|null} */
    private function lockFailedImmatureDelayedAttribution(PipelineSnapshot $snapshot, int $now): array
    {
        foreach ($this->store->immatureBackfillDelayedAttributions($now) as $entry) {
            $group = trim((string) ($entry['group'] ?? ''));
            $generation = (int) ($entry['generation'] ?? 0);
            $cursorDelta = (int) ($entry['cursor_delta'] ?? 0);
            if ($group === '' || $generation <= 0 || $cursorDelta <= 0) {
                continue;
            }
            $quality = $this->backfillCreatedNzbCategoryQualityForCohort(
                $group,
                (int) ($entry['release_high_watermark'] ?? 0),
                (string) ($entry['cursor_start_postdate'] ?? ''),
                (string) ($entry['cursor_end_postdate'] ?? ''),
            );
            $counts = $quality['counts'];
            $bytes = $quality['bytes'];
            $classifiedNzbs = array_sum($counts);
            $qualityFailure = match (true) {
                $counts['uncategorized'] > 0
                    && $now - (int) ($entry['quality_grace_started_at'] ?? $entry['queued_at'] ?? $now)
                        >= (int) config('nntmux.orchestrator.backfill_incomplete_release_grace_seconds', 600) => 'backfill_permit_uncategorized_after_grace',
                $counts['uncategorized'] === 0
                    && $counts['non_target'] > 0
                    && ! $this->cohortMeetsTargetQuality($counts, $bytes) => 'backfill_permit_wrong_category',
                default => '',
            };
            $productive = 0;
            $createdReleases = 0;
            $fullyDrained = false;
            if ($qualityFailure === ''
                && $counts['target'] > 0
                && $this->cohortMeetsTargetQuality($counts, $bytes)
                && $counts['uncategorized'] === 0
                && $snapshot->telemetryIsValid()
                && $snapshot->hardSafetyPassed()
                && $snapshot->lowPressure
                && ! $snapshot->highPressure
                && $snapshot->databaseCurrentWaits === 0
                && $snapshot->eligibleNzbs === 0
            ) {
                $createdReleases = $this->snapshots->backfillCreatedReleasesForCohort(
                    $group,
                    (int) ($entry['release_high_watermark'] ?? 0),
                    (string) ($entry['cursor_start_postdate'] ?? ''),
                    (string) ($entry['cursor_end_postdate'] ?? ''),
                );
                $allCreatedReleasesAreClassified = $createdReleases === $classifiedNzbs;
                if ($createdReleases > $classifiedNzbs) {
                    $releaseQuality = $this->backfillCreatedReleaseCategoryQualityForCohort(
                        $group,
                        (int) ($entry['release_high_watermark'] ?? 0),
                        (string) ($entry['cursor_start_postdate'] ?? ''),
                        (string) ($entry['cursor_end_postdate'] ?? ''),
                    );
                    $releaseCounts = $releaseQuality['counts'];
                    $releaseBytes = $releaseQuality['bytes'];
                    $allCreatedReleasesAreClassified = array_sum($releaseCounts) === $createdReleases
                        && $releaseCounts['uncategorized'] === 0
                        && $this->cohortMeetsTargetQuality($releaseCounts, $releaseBytes);
                }
                $fullyDrained = $createdReleases >= $classifiedNzbs
                    && $allCreatedReleasesAreClassified
                    && $this->snapshots->backfillPendingCollectionsForCohort(
                        $group,
                        (string) ($entry['cursor_start_postdate'] ?? ''),
                        (string) ($entry['cursor_end_postdate'] ?? ''),
                    ) === 0;
            }
            if ($fullyDrained) {
                $drainStartedAt = $this->store->observeBackfillProductiveDrain(
                    $generation,
                    (int) $counts['target'],
                    $createdReleases,
                    $now,
                );
                if ($drainStartedAt > 0
                    && $now - $drainStartedAt
                        >= (int) config('nntmux.orchestrator.backfill_productive_settlement_grace_seconds', 120)
                ) {
                    $productive = (int) $counts['target'];
                }
            } else {
                $this->store->clearBackfillProductiveDrain($generation);
            }
            if ($qualityFailure === '' && $productive === 0) {
                continue;
            }

            if ($qualityFailure !== '') {
                $this->applier->qualityLockBackfillTarget($group, $qualityFailure);
            }
            $this->store->recordBackfillYield(
                $group,
                cursorDelta: $cursorDelta,
                nzbCreatedDelta: $productive,
                now: $now,
                generation: $generation,
                relatedGenerations: array_values(array_diff(
                    $this->store->backfillDelayedAttributionGenerations($entry),
                    [$generation],
                )),
            );

            return [
                $snapshot->withPermitOutcome(
                    completed: true,
                    effective: $productive > 0,
                    claimed: true,
                    inputMoved: true,
                    group: $group,
                    qualityFailure: $qualityFailure,
                    generation: $generation,
                ),
                [
                    'generation' => $generation,
                    'group' => $group,
                    'result' => $qualityFailure !== '' ? $qualityFailure : 'productive',
                ],
            ];
        }

        return [$snapshot, null];
    }

    /** @return array{PipelineSnapshot, array{generation: int, group: string, result: string}|null} */
    private function settleMatureDelayedAttribution(PipelineSnapshot $snapshot, int $now): array
    {
        $entry = $this->store->matureBackfillDelayedAttribution($now);
        if ($entry === null
            || ! $snapshot->telemetryIsValid()
            || ! $snapshot->hardSafetyPassed()
            || $snapshot->highPressure
            || $snapshot->databaseCurrentWaits > 0
            || $snapshot->eligibleNzbs > 0
        ) {
            return [$snapshot, null];
        }

        $group = trim((string) ($entry['group'] ?? ''));
        $generation = (int) ($entry['generation'] ?? 0);
        $cursorDelta = (int) ($entry['cursor_delta'] ?? 0);
        if ($group === '' || $generation <= 0 || $cursorDelta <= 0) {
            return [$snapshot, null];
        }
        $quality = $this->backfillCreatedNzbCategoryQualityForCohort(
            $group,
            (int) ($entry['release_high_watermark'] ?? 0),
            (string) ($entry['cursor_start_postdate'] ?? ''),
            (string) ($entry['cursor_end_postdate'] ?? ''),
        );
        $counts = $quality['counts'];
        if ($this->snapshots->backfillPendingCollectionsForCohort(
            $group,
            (string) ($entry['cursor_start_postdate'] ?? ''),
            (string) ($entry['cursor_end_postdate'] ?? ''),
        ) > 0) {
            return [$snapshot, null];
        }
        $bytes = $quality['bytes'];
        $qualityFailure = match (true) {
            $counts['uncategorized'] > 0 => 'backfill_permit_uncategorized_after_grace',
            $counts['non_target'] > 0 && ! $this->cohortMeetsTargetQuality($counts, $bytes) => 'backfill_permit_wrong_category',
            default => '',
        };
        $productive = $qualityFailure === '' ? max(0, (int) $counts['target']) : 0;
        if ($qualityFailure !== '') {
            $this->applier->qualityLockBackfillTarget($group, $qualityFailure);
        }
        $this->store->recordBackfillYield(
            $group,
            cursorDelta: $cursorDelta,
            nzbCreatedDelta: $productive,
            now: $now,
            generation: $generation,
            relatedGenerations: array_values(array_diff(
                $this->store->backfillDelayedAttributionGenerations($entry),
                [$generation],
            )),
        );

        return [
            $snapshot->withPermitOutcome(
                completed: true,
                effective: $productive > 0,
                claimed: true,
                inputMoved: true,
                group: $group,
                qualityFailure: $qualityFailure,
                generation: $generation,
            ),
            [
                'generation' => $generation,
                'group' => $group,
                'result' => $qualityFailure !== '' ? $qualityFailure : ($productive > 0 ? 'productive' : 'zero_output'),
            ],
        ];
    }

    /** @param array<string, mixed> $permitObservation */
    private function backfillRunFinishedAfterPermitIssue(array $permitObservation): bool
    {
        $telemetry = $this->workerTelemetry->snapshot(['backfill']);
        if (($telemetry['available'] ?? false) !== true) {
            return false;
        }

        $worker = $telemetry['workers']['backfill'] ?? null;
        if (! is_array($worker) || ($worker['in_progress'] ?? true) === true) {
            return false;
        }

        $issuedAt = (float) ($permitObservation['issued_at'] ?? 0);
        $startedAt = (float) ($worker['last_started_timestamp_seconds'] ?? 0);
        $completedAt = (float) ($worker['last_completed_timestamp_seconds'] ?? 0);
        $lastSuccessAt = (float) ($worker['last_success_timestamp_seconds'] ?? 0);

        return $issuedAt > 0
            && $startedAt >= $issuedAt
            && $completedAt >= $startedAt
            && $lastSuccessAt < $startedAt;
    }

    private function safeToFinishPermitHandoff(PipelineSnapshot $snapshot, ControlDecision $decision): bool
    {
        return $snapshot->telemetryIsValid()
            && $snapshot->hardSafetyPassed()
            && $decision->profile->backfillEnabled
            && ! $snapshot->highPressure
            && $snapshot->databaseCurrentWaits === 0
            && $snapshot->backfillPermitHandoffSafe
            && $snapshot->currentGroupsAvailable
            && array_intersect(self::CLAIM_GRACE_SUPPLY_REASONS, $decision->reasons) !== [];
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
        $expectedCursorDelta = $this->expectedCursorDelta($observation);

        return $permitCompleted
            && $cursorMoved
            && $quantity >= 10_000
            && $expectedCursorDelta > 0
            && $cursorDelta === $expectedCursorDelta
            && $cohortReleases > 0
            && $cohortNzbs === 0;
    }

    /**
     * @param  array{target: int, non_target: int, uncategorized: int}  $counts
     * @param  array<string, mixed>  $observation
     * @param  array{target: int, non_target: int, uncategorized: int}  $bytes
     * @return array{productive: int, hold: bool, failure: string|null}
     */
    private function classifyCohortNzbQuality(
        array $counts,
        array $observation,
        int $now,
        array $bytes = ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
    ): array {
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

        if ($counts['non_target'] > 0 && ! $this->cohortMeetsTargetQuality($counts, $bytes)) {
            return [
                'productive' => 0,
                'hold' => false,
                'failure' => 'backfill_permit_wrong_category',
            ];
        }

        return [
            'productive' => max(0, $counts['target']),
            'hold' => false,
            'failure' => null,
        ];
    }

    /**
     * @return array{
     *     counts: array{target: int, non_target: int, uncategorized: int},
     *     bytes: array{target: int, non_target: int, uncategorized: int}
     * }
     */
    private function backfillCreatedNzbCategoryQualityForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): array {
        if ($this->mixedCohortToleranceEnabled()) {
            return $this->snapshots->backfillCreatedNzbCategoryQualityForCohort(
                $group,
                $releaseHighWatermark,
                $startPostdate,
                $endPostdate,
            );
        }

        return $this->strictBackfillCategoryQuality(
            $this->snapshots->backfillCreatedNzbCategoryCountsForCohort(
                $group,
                $releaseHighWatermark,
                $startPostdate,
                $endPostdate,
            ),
        );
    }

    /**
     * @return array{
     *     counts: array{target: int, non_target: int, uncategorized: int},
     *     bytes: array{target: int, non_target: int, uncategorized: int}
     * }
     */
    private function backfillCreatedReleaseCategoryQualityForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): array {
        if ($this->mixedCohortToleranceEnabled()) {
            return $this->snapshots->backfillCreatedReleaseCategoryQualityForCohort(
                $group,
                $releaseHighWatermark,
                $startPostdate,
                $endPostdate,
            );
        }

        return $this->strictBackfillCategoryQuality(
            $this->snapshots->backfillCreatedReleaseCategoryCountsForCohort(
                $group,
                $releaseHighWatermark,
                $startPostdate,
                $endPostdate,
            ),
        );
    }

    /**
     * @return array{
     *     counts: array{target: int, non_target: int, uncategorized: int},
     *     bytes: array{target: int, non_target: int, uncategorized: int}
     * }
     */
    private function backfillCompletedNzbCategoryQualityForReleaseCohort(
        string $group,
        int $releaseIdLowExclusive,
        int $releaseIdHighInclusive,
        string $startPostdate,
        string $endPostdate,
    ): array {
        if ($this->mixedCohortToleranceEnabled()) {
            return $this->snapshots->backfillCompletedNzbCategoryQualityForReleaseCohort(
                $group,
                $releaseIdLowExclusive,
                $releaseIdHighInclusive,
                $startPostdate,
                $endPostdate,
            );
        }

        return $this->strictBackfillCategoryQuality(
            $this->snapshots->backfillCompletedNzbCategoryCountsForReleaseCohort(
                $group,
                $releaseIdLowExclusive,
                $releaseIdHighInclusive,
                $startPostdate,
                $endPostdate,
            ),
        );
    }

    private function mixedCohortToleranceEnabled(): bool
    {
        return (int) config('nntmux.orchestrator.backfill_max_non_target_releases', 0) > 0
            && (int) config('nntmux.orchestrator.backfill_max_non_target_bytes', 0) > 0;
    }

    /**
     * @param  array{target: int, non_target: int, uncategorized: int}  $counts
     * @return array{
     *     counts: array{target: int, non_target: int, uncategorized: int},
     *     bytes: array{target: int, non_target: int, uncategorized: int}
     * }
     */
    private function strictBackfillCategoryQuality(array $counts): array
    {
        return [
            'counts' => $counts,
            'bytes' => ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
        ];
    }

    /**
     * @param  array{target: int, non_target: int, uncategorized: int}  $counts
     * @param  array{target: int, non_target: int, uncategorized: int}  $bytes
     */
    private function cohortMeetsTargetQuality(array $counts, array $bytes): bool
    {
        $targetCount = max(0, (int) $counts['target']);
        $nonTargetCount = max(0, (int) $counts['non_target']);
        if ($targetCount === 0 || $counts['uncategorized'] > 0) {
            return false;
        }

        $targetBytes = max(0, (int) $bytes['target']);
        $nonTargetBytes = max(0, (int) $bytes['non_target']);
        $uncategorizedBytes = max(0, (int) $bytes['uncategorized']);
        if ($this->mixedCohortToleranceEnabled()
            && ($targetBytes === 0
                || ($nonTargetCount > 0) !== ($nonTargetBytes > 0)
                || ($counts['uncategorized'] > 0) !== ($uncategorizedBytes > 0))
        ) {
            return false;
        }
        if ($nonTargetCount === 0) {
            return true;
        }

        $classifiedBytes = $targetBytes + $nonTargetBytes;

        return $nonTargetCount <= (int) config('nntmux.orchestrator.backfill_max_non_target_releases', 0)
            && $nonTargetBytes <= (int) config('nntmux.orchestrator.backfill_max_non_target_bytes', 0)
            && $targetBytes > 0
            && $classifiedBytes > 0
            && ($targetBytes / $classifiedBytes)
                >= (float) config('nntmux.orchestrator.backfill_min_target_byte_share', 1.0);
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
        $expectedCursorDelta = $this->expectedCursorDelta($observation);

        return $permitClaimed
            && $permitCompleted
            && $completedAt > 0
            && $now - $completedAt >= $graceSeconds
            && $quantity >= 10_000
            && $expectedCursorDelta > 0
            && $cursorDelta === $expectedCursorDelta
            && $cohortNzbs === 0
            && (int) ($outcome['ready_collections'] ?? 0) <= (int) ($observation['ready_collections'] ?? 0)
            && $snapshot->eligibleNzbs === 0
            && $snapshot->telemetryIsValid()
            && $snapshot->hardSafetyPassed()
            && ! $snapshot->highPressure
            && $snapshot->databaseCurrentWaits === 0;
    }

    /** @param array<string, mixed> $observation */
    private function expectedCursorDelta(array $observation): int
    {
        return max(0, array_key_exists('backfill_expected_cursor_delta', $observation)
            ? (int) $observation['backfill_expected_cursor_delta']
            : (int) ($observation['backfill_quantity'] ?? 0));
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

    /**
     * @param  array<string, mixed>  $observation
     * @param  array<string, mixed>  $outcome
     * @param  array{productive: int, hold: bool, failure: string|null}  $quality
     */
    private function hasRawBackfillOnlyContextProgress(
        array $observation,
        array $outcome,
        bool $permitClaimed,
        bool $permitCompleted,
        int $cursorDelta,
        int $requestedQuantity,
        int $cohortReleases,
        int $cohortNzbs,
        array $quality,
    ): bool {
        return $permitClaimed
            && $permitCompleted
            && array_key_exists('raw_collections', $observation)
            && array_key_exists('raw_binaries', $observation)
            && array_key_exists('raw_collections', $outcome)
            && array_key_exists('raw_binaries', $outcome)
            && (int) ($observation['backfill_group_active'] ?? 1) === 0
            && (int) ($outcome['group_active'] ?? 1) === 0
            && $requestedQuantity === 10_000
            && $cursorDelta === 10_000
            && (int) ($outcome['raw_collections'] ?? 0) >= (int) ($observation['raw_collections'] ?? 0)
            && (int) ($outcome['raw_binaries'] ?? 0) >= (int) ($observation['raw_binaries'] ?? 0)
            && ((int) ($outcome['raw_collections'] ?? 0) > (int) ($observation['raw_collections'] ?? 0)
                || (int) ($outcome['raw_binaries'] ?? 0) > (int) ($observation['raw_binaries'] ?? 0))
            && $cohortReleases === 0
            && $cohortNzbs === 0
            && $quality['productive'] === 0
            && $quality['failure'] === null;
    }
}
