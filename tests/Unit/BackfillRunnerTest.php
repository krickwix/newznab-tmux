<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Runners\BackfillRunner;
use Tests\TestCase;

final class BackfillRunnerTest extends TestCase
{
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
}
