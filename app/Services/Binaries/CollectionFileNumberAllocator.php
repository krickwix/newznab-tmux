<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use Illuminate\Support\Facades\DB;

/**
 * Dense per-collection file ordinals -- section 3 of
 * docs/design/2026-08-04-ingest-collection-keying.md, and the hard part.
 *
 * Once a leaked part count is kept out of `collections.totalfiles`, the
 * collection sits at totalfiles = 0 and is promoted by
 * ReleaseProcessingService::runCollectionFileCheckStage0(), whose gate is
 *
 *     COUNT(DISTINCT filenumber) >= GREATEST(1, CEIL(MAX(filenumber) * completion / 100))
 *
 * With `settings.completion` NULL in production (-> 100 via
 * requiredCompletionPercent()) that reduces to
 * COUNT(DISTINCT filenumber) == MAX(filenumber). **Only a dense 1..N satisfies
 * it.** So the ordinal cannot be left at 1, and it cannot be sparse.
 *
 * Per ingest batch, per collection:
 *
 *  1. read the collection's current MAX(filenumber) -- once per collection, not
 *     per header;
 *  2. assign max+1, max+2, ... to the NEW files of the batch in a stable order
 *     (subject sort, hash tie-break) so a replay is deterministic;
 *  3. files already present resolve by binary hash and keep the ordinal they
 *     have.
 *
 * Neither query is a COUNT(*): step 1 is a grouped MAX over
 * UNIQUE (collections_id, filenumber) restricted to a bounded id list, and step
 * 3 is an equality lookup on ix_binaries_collection_hash. Both are chunked to
 * MAX_SQL_ROWS_PER_STATEMENT, matching the rest of the ingest path.
 *
 * Concurrency is the open risk the design flags, and it is handled by
 * assertOrdinalsHeld() rather than by locking -- header storage runs at READ
 * COMMITTED specifically to avoid gap locks, so a locking read could not
 * protect the gap above MAX anyway. See CollectionFileNumberCollision.
 *
 * NOT final, unlike its siblings here, and only for that reason: the design
 * says "the contention test is not optional", and a real race cannot be staged
 * from one process. IngestCollectionKeyingContentionTest subclasses this to
 * make the collision happen on demand and then asserts the chunk retry path is
 * actually exercised rather than merely present.
 */
class CollectionFileNumberAllocator
{
    /**
     * Hard upper bound on rows packed into a single SQL statement. Same value,
     * for the same reason, as BinaryHandler and CollectionHandler.
     */
    private const MAX_SQL_ROWS_PER_STATEMENT = 500;

    /**
     * Allocate a filenumber for every request.
     *
     * @param  array<int, array{collection_id: int, hash: string, sort: string}>  $requests  keyed by header index
     * @return array<int, int> filenumber keyed by header index
     */
    public function allocate(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $filesByCollection = $this->groupRequests($requests);
        if ($filesByCollection === []) {
            return [];
        }

        // The hash lookup runs FIRST and the high-water mark second, then the
        // mark is folded with every ordinal the lookup actually saw. Two
        // statements cannot share a snapshot under READ COMMITTED, so this is
        // what stops a row committed between them from sitting at or above
        // where allocation starts.
        $known = $this->existingFileNumbers($filesByCollection);
        $highWater = $this->highWaterMarks(array_keys($filesByCollection));

        $assigned = [];
        foreach ($filesByCollection as $collectionId => $files) {
            $order = [];
            foreach ($files as $subject => $hash) {
                $order[] = ['subject' => (string) $subject, 'hash' => (string) $hash];
            }

            usort(
                $order,
                static fn (array $left, array $right): int => strcmp($left['subject'], $right['subject'])
                    ?: strcmp($left['hash'], $right['hash']),
            );

            $next = (int) ($highWater[$collectionId] ?? 0);
            foreach ($known[$collectionId] ?? [] as $fileNumber) {
                $next = max($next, (int) $fileNumber);
            }

            foreach ($order as $entry) {
                $existing = $known[$collectionId][$entry['hash']] ?? null;
                if ($existing !== null) {
                    // Step 3: an already-present file keeps the ordinal it has,
                    // including a legacy 0. Re-numbering it would rewrite a row
                    // that is already committed and, worse, would move a
                    // filenumber other rows may already be paired against.
                    $assigned[$collectionId][$entry['subject']] = (int) $existing;

                    continue;
                }

                $assigned[$collectionId][$entry['subject']] = ++$next;
            }
        }

        $allocated = [];
        foreach ($requests as $index => $request) {
            $collectionId = (int) $request['collection_id'];
            $subject = (string) $request['sort'];
            if (isset($assigned[$collectionId][$subject])) {
                $allocated[$index] = (int) $assigned[$collectionId][$subject];
            }
        }

        return $allocated;
    }

