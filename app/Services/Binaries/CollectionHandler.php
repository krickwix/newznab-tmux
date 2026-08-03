<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Models\Collection;
use App\Services\CollectionsCleaningService;
use App\Services\XrefService;
use App\Support\Utf8;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles collection record creation and retrieval during header storage.
 */
final class CollectionHandler
{
    /**
     * Hard upper bound on rows packed into a single SQL statement
     * (multi-row INSERT, IN(...) lookup, etc.). Keeps the generated SQL
     * and PDO parameter count bounded regardless of caller batch size.
     */
    private const MAX_SQL_ROWS_PER_STATEMENT = 500;

    private CollectionsCleaningService $collectionsCleaning;

    private XrefService $xrefService;

    private ObfuscatedHashSetNormalizer $hashSetNormalizer;

    /** @var array<string, bool> Cached per-group applicability of hash-set cohort keying */
    private array $hashSetGroupApplies = [];

    /** @var array<string, int> Cached collection IDs by key */
    private array $collectionIds = [];

    /** @var array<int, true> IDs of collections created in this batch */
    private array $insertedCollectionIds = [];

    /** @var array<string, true> Collection hashes touched in this batch */
    private array $batchCollectionHashes = [];

    /** @var array<string, string|null> Cached collection xrefs by collection key */
    private array $existingXrefs = [];

    /** @var array<string, int> Cached collection IDs by collectionhash (populated by bulk prefetch) */
    private array $existingIdsByHash = [];

    public function __construct(
        ?CollectionsCleaningService $collectionsCleaning = null,
        ?XrefService $xrefService = null,
        ?ObfuscatedHashSetNormalizer $hashSetNormalizer = null
    ) {
        $this->collectionsCleaning = $collectionsCleaning ?? new CollectionsCleaningService;
        $this->xrefService = $xrefService ?? new XrefService;
        $this->hashSetNormalizer = $hashSetNormalizer ?? new ObfuscatedHashSetNormalizer;
    }

    /**
     * Resolve the identity used to key a collection.
     *
     * Hash-set posts name every file by its own SHA-1, so the cleaned subject
     * name is unique per file and cannot group the set. For those we substitute
     * a cohort identity shared by every file of the post. All other subjects
     * keep the existing cleaned-name behaviour untouched.
     *
     * Brace-token posts pull in the opposite direction: HeaderParser has
     * already stripped their per-article token, and the cleaned name that
     * remains is TOO coarse -- the cleaner strips digit runs, so part01..partNN
     * and every par2 volume of a posting share one cleaned name. Keying on that
     * would fuse distinct files into one collection, and because these subjects
     * pin file_number to 1/1, into one binary as well. They are therefore keyed
     * on the de-tokenised filename.
     *
     * @param  array{name: string, id: int|string}  $collMatch
     */
    private function collectionIdentity(
        array $collMatch,
        string $parsedSubject,
        string $groupName,
        int $groupId,
        int $totalFiles,
        int $articleUnixtime,
        bool $isBraceToken = false
    ): string {
        if ($isBraceToken) {
            return ObfuscatedSubjectNormalizer::collectionKey($parsedSubject, $groupId);
        }

        if (! isset($this->hashSetGroupApplies[$groupName])) {
            $this->hashSetGroupApplies[$groupName] = $this->hashSetNormalizer->appliesTo($groupName);
        }

        if ($this->hashSetGroupApplies[$groupName]) {
            $cohort = $this->hashSetNormalizer->normalize($parsedSubject, $groupId, $articleUnixtime);
            if ($cohort !== null) {
                return $cohort['name'];
            }
        }

        return $collMatch['name'].$totalFiles;
    }

    /**
     * Reset state for a new batch.
     */
    public function reset(): void
    {
        $this->collectionIds = [];
        $this->insertedCollectionIds = [];
        $this->batchCollectionHashes = [];
        $this->existingXrefs = [];
        $this->existingIdsByHash = [];
    }

