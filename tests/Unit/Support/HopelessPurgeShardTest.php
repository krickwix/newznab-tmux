<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Services\Distributed\DistributedJobCatalog;
use App\Support\HopelessPurgeShard;
use InvalidArgumentException;
use Tests\TestCase;

final class HopelessPurgeShardTest extends TestCase
{
    public function test_blank_means_unsharded(): void
    {
        $this->assertNull(HopelessPurgeShard::parse(null));
        $this->assertNull(HopelessPurgeShard::parse(''));
        $this->assertNull(HopelessPurgeShard::parse('  '));
    }

    public function test_valid_shards_parse(): void
    {
        $shard = HopelessPurgeShard::parse(' 2 / 3 ');

        $this->assertNotNull($shard);
        $this->assertSame(2, $shard->index);
        $this->assertSame(3, $shard->count);
        $this->assertSame('shard-2-of-3', $shard->suffix());
        $this->assertSame('shard-15-of-16', HopelessPurgeShard::parse('15/16')?->suffix());
    }

    public function test_invalid_shards_throw(): void
    {
        foreach (['3/3', '1/0', '0/17', 'a/b', '-1/2', '1', '1/2/3'] as $value) {
            try {
                HopelessPurgeShard::parse($value);
                $this->fail("Shard [{$value}] should be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_the_hopeless_purge_lock_carries_the_shard(): void
    {
        config(['nntmux.hopeless_purge_shard' => '']);
        $this->assertSame('nntmux:distributed-worker:hopeless-purge', DistributedJobCatalog::lockName('hopeless-purge'));

        config(['nntmux.hopeless_purge_shard' => '0/3']);
        $this->assertSame(
            'nntmux:distributed-worker:hopeless-purge:shard-0-of-3',
            DistributedJobCatalog::lockName('hopeless-purge'),
        );
        $this->assertSame('nntmux:distributed-worker:releases', DistributedJobCatalog::lockName('releases'));
    }
}
