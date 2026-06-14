<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources;

class ExternalReleaseHit
{
    /**
     * @param  array<string, mixed>  $payloadSummary
     */
    public function __construct(
        public readonly string $source,
        public readonly string $title,
        public readonly ?string $group = null,
        public readonly ?string $category = null,
        public readonly ?int $files = null,
        public readonly ?int $size = null,
        public readonly ?int $pretime = null,
        public readonly ?string $externalId = null,
        public readonly bool $autoRenameEligible = false,
        public readonly array $payloadSummary = [],
    ) {}
}
