<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use RuntimeException;

/**
 * A dense ordinal allocated by CollectionFileNumberAllocator was not the one we
 * ended up holding.
 *
 * `binaries` is UNIQUE (collections_id, filenumber) and there is no per-group
 * lock anywhere in the ingest path -- no GET_LOCK, no lockForUpdate, no cache
 * lock in HeaderStorageService or CollectionHandler. The lane locks
 * (nntmux:release-worker-lock) scope a LANE, not a group, and `binaries`,
 * `current-forward` and `backfill` all reach the same code. So two writers can
 * allocate from the same stale MAX(filenumber).
 *
 * The insert itself will not tell us: BinaryHandler's bulk insert carries
 * ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), so a stolen ordinal resolves
 * silently to the OTHER writer's binary and this file's parts would be attached
 * to that file's name. That is worse than an error, so the allocator verifies
 * after resolution and raises this instead.
 *
 * TransientHeaderStorageFailure::is() recognises it, which puts it on the chunk
 * retry path HeaderStorageService::storeChunk() already runs: the transaction is
 * rolled back, MAX(filenumber) is re-read from scratch on the next attempt, and
 * after MAX_CHUNK_ATTEMPTS the chunk's article numbers go to part repair rather
 * than being guessed at. That is design section 3's retry contract, using the
 * machinery that was already there.
 */
final class CollectionFileNumberCollision extends RuntimeException
{
    public static function sharedBinary(int $binaryId, string $expectedSubject, string $otherSubject): self
    {
        return new self(\sprintf(
            'Two distinct files resolved to binary %d during ordinal allocation (%s vs %s); '
            .'a concurrent writer took an allocated filenumber.',
            $binaryId,
            $expectedSubject,
            $otherSubject,
        ));
    }

    public static function foreignBinary(int $binaryId, string $expectedHash, string $actualHash, int $expectedCollectionId, int $actualCollectionId): self
    {
        return new self(\sprintf(
            'Binary %d is not the file its ordinal was allocated for '
            .'(collection %d/%d, hash %s/%s); a concurrent writer took an allocated filenumber.',
            $binaryId,
            $expectedCollectionId,
            $actualCollectionId,
            $expectedHash,
            $actualHash,
        ));
    }

    public static function missingBinary(int $binaryId): self
    {
        return new self(\sprintf(
            'Binary %d was resolved during ordinal allocation but is no longer readable in this transaction.',
            $binaryId,
        ));
    }
}
