<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use RuntimeException;
use Throwable;

class NativeSearchSideEffectSyncFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(private readonly array $report, Throwable $previous)
    {
        $failedId = $report['failed_release_ids'][0] ?? 'unknown';

        parent::__construct("Native search sync failed for release ID [{$failedId}].", 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->report;
    }
}
