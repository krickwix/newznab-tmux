<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Runners\BackfillRunner;
use Tests\TestCase;

final class BackfillRunnerTest extends TestCase
{
    public function test_safe_backfill_schedules_final_partial_chunk_to_provider_first_article(): void
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
                'our_first' => 3,
                'their_first' => 2,
            ],
        ]);

        $this->assertSame(
            ['a.b.multimedia.vintage-film#1' => 'get_range  backfill  a.b.multimedia.vintage-film  2  2  1'],
            $queues
        );
        $this->assertSame(['a.b.multimedia.vintage-film#1' => 'a.b.multimedia.vintage-film'], $queueGroups);
    }
}
