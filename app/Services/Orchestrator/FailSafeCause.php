<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

enum FailSafeCause: string
{
    case Telemetry = 'telemetry';
    case Hard = 'hard';
    case Unknown = 'unknown';

    /**
     * An operator pinned fail-safe. Nothing is wrong with the pipeline; it is
     * parked. Distinct from Telemetry so an incident responder reading the
     * state is not sent looking for a broken Prometheus scrape. An older binary
     * reading this back resolves it to Unknown, which is the conservative
     * recovery path rather than the fast one.
     */
    case Pinned = 'pinned';
}
