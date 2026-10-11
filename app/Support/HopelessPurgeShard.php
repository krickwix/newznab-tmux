<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * One slice of the hopeless-purge lane, written "i/N".
 *
 * Shard i scans only the collections whose id % N = i, so N replicas of the
 * lane split the table without overlap. Each shard keeps its own cursor and
 * its own worker lock, so the replicas never wait on each other.
 */
final readonly class HopelessPurgeShard
{
    public const int MAX_SHARDS = 16;

    private function __construct(
        public int $index,
        public int $count,
    ) {}

    /**
     * Null or blank means unsharded. Anything else must be a valid "i/N".
     *
     * @throws InvalidArgumentException
     */
    public static function parse(?string $value): ?self
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\s*\/\s*(\d{1,2})$/', $value, $match) !== 1) {
            throw new InvalidArgumentException("Invalid hopeless purge shard [{$value}]; expected i/N.");
        }
        $index = (int) $match[1];
        $count = (int) $match[2];
        if ($count < 1 || $count > self::MAX_SHARDS || $index >= $count) {
            throw new InvalidArgumentException(sprintf(
                'Invalid hopeless purge shard [%s]; need 0 <= i < N <= %d.',
                $value,
                self::MAX_SHARDS,
            ));
        }

        return new self($index, $count);
    }

    /** @throws InvalidArgumentException */
    public static function fromConfig(): ?self
    {
        return self::parse((string) config('nntmux.hopeless_purge_shard', ''));
    }

    /** Suffix for cursor and lock names, e.g. "shard-0-of-3". */
    public function suffix(): string
    {
        return sprintf('shard-%d-of-%d', $this->index, $this->count);
    }
}