    /**
     * Get or create a collection for the given header.
     *
     * @param  array<string, mixed>  $header
     * @return int|null Collection ID or null on failure
     */
    public function getOrCreateCollection(
        array $header,
        int $groupId,
        string $groupName,
        int $totalFiles,
        string $batchNoise
    ): ?int {
        $collMatch = $this->collectionsCleaning->collectionsCleaner(
            $header['matches'][1],
            $groupName
        );

        $headerDate = is_numeric($header['Date']) ? (int) $header['Date'] : strtotime($header['Date']);
        $now = now()->timestamp;
        $unixtime = min($headerDate, $now) ?: $now;

        $collectionKey = $this->collectionIdentity(
            $collMatch,
            (string) $header['matches'][1],
            $groupName,
            $groupId,
            $totalFiles,
            $unixtime,
            (bool) ($header['collection_brace_token'] ?? false)
        );

        // Return cached ID if already processed this batch
        if (isset($this->collectionIds[$collectionKey])) {
            return $this->collectionIds[$collectionKey];
        }

        $collectionHash = sha1($collectionKey);
        $this->batchCollectionHashes[$collectionHash] = true;

        if (! array_key_exists($collectionKey, $this->existingXrefs)) {
            $this->existingXrefs[$collectionKey] = Collection::whereCollectionhash($collectionHash)->value('xref');
        }
        $existingXref = $this->existingXrefs[$collectionKey];
        $headerTokens = $this->xrefService->extractTokens($header['Xref'] ?? '');
        $newTokens = $this->xrefService->diffNewTokens($existingXref, $header['Xref'] ?? '');
        $finalXrefAppend = implode(' ', $newTokens);

        $subject = substr(Utf8::clean($header['matches'][1]), 0, 255);
        $fromName = Utf8::clean($header['From']);

        $driver = DB::getDriverName();

        try {
            $collectionId = $this->insertOrGetCollection(
                $driver,
                $subject,
                $fromName,
                $unixtime,
                $headerTokens,
                $finalXrefAppend,
                $groupId,
                $totalFiles,
                $collectionHash,
                $collMatch['id'],
                $batchNoise
            );

            if ($collectionId > 0) {
                $this->collectionIds[$collectionKey] = $collectionId;

                return $collectionId;
            }
        } catch (Throwable $e) {
            Log::error('Collection insert failed', [
                'driver' => DB::getDriverName(),
                'group_id' => $groupId,
                'subject' => $subject,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Resolve collections for a chunk of headers with one bulk insert and one id lookup.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<int, int>  $totalFilesByIndex
     * @return array<int, int> Collection ids keyed by header index
     */
    public function getOrCreateCollections(
        array $headers,
        int $groupId,
        string $groupName,
        array $totalFilesByIndex,
        string $batchNoise
    ): array {
        $resolved = [];
        $pending = [];
        $indexByCollectionKey = [];
        $xrefsToPrefetch = [];
        $cleanedBySubject = [];

        foreach ($headers as $index => $header) {
            $totalFiles = (int) ($totalFilesByIndex[$index] ?? 0);
            $subject = (string) $header['matches'][1];
            if (! isset($cleanedBySubject[$subject])) {
                $cleanedBySubject[$subject] = $this->collectionsCleaning->collectionsCleaner(
                    $subject,
                    $groupName
                );
            }
            $collMatch = $cleanedBySubject[$subject];

            $headerDate = is_numeric($header['Date']) ? (int) $header['Date'] : strtotime($header['Date']);
            $now = now()->timestamp;
            $unixtime = min($headerDate, $now) ?: $now;

            $collectionKey = $this->collectionIdentity(
                $collMatch,
                $subject,
                $groupName,
                $groupId,
                $totalFiles,
                $unixtime,
                (bool) ($header['collection_brace_token'] ?? false)
            );
            if (isset($this->collectionIds[$collectionKey])) {
                $resolved[$index] = $this->collectionIds[$collectionKey];

                continue;
            }

            $indexByCollectionKey[$collectionKey][] = $index;
            if (isset($pending[$collectionKey])) {
                continue;
            }

            $collectionHash = sha1($collectionKey);
            $this->batchCollectionHashes[$collectionHash] = true;

            $xrefsToPrefetch[$collectionKey] = $collectionHash;

            $headerTokens = $this->xrefService->extractTokens($header['Xref'] ?? '');

            $pending[$collectionKey] = [
                'subject' => substr(Utf8::clean($header['matches'][1]), 0, 255),
                'fromname' => Utf8::clean($header['From']),
                'unixtime' => $unixtime,
                'xref' => implode(' ', $headerTokens),
                'header_xref' => $header['Xref'] ?? '',
                'groups_id' => $groupId,
                'totalfiles' => $totalFiles,
                'collectionhash' => $collectionHash,
                'collection_regexes_id' => (int) $collMatch['id'],
                'noise' => $batchNoise,
            ];
        }

        if ($pending === []) {
            return $resolved;
        }

        $this->prefetchExistingCollections($xrefsToPrefetch);

        foreach ($pending as $collectionKey => &$row) {
            $row['xref_append'] = implode(' ', $this->xrefService->diffNewTokens(
                $this->existingXrefs[$collectionKey],
                $row['header_xref']
            ));
            unset($row['header_xref']);
        }
        unset($row);

        try {
            $idsByHash = $this->bulkInsertAndResolve($pending);
            foreach ($pending as $collectionKey => $row) {
                $collectionId = $idsByHash[$row['collectionhash']] ?? 0;
                if ($collectionId <= 0) {
                    continue;
                }

                $this->collectionIds[$collectionKey] = $collectionId;
                foreach ($indexByCollectionKey[$collectionKey] ?? [] as $index) {
                    $resolved[$index] = $collectionId;
                }
            }
        } catch (Throwable $e) {
            if (TransientHeaderStorageFailure::is($e)) {
                throw $e;
            }

            Log::error('Bulk collection insert failed', [
                'driver' => DB::getDriverName(),
                'group_id' => $groupId,
                'pending' => \count($pending),
                'sample_hashes' => array_slice(array_column($pending, 'collectionhash'), 0, 5),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $resolved;
    }

    /**
     * Prefetch existing collections in one round-trip. Populates both
     * $existingXrefs (keyed by collectionKey) and $existingIdsByHash so the
     * subsequent bulkInsertAndResolve() can skip its existence-check SELECT
     * and only re-query for the freshly inserted rows.
     *
     * @param  array<string, string>  $collectionHashByKey
     */
    private function prefetchExistingCollections(array $collectionHashByKey): void
    {
        $missing = array_filter(
            $collectionHashByKey,
            fn (string $hash, string $collectionKey): bool => ! array_key_exists($collectionKey, $this->existingXrefs),
            ARRAY_FILTER_USE_BOTH
        );

        if ($missing === []) {
            return;
        }

        $rows = [];
        foreach (array_chunk(array_values($missing), self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            foreach (Collection::query()
                ->whereIn('collectionhash', $chunk)
                ->select(['id', 'collectionhash', 'xref'])
                ->get() as $row) {
                $rows[(string) $row->collectionhash] = [
                    'id' => (int) $row->id,
                    'xref' => (string) $row->xref,
                ];
            }
        }

        foreach ($missing as $collectionKey => $hash) {
            if (isset($rows[$hash])) {
                $this->existingXrefs[$collectionKey] = $rows[$hash]['xref'];
                $this->existingIdsByHash[$hash] = $rows[$hash]['id'];
            } else {
                $this->existingXrefs[$collectionKey] = null;
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByCollectionKey
     * @return array<string, int> Collection ids keyed by collectionhash
     */
    private function bulkInsertAndResolve(array $rowsByCollectionKey): array
    {
        $hashes = array_values(array_column($rowsByCollectionKey, 'collectionhash'));

        // The prefetch step has already populated $existingIdsByHash, so we
        // know which hashes existed before the INSERT without issuing a
        // separate "existingHashes" SELECT.
        $existingHashes = [];
        $idsByHash = [];
        foreach ($hashes as $hash) {
            if (isset($this->existingIdsByHash[$hash])) {
                $existingHashes[$hash] = true;
                $idsByHash[$hash] = $this->existingIdsByHash[$hash];
            }
        }

        $newRowsByCollectionKey = array_filter(
            $rowsByCollectionKey,
            static fn (array $row): bool => ! isset($existingHashes[$row['collectionhash']])
        );

        if (DB::getDriverName() === 'sqlite') {
            $this->bulkInsertCollectionsSqlite($newRowsByCollectionKey);
        } else {
            $this->bulkInsertCollectionsMysql($newRowsByCollectionKey, $existingHashes, $rowsByCollectionKey);
        }

        // Only resolve ids for the hashes we couldn't satisfy from the
        // prefetch cache (i.e. the freshly inserted rows). For chunks where
        // every collection already existed this issues zero extra SELECTs.
        $newHashes = array_values(array_diff(array_unique($hashes), array_keys($idsByHash)));
        if ($newHashes !== []) {
            foreach ($this->resolveIdsByHash($newHashes) as $hash => $id) {
                $idsByHash[$hash] = $id;
                $this->existingIdsByHash[$hash] = $id;
            }
        }

        foreach ($idsByHash as $hash => $id) {
            if (! isset($existingHashes[$hash])) {
                $this->insertedCollectionIds[$id] = true;
            }
        }

        return $idsByHash;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByCollectionKey
     */
    private function bulkInsertCollectionsSqlite(array $rowsByCollectionKey): void
    {
        $rows = [];
        foreach ($rowsByCollectionKey as $row) {
            $rows[] = [
                'subject' => $row['subject'],
                'fromname' => $row['fromname'],
                'date' => date('Y-m-d H:i:s', (int) $row['unixtime']),
                'xref' => $row['xref'],
                'groups_id' => $row['groups_id'],
                'totalfiles' => $row['totalfiles'],
                'collectionhash' => $row['collectionhash'],
                'collection_regexes_id' => $row['collection_regexes_id'],
                'dateadded' => now(),
                'noise' => $row['noise'],
            ];
        }

        foreach (array_chunk($rows, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            DB::table('collections')->insertOrIgnore($chunk);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByCollectionKey
     * @param  array<string, true>  $existingHashes
     * @param  array<string, array<string, mixed>>|null  $xrefRowsByCollectionKey
     */
    private function bulkInsertCollectionsMysql(array $rowsByCollectionKey, array $existingHashes, ?array $xrefRowsByCollectionKey = null): void
    {
        $xrefUpdates = $this->prepareXrefUpdates($xrefRowsByCollectionKey ?? $rowsByCollectionKey, $existingHashes);
        $existingIds = [];
        foreach (array_keys($existingHashes) as $hash) {
            if (isset($this->existingIdsByHash[$hash])) {
                $existingIds[] = $this->existingIdsByHash[$hash];
            }
        }
        $xrefUpdates = $this->prelockXrefUpdates($xrefUpdates, $existingIds);

        $insertRows = array_values($rowsByCollectionKey);
        usort(
            $insertRows,
            static fn (array $left, array $right): int => strcmp($left['collectionhash'], $right['collectionhash'])
        );
        foreach (array_chunk($insertRows, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, NOW(), ?)';
                array_push(
                    $bindings,
                    $row['subject'],
                    $row['fromname'],
                    $row['unixtime'],
                    $row['xref'],
                    $row['groups_id'],
                    $row['totalfiles'],
                    $row['collectionhash'],
                    $row['collection_regexes_id'],
                    $row['noise']
                );
            }

            // ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) is the standard
            // "insert or do nothing" idiom that avoids re-writing existing
            // rows (and the redo/binlog churn that comes with it) while still
            // letting LAST_INSERT_ID() return the existing row's id.
            $this->runCollectionWriteWithRetry(fn (): bool => DB::statement(
                'INSERT INTO collections (subject, fromname, date, xref, groups_id, totalfiles, collectionhash, collection_regexes_id, dateadded, noise) VALUES '
                .implode(',', $placeholders)
                .' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                $bindings
            ));
        }

        $this->batchAppendXrefs($xrefUpdates);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rowsByCollectionKey
     * @param  array<string, true>  $existingHashes
     * @return list<array{id:int,xref_append:string}>
     */
    private function prepareXrefUpdates(array $rowsByCollectionKey, array $existingHashes): array
    {
        $updates = [];
        foreach ($rowsByCollectionKey as $row) {
            if (($row['xref_append'] ?? '') !== '' && isset($existingHashes[$row['collectionhash']])) {
                $updates[] = [
                    'id' => $this->existingIdsByHash[$row['collectionhash']],
                    'xref_append' => $row['xref_append'],
                ];
            }
        }

        usort(
            $updates,
            static fn (array $left, array $right): int => $left['id'] <=> $right['id']
        );

        return $updates;
    }

    /**
     * @param  list<array{id:int,xref_append:string}>  $updates
     * @param  list<int>  $existingIds
     * @return list<array{id:int,xref_append:string}>
     */
    private function prelockXrefUpdates(array $updates, array $existingIds): array
    {
        $existingIds = array_values(array_unique(array_map('intval', $existingIds)));
        sort($existingIds, SORT_NUMERIC);
        $lockedXrefs = [];
        $lockClause = $updates === [] ? ' LOCK IN SHARE MODE' : ' FOR UPDATE';

        foreach (array_chunk($existingIds, self::MAX_SQL_ROWS_PER_STATEMENT) as $ids) {
            $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
            $lockedRows = DB::select(
                "SELECT id, xref FROM collections FORCE INDEX (PRIMARY) WHERE id IN ({$idPlaceholders}) ORDER BY id{$lockClause}",
                $ids,
            );
            foreach ($lockedRows as $row) {
                $lockedXrefs[(int) $row->id] = (string) ($row->xref ?? '');
            }
        }

        $validated = [];
        foreach ($updates as $update) {
            if (! array_key_exists($update['id'], $lockedXrefs)) {
                continue;
            }

            $newTokens = $this->xrefService->diffNewTokens(
                $lockedXrefs[$update['id']],
                $update['xref_append'],
            );
            if ($newTokens === []) {
                continue;
            }

            $validated[] = [
                'id' => $update['id'],
                'xref_append' => implode(' ', $newTokens),
            ];
        }

        return $validated;
    }

    /**
     * Append xref tokens for every existing collection in a chunk in a single
     * UPDATE...JOIN per sub-chunk instead of N standalone UPDATEs (one per row).
     * Existing rows were already locked in global primary-key order before any
     * new collection insert in this transaction.
     *
     * @param  list<array{id:int,xref_append:string}>  $updates
     */
    private function batchAppendXrefs(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        foreach (array_chunk($updates, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $selects = [];
            $bindings = [];
            foreach ($chunk as $u) {
                $selects[] = 'SELECT ? AS id, ? AS xref_append';
                $bindings[] = $u['id'];
                $bindings[] = $u['xref_append'];
            }

            $sql = 'UPDATE collections c INNER JOIN ('
                .implode(' UNION ALL ', $selects)
                .') u ON u.id = c.id '
                .'SET c.xref = CONCAT(c.xref, ?, u.xref_append)';
            $bindings[] = "\n";

            $this->runCollectionWriteWithRetry(fn (): bool => DB::statement($sql, $bindings));
        }
    }

    /**
     * @param  list<string>  $hashes
     * @return array<string, int>
     */
    private function resolveIdsByHash(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $resolved = [];
        foreach (array_chunk($hashes, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $resolved += Collection::query()
                ->whereIn('collectionhash', $chunk)
                ->pluck('id', 'collectionhash')
                ->mapWithKeys(static fn (int|string $id, string $hash): array => [$hash => (int) $id])
                ->all();
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $headerTokens
     */
    private function insertOrGetCollection(
        string $driver,
        string $subject,
        string $fromName,
        int $unixtime,
        array $headerTokens,
        string $finalXrefAppend,
        int $groupId,
        int $totalFiles,
        string $collectionHash,
        int $regexId,
        string $batchNoise
    ): int {
        if ($driver === 'sqlite') {
            return $this->insertCollectionSqlite(
                $subject,
                $fromName,
                $unixtime,
                $headerTokens,
                $groupId,
                $totalFiles,
                $collectionHash,
                $regexId,
                $batchNoise
            );
        }

        return $this->insertCollectionMysql(
            $subject,
            $fromName,
            $unixtime,
            $headerTokens,
            $finalXrefAppend,
            $groupId,
            $totalFiles,
            $collectionHash,
            $regexId,
            $batchNoise
        );
    }

    /**
     * @param  array<string, mixed>  $headerTokens
     */
    private function insertCollectionSqlite(
        string $subject,
        string $fromName,
        int $unixtime,
        array $headerTokens,
        int $groupId,
        int $totalFiles,
        string $collectionHash,
        int $regexId,
        string $batchNoise
    ): int {
        $affected = DB::table('collections')->insertOrIgnore([
            'subject' => $subject,
            'fromname' => $fromName,
            'date' => date('Y-m-d H:i:s', $unixtime),
            'xref' => implode(' ', $headerTokens),
            'groups_id' => $groupId,
            'totalfiles' => $totalFiles,
            'collectionhash' => $collectionHash,
            'collection_regexes_id' => $regexId,
            'dateadded' => now(),
            'noise' => $batchNoise,
        ]);

        if ($affected > 0 && ($lastId = (int) DB::connection()->getPdo()->lastInsertId()) > 0) {
            $this->insertedCollectionIds[$lastId] = true;

            return $lastId;
        }

        return (int) (Collection::whereCollectionhash($collectionHash)->value('id') ?? 0);
    }

    /**
     * @param  array<string, mixed>  $headerTokens
     */
    private function insertCollectionMysql(
        string $subject,
        string $fromName,
        int $unixtime,
        array $headerTokens,
        string $finalXrefAppend,
        int $groupId,
        int $totalFiles,
        string $collectionHash,
        int $regexId,
        string $batchNoise
    ): int {
        // ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) lets LAST_INSERT_ID()
        // return the existing row's id without rewriting the row (avoids the
        // redo/binlog churn of `dateadded = NOW()`).
        $insertSql = 'INSERT INTO collections '
            .'(subject, fromname, date, xref, groups_id, totalfiles, collectionhash, collection_regexes_id, dateadded, noise) '
            .'VALUES (?, ?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, NOW(), ?) '
            .'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)';

        $bindings = [
            $subject,
            $fromName,
            $unixtime,
            implode(' ', $headerTokens),
            $groupId,
            $totalFiles,
            $collectionHash,
            $regexId,
            $batchNoise,
        ];

        if ($finalXrefAppend !== '') {
            $insertSql .= ', xref = CONCAT(xref, "\\n", ?)';
            $bindings[] = $finalXrefAppend;
        }

        // affectingStatement so we can distinguish a brand-new insert
        // (rowCount = 1) from a duplicate-key hit (rowCount = 0 with no xref
        // append, or 2 when xref was actually appended). LAST_INSERT_ID(id) in
        // the ODKU clause makes lastInsertId() return the existing row id
        // even on a duplicate, so we can't rely on lastInsertId() alone.
        $affected = (int) $this->runCollectionWriteWithRetry(
            fn (): int => (int) DB::affectingStatement($insertSql, $bindings)
        );
        $lastId = (int) DB::connection()->getPdo()->lastInsertId();

        if ($lastId > 0) {
            if ($affected === 1) {
                $this->insertedCollectionIds[$lastId] = true;
            }

            return $lastId;
        }

        return (int) (Collection::whereCollectionhash($collectionHash)->value('id') ?? 0);
    }

    private function runCollectionWriteWithRetry(callable $write): mixed
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
     * Get IDs created in this batch.
     *
     * @return list<int>
     */
    public function getInsertedIds(): array
    {
        return array_keys($this->insertedCollectionIds);
    }

    /**
     * Get all collection IDs processed this batch.
     *
     * @return list<int>
     */
    public function getAllIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->collectionIds)));
    }

    /**
     * Get all collection hashes processed this batch.
     *
     * @return list<string>
     */
    public function getBatchHashes(): array
    {
        return array_keys($this->batchCollectionHashes);
    }
}
