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

    public function test_actionable_groups_are_selected_ahead_of_one_starvation_sweep_group(): void
    {
        $runner = new class extends ReleasesRunner
        {
            /**
             * @param  array<int, array{id: int|string, name: string}>  $groups
             * @param  list<int>  $actionableGroupIds
             * @return array<int, array{id: int|string, name: string}>
             */
            public function select(array $groups, string $pin, array $actionableGroupIds): array
            {
                return $this->selectActionableGroups($groups, $pin, $actionableGroupIds, 0, 1);
            }
        };

        $groups = [
            ['id' => 10, 'name' => 'alt.idle.ten'],
            ['id' => 20, 'name' => 'alt.actionable.twenty'],
            ['id' => 30, 'name' => 'alt.actionable.pinned'],
            ['id' => 40, 'name' => 'alt.idle.forty'],
        ];

        self::assertSame([
            ['id' => 30, 'name' => 'alt.actionable.pinned'],
            ['id' => 20, 'name' => 'alt.actionable.twenty'],
            ['id' => 10, 'name' => 'alt.idle.ten'],
        ], $runner->select($groups, 'alt.actionable.pinned', [20, 30]));
    }

    public function test_starvation_sweep_offset_rotates_across_idle_groups(): void
    {
        $runner = new class extends ReleasesRunner
        {
            /**
             * @param  array<int, array{id: int|string, name: string}>  $groups
             * @param  list<int>  $actionableGroupIds
             * @return array<int, array{id: int|string, name: string}>
             */
            public function select(array $groups, array $actionableGroupIds, int $offset): array
            {
                return $this->selectActionableGroups($groups, '', $actionableGroupIds, $offset, 1);
            }
        };

        $groups = [
            ['id' => 10, 'name' => 'alt.idle.ten'],
            ['id' => 20, 'name' => 'alt.actionable.twenty'],
            ['id' => 30, 'name' => 'alt.idle.thirty'],
            ['id' => 40, 'name' => 'alt.idle.forty'],
        ];

        self::assertSame([
            ['id' => 20, 'name' => 'alt.actionable.twenty'],
            ['id' => 30, 'name' => 'alt.idle.thirty'],
        ], $runner->select($groups, [20], 1));
    }

    public function test_ready_groups_run_before_preparation_only_groups_even_when_their_ids_are_higher(): void
    {
        $runner = new class extends ReleasesRunner
        {
            public function select(array $groups, array $actionableGroupIds, array $readyGroupIds): array
            {
                return $this->selectActionableGroups(
                    $groups,
                    '',
                    $actionableGroupIds,
                    0,
                    0,
                    $readyGroupIds,
                );
            }
        };

        $groups = [
            ['id' => 10, 'name' => 'alt.preparing.low-id'],
            ['id' => 20, 'name' => 'alt.ready.high-id'],
        ];

        self::assertSame([
            ['id' => 20, 'name' => 'alt.ready.high-id'],
            ['id' => 10, 'name' => 'alt.preparing.low-id'],
        ], $runner->select($groups, [10, 20], [20]));
    }
}
