<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tmux\TmuxSettingsInputNormalizer;
use Tests\TestCase;

final class TmuxSettingsInputNormalizerTest extends TestCase
{
    public function test_numeric_backfill_days_above_mode_values_becomes_group_target(): void
    {
        $normalizer = new TmuxSettingsInputNormalizer;

        $result = $normalizer->normalize([
            'action' => 'submit',
            'backfill_days' => '7305',
            'backfill_qty' => '75000',
        ]);

        $this->assertSame(1, $result->settings['backfill_days']);
        $this->assertSame(7305, $result->backfillTargetForEnabledGroups);
        $this->assertSame('75000', $result->settings['backfill_qty']);
    }

    public function test_invalid_backfill_days_falls_back_to_group_days_mode(): void
    {
        $normalizer = new TmuxSettingsInputNormalizer;

        $result = $normalizer->normalize([
            'backfill_days' => 'invalid',
        ]);

        $this->assertSame(1, $result->settings['backfill_days']);
        $this->assertNull($result->backfillTargetForEnabledGroups);
    }

    public function test_valid_backfill_day_modes_are_preserved(): void
    {
        $normalizer = new TmuxSettingsInputNormalizer;

        $perGroup = $normalizer->normalize(['backfill_days' => '1']);
        $safeDate = $normalizer->normalize(['backfill_days' => '2']);

        $this->assertSame(1, $perGroup->settings['backfill_days']);
        $this->assertNull($perGroup->backfillTargetForEnabledGroups);
        $this->assertSame(2, $safeDate->settings['backfill_days']);
        $this->assertNull($safeDate->backfillTargetForEnabledGroups);
    }

    public function test_missing_backfill_days_is_not_added(): void
    {
        $normalizer = new TmuxSettingsInputNormalizer;

        $result = $normalizer->normalize(['backfill_qty' => '75000']);

        $this->assertArrayNotHasKey('backfill_days', $result->settings);
        $this->assertNull($result->backfillTargetForEnabledGroups);
    }

    public function test_non_positive_and_decimal_backfill_days_fall_back_to_group_days_mode(): void
    {
        $normalizer = new TmuxSettingsInputNormalizer;

        foreach (['0', '-4', '2.5'] as $value) {
            $result = $normalizer->normalize(['backfill_days' => $value]);

            $this->assertSame(1, $result->settings['backfill_days']);
            $this->assertNull($result->backfillTargetForEnabledGroups);
        }
    }
}
