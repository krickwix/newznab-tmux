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
        $lock = $this->store->leaderLock();
        if (! $lock->get()) {
            return ['leader' => false, 'applied' => false, 'reason' => 'leader_lock_contended'];
        }

        try {
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
                $ingested = $snapshot->partsBacklog > $permitObservation['parts']
                    || $snapshot->binariesBacklog > $permitObservation['binaries'];
                $produced = $snapshot->readyCollections > $permitObservation['ready_collections']
                    || $snapshot->releaseTotal > $permitObservation['release_total'];
                $snapshot = $snapshot->withPermitOutcome(true, $permitConsumed && $ingested && $produced);
                $this->store->clearPermitObservation();
            }
            $state = $this->store->loadState();
            $decision = $this->policy->decide($snapshot, $state, time());
            $generation = null;
            $autoGrant = ! $shadow
                && $decision->backfillPermitted
                && $permitObservation === null
                && (int) Settings::settingValue('orchestrator_backfill_permit') === 0;
            $issuePermit = $grantPermit || $autoGrant;
            if (! $shadow) {
                $generation = $this->applier->apply($decision, time(), $issuePermit);
                if ($issuePermit && $decision->backfillPermitted) {
                    $this->store->beginPermitObservation($snapshot, $generation, time());
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
            ];
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
            $lock->release();
        }
    }
}
