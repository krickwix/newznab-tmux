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
                    ? ['cursor' => 0, 'cursor_postdate' => '', 'ready_collections' => 0, 'releases' => 0, 'release_high_watermark' => 0]
                    : $this->snapshots->backfillOutcomeForGroup($observedGroup);
                $cursorMoved = $outcome['cursor'] > 0
                    && $outcome['cursor'] < (int) ($permitObservation['backfill_cursor'] ?? 0);
                $produced = $outcome['ready_collections'] > (int) ($permitObservation['ready_collections'] ?? 0)
                    || $outcome['releases'] > (int) ($permitObservation['release_total'] ?? 0);
                $cohortNzbs = 0;
                $cohortReleases = 0;
                if ($permitClaimed && $hasCohortBaseline && ($observationExpired || $cursorMoved)) {
                    $cohortReleases = $this->snapshots->backfillCreatedReleasesForCohort(
                        $observedGroup,
                        (int) $permitObservation['release_high_watermark'],
                        (string) $permitObservation['backfill_cursor_postdate'],
                        (string) ($outcome['cursor_postdate'] ?? ''),
                    );
                    $cohortNzbs = $this->snapshots->backfillCreatedNzbsForCohort(
                        $observedGroup,
                        (int) $permitObservation['release_high_watermark'],
                        (string) $permitObservation['backfill_cursor_postdate'],
                        (string) ($outcome['cursor_postdate'] ?? ''),
                    );
                }
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
                $closeObservation = $observationExpired
                    || ($permitCompleted && $cursorMoved && $cohortNzbs > 0 && $snapshot->eligibleNzbs === 0)
                    || ($permitCompleted && ! $cursorMoved)
                    || $zeroOutputContextReady;
                if ($observationExpired && $permitClaimed && $hasCohortBaseline && ! $permitCompleted) {
                    $closeObservation = false;
                    if (! $shadow) {
                        $this->applier->revokePermit();
                    }
                }
                if ($closeObservation && ! $shadow) {
                    $this->applier->revokePermit();
                }
                if ($closeObservation && $permitClaimed && $hasCohortBaseline) {
                    $this->store->recordBackfillYield(
                        $observedGroup,
                        cursorDelta: $cursorDelta,
                        nzbCreatedDelta: $cohortNzbs,
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
                }
                if ($closeObservation) {
                    $snapshot = $snapshot->withPermitOutcome(
                        completed: true,
                        effective: $permitClaimed && $cursorMoved && ($hasCohortBaseline ? $cohortNzbs > 0 : $produced),
                        claimed: $permitClaimed,
                        inputMoved: $cursorMoved,
                        group: $observedGroup,
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
                'reasons' => $preserveUnclaimedPermit
                    ? [...$decision->reasons, 'backfill_permit_claim_grace']
                    : $decision->reasons,
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
            && $snapshot->backfillGatesPassed();
    }
}
