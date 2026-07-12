<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

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
        return match ($profile) {
            ControlProfile::FailSafe => new self($profile, 300, 1800, 180, 180, 5, false, 0, 0, 0),
            ControlProfile::Drain => new self($profile, 160, 1800, 45, 55, 20, false, 0, 0, 0),
            ControlProfile::Balanced => new self($profile, 40, 900, 60, 55, 20, true, 1, 1, 10000),
            ControlProfile::Fill => new self($profile, 20, 600, 90, 55, 20, true, 1, 1, 10000),
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
    ): int {
        $minimumYield = (float) config('nntmux.orchestrator.backfill_scale_min_yield', 1.0);
        if ($this->profile !== ControlProfile::Fill || ! is_finite($nzbsPer10k) || $nzbsPer10k < $minimumYield) {
            if ($this->profile === ControlProfile::Fill
                && $yieldAttempts === 1
                && $lastCursorDelta > 0
                && $lastEffectiveAt === 0
                && $historyRecent
                && $targetIneffectivePermits === 1
                && is_finite($nzbsPer10k)
                && $nzbsPer10k === 0.0
            ) {
                $retryQuantity = (int) config('nntmux.orchestrator.backfill_context_retry_quantity', 50000);
                $maxQuantity = (int) config('nntmux.orchestrator.backfill_max_quantity', 200000);
                $availableQuantity = max(
                    $this->backfillQuantity,
                    intdiv(max(0, $remainingArticles - 10000), 10000) * 10000,
                );

                return max($this->backfillQuantity, min($retryQuantity, $maxQuantity, $availableQuantity, $safeQuantity));
            }

            return $this->backfillQuantity;
        }

        $targetNzbs = (int) config('nntmux.orchestrator.backfill_target_nzbs_per_permit', 60);
        $maxQuantity = (int) config('nntmux.orchestrator.backfill_max_quantity', 200000);
        $quantity = (int) ceil($targetNzbs / $nzbsPer10k) * 10000;

        $availableQuantity = max(
            $this->backfillQuantity,
            intdiv(max(0, $remainingArticles - 10000), 10000) * 10000,
        );

        return max($this->backfillQuantity, min($maxQuantity, $availableQuantity, $safeQuantity, $quantity));
    }
}
