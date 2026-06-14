<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources;

class ExternalMetadataSourceSummary
{
    public int $queried = 0;

    public int $imported = 0;

    public int $skipped = 0;

    public int $failed = 0;

    /**
     * @var list<string>
     */
    public array $messages = [];

    public function __construct(public readonly string $source) {}

    public function message(string $message): void
    {
        $this->messages[] = $message;
    }
}
