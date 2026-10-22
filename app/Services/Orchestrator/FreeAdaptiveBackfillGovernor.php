<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Throwable;

/**
 * Adapts the free_adaptive delay between backfill permits to pipeline pressure.
 *
 * The lever is permit ISSUANCE: the backfill worker runs only while a fresh
 * permit exists, so orchestrator_back_timer is never used for the delay.
 * The orchestrator grants a permit only once `last_done_at + delay` has passed.
 *
 * Control law, AIMD-like and asymmetric on purpose (back off fast, recover
 * gently): the projected utilisation of the busiest stage,
 * (backlog + ewma * horizon) / high watermark, grows the delay at or above the
 * grow threshold and shrinks it at or below the shrink threshold; in between it
 * holds, so the watermark itself cannot flap. A permit's effect on the backlog
 * lags by minutes, so the delay changes at most once per settle window.
 *
 * Bad data fails long: stale or missing metrics lengthen the delay and never
 * shorten it. The profile still bypasses the safety gates, so this is the only
 * brake on backfill.
 */
class FreeAdaptiveBackfillGovernor
{
    public function __construct(
        private readonly FreeAdaptiveStateStore $store = new FreeAdaptiveStateStore,
    ) {}

    /**
     * One orchestrator cycle: evaluate, persist, return the state to gate on.
     * Shadow mode passes $persist = false: it evaluates but writes nothing.
     * Never throws; on any failure it returns a maximum-delay closed gate.
     */
    public function tick(PipelineSnapshot $snapshot, bool $entering, int $now, bool $persist = true): FreeAdaptiveState
    {
        $config = FreeAdaptiveConfig::fromConfig();
        try {
            $state = $this->evaluate($this->store->read(), $snapshot, $now, $entering, $config);
            if ($persist) {
                $this->store->write($state);
            }

            return $state;
        } catch (Throwable $e) {
            report($e);

            return new FreeAdaptiveState($config->maxDelay, $now, $now, $now, 'governor_error');
        }
    }

    public function evaluate(
        FreeAdaptiveState $prev,
        PipelineSnapshot $snapshot,
        int $now,
        bool $entering,
        ?FreeAdaptiveConfig $config = null,
    ): FreeAdaptiveState {
        $config ??= FreeAdaptiveConfig::fromConfig();
        $discard = $prev->delay < 0
            || $entering
            || $prev->checkedAt < $now - 2 * $config->maxDelay;
        if ($discard) {
            [$projected, $stage, $ewma] = $this->projection($snapshot, $config);

            return new FreeAdaptiveState($config->initialDelay, $now, $now, $prev->lastDoneAt, 'entered', $stage, $projected, $ewma, 0.0, 0, $now);
        }

        $delay = min($config->maxDelay, max($config->minDelay, $prev->delay));
        [$projected, $stage, $ewma, $missing] = $this->projection($snapshot, $config);
        $stale = ! $snapshot->telemetryFresh || $snapshot->observedAt < $now - $config->staleSeconds;
        $bad = $stale ? 'metrics_stale' : ($stage === '' ? 'metrics_missing' : null);
        // Only fresh samples feed the window mean: a stale tick has no
        // trustworthy projection and must not drag the average either way.
        $sum = $prev->windowSum + ($bad === null ? $projected : 0.0);
        $count = $prev->windowSamples + ($bad === null ? 1 : 0);
        $start = $prev->windowStart;
        $anchor = max($prev->updatedAt, $start);
        $next = $delay;
        $changedAt = $prev->updatedAt;
        $reason = 'settling';

        if ($now - $anchor >= $config->settleSeconds) {
            [$direction, $reason] = $this->decide($bad, $count > 0 ? $sum / $count : 0.0, $count, $config);
            if ($direction !== 0) {
                $next = $this->step($delay, $direction, $config);
                $changedAt = $next === $delay ? $changedAt : $now;
            }
            // A decision (change, clamp or hold) closes the window; a window
            // too short to decide on keeps collecting.
            if ($reason !== 'insufficient_samples') {
                [$sum, $count, $start] = [0.0, 0, $now];
            }
            if ($missing !== [] && $stage !== '') {
                $reason .= ' stage_missing:'.implode(',', $missing);
            }
        }

        return new FreeAdaptiveState(
            $next, $changedAt, $now, $prev->lastDoneAt, $reason, $stage, $projected, $ewma, $sum, $count, $start,
        );
    }

    /**
     * Direction (+1 grow, -1 shrink, 0 hold) and reason for a closed window.
     * A window whose latest sample is stale or missing grows, whatever its mean.
     *
     * @return array{0: int, 1: string}
     */
    private function decide(?string $bad, float $mean, int $count, FreeAdaptiveConfig $config): array
    {
        if ($bad !== null) {
            return [1, $bad];
        }
        if ($count < $config->minSamples) {
            return [0, 'insufficient_samples'];
        }

        return match (true) {
            $mean >= $config->growThreshold => [1, 'increase'],
            $mean <= $config->shrinkThreshold => [-1, 'decrease'],
            default => [0, 'hold'],
        };
    }

    private function step(int $delay, int $direction, FreeAdaptiveConfig $config): int
    {
        if ($direction > 0) {
            $next = max((int) round($delay * $config->increaseFactor), $delay + $config->increaseStep);
        } else {
            $next = (int) floor($delay * $config->decreaseFactor);
            if ($next < $config->snapToMinBelow) {
                $next = $config->minDelay;
            }
        }

        return min($config->maxDelay, max($config->minDelay, $next));
    }

    /**
     * Busiest configured stage as [projected utilisation, stage, ewma, skipped stages].
     * A stage without an ewma sample or a watermark is skipped, not read as zero.
     *
     * @return array{0: float, 1: string, 2: float, 3: list<string>}
     */
    private function projection(PipelineSnapshot $snapshot, FreeAdaptiveConfig $config): array
    {
        $best = [0.0, '', 0.0, []];
        foreach ($config->stages as $stage) {
            $high = $config->highWatermarks[$stage] ?? 0;
            if ($high <= 0 || ! array_key_exists($stage, $snapshot->backlogEwmaPerMinute)) {
                $best[3][] = $stage;

                continue;
            }
            $ewma = (float) $snapshot->backlogEwmaPerMinute[$stage];
            $backlog = match ($stage) {
                'parts' => $snapshot->schedulablePartsBacklog(),
                'binaries' => $snapshot->schedulableBinariesBacklog(),
                default => $snapshot->schedulableCollectionsBacklog(),
            };
            $p = max(0.0, ($backlog + $ewma * $config->horizonMinutes) / $high);
            if ($best[1] === '' || $p > $best[0]) {
                $best = [$p, $stage, $ewma, $best[3]];
            }
        }

        return $best;
    }
}
