<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

enum FailSafeCause: string
{
    case Telemetry = 'telemetry';
    case Hard = 'hard';
    case Unknown = 'unknown';
}
