<?php

declare(strict_types=1);

namespace App\Services\Tmux;

final class TmuxSettingsInputNormalizer
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function normalize(array $settings): TmuxSettingsInput
    {
        $backfillTargetForEnabledGroups = null;

        if (array_key_exists('backfill_days', $settings)) {
            $backfillDays = $this->integerValue($settings['backfill_days']);

            if ($backfillDays !== null) {
                if ($backfillDays > 2) {
                    $backfillTargetForEnabledGroups = $backfillDays;
                    $settings['backfill_days'] = 1;
                } elseif (in_array($backfillDays, [1, 2], true)) {
                    $settings['backfill_days'] = $backfillDays;
                } else {
                    $settings['backfill_days'] = 1;
                }
            } else {
                $settings['backfill_days'] = 1;
            }
        }

        return new TmuxSettingsInput($settings, $backfillTargetForEnabledGroups);
    }

    private function integerValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);

        return $parsed === false ? null : $parsed;
    }
}
