<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

/**
 * Persisted free_adaptive governor state (the orchestrator_fa_* settings).
 */
final readonly class FreeAdaptiveState
{
    public function __construct(
        public int $delay,
        /** Last time the delay CHANGED; the settle anchor. 0 = never stored. */
        public int $updatedAt = 0,
        /** Last cycle the governor ran; lets a stale stored delay be discarded. */
        public int $checkedAt = 0,
        /** Last backfill permit completion or failure, any profile. */
        public int $lastDoneAt = 0,
        public string $reason = 'none',
        public string $bindingStage = '',
        public float $projected = 0.0,
        public float $ewma = 0.0,
    ) {}

    public function nextEligibleAt(): int
    {
        return $this->lastDoneAt + max(0, $this->delay);
    }

    /**
     * Source of truth for the permit gate: recomputed from the CURRENT delay,
     * so a decrease takes effect at once.
     */
    public function gateOpen(int $now): bool
    {
        return $now >= $this->nextEligibleAt();
    }

    /** @return array<string, mixed> */
    public function toArray(int $now, FreeAdaptiveConfig $config): array
    {
        // Before the first governor tick there is no delay to show.
        $stored = $this->delay >= 0;

        return [
            'delay_seconds' => $stored ? $this->delay : null,
            'next_eligible_at' => $stored ? $this->nextEligibleAt() : null,
            'seconds_until_eligible' => $stored ? max(0, $this->nextEligibleAt() - $now) : null,
            'last_done_at' => $this->lastDoneAt,
            'last_change_at' => $this->updatedAt,
            'reason' => $this->reason,
            'binding_stage' => $this->bindingStage,
            'projected_utilisation' => round($this->projected, 4),
            'ewma_per_minute' => round($this->ewma, 2),
            'min_delay_seconds' => $config->minDelay,
            'max_delay_seconds' => $config->maxDelay,
        ];
    }
}