    /**
     * One entry per FILE, keyed the way BinaryHandler keys its in-batch article
     * dedupe: by collection and parsed subject.
     *
     * Keying on the binary hash instead would be the obvious choice, and it is
     * wrong in one corner. BinaryHandler's `articleKey` is
     * `collectionId . ':' . matches[1]`, while its binary hash also folds in
     * `From`. Two headers with the same subject and a different poster inside
     * one chunk therefore collapse to ONE binary there while producing TWO
     * hashes here -- and the verification below would read that as a stolen
     * ordinal and stall the chunk into part repair permanently, since a replay
     * derives the same thing. Grouping by subject and carrying the FIRST hash
     * seen -- which is the one BinaryHandler will have inserted, both iterating
     * the same header order -- keeps the two identities in step.
     *
     * @param  array<int, array{collection_id: int, hash: string, sort: string}>  $requests
     * @return array<int, array<string, string>> collectionId => subject => binary hash
     */
    private function groupRequests(array $requests): array
    {
        $filesByCollection = [];
        foreach ($requests as $request) {
            $collectionId = (int) $request['collection_id'];
            if ($collectionId <= 0) {
                continue;
            }

            $subject = (string) $request['sort'];
            if (! isset($filesByCollection[$collectionId][$subject])) {
                $filesByCollection[$collectionId][$subject] = strtolower((string) $request['hash']);
            }
        }

        return $filesByCollection;
    }

