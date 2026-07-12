<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

final readonly class PipelineSnapshot
{
    public function __construct(
        public int $partsBacklog,
        public int $binariesBacklog,
        public int $collectionsBacklog,
        public int $releasesBacklog,
        public int $nzbsBacklog,
        public bool $telemetryFresh = true,
        public bool $telemetryComplete = true,
        public bool $telemetryConsistent = true,
        public bool $databaseMemorySafe = true,
        public bool $databaseCpuSafe = true,
        public bool $databaseWaitsSafe = true,
        public bool $storageSafe = true,
        public bool $highPressure = false,
        public bool $lowPressure = false,
        public bool $providerAvailable = true,
        public bool $cursorAvailable = true,
        public bool $currentGroupsAvailable = true,
        public bool $eligibleBackfillSupply = false,
        public bool $backfillPermitCompleted = false,
        public bool $backfillPermitEffective = false,
        public bool $backfillPermitClaimed = true,
        public bool $backfillPermitInputMoved = true,
        public string $backfillPermitGroup = '',
        public int $databaseDeadlocks = 0,
        public int $databaseCurrentWaits = 0,
        public int $storageAvailableBytes = 0,
        public int $observedAt = 0,
        public int $readyCollections = 0,
        public int $releaseTotal = 0,
        public int $eligibleNzbs = 0,
        public int $oldestBinaryAgeSeconds = 0,
        public int $oldestCollectionAgeSeconds = 0,
        public int $oldestReleaseAgeSeconds = 0,
        public int $oldestNzbAgeSeconds = 0,
        /** @var array<string, float> */
        public array $backlogRatesPerMinute = [],
        /** @var array<string, float> */
        public array $backlogEwmaPerMinute = [],
        public string $backfillGroup = '',
        public int $backfillCursor = 0,
        public float $backfillYieldNzbsPer10k = 0.0,
        public int $backfillYieldAttempts = 0,
        public int $backfillLastCursorDelta = 0,
        public int $backfillLastEffectiveAt = 0,
        public bool $backfillHistoryRecent = false,
        public int $backfillTargetIneffectivePermits = 0,
        public int $backfillRemainingArticles = 0,
        public int $backfillSafeQuantity = 10000,
    ) {}

    public function withPermitOutcome(
        bool $completed,
        bool $effective,
        bool $claimed = true,
        bool $inputMoved = true,
        string $group = '',
    ): self {
        return new self(
            partsBacklog: $this->partsBacklog,
            binariesBacklog: $this->binariesBacklog,
            collectionsBacklog: $this->collectionsBacklog,
            releasesBacklog: $this->releasesBacklog,
            nzbsBacklog: $this->nzbsBacklog,
            telemetryFresh: $this->telemetryFresh,
            telemetryComplete: $this->telemetryComplete,
            telemetryConsistent: $this->telemetryConsistent,
            databaseMemorySafe: $this->databaseMemorySafe,
            databaseCpuSafe: $this->databaseCpuSafe,
            databaseWaitsSafe: $this->databaseWaitsSafe,
            storageSafe: $this->storageSafe,
            highPressure: $this->highPressure,
            lowPressure: $this->lowPressure,
            providerAvailable: $this->providerAvailable,
            cursorAvailable: $this->cursorAvailable,
            currentGroupsAvailable: $this->currentGroupsAvailable,
            eligibleBackfillSupply: $this->eligibleBackfillSupply,
            backfillPermitCompleted: $completed,
            backfillPermitEffective: $effective,
            backfillPermitClaimed: $claimed,
            backfillPermitInputMoved: $inputMoved,
            backfillPermitGroup: $group,
            databaseDeadlocks: $this->databaseDeadlocks,
            databaseCurrentWaits: $this->databaseCurrentWaits,
            storageAvailableBytes: $this->storageAvailableBytes,
            observedAt: $this->observedAt,
            readyCollections: $this->readyCollections,
            releaseTotal: $this->releaseTotal,
            eligibleNzbs: $this->eligibleNzbs,
            oldestBinaryAgeSeconds: $this->oldestBinaryAgeSeconds,
            oldestCollectionAgeSeconds: $this->oldestCollectionAgeSeconds,
            oldestReleaseAgeSeconds: $this->oldestReleaseAgeSeconds,
            oldestNzbAgeSeconds: $this->oldestNzbAgeSeconds,
            backlogRatesPerMinute: $this->backlogRatesPerMinute,
            backlogEwmaPerMinute: $this->backlogEwmaPerMinute,
            backfillGroup: $this->backfillGroup,
            backfillCursor: $this->backfillCursor,
            backfillYieldNzbsPer10k: $this->backfillYieldNzbsPer10k,
            backfillYieldAttempts: $this->backfillYieldAttempts,
            backfillLastCursorDelta: $this->backfillLastCursorDelta,
            backfillLastEffectiveAt: $this->backfillLastEffectiveAt,
            backfillHistoryRecent: $this->backfillHistoryRecent,
            backfillTargetIneffectivePermits: $this->backfillTargetIneffectivePermits,
            backfillRemainingArticles: $this->backfillRemainingArticles,
            backfillSafeQuantity: $this->backfillSafeQuantity,
        );
    }

    public function telemetryIsValid(): bool
    {
        return $this->telemetryFresh
            && $this->telemetryComplete
            && $this->telemetryConsistent
            && ! $this->hasNegativeBacklog()
            && ! ($this->highPressure && $this->lowPressure);
    }

    public function hardSafetyPassed(): bool
    {
        return $this->databaseMemorySafe
            && $this->databaseCpuSafe
            && $this->databaseWaitsSafe
            && $this->storageSafe;
    }

    public function backfillGatesPassed(): bool
    {
        return $this->providerAvailable
            && $this->cursorAvailable
            && $this->currentGroupsAvailable
            && $this->eligibleBackfillSupply;
    }

    private function hasNegativeBacklog(): bool
    {
        return $this->partsBacklog < 0
            || $this->binariesBacklog < 0
            || $this->collectionsBacklog < 0
            || $this->releasesBacklog < 0
            || $this->nzbsBacklog < 0;
    }
}
