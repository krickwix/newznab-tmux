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
     * Never throws; on any failure it returns a maximum-delay closed gate.
     */
    public function tick(PipelineSnapshot $snapshot, bool $entering, int $now): FreeAdaptiveState
    {
        $config = FreeAdaptiveConfig::fromConfig();
        try {
            $state = $this->evaluate($this->store->read(), $snapshot, $now, $entering, $config);
            $this->store->write($state);

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
            return $this->state($prev, $config->initialDelay, $now, $now, 'entered', $snapshot, $config);
        }

        $delay = min($config->maxDelay, max($config->minDelay, $prev->delay));
        [$projected, $stage, $ewma, $missing] = $this->projection($snapshot, $config);
        $stale = ! $snapshot->telemetryFresh || $snapshot->observedAt < $now - $config->staleSeconds;
        $reason = $stale ? 'metrics_stale' : ($stage === '' ? 'metrics_missing' : null);
        $direction = $reason !== null || $projected >= $config->growThreshold
            ? 1
            : ($projected <= $config->shrinkThreshold ? -1 : 0);
        $reason ??= match ($direction) {
            1 => 'increase',
            -1 => 'decrease',
            default => 'hold',
        };
        if ($missing !== [] && $stage !== '') {
            $reason .= ' stage_missing:'.implode(',', $missing);
        }
        if ($direction !== 0 && $now - $prev->updatedAt < $config->settleSeconds) {
            $direction = 0;
            $reason = 'settling';
        }

        $next = $direction === 0 ? $delay : $this->step($delay, $direction, $config);
        $changedAt = $next === $delay ? $prev->updatedAt : $now;

        return $this->state($prev, $next, $changedAt, $now, $reason, $snapshot, $config, [$projected, $stage, $ewma]);
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

    /** @param  array{0: float, 1: string, 2: float}|null  $projection */
    private function state(
        FreeAdaptiveState $prev,
        int $delay,
        int $updatedAt,
        int $checkedAt,
        string $reason,
        PipelineSnapshot $snapshot,
        FreeAdaptiveConfig $config,
        ?array $projection = null,
    ): FreeAdaptiveState {
        [$projected, $stage, $ewma] = $projection ?? $this->projection($snapshot, $config);

        return new FreeAdaptiveState($delay, $updatedAt, $checkedAt, $prev->lastDoneAt, $reason, $stage, $projected, $ewma);
    }
}
