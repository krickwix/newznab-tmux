<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

final readonly class ControlDecision
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public WorkerControlProfile $profile,
        public bool $backfillPermitted,
        public array $reasons,
        public ControlState $nextState,
        public bool $transitioned,
    ) {}

    public function primaryReason(): string
    {
        return $this->reasons[0] ?? 'no_change';
    }
}
