<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\Utf8;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles binary record creation and updates during header storage.
 */
final class BinaryHandler
{
    /**
     * Hard upper bound on the number of rows packed into a single SQL
     * statement (multi-row INSERT, OR-clause SELECT, etc.). Keeps the
     * generated SQL string and PDO parameter list bounded regardless of
     * how many headers the caller passes in.
     */
    private const MAX_SQL_ROWS_PER_STATEMENT = 500;

    /**
     * A binary stops taking parts once it holds this many times its declared
     * part count (and at least OVERFLOW_MIN_PARTS). binaryhash is
     * md5(name + poster + group), so every repost of an identical subject
     * folds into one row: on 2026-11-26 one alt.binaries.boneless file
     * declared 250 parts and held 64,235. Every header chunk touching it
     * X-locked that row for the whole ~2.4 s chunk transaction, and 81% of
     * all ingest row-lock waits queued behind it. Such a binary can never
     * become a release, so further parts are skipped, not failed: they do
     * not go to part repair and never take the row lock.
     */
    private const OVERFLOW_PART_RATIO = 4;

    private const OVERFLOW_MIN_PARTS = 1000;

    /** @var array<int, array{Size: int, Parts: int}> Pending binary updates */
    private array $binariesUpdate = [];

    /** @var array<int, true> IDs of binaries created in this batch */
    private array $insertedBinaryIds = [];

    /** @var array<string, array{CollectionID: int, BinaryID: int}> Processed articles */
    private array $articles = [];

    /** @var array<int, true> Existing binaries found over the overflow limit in this batch */
    private array $overflowedBinaryIds = [];

    /** @var array<int, true> Header indexes skipped because their binary overflowed */
    private array $overflowSkippedIndexes = [];

    public function __construct() {}

    /**
     * The binary identity a header resolves to inside its collection.
     *
     * Extracted so CollectionFileNumberAllocator can ask "does this file already
     * exist in this collection, and at which ordinal?" with exactly the identity
     * the insert will use. If the two ever disagree, the allocator hands out an
     * ordinal for a file the insert then matches to a different row -- which is
     * the failure the allocator exists to prevent.
     */
    public static function binaryHash(string $name, string $fromName, int $groupId): string
    {
        return md5($name.$fromName.$groupId);
    }

    /**
     * Reset state for a new batch.
     */
    public function reset(): void
    {
        $this->binariesUpdate = [];
        $this->insertedBinaryIds = [];
        $this->articles = [];
        $this->overflowedBinaryIds = [];
        $this->overflowSkippedIndexes = [];
    }

    /**
     * Header indexes from the last getOrCreateBinaries() call that resolved to
     * an overflowed binary. They are absent from its result on purpose and
     * must be counted as skipped, not failed.
     *
     * @return array<int, true>
     */
    public function getOverflowSkippedIndexes(): array
    {
        return $this->overflowSkippedIndexes;
    }

    /** @return list<int> */
    public function getOverflowedBinaryIds(): array
    {
        return array_keys($this->overflowedBinaryIds);
    }

    public static function isOverflowed(int $currentParts, int $totalParts): bool
    {
        return $totalParts > 0
            && $currentParts >= self::OVERFLOW_MIN_PARTS
            && $currentParts >= $totalParts * self::OVERFLOW_PART_RATIO;
    }

    /** @param list<int> $binaryIds */
    public function prelockForPartWrites(array $binaryIds): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $binaryIds = array_values(array_unique(array_map('intval', $binaryIds)));
        sort($binaryIds, SORT_NUMERIC);

