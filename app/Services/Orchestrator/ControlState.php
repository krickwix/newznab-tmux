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
        /** @var array<string, int> */
        public array $ineffectiveBackfillPermitsByTarget = [],
        public ?FailSafeCause $failSafeCause = null,
        public int $failSafeRecoverySamples = 0,
        public int $failSafeLastObservedAt = 0,
    ) {
        if ($consecutiveHigh < 0 || $consecutiveLow < 0 || $consecutiveIneffectiveBackfillPermits < 0 || $failSafeRecoverySamples < 0 || $failSafeLastObservedAt < 0) {
            throw new \InvalidArgumentException('Control state counters cannot be negative.');
        }
        foreach ($ineffectiveBackfillPermitsByTarget as $group => $count) {
            if (! is_string($group) || $group === '' || $count < 0 || $count > WorkerControlPolicy::INEFFECTIVE_BACKFILL_LIMIT) {
                throw new \InvalidArgumentException('Target ineffective permit counters must use a group name and remain bounded.');
            }
        }
    }

    public static function initial(): self
    {
        return new self;
    }
}
