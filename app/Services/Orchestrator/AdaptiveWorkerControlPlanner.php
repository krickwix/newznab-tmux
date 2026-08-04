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
    public function plan(
        ControlDecision $decision,
        PipelineSnapshot $snapshot,
        bool $backfillAttributionPending = false,
    ): ControlDecision {
        $base = $decision->profile;
        // FailSafe is authoritative because nothing may accelerate past it.
        // FreeRun is authoritative for the mirror-image reason: every sleep
        // below is a `max($base->..., floor)`, so an operator's zeros would be
        // floored straight back up -- releases and nzb to 60s, backfill to 10s
        // -- and free-run would report profile=free_run while the workers kept
        // sleeping. Adaptive modulation is precisely what this mode exists to
        // switch off.
        if ($base->profile === ControlProfile::FailSafe || $base->profile === ControlProfile::FreeRun) {
            return $decision;
        }

        $partsPressure = $this->pressure('parts', $snapshot->schedulablePartsBacklog(), $snapshot);
        $binariesPressure = $this->pressure('binaries', $snapshot->schedulableBinariesBacklog(), $snapshot);
        $collectionsPressure = $this->pressure('collections', $snapshot->schedulableCollectionsBacklog(), $snapshot);
        if ($snapshot->schedulableCollectionsBacklog === null) {
            $collectionsPressure = max(
                $collectionsPressure,
                $this->pressure('collections_total', $snapshot->physicalCollectionsBacklog(), $snapshot),
            );
        }
        $downstreamPressure = max($binariesPressure, $collectionsPressure);
        $inputPressure = max($partsPressure, $downstreamPressure);

        $binariesSleep = match (true) {
            $inputPressure >= 0.85 => max($base->binariesSleepSeconds, 160),
            $inputPressure >= 0.70 => max($base->binariesSleepSeconds, 60),
            $inputPressure >= 0.30 => max($base->binariesSleepSeconds, 40),
            default => $base->binariesSleepSeconds,
        };
        if ($decision->nextState->qualifiedSupplyStarved) {
            $binariesSleep = max(
                $binariesSleep,
                (int) config('nntmux.orchestrator.qualified_supply_binaries_sleep_seconds', 300),
            );
        }

        $releaseDemand = $snapshot->readyCollections > 0
            || $snapshot->releasesBacklog > 0
            || $downstreamPressure >= 0.25
            || $this->ageSloReached('binaries', $snapshot->oldestBinaryAgeSeconds)
            || $this->ageSloReached('collections', $snapshot->oldestCollectionAgeSeconds);
        // Release discovery is a bounded downstream drain: shorten only the
        // polling gap when work is actionable; the worker's batch cap and the
        // global pressure envelope still bound database load.
        $releasesSleep = $releaseDemand ? 10 : max($base->releasesSleepSeconds, 60);

        $nzbDemand = $snapshot->eligibleNzbs > 0;
        $nzbSleep = $nzbDemand ? 20 : max($base->nzbSleepSeconds, 60);
        $nzbBatchSize = $nzbDemand ? 20 : 5;
        $backfillPollable = $decision->backfillPermitted || $backfillAttributionPending;
        // A permit or pending attribution is already pressure-qualified by the
        // orchestrator. Poll promptly so a granted window is not stranded,
        // without relaxing the quantity, concurrency, or pressure gates.
        $backfillSleep = $backfillPollable ? 10 : max(60, min(1800, $base->backfillSleepSeconds));

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
        if ($decision->nextState->qualifiedSupplyStarved) {
            $reasons[] = 'qualified_supply_growth_throttle';
        }
        $reasons[] = $releaseDemand ? 'adaptive_releases_drain' : 'adaptive_releases_idle';
        $reasons[] = $nzbDemand ? 'adaptive_nzb_drain' : 'adaptive_nzb_idle';
        $reasons[] = match (true) {
            $decision->backfillPermitted => 'adaptive_backfill_ready',
            $backfillAttributionPending => 'adaptive_backfill_attribution',
            default => 'adaptive_backfill_idle',
        };

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
