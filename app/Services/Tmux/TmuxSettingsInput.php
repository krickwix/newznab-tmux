<?php

declare(strict_types=1);

namespace App\Services\Tmux;

final readonly class TmuxSettingsInput
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public array $settings,
        public ?int $backfillTargetForEnabledGroups = null,
    ) {}
}
