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
}
