<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources;

class ExternalMetadataRefreshSummary
{
    /**
     * @var array<string, ExternalMetadataSourceSummary>
     */
    private array $sources = [];

    public function source(string $source): ExternalMetadataSourceSummary
    {
        return $this->sources[$source] ??= new ExternalMetadataSourceSummary($source);
    }

    /**
     * @return array<string, ExternalMetadataSourceSummary>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    public function imported(): int
    {
        return array_sum(array_map(
            static fn (ExternalMetadataSourceSummary $summary): int => $summary->imported,
            $this->sources
        ));
    }
}
