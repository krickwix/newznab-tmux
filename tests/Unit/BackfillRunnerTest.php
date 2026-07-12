<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Runners\BackfillRunner;
use Tests\TestCase;

final class BackfillRunnerTest extends TestCase
{
    public function test_orchestrated_quantity_uses_the_permit_pinned_value(): void
    {
        $runner = new class extends BackfillRunner
        {
            public function quantity(int $legacy, string $group, int $pinned): int
            {
                return $this->resolveBackfillQuantity($legacy, $group, $pinned);
            }
        };

        self::assertSame(200_000, $runner->quantity(75_000, 'alt.proven', 200_000));
        self::assertSame(75_000, $runner->quantity(75_000, '', 200_000));
    }

    public function test_orchestrated_queue_preserves_ten_thousand_live_provider_articles(): void
    {
        $runner = new class extends BackfillRunner
        {
            /** @return array{0: array<string, string>, 1: array<string, string>} */
            public function queues(object $group): array
            {
                return $this->buildSafeBackfillQueues([$group], 200_000, 10_000, 1, 10_000);
            }
        };

        [$queues] = $runner->queues((object) [
            'name' => 'alt.proven',
            'our_first' => 35_000,
            'their_first' => 5_000,
            'their_last' => 1_000_000,
        ]);

        self::assertSame([
            'alt.proven#1' => 'get_range  backfill  alt.proven  25000  34999  1',
            'alt.proven#2' => 'get_range  backfill  alt.proven  15000  24999  2',
        ], $queues);
    }

    public function test_safe_backfill_schedules_meaningful_final_partial_chunk_to_provider_first_article(): void
    {
        $runner = new class extends BackfillRunner
        {
            /**
             * @param  array<int, object>  $groups
             * @return array{0: array<string, string>, 1: array<string, string>}
             */
            public function exposeQueues(array $groups): array
            {
                return $this->buildSafeBackfillQueues($groups, 75000, 20000, 4);
            }
        };

        [$queues, $queueGroups] = $runner->exposeQueues([
            (object) [
                'name' => 'a.b.multimedia.vintage-film',
                'our_first' => 105,
                'their_first' => 2,
                'their_last' => 200000,
            ],
        ]);

        $this->assertSame(
            ['a.b.multimedia.vintage-film#1' => 'get_range  backfill  a.b.multimedia.vintage-film  2  104  1'],
            $queues
        );
        $this->assertSame(['a.b.multimedia.vintage-film#1' => 'a.b.multimedia.vintage-film'], $queueGroups);
    }

    public function test_safe_backfill_skips_tiny_provider_floor_ranges(): void
    {
        $runner = new class extends BackfillRunner
        {
            /**
             * @param  array<int, object>  $groups
             * @return array{0: array<string, string>, 1: array<string, string>}
             */
            public function exposeQueues(array $groups): array
            {
                return $this->buildSafeBackfillQueues($groups, 75000, 20000, 4);
            }
        };

        [$queues, $queueGroups] = $runner->exposeQueues([
            (object) [
                'name' => 'a.b.multimedia.vintage-film',
                'our_first' => 4,
                'their_first' => 2,
                'their_last' => 200000,
            ],
        ]);

        $this->assertSame([], $queues);
        $this->assertSame([], $queueGroups);
    }

    public function test_safe_backfill_skips_uninitialized_or_invalid_provider_rows(): void
    {
        $runner = new class extends BackfillRunner
        {
            /**
             * @param  array<int, object>  $groups
             * @return array{0: array<string, string>, 1: array<string, string>}
             */
            public function exposeQueues(array $groups): array
            {
                return $this->buildSafeBackfillQueues($groups, 75000, 20000, 4);
            }
        };

        [$queues, $queueGroups] = $runner->exposeQueues([
            (object) [
                'name' => 'a.b.uninitialized',
                'our_first' => 0,
                'their_first' => 2,
                'their_last' => 200000,
            ],
            (object) [
                'name' => 'a.b.bad-provider-row',
                'our_first' => 1000,
                'their_first' => 2000,
                'their_last' => 1000,
            ],
        ]);

        $this->assertSame([], $queues);
        $this->assertSame([], $queueGroups);
    }

    public function test_safe_backfill_falls_back_when_max_messages_is_invalid(): void
    {
        $runner = new class extends BackfillRunner
        {
            /**
             * @param  array<int, object>  $groups
             * @return array{0: array<string, string>, 1: array<string, string>}
             */
            public function exposeQueues(array $groups): array
            {
                return $this->buildSafeBackfillQueues($groups, 75000, 0, 4);
            }
        };

        [$queues, $queueGroups] = $runner->exposeQueues([
            (object) [
                'name' => 'a.b.multimedia.vintage-film',
                'our_first' => 105,
                'their_first' => 2,
                'their_last' => 200000,
            ],
        ]);

        $this->assertSame(
            ['a.b.multimedia.vintage-film#1' => 'get_range  backfill  a.b.multimedia.vintage-film  2  104  1'],
            $queues
        );
        $this->assertSame(['a.b.multimedia.vintage-film#1' => 'a.b.multimedia.vintage-film'], $queueGroups);
    }
}
