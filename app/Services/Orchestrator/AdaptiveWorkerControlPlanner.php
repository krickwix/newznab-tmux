<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

/**
 * Converts the global pressure profile into stage-specific controls.
 *
 * The global profile remains the safety envelope. Header ingestion is never
 * accelerated beyond that envelope, while downstream consumers may drain
 * faster when their input is old or accumulating. Values are intentionally
 * quantized so small telemetry noise cannot churn worker sleep intervals.
 */
final class AdaptiveWorkerControlPlanner
{
    public function plan(ControlDecision $decision, PipelineSnapshot $snapshot): ControlDecision
    {
        $base = $decision->profile;
        if ($base->profile === ControlProfile::FailSafe) {
            return $decision;
        }

        $partsPressure = $this->pressure('parts', $snapshot->partsBacklog, $snapshot);
        $binariesPressure = $this->pressure('binaries', $snapshot->binariesBacklog, $snapshot);
        $collectionsPressure = max(
            $this->pressure('collections', $snapshot->collectionsBacklog, $snapshot),
            $this->pressure('collections_total', $snapshot->physicalCollectionsBacklog(), $snapshot),
        );
        $downstreamPressure = max($binariesPressure, $collectionsPressure);
        $inputPressure = max($partsPressure, $downstreamPressure);

        $binariesSleep = match (true) {
            $inputPressure >= 0.85 => max($base->binariesSleepSeconds, 160),
            $inputPressure >= 0.70 => max($base->binariesSleepSeconds, 60),
            $inputPressure >= 0.30 => max($base->binariesSleepSeconds, 40),
            default => $base->binariesSleepSeconds,
        };

        $releaseDemand = $snapshot->readyCollections > 0
            || $snapshot->releasesBacklog > 0
            || $downstreamPressure >= 0.25
            || $this->ageSloReached('binaries', $snapshot->oldestBinaryAgeSeconds)
            || $this->ageSloReached('collections', $snapshot->oldestCollectionAgeSeconds);
        $releasesSleep = $releaseDemand ? 20 : max($base->releasesSleepSeconds, 60);

        $nzbDemand = $snapshot->eligibleNzbs > 0;
        $nzbSleep = $nzbDemand ? 20 : max($base->nzbSleepSeconds, 60);
        $nzbBatchSize = $nzbDemand ? 20 : 5;
        $backfillSleep = $decision->backfillPermitted ? 20 : max(60, min(1800, $base->backfillSleepSeconds));

        $profile = new WorkerControlProfile(
            profile: $base->profile,
            binariesSleepSeconds: $binariesSleep,
            backfillSleepSeconds: $backfillSleep,
            releasesSleepSeconds: $releasesSleep,
            nzbSleepSeconds: $nzbSleep,
            nzbBatchSize: $nzbBatchSize,
            backfillEnabled: $base->backfillEnabled,
            backfillGroups: $base->backfillGroups,
            backfillThreads: $base->backfillThreads,
            backfillQuantity: $base->backfillQuantity,
        );
        $reasons = $decision->reasons;
        if ($inputPressure >= 0.70) {
            $reasons[] = 'adaptive_binaries_input_guard';
        } elseif ($inputPressure >= 0.30) {
            $reasons[] = 'adaptive_binaries_steady';
        } else {
            $reasons[] = 'adaptive_binaries_fill';
        }
        $reasons[] = $releaseDemand ? 'adaptive_releases_drain' : 'adaptive_releases_idle';
        $reasons[] = $nzbDemand ? 'adaptive_nzb_drain' : 'adaptive_nzb_idle';
        $reasons[] = $decision->backfillPermitted ? 'adaptive_backfill_ready' : 'adaptive_backfill_idle';

        return new ControlDecision(
            profile: $profile,
            backfillPermitted: $decision->backfillPermitted,
            reasons: $reasons,
            nextState: $decision->nextState,
            transitioned: $decision->transitioned,
        );
    }

    private function pressure(string $stage, int $backlog, PipelineSnapshot $snapshot): float
    {
        $limit = max(1, (int) config('nntmux.orchestrator.high_watermarks.'.$stage, PHP_INT_MAX));
        $growth = max(0.0, (float) ($snapshot->backlogEwmaPerMinute[$stage] ?? 0.0));
        $horizon = max(1, (int) config('nntmux.orchestrator.pressure_projection_horizon_minutes', 120));

        return max(0.0, ($backlog + ($growth * $horizon)) / $limit);
    }

    private function ageSloReached(string $stage, int $age): bool
    {
        $slo = max(1, (int) config('nntmux.orchestrator.age_slo_seconds.'.$stage, PHP_INT_MAX));

        return $age >= $slo;
    }
}
