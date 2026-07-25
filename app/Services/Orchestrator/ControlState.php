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
        public int $recoveryDrainSamples = 0,
        public int $recoveryDrainHoldSamples = 0,
        /** @var list<int> */
        public array $processedBackfillPermitGenerations = [],
        public bool $qualifiedSupplyStarved = false,
        public int $qualifiedSupplyCandidateSince = 0,
        public int $qualifiedSupplyStarvedSince = 0,
        public int $qualifiedSupplyLastObservedAt = 0,
        public int $qualifiedSupplyRecoverySamples = 0,
        public int $qualifiedSupplyColdStartAt = 0,
    ) {
        if ($consecutiveHigh < 0 || $consecutiveLow < 0 || $consecutiveIneffectiveBackfillPermits < 0 || $failSafeRecoverySamples < 0 || $failSafeLastObservedAt < 0 || $recoveryDrainSamples < 0 || $recoveryDrainHoldSamples < 0 || $qualifiedSupplyCandidateSince < 0 || $qualifiedSupplyStarvedSince < 0 || $qualifiedSupplyLastObservedAt < 0 || $qualifiedSupplyRecoverySamples < 0 || $qualifiedSupplyColdStartAt < 0) {
            throw new \InvalidArgumentException('Control state counters cannot be negative.');
        }
        foreach ($ineffectiveBackfillPermitsByTarget as $group => $count) {
            // Upper bound is a sanity guard only; the effective lock limit is
            // enforced (min-capped) in WorkerControlPolicy before storage and is
            // env-tunable, so allow up to the configured maximum here.
            if (! is_string($group) || $group === '' || $count < 0 || $count > 50) {
                throw new \InvalidArgumentException('Target ineffective permit counters must use a group name and remain bounded.');
            }
        }
        foreach ($processedBackfillPermitGenerations as $generation) {
            if (! is_int($generation) || $generation <= 0) {
                throw new \InvalidArgumentException('Processed permit generations must be positive integers.');
            }
        }
    }

    public static function initial(): self
    {
        return new self;
    }
}