    /**
     * Prove every allocated ordinal is still ours after the binaries resolved.
     *
     * The insert cannot raise for us: BinaryHandler's bulk statement carries
     * ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), and its post-insert
     * fallback resolves by (collections_id, filenumber). Between them, an
     * ordinal stolen by a concurrent writer comes back as THEIR binary id, with
     * no error anywhere. So the check is: the binary we were handed must be the
     * file we asked for.
     *
     * The filenumber itself is deliberately NOT compared -- a pre-existing file
     * legitimately keeps a legacy 0 -- because the property that matters is
     * attribution, not the number.
     *
     * @param  array<int, array{collection_id: int, hash: string, sort: string}>  $requests  keyed by header index
     * @param  array<int, int>  $binaryIds  binary id keyed by header index
     *
     * @throws CollectionFileNumberCollision
     */
    public function assertOrdinalsHeld(array $requests, array $binaryIds): void
    {
        if ($requests === []) {
            return;
        }

        // Same grouping the allocation used, so the identity checked here is the
        // identity BinaryHandler actually wrote. See groupRequests().
        $filesByCollection = $this->groupRequests($requests);

        /** @var array<int, array{collection_id: int, subject: string, hash: string}> $expected */
        $expected = [];
        foreach ($requests as $index => $request) {
            $binaryId = (int) ($binaryIds[$index] ?? 0);
            if ($binaryId <= 0) {
                // No binary at all is already handled by the caller as a failed
                // header; it is not a stolen ordinal.
                continue;
            }

            $collectionId = (int) $request['collection_id'];
            $subject = (string) $request['sort'];
            $hash = $filesByCollection[$collectionId][$subject] ?? strtolower((string) $request['hash']);

            if (isset($expected[$binaryId]) && $expected[$binaryId]['subject'] !== $subject) {
                // Two distinct files sharing one binary is the collision seen
                // from inside this batch, before any read.
                throw CollectionFileNumberCollision::sharedBinary(
                    $binaryId,
                    $expected[$binaryId]['subject'],
                    $subject,
                );
            }

            $expected[$binaryId] = [
                'collection_id' => $collectionId,
                'subject' => $subject,
                'hash' => $hash,
            ];
        }

        if ($expected === []) {
            return;
        }

        $driver = DB::getDriverName();
        $hashExpression = $driver === 'sqlite' ? 'binaryhash' : 'LOWER(HEX(binaryhash))';

        $binaryIdList = array_keys($expected);
        sort($binaryIdList, SORT_NUMERIC);

        foreach (array_chunk($binaryIdList, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));

            // Non-locking read by primary key. This transaction already holds an
            // X lock on every one of these rows -- rows it inserted by way of
            // the insert itself, rows that pre-existed via
            // BinaryHandler::prelockForPartWrites() -- so FOR UPDATE here would
            // buy nothing and only widen the ingest path's lock footprint.
            $rows = DB::select(
                "SELECT id, collections_id, {$hashExpression} AS hashvalue FROM binaries WHERE id IN ({$placeholders})",
                $chunk,
            );

            $seen = [];
            foreach ($rows as $row) {
                $binaryId = (int) $row->id;
                if (! isset($expected[$binaryId])) {
                    continue;
                }

                $seen[$binaryId] = true;

                $actualHash = strtolower((string) $row->hashvalue);
                $actualCollectionId = (int) $row->collections_id;

                if ($actualHash !== $expected[$binaryId]['hash']
                    || $actualCollectionId !== $expected[$binaryId]['collection_id']
                ) {
                    throw CollectionFileNumberCollision::foreignBinary(
                        $binaryId,
                        $expected[$binaryId]['hash'],
                        $actualHash,
                        $expected[$binaryId]['collection_id'],
                        $actualCollectionId,
                    );
                }
            }

            foreach ($chunk as $binaryId) {
                if (! isset($seen[$binaryId])) {
                    throw CollectionFileNumberCollision::missingBinary((int) $binaryId);
                }
            }
        }
    }

    /**
     * Current MAX(filenumber) per collection.
     *
     * A grouped MAX over UNIQUE (collections_id, filenumber) for a bounded id
     * list -- an index range read, never a COUNT(*) over a bulk-loading table.
     *
     * @param  list<int|string>  $collectionIds
     * @return array<int, int>
     */
    private function highWaterMarks(array $collectionIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $collectionIds)));
        sort($ids, SORT_NUMERIC);

        $marks = [];
        foreach (array_chunk($ids, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));
            $rows = DB::select(
                'SELECT collections_id, MAX(filenumber) AS maxfilenumber FROM binaries '
                ."WHERE collections_id IN ({$placeholders}) GROUP BY collections_id",
                $chunk,
            );

            foreach ($rows as $row) {
                $marks[(int) $row->collections_id] = (int) $row->maxfilenumber;
            }
        }

        return $marks;
    }

    /**
     * Ordinals already held by the files of this batch, by binary hash.
     *
     * Grouped per collection and chunked, exactly like
     * BinaryHandler::selectBinaryRowsByHash(), so this cannot degenerate into a
     * thousand-clause OR expression.
     *
     * @param  array<int, array<string, string>>  $filesByCollection  collectionId => subject => hash
     * @return array<int, array<string, int>> collectionId => hash => filenumber
     */
    private function existingFileNumbers(array $filesByCollection): array
    {
        $driver = DB::getDriverName();
        $hashExpression = $driver === 'sqlite' ? 'binaryhash' : 'LOWER(HEX(binaryhash))';
        $hashPlaceholder = $driver === 'sqlite' ? '?' : 'UNHEX(?)';

        $resolved = [];
        foreach ($filesByCollection as $collectionId => $files) {
            $hashes = array_values(array_unique(array_map('strval', array_values($files))));

            foreach (array_chunk($hashes, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
                $placeholders = implode(',', array_fill(0, \count($chunk), $hashPlaceholder));
                $bindings = $chunk;
                $bindings[] = (int) $collectionId;

                $rows = DB::select(
                    "SELECT {$hashExpression} AS hashvalue, filenumber FROM binaries "
                    ."WHERE binaryhash IN ({$placeholders}) AND collections_id = ?",
                    $bindings,
                );

                foreach ($rows as $row) {
                    $resolved[(int) $collectionId][strtolower((string) $row->hashvalue)] = (int) $row->filenumber;
                }
            }
        }

        return $resolved;
    }
}
