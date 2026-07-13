<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Runners\ReleasesRunner;
use Tests\TestCase;

final class ReleasesRunnerTest extends TestCase
{
    public function test_pinned_backfill_group_is_processed_first_with_deterministic_fallback(): void
    {
        $runner = new class extends ReleasesRunner
        {
            /**
             * @param  array<int, array{id: int|string, name: string}>  $groups
             * @return array<int, array{id: int|string, name: string}>
             */
            public function prioritize(array $groups, string $pinnedGroup): array
            {
                return $this->prioritizePinnedGroup($groups, $pinnedGroup);
            }
        };

        $groups = [
            ['id' => 20, 'name' => 'alt.unrelated.twenty'],
            ['id' => 30, 'name' => 'alt.pinned'],
            ['id' => 10, 'name' => 'alt.unrelated.ten'],
        ];

        self::assertSame([
            ['id' => 30, 'name' => 'alt.pinned'],
            ['id' => 10, 'name' => 'alt.unrelated.ten'],
            ['id' => 20, 'name' => 'alt.unrelated.twenty'],
        ], $runner->prioritize($groups, 'alt.pinned'));
    }

    public function test_missing_or_empty_pin_uses_deterministic_group_order(): void
    {
        $runner = new class extends ReleasesRunner
        {
            /**
             * @param  array<int, array{id: int|string, name: string}>  $groups
             * @return array<int, array{id: int|string, name: string}>
             */
            public function prioritize(array $groups, string $pinnedGroup): array
            {
                return $this->prioritizePinnedGroup($groups, $pinnedGroup);
            }
        };

        $groups = [
            ['id' => '30', 'name' => 'alt.thirty'],
            ['id' => '10', 'name' => 'alt.ten'],
            ['id' => '20', 'name' => 'alt.twenty'],
        ];
        $expected = [
            ['id' => '10', 'name' => 'alt.ten'],
            ['id' => '20', 'name' => 'alt.twenty'],
            ['id' => '30', 'name' => 'alt.thirty'],
        ];

        self::assertSame($expected, $runner->prioritize($groups, ''));
        self::assertSame($expected, $runner->prioritize($groups, 'alt.not-eligible'));
    }
}
