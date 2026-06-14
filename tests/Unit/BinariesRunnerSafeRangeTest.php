<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Runners\BinariesRunner;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BinariesRunnerSafeRangeTest extends TestCase
{
    public function test_safe_binaries_partial_range_stops_at_remaining_article_count(): void
    {
        $group = new stdClass;
        $group->groupname = 'alt.binaries.blu-ray';
        $group->our_last = 1000;
        $group->their_last = 41505;

        $queueFactories = (new BinariesRunner)->safeBinaryQueueEntries($group, 10000, 50000);
        $queues = [];
        foreach ($queueFactories as $offset => $queueFactory) {
            $queues[] = $queueFactory($offset + 1);
        }

        $this->assertSame([
            'part_repair  alt.binaries.blu-ray',
            'get_range  binaries  alt.binaries.blu-ray  1001  11000  2',
            'get_range  binaries  alt.binaries.blu-ray  11001  21000  3',
            'get_range  binaries  alt.binaries.blu-ray  21001  21505  4',
        ], $queues);
    }
}
