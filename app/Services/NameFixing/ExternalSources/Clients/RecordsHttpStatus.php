<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Lets the refresh service tell "no match" from "source unhealthy" without
 * changing the clients' return contracts: null means no response at all.
 */
trait RecordsHttpStatus
{
    private ?int $lastStatus = null;

    public function lastStatus(): ?int
    {
        return $this->lastStatus;
    }

    private function request(): PendingRequest
    {
        $this->lastStatus = null;

        return Http::connectTimeout((int) config('external_metadata.connect_timeout', 3))
            ->timeout((int) config('external_metadata.timeout', 20));
    }

    private function recordStatus(int $status): void
    {
        $this->lastStatus = $status;
    }
}
