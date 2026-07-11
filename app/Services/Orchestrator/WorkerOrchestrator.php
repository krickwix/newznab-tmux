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
            if ($grantPermit && $permitObservation !== null) {
                return [
                    'leader' => true,
                    'applied' => false,
                    'reason' => 'backfill_permit_observation_in_progress',
                ];
            }
            if ($permitObservation !== null && time() - $permitObservation['issued_at'] >= 900) {
                $permitConsumed = (int) Settings::settingValue('orchestrator_backfill_permit') === 0;
                if (! $shadow) {
                    $this->applier->revokePermit();
                }
                $observedGroup = (string) ($permitObservation['backfill_group'] ?? '');
                $outcome = $observedGroup === ''
                    ? ['cursor' => 0, 'ready_collections' => 0, 'releases' => 0]
                    : $this->snapshots->backfillOutcomeForGroup($observedGroup);
                $cursorMoved = $outcome['cursor'] > 0
                    && $outcome['cursor'] < (int) ($permitObservation['backfill_cursor'] ?? 0);
                $produced = $outcome['ready_collections'] > (int) ($permitObservation['ready_collections'] ?? 0)
                    || $outcome['releases'] > (int) ($permitObservation['release_total'] ?? 0);
                $snapshot = $snapshot->withPermitOutcome(true, $permitConsumed && $cursorMoved && $produced);
                $this->store->clearPermitObservation();
            }
            $state = $this->store->loadState();
            $decision = $this->policy->decide($snapshot, $state, time());
            $generation = null;
            $autoGrant = ! $shadow
                && (bool) config('nntmux.orchestrator.auto_backfill', false)
                && $decision->backfillPermitted
                && $permitObservation === null
                && (int) Settings::settingValue('orchestrator_backfill_permit') === 0;
            $issuePermit = $grantPermit || $autoGrant;
            if (! $shadow) {
                $generation = $this->applier->apply($decision, time(), $issuePermit, $snapshot->backfillGroup);
                if ($issuePermit && $decision->backfillPermitted) {
                    $this->store->beginPermitObservation(
                        $snapshot,
                        $generation,
                        time(),
                        $this->snapshots->backfillOutcomeForGroup($snapshot->backfillGroup),
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
                'reasons' => $decision->reasons,
                'backlogs' => [
                    'parts' => $snapshot->partsBacklog,
                    'binaries' => $snapshot->binariesBacklog,
                    'collections' => $snapshot->collectionsBacklog,
                    'releases' => $snapshot->releasesBacklog,
                    'nzbs' => $snapshot->nzbsBacklog,
                ],
                'storage_available_bytes' => $snapshot->storageAvailableBytes,
                'observed_at' => $snapshot->observedAt,
                'eligible_nzbs' => $snapshot->eligibleNzbs,
                'pressure' => $snapshot->highPressure ? 'high' : ($snapshot->lowPressure ? 'low' : 'neutral'),
                'rates_per_minute' => $snapshot->backlogRatesPerMinute,
                'ewma_per_minute' => $snapshot->backlogEwmaPerMinute,
                'oldest_age_seconds' => [
                    'binaries' => $snapshot->oldestBinaryAgeSeconds,
                    'collections' => $snapshot->oldestCollectionAgeSeconds,
                    'releases' => $snapshot->oldestReleaseAgeSeconds,
                    'nzbs' => $snapshot->oldestNzbAgeSeconds,
                ],
                'backfill_target' => [
                    'group' => $snapshot->backfillGroup,
                    'cursor' => $snapshot->backfillCursor,
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
}
