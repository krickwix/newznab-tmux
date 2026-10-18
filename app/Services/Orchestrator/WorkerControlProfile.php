<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Container\Container;

final readonly class WorkerControlProfile
{
    public function __construct(
        public ControlProfile $profile,
        public int $binariesSleepSeconds,
        public int $backfillSleepSeconds,
        public int $releasesSleepSeconds,
        public int $nzbSleepSeconds,
        public int $nzbBatchSize,
        public bool $backfillEnabled,
        public int $backfillGroups,
        public int $backfillThreads,
        public int $backfillQuantity,
    ) {}

    public static function for(ControlProfile $profile): self
    {
        $container = \class_exists(Container::class)
            ? Container::getInstance()
            : null;
        $configBound = $container !== null && $container->bound('config');
        $fillGroups = $configBound
            ? max(1, (int) config('nntmux.orchestrator.backfill_fill_groups', 1))
            : 1;
        $fillThreads = $configBound
            ? max(1, (int) config('nntmux.orchestrator.backfill_fill_threads', 1))
            : 1;
        // Per-round backfill download quantity (header count per permit). Must be a
        // multiple of 10000 (permit ranges are 10k-aligned); clamped up to that.
        $fillQuantity = $configBound
            ? max(10000, (int) round((int) config('nntmux.orchestrator.backfill_fill_quantity', 10000) / 10000) * 10000)
            : 10000;
        // Free-run drains the NZB queue in larger bites; there is no reason to
        // hold it at the adaptive batch size once the timers are gone.
        $fillNzbBatch = $configBound
            ? max(1, (int) config('nntmux.orchestrator.free_run_nzb_batch_size', 100))
            : 100;

        return match ($profile) {
            ControlProfile::FailSafe => new self($profile, 300, 1800, 180, 180, 5, false, 0, 0, 0),
            ControlProfile::Drain => new self($profile, 160, 1800, 45, 55, 20, false, 0, 0, 0),
            ControlProfile::Balanced => new self($profile, 40, 900, 60, 55, 20, true, 1, 1, 10000),
            ControlProfile::Fill => new self($profile, 20, 20, 20, 20, 20, true, $fillGroups, $fillThreads, $fillQuantity),
            // Every timer at zero and backfill always enabled. Groups, threads
            // and quantity still come from config so free-run is "no waiting",
            // not "no limits" -- the connection budget is still whatever
            // FILL_THREADS says, because exceeding the provider's allowance
            // fails the fetch rather than speeding it up.
            ControlProfile::FreeRun => new self($profile, 0, 0, 0, 0, max(20, $fillNzbBatch), true, $fillGroups, $fillThreads, $fillQuantity),
        };
    }

    public function quantityForYield(
        float $nzbsPer10k,
        int $remainingArticles = PHP_INT_MAX,
        int $safeQuantity = PHP_INT_MAX,
        int $yieldAttempts = 0,
        int $lastCursorDelta = 0,
        int $lastEffectiveAt = 0,
        bool $historyRecent = false,
        int $targetIneffectivePermits = 0,
        bool $targetLockRetryDue = false,
    ): int {
        // The fill quantity is a floor, but a floor above the live headroom
        // used to zero the permit, which the applier then issued as a 10k
        // probe: a 20k swing in collections cut night permits from 350k to
        // 10k. Shrink the floor to the largest 10k-aligned quantity the
        // headroom still fits instead, and give up only below one quantum.
        $floor = $this->backfillQuantity;
        if ($safeQuantity < $floor) {
            $floor = intdiv(max(0, $safeQuantity), 10000) * 10000;
            if ($floor < 10000) {
                return 0;
            }
        }

        $minimumYield = (float) config('nntmux.orchestrator.backfill_scale_min_yield', 1.0);
        $maxQuantity = (int) config('nntmux.orchestrator.backfill_max_quantity', 200000);
        // A positive long-term EWMA can hide a fresh source-quality cliff. Once
        // a target has an ineffective strike, probe it at the minimum quantity
        // until a productive cohort clears the strike or the target locks.
        if ($targetIneffectivePermits > 0) {
            return $floor;
        }
        $rampLimit = $historyRecent && $lastCursorDelta >= $floor
            ? min($maxQuantity, $lastCursorDelta * 2)
            : $maxQuantity;
        if ($this->profile !== ControlProfile::Fill || ! is_finite($nzbsPer10k) || $nzbsPer10k < $minimumYield) {
            return $floor;
        }

        $targetNzbs = (int) config('nntmux.orchestrator.backfill_target_nzbs_per_permit', 60);
        $quantity = (int) ceil($targetNzbs / $nzbsPer10k) * 10000;

        $availableQuantity = max(
            $floor,
            intdiv(max(0, $remainingArticles - 10000), 10000) * 10000,
        );

        return max($floor, min($rampLimit, $availableQuantity, $safeQuantity, $quantity));
    }
}
