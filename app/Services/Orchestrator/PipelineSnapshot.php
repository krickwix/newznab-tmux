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
    ) {}

    public function withPermitOutcome(bool $completed, bool $effective): self
    {
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
