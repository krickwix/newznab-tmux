<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

final readonly class ControlState
{
    public function __construct(
        public ControlProfile $profile = ControlProfile::Drain,
        public int $consecutiveHigh = 0,
        public int $consecutiveLow = 0,
        public int $lastTransitionAt = 0,
        public int $cooldownUntil = 0,
        public int $consecutiveIneffectiveBackfillPermits = 0,
        public bool $backfillLocked = false,
    ) {
        if ($consecutiveHigh < 0 || $consecutiveLow < 0 || $consecutiveIneffectiveBackfillPermits < 0) {
            throw new \InvalidArgumentException('Control state counters cannot be negative.');
        }
    }

    public static function initial(): self
    {
        return new self;
    }
}