        foreach (array_chunk($binaryIds, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));
            DB::select(
                "SELECT id FROM binaries FORCE INDEX (PRIMARY) WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE",
                $chunk,
            );
        }
    }

    /**
     * Get or create a binary for the given header.
     *
     * @param  array<string, mixed>  $header
     * @return int|null Binary ID or null on failure
     */
    public function getOrCreateBinary(
        array $header,
        int $collectionId,
        int $groupId,
        int $fileNumber
    ): ?int {
        $articleKey = $this->articleKey($collectionId, $header['matches'][1]);

        // Return cached if already processed
        if (isset($this->articles[$articleKey])) {
            $binaryId = $this->articles[$articleKey]['BinaryID'];
            $this->binariesUpdate[$binaryId]['Size'] += $header['Bytes'];
            $this->binariesUpdate[$binaryId]['Parts']++;

            return $binaryId;
        }

        $hash = self::binaryHash(
            (string) ($header['matches'][1] ?? ''),
            (string) ($header['From'] ?? ''),
            $groupId
        );
        $driver = DB::getDriverName();

        try {
            $binaryId = $this->insertOrGetBinary(
                $driver,
                $hash,
                $header,
                $collectionId,
                $fileNumber
            );

            if ($binaryId > 0) {
                $this->binariesUpdate[$binaryId] = ['Size' => 0, 'Parts' => 0];
                $this->articles[$articleKey] = [
                    'CollectionID' => $collectionId,
                    'BinaryID' => $binaryId,
                ];

                return $binaryId;
            }
        } catch (Throwable $e) {
            Log::error('Binary insert failed', [
                'driver' => DB::getDriverName(),
                'collection_id' => $collectionId,
                'group_id' => $groupId,
                'hash' => $hash,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Resolve binaries for a chunk of headers with one bulk insert and one id lookup.
     *
     * @param  array<int, array{header: array<string, mixed>, collection_id: int, file_number: int}>  $records
     * @return array<int, int> Binary ids keyed by header index
     */
    public function getOrCreateBinaries(array $records, int $groupId): array
    {
        $this->overflowSkippedIndexes = [];
        $resolved = [];
        $pending = [];
        $indexesByArticleKey = [];
        $extraUpdatesByArticleKey = [];

        foreach ($records as $index => $record) {
            $header = $record['header'];
            $collectionId = (int) $record['collection_id'];
            $fileNumber = (int) $record['file_number'];
            $articleKey = $this->articleKey($collectionId, $header['matches'][1]);

            if (isset($this->articles[$articleKey])) {
                $binaryId = $this->articles[$articleKey]['BinaryID'];
                $this->binariesUpdate[$binaryId]['Size'] += (int) $header['Bytes'];
                $this->binariesUpdate[$binaryId]['Parts']++;
                $resolved[$index] = $binaryId;

                continue;
            }

            $indexesByArticleKey[$articleKey][] = $index;
            if (isset($pending[$articleKey])) {
                $extraUpdatesByArticleKey[$articleKey][] = [
                    'number' => (int) $header['Number'],
                    'partsize' => (int) $header['Bytes'],
                ];

                continue;
            }

            $hash = self::binaryHash(
                (string) ($header['matches'][1] ?? ''),
                (string) ($header['From'] ?? ''),
                $groupId
            );
            $pending[$articleKey] = [
                'hash' => $hash,
                'name' => Utf8::clean($header['matches'][1]),
                'collections_id' => $collectionId,
                'totalparts' => (int) $header['matches'][3],
                'filenumber' => $fileNumber,
                'partsize' => (int) $header['Bytes'],
                'number' => (int) $header['Number'],
            ];
        }

        if ($pending === []) {
            return $resolved;
        }

        $this->sortBinaryInsertRows($pending);

        try {
            $result = $this->bulkInsertAndResolve($pending);
            $idsByKey = $result['ids'];
            // Overflowed binaries get no part writes, so their part keys need no lookup.
            $existingPartKeys = $this->existingPartKeysForResolvedRows($pending, $this->withoutOverflowed($idsByKey), $extraUpdatesByArticleKey);
            $seenPartKeys = [];
            foreach ($pending as $articleKey => $row) {
                $hashLookupKey = $this->binaryHashLookupKey((string) $row['hash'], (int) $row['collections_id']);
                $fileLookupKey = $this->binaryFileLookupKey((int) $row['collections_id'], (int) $row['filenumber']);
                $binaryId = $idsByKey[$hashLookupKey] ?? $idsByKey[$fileLookupKey] ?? 0;
                if ($binaryId <= 0) {
                    continue;
                }

                if (isset($this->overflowedBinaryIds[$binaryId])) {
                    foreach ($indexesByArticleKey[$articleKey] ?? [] as $index) {
                        $this->overflowSkippedIndexes[$index] = true;
                    }

                    continue;
                }

                $this->binariesUpdate[$binaryId] = $this->binariesUpdate[$binaryId] ?? ['Size' => 0, 'Parts' => 0];
                $this->addBinaryUpdateForNewPart(
                    $binaryId,
                    (int) $row['number'],
                    (int) $row['partsize'],
                    $existingPartKeys,
                    $seenPartKeys
                );
                foreach ($extraUpdatesByArticleKey[$articleKey] ?? [] as $extraPart) {
                    $this->addBinaryUpdateForNewPart(
                        $binaryId,
                        (int) $extraPart['number'],
                        (int) $extraPart['partsize'],
                        $existingPartKeys,
                        $seenPartKeys
                    );
                }

                $this->articles[$articleKey] = [
                    'CollectionID' => (int) $row['collections_id'],
                    'BinaryID' => $binaryId,
                ];

                foreach ($indexesByArticleKey[$articleKey] ?? [] as $index) {
                    $resolved[$index] = $binaryId;
                }
            }
        } catch (Throwable $e) {
            if (TransientHeaderStorageFailure::is($e)) {
                throw $e;
            }

            Log::error('Bulk binary insert failed', [
                'driver' => DB::getDriverName(),
                'group_id' => $groupId,
                'pending' => \count($pending),
                'sample_hashes' => array_slice(array_column($pending, 'hash'), 0, 5),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $resolved;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByArticleKey
     * @return array{ids: array<string, int>, existing: array<string, true>}
     */
    private function bulkInsertAndResolve(array $rowsByArticleKey): array
    {
        $lookupRows = array_values($rowsByArticleKey);

        // One SELECT establishes both "did this row exist?" and "what is its
        // id?", instead of two identical SELECTs (existingBinaryKeys +
        // resolveBinaryIds) that the previous implementation issued.
        $idsByKey = $this->selectBinaryIdsByHash($lookupRows);
        $idsByFileKeyBeforeInsert = $this->selectBinaryIdsByFileNumber($lookupRows);
        $existingKeys = [];
        foreach ($idsByKey as $key => $_id) {
            $existingKeys[$key] = true;
        }
        foreach ($idsByFileKeyBeforeInsert as $key => $id) {
            $idsByKey[$key] = $id;
            $existingKeys[$key] = true;
        }

        $insertRows = [];
        foreach ($lookupRows as $row) {
            $hashKey = $this->binaryHashLookupKey((string) $row['hash'], (int) $row['collections_id']);
            $fileKey = $this->binaryFileLookupKey((int) $row['collections_id'], (int) $row['filenumber']);
            if (! isset($idsByKey[$hashKey]) && ! isset($idsByKey[$fileKey])) {
                $insertRows[] = $row;
            }
        }

        $this->sortBinaryInsertRows($insertRows);
        // Overflowed rows get no update and no parts, so they are never locked.
        $this->prelockForPartWrites(array_values($this->withoutOverflowed($idsByKey)));

        if (DB::getDriverName() === 'sqlite') {
            $this->bulkInsertBinariesSqlite($insertRows);
        } else {
            $this->bulkInsertBinariesMysql($insertRows);
        }

        // Only re-query for rows we couldn't satisfy from the prefetch
        // (i.e. those that didn't exist before the bulk INSERT).
        $missingRows = [];
        foreach ($insertRows as $row) {
            $hashKey = $this->binaryHashLookupKey((string) $row['hash'], (int) $row['collections_id']);
            $fileKey = $this->binaryFileLookupKey((int) $row['collections_id'], (int) $row['filenumber']);
            if (! isset($idsByKey[$hashKey]) && ! isset($idsByKey[$fileKey])) {
                $missingRows[] = $row;
            }
        }

        if ($missingRows !== []) {
            foreach ($this->selectBinaryIdsByFileNumber($missingRows) as $key => $id) {
                $idsByKey[$key] = $id;
            }
        }

        foreach ($idsByKey as $key => $id) {
            if (! isset($existingKeys[$key])) {
                $this->insertedBinaryIds[$id] = true;
            }
        }

        return ['ids' => $idsByKey, 'existing' => $existingKeys];
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    private function sortBinaryInsertRows(array &$rows): void
    {
        uasort($rows, static function (array $left, array $right): int {
            return ((int) $left['collections_id'] <=> (int) $right['collections_id'])
                ?: ((int) $left['filenumber'] <=> (int) $right['filenumber'])
                ?: strcmp((string) $left['hash'], (string) $right['hash']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int> id keyed by binaryHashLookupKey(hash, collectionId)
     */
    private function selectBinaryIdsByHash(array $rows): array
    {
        $resolved = [];
        foreach ($this->selectBinaryRowsByHash($rows) as $row) {
            $this->noteOverflow($row);
            $resolved[$this->binaryHashLookupKey((string) $row->hashvalue, (int) $row->collections_id)] = (int) $row->id;
        }

        return $resolved;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int> id keyed by binaryFileLookupKey(collectionId, fileNumber)
     */
    private function selectBinaryIdsByFileNumber(array $rows): array
    {
        $resolved = [];
        foreach ($this->selectBinaryRowsByFileNumber($rows) as $row) {
            $resolved[$this->binaryFileLookupKey((int) $row->collections_id, (int) $row->filenumber)] = (int) $row->id;
        }

        return $resolved;
    }

    /**
     * @param  array<string, int>  $idsByKey
     * @return array<string, int>
     */
    private function withoutOverflowed(array $idsByKey): array
    {
        return array_filter($idsByKey, fn (int $id): bool => ! isset($this->overflowedBinaryIds[$id]));
    }

    /**
     * Only an identity (hash) match can mark a binary overflowed: a file-number
     * match may be a different file sharing the ordinal, and skipping it would
     * hide the collision CollectionFileNumberAllocator::assertOrdinalsHeld()
     * exists to raise.
     */
    private function noteOverflow(object $row): void
    {
        if (self::isOverflowed((int) ($row->currentparts ?? 0), (int) ($row->totalparts ?? 0))) {
            $this->overflowedBinaryIds[(int) $row->id] = true;
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function bulkInsertBinariesSqlite(array $rows): void
    {
        foreach (array_chunk($rows, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $insertRows = [];
            foreach ($chunk as $row) {
                $insertRows[] = [
                    'binaryhash' => $row['hash'],
                    'name' => $row['name'],
                    'collections_id' => $row['collections_id'],
                    'totalparts' => $row['totalparts'],
                    'currentparts' => 0,
                    'filenumber' => $row['filenumber'],
                    'partsize' => 0,
                ];
            }

            DB::table('binaries')->insertOrIgnore($insertRows);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function bulkInsertBinariesMysql(array $rows): void
    {
        foreach (array_chunk($rows, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(UNHEX(?), ?, ?, ?, 0, ?, 0)';
                array_push(
                    $bindings,
                    $row['hash'],
                    $row['name'],
                    $row['collections_id'],
                    $row['totalparts'],
                    $row['filenumber']
                );
            }

            $this->runBinaryWriteWithRetry(fn (): bool => DB::statement(
                'INSERT INTO binaries (binaryhash, name, collections_id, totalparts, currentparts, filenumber, partsize) VALUES '
                .implode(',', $placeholders)
                .' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                $bindings
            ));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<object>
     */
    private function selectBinaryRowsByHash(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $driver = DB::getDriverName();
        $hashExpression = $driver === 'sqlite' ? 'binaryhash' : 'LOWER(HEX(binaryhash))';

        $hashesByCollection = [];
        foreach ($rows as $row) {
            $hashesByCollection[(int) $row['collections_id']][] = (string) $row['hash'];
        }

        return $this->selectBinariesPerCollection(
            "id, {$hashExpression} AS hashvalue, collections_id, currentparts, totalparts",
            'binaryhash',
            $driver === 'sqlite' ? '?' : 'UNHEX(?)',
            $hashesByCollection
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<object>
     */
    private function selectBinaryRowsByFileNumber(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $fileNumbersByCollection = [];
        foreach ($rows as $row) {
            $fileNumbersByCollection[(int) $row['collections_id']][] = (int) $row['filenumber'];
        }

        return $this->selectBinariesPerCollection(
            'id, filenumber, collections_id',
            'filenumber',
            '?',
            $fileNumbersByCollection
        );
    }

    /**
     * Non-locking lookup of binaries by `{$column} IN (...) AND collections_id = ?`.
     *
     * Each collection keeps its own branch with exactly that predicate, so
     * every branch uses the same (collections_id, ...) index access as a
     * standalone per-collection query and no thousand-clause OR expression is
     * ever produced. The branches are joined with UNION ALL and packed up to
     * MAX_SQL_ROWS_PER_STATEMENT values per statement: a header chunk that
     * touches many collections with one file each used to cost one round
     * trip per collection, it now costs one per 500 values.
     *
     * Plain SELECT, no FOR UPDATE: it takes no locks, so packing the branches
     * changes neither the lock set nor the order prelockForPartWrites() locks
     * in. Results are only merged into maps keyed by collection, so row order
     * across branches is irrelevant.
     *
     * @param  array<int, list<int|string>>  $valuesByCollection
     * @return list<object>
     */
    private function selectBinariesPerCollection(
        string $columns,
        string $column,
        string $valuePlaceholder,
        array $valuesByCollection
    ): array {
        $statements = [];
        $branches = [];
        $bindings = [];
        $valueCount = 0;

        foreach ($valuesByCollection as $collectionId => $values) {
            $values = array_values(array_unique($values));
            while ($values !== []) {
                $take = array_splice($values, 0, self::MAX_SQL_ROWS_PER_STATEMENT - $valueCount);
                $placeholders = implode(',', array_fill(0, \count($take), $valuePlaceholder));
                $branches[] = "SELECT {$columns} FROM binaries WHERE {$column} IN ({$placeholders}) AND collections_id = ?";
                array_push($bindings, ...$take);
                $bindings[] = $collectionId;
                $valueCount += \count($take);

                if ($valueCount >= self::MAX_SQL_ROWS_PER_STATEMENT) {
                    $statements[] = [$branches, $bindings];
                    $branches = [];
                    $bindings = [];
                    $valueCount = 0;
                }
            }
        }

        if ($branches !== []) {
            $statements[] = [$branches, $bindings];
        }

        $results = [];
        foreach ($statements as [$statementBranches, $statementBindings]) {
            foreach (DB::select(implode(' UNION ALL ', $statementBranches), $statementBindings) as $row) {
                $results[] = $row;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByArticleKey
     * @param  array<string, int>  $idsByKey
     * @param  array<string, list<array{number: int, partsize: int}>>  $extraPartsByArticleKey
     * @return array<string, true>
     */
    private function existingPartKeysForResolvedRows(array $rowsByArticleKey, array $idsByKey, array $extraPartsByArticleKey = []): array
    {
        $pairs = [];
        foreach ($rowsByArticleKey as $articleKey => $row) {
            $hashLookupKey = $this->binaryHashLookupKey((string) $row['hash'], (int) $row['collections_id']);
            $fileLookupKey = $this->binaryFileLookupKey((int) $row['collections_id'], (int) $row['filenumber']);
            $binaryId = $idsByKey[$hashLookupKey] ?? $idsByKey[$fileLookupKey] ?? 0;
            if ($binaryId > 0) {
                $pairs[$this->partKey($binaryId, (int) $row['number'])] = [$binaryId, (int) $row['number']];
                foreach ($extraPartsByArticleKey[$articleKey] ?? [] as $extraPart) {
                    $pairs[$this->partKey($binaryId, (int) $extraPart['number'])] = [$binaryId, (int) $extraPart['number']];
                }
            }
        }

        if ($pairs === []) {
            return [];
        }

        $existing = [];
        foreach (array_chunk(array_values($pairs), self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $tuples = implode(',', array_fill(0, \count($chunk), '(?,?)'));
            $bindings = [];
            foreach ($chunk as [$binaryId, $number]) {
                $bindings[] = $binaryId;
                $bindings[] = $number;
            }

            // Header storage uses READ COMMITTED and holds each parent binary's
            // X lock before this current read. A locking read for absent part
            // keys would add next-key/gap locks that later part inserts must
            // convert, creating PRIMARY-supremum deadlocks across transactions.
            $rows = DB::select("SELECT binaries_id, number FROM parts WHERE (binaries_id, number) IN ({$tuples})", $bindings);
            foreach ($rows as $row) {
                $existing[$this->partKey((int) $row->binaries_id, (int) $row->number)] = true;
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, true>  $existingPartKeys
     * @param  array<string, true>  $seenPartKeys
     */
    private function addBinaryUpdateForNewPart(
        int $binaryId,
        int $number,
        int $partsize,
        array $existingPartKeys,
        array &$seenPartKeys
    ): void {
        $partKey = $this->partKey($binaryId, $number);
        if (isset($existingPartKeys[$partKey]) || isset($seenPartKeys[$partKey])) {
            return;
        }

        $seenPartKeys[$partKey] = true;

        $this->binariesUpdate[$binaryId]['Size'] += $partsize;
        $this->binariesUpdate[$binaryId]['Parts']++;
    }

    private function binaryHashLookupKey(string $hash, int $collectionId): string
    {
        return strtolower($hash).':hash:'.$collectionId;
    }

    private function binaryFileLookupKey(int $collectionId, int $fileNumber): string
    {
        return $collectionId.':file:'.$fileNumber;
    }

    private function partKey(int $binaryId, int $number): string
    {
        return $binaryId.':'.$number;
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function insertOrGetBinary(
        string $driver,
        string $hash,
        array $header,
        int $collectionId,
        int $fileNumber
    ): int {
        $name = Utf8::clean($header['matches'][1]);
        $totalParts = (int) $header['matches'][3];
        $partSize = (int) $header['Bytes'];

        if ($driver === 'sqlite') {
            return $this->insertBinarySqlite($hash, $name, $collectionId, $totalParts, $fileNumber, $partSize);
        }

        return $this->insertBinaryMysql($hash, $name, $collectionId, $totalParts, $fileNumber, $partSize);
    }

    private function insertBinarySqlite(
        string $hash,
        string $name,
        int $collectionId,
        int $totalParts,
        int $fileNumber,
        int $partSize
    ): int {
        $affected = DB::table('binaries')->insertOrIgnore([
            'binaryhash' => $hash,
            'name' => $name,
            'collections_id' => $collectionId,
            'totalparts' => $totalParts,
            'currentparts' => 1,
            'filenumber' => $fileNumber,
            'partsize' => $partSize,
        ]);

        if ($affected > 0 && ($lastId = (int) DB::connection()->getPdo()->lastInsertId()) > 0) {
            $this->insertedBinaryIds[$lastId] = true;

            return $lastId;
        }

        $bin = DB::selectOne(
            'SELECT id FROM binaries WHERE collections_id = ? AND filenumber = ? LIMIT 1',
            [$collectionId, $fileNumber]
        );

        return (int) ($bin->id ?? 0);
    }

    private function insertBinaryMysql(
        string $hash,
        string $name,
        int $collectionId,
        int $totalParts,
        int $fileNumber,
        int $partSize
    ): int {

        $sql = 'INSERT INTO binaries '
            .'(binaryhash, name, collections_id, totalparts, currentparts, filenumber, partsize) '
            .'VALUES (UNHEX(?), ?, ?, ?, 1, ?, ?) '
            .'ON DUPLICATE KEY UPDATE currentparts = currentparts + 1, partsize = partsize + VALUES(partsize)';

        $this->runBinaryWriteWithRetry(
            fn (): bool => DB::statement($sql, [$hash, $name, $collectionId, $totalParts, $fileNumber, $partSize])
        );

        $lastId = (int) DB::connection()->getPdo()->lastInsertId();
        if ($lastId > 0) {
            $this->insertedBinaryIds[$lastId] = true;

            return $lastId;
        }

        $bin = DB::selectOne(
            'SELECT id FROM binaries WHERE collections_id = ? AND filenumber = ? LIMIT 1',
            [$collectionId, $fileNumber]
        );

        return (int) ($bin->id ?? 0);
    }

    /**
     * Flush accumulated size/parts updates to the database.
     */
    public function flushUpdates(int $chunkSize = 1000): bool
    {
        $updates = $this->getPendingUpdates();
        if (empty($updates)) {
            return true;
        }

        $driver = DB::getDriverName();

        try {
            if ($driver === 'sqlite') {
                return $this->flushUpdatesSqlite($updates); // @phpstan-ignore argument.type
            }

            return $this->flushUpdatesMysql($updates, $chunkSize); // @phpstan-ignore argument.type
        } catch (Throwable $e) {
            if (TransientHeaderStorageFailure::is($e)) {
                throw $e;
            }

            Log::error('Binaries aggregate update failed', [
                'driver' => $driver,
                'updates' => \count($updates),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function flushUpdatesSqlite(array $updates): bool
    {
        foreach ($updates as $row) {
            DB::statement(
                'UPDATE binaries SET partsize = partsize + ?, currentparts = currentparts + ? WHERE id = ?',
                [$row['partsize'], $row['currentparts'], $row['id']]
            );
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function flushUpdatesMysql(array $updates, int $chunkSize): bool
    {
        // Clamp the caller-provided chunk size to our hard upper bound so an
        // unusually large config value cannot produce a 100 KB+ UNION ALL
        // query that exhausts memory on either side of the connection.
        $chunkSize = max(1, min($chunkSize, self::MAX_SQL_ROWS_PER_STATEMENT));

        foreach (array_chunk($updates, $chunkSize) as $chunk) {
            $selects = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $selects[] = 'SELECT ? AS id, ? AS partsize, ? AS currentparts';
                $bindings[] = $row['id'];
                $bindings[] = $row['partsize'];
                $bindings[] = $row['currentparts'];
            }

            $sql = 'UPDATE binaries b INNER JOIN ('
                .implode(' UNION ALL ', $selects)
                .') u ON u.id = b.id '
                .'SET b.partsize = b.partsize + u.partsize, '
                .'b.currentparts = b.currentparts + u.currentparts';

            $this->runBinaryWriteWithRetry(fn (): bool => DB::statement($sql, $bindings));
        }

        return true;
    }

    private function runBinaryWriteWithRetry(callable $write): mixed
    {
        $attempts = 0;

        while (true) {
            try {
                return $write();
            } catch (Throwable $e) {
                $attempts++;
                if ($attempts >= 3 || ! TransientHeaderStorageFailure::canRetryStatement($e)) {
                    throw $e;
                }

                usleep(25_000 * $attempts);
            }
        }
    }

    /**
     * Check if article is already processed.
     */
    public function hasArticle(string $articleKey): bool
    {
        return isset($this->articles[$articleKey]);
    }

    private function articleKey(int $collectionId, string $name): string
    {
        return $collectionId.':'.$name;
    }

    /**
     * Get IDs created in this batch.
     *
     * @return list<int>
     */
    public function getInsertedIds(): array
    {
        return array_keys($this->insertedBinaryIds);
    }

    /**
     * Get pending binary updates that haven't been flushed.
     *
     * @return list<array<string, int>>
     */
    private function getPendingUpdates(): array
    {
        $rows = [];
        foreach ($this->binariesUpdate as $binaryId => $binary) {
            if (($binary['Size'] ?? 0) > 0 || ($binary['Parts'] ?? 0) > 0) {
                $rows[] = [
                    'id' => $binaryId,
                    'partsize' => $binary['Size'],
                    'currentparts' => $binary['Parts'],
                ];
            }
        }

        return $rows;
    }
}
