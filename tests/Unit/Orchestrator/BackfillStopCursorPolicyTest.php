<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\BackfillStopCursorPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BackfillStopCursorPolicyTest extends TestCase
{
    public function test_exact_source_floor_caps_planning_and_execution_at_the_same_cursor(): void
    {
        config()->set('nntmux.orchestrator.backfill_stop_cursors', 'alt.test:60000');
        $policy = new BackfillStopCursorPolicy;

        self::assertSame(50_000, $policy->remainingArticles('alt.test', 100_000, 90_000));
        self::assertSame(40_000, $policy->clampQuantity('alt.test', 100_000, 60_000));
        self::assertSame(5_000, $policy->clampQuantity('alt.test', 65_000, 40_000));
        self::assertSame(0, $policy->clampQuantity('alt.test', 60_000, 40_000));
    }

    public function test_any_malformed_stop_cursor_configuration_fails_closed_for_managed_backfill(): void
    {
        config()->set('nntmux.orchestrator.backfill_stop_cursors', 'malformed,alt.other:50000');
        $policy = new BackfillStopCursorPolicy;

        self::assertSame(0, $policy->remainingArticles('alt.test', 100_000, 90_000));
        self::assertSame(0, $policy->clampQuantity('alt.test', 100_000, 40_000));
    }

    public static function invalidConfigurations(): array
    {
        return [
            'duplicate' => ['alt.test:60000,alt.test:50000'],
            'empty token' => ['alt.test:60000,'],
            'zero' => ['alt.test:0'],
            'overflow' => ['alt.test:999999999999999999999999999999999'],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_strict_parser_rejects_duplicate_empty_zero_and_overflow_values(string $configured): void
    {
        config()->set('nntmux.orchestrator.backfill_stop_cursors', $configured);
        $policy = new BackfillStopCursorPolicy;

        self::assertFalse($policy->isValid());
        self::assertSame(0, $policy->clampQuantity('alt.test', 100_000, 40_000));
    }
}
