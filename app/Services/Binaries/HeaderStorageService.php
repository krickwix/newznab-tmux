<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\Orchestrator\CurrentForwardWindowLineage;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the header storage process.
 *
 * This service coordinates the CollectionHandler, BinaryHandler, PartHandler,
 * and HeaderStorageTransaction to store parsed headers into the database.
 */
final class HeaderStorageService
{
    private const MAX_CHUNK_ATTEMPTS = 3;

    private CollectionHandler $collectionHandler;

    private BinaryHandler $binaryHandler;

    private PartHandler $partHandler;

    private BinariesConfig $config;

    private CurrentForwardWindowLineage $lineage;

    private IngestCollectionKeying $keying;

    private CollectionFileNumberAllocator $fileNumbers;

    /** @var array<int> Article numbers that failed to insert */
    private array $failedInserts = [];

    /**
     * Headers this batch whose collection key took a PART count for a file
     * count. Sizing data for the keying change, nothing more: it is counted
     * whatever the group, and only REPORTED for groups on the allowlist.
     */
    private int $partCounterKeyedHeaders = 0;

    /**
     * Headers this batch that were actually re-keyed -- i.e. the group is on
     * nntmux.ingest_collection_keying_groups, so the leaked count was kept out
     * of the key and the ordinal was allocated. This is the number step 3 of the
     * rollout watches.
     *
     * It is NOT a subset of partCounterKeyedHeaders. That counter deliberately
     * only counts a count that LEAKED (totalFiles > 0); this one also covers the
     * subject that declares nothing at all, which classifies as "not a real
     * counter" for the same reason and today lands every such file of a posting
     * on one binary at filenumber 0. Those get a dense ordinal too.
     */
    private int $rekeyedHeaders = 0;

    public function __construct(
        ?CollectionHandler $collectionHandler = null,
        ?BinaryHandler $binaryHandler = null,
        ?PartHandler $partHandler = null,
        ?BinariesConfig $config = null,
        ?CurrentForwardWindowLineage $lineage = null,
        ?IngestCollectionKeying $keying = null,
        ?CollectionFileNumberAllocator $fileNumbers = null,
    ) {
        $this->config = $config ?? BinariesConfig::fromSettings();
        $this->collectionHandler = $collectionHandler ?? new CollectionHandler;
        $this->binaryHandler = $binaryHandler ?? new BinaryHandler;
        $this->partHandler = $partHandler ?? new PartHandler(
            $this->config->partsChunkSize,
            true
        );
        $this->lineage = $lineage ?? new CurrentForwardWindowLineage;
        $this->keying = $keying ?? new IngestCollectionKeying;
        $this->fileNumbers = $fileNumbers ?? new CollectionFileNumberAllocator;
    }

    /**
     * Store parsed headers to the database.
     *
     * @param  array<int, array<string, mixed>>  $headers  Parsed headers with 'matches' already populated
     * @param  array<string, mixed>  $groupMySQL  Group info from database
     * @param  bool  $addToPartRepair  Whether to track failed inserts
     * @return list<int> Article numbers that failed to insert
     */
    public function store(
        array $headers,
        array $groupMySQL,
        bool $addToPartRepair = true,
        ?int $currentForwardGeneration = null,
    ): array {
        if (empty($headers)) {
            return [];
        }

        $this->failedInserts = [];
        $this->partCounterKeyedHeaders = 0;
        $this->rekeyedHeaders = 0;

        // Use the dedicated header chunk size, NOT partsChunkSize. The latter
        // controls single-row part flushes and is normally much larger; using
        // it here forces every collection/binary bulk INSERT and OR-clause
        // SELECT to scale to thousands of rows per chunk, which exhausts PHP
        // and MySQL memory.
        $chunkSize = max(1, $this->config->headerChunkSize);

        // Walk the array with offset slicing instead of array_chunk() so we
        // don't materialize every chunk simultaneously in memory.
        $total = \count($headers);
        $headers = array_values($headers);
        for ($offset = 0; $offset < $total; $offset += $chunkSize) {
            $chunk = \array_slice($headers, $offset, $chunkSize);
            $this->storeChunk($chunk, $groupMySQL, $addToPartRepair, $currentForwardGeneration);
            unset($chunk);
        }

        $this->reportPartCounterKeying((string) ($groupMySQL['name'] ?? ''));
        $this->reportRekeying((string) ($groupMySQL['name'] ?? ''));

        return array_values(array_unique($this->failedInserts));
    }

    /**
     * Say how much of this batch the keying change actually touched.
     *
     * Unconditional for an enabled group -- no second allowlist. Enabling a
     * group here is already an explicit, per-group, write-affecting decision,
     * and step 3 of the rollout is "watch for 24h", which needs a number.
     *
     * The wording deliberately avoids the substring "part count". The
     * measurement CronJob in
     * mediahome/manifests/media/nntmux/distributed-workers.yaml sums every log
     * line containing `part count` followed by a JSON object with `group` and
     * `headers` -- which is exactly the shape below. Reusing the phrase would
     * silently double-count the reporting flag's window.
     */
    private function reportRekeying(string $groupName): void
    {
        if ($this->rekeyedHeaders === 0 || $groupName === '') {
            return;
        }

        Log::info('ingest re-keyed collections off a leaked counter', [
            'group' => $groupName,
            'headers' => $this->rekeyedHeaders,
        ]);
    }

    /**
     * Report how much of this batch was keyed on a part count.
     *
     * The allowlist gates the REPORT, not the classification: the count is
     * always accurate, and this decides whether it is worth saying out loud.
     * That ordering matters, because the number is what sizes the keying change
     * before it is written -- enabling a group here has no effect on ingest.
     *
     * Mirrors nntmux.obfuscated_brace_token_groups; a group is named in full,
     * or `all` for every group -- the same sentinel
     * NNTMUX_ORCHESTRATOR_BACKFILL_PROBE_GROUPS already uses.
     *
     * `all` is for a measurement window, not a resting state: this logs once
     * per batch per group, and ingest runs continuously. Narrow it back to the
     * groups of interest, or unset it, once the numbers are in.
     */
    private function reportPartCounterKeying(string $groupName): void
    {
        if ($this->partCounterKeyedHeaders === 0 || $groupName === '') {
            return;
        }

        $groups = array_values(array_filter(array_map(
            static fn (mixed $group): string => strtolower(trim((string) $group)),
            (array) config('nntmux.ingest_partcount_key_groups', []),
        )));

        if ($groups === []) {
            return;
        }

        if (! \in_array('all', $groups, true)
            && ! \in_array(strtolower(trim($groupName)), $groups, true)
        ) {
            return;
        }

        Log::info('ingest keyed collections on a part count', [
            'group' => $groupName,
            'headers' => $this->partCounterKeyedHeaders,
        ]);
    }

    /**
     * Store one bounded header chunk inside its own transaction.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $groupMySQL
     */
    private function storeChunk(
        array $headers,
        array $groupMySQL,
        bool $addToPartRepair,
        ?int $currentForwardGeneration,
    ): void {
        $chunkNumbers = array_values(array_filter(array_map(
            static fn (array $header): mixed => $header['Number'] ?? null,
            $headers
        )));

        $failedBaseCount = \count($this->failedInserts);

        for ($attempt = 1; $attempt <= self::MAX_CHUNK_ATTEMPTS; $attempt++) {
            try {
                if ($this->storeChunkOnce(
                    $headers,
                    $groupMySQL,
                    $addToPartRepair,
                    $chunkNumbers,
                    $currentForwardGeneration,
                )) {
                    return;
                }

                return;
            } catch (\Throwable $e) {
                $this->failedInserts = \array_slice($this->failedInserts, 0, $failedBaseCount);

                if (! TransientHeaderStorageFailure::is($e)) {
                    throw $e;
                }

                if ($attempt >= self::MAX_CHUNK_ATTEMPTS) {
                    if ($addToPartRepair) {
                        $this->failedInserts = array_merge(
                            $this->failedInserts,
                            $chunkNumbers,
                            $this->partHandler->getFailedNumbers()
                        );
                    }

                    return;
                }

                usleep(50_000 * $attempt);
            }
        }
    }

    /**
     * Store one bounded header chunk attempt inside its own transaction.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $groupMySQL
     * @param  list<int>  $chunkNumbers
     */
    private function storeChunkOnce(
        array $headers,
        array $groupMySQL,
        bool $addToPartRepair,
        array $chunkNumbers,
        ?int $currentForwardGeneration,
    ): bool {
        $this->collectionHandler->reset();
        $this->binaryHandler->reset();
        $this->partHandler->reset();
        $this->partHandler->setAddToPartRepair($addToPartRepair);
        $this->partHandler->setTrackInsertedParts(
            $currentForwardGeneration !== null && $this->lineage->enabled(),
        );

        // Create transaction
        $transaction = new HeaderStorageTransaction(
            $this->collectionHandler,
            $this->binaryHandler,
            $this->partHandler
        );

        try {
            $transaction->begin();

            $objects = $this->processHeaderChunk($headers, $groupMySQL, $transaction, $addToPartRepair);

            // Flush remaining parts
            if ($this->partHandler->hasPending()) {
                if (! $this->partHandler->flush()) {
                    $transaction->markError();
                }
            }

            if ($currentForwardGeneration !== null && $this->lineage->enabled()) {
                $this->lineage->recordHeaderChunk(
                    $currentForwardGeneration,
                    $objects['collections'],
                    $this->collectionHandler->getInsertedIds(),
                    $objects['binaries'],
                    $this->binaryHandler->getInsertedIds(),
                    $this->partHandler->getInsertedParts(),
                );
            }

            // Finish transaction
            if (! $transaction->finish()) {
                if ($addToPartRepair) {
                    $this->failedInserts = array_merge(
                        $this->failedInserts,
                        $chunkNumbers,
                        $this->partHandler->getFailedNumbers()
                    );
                }

                return false;
            }
        } catch (\Throwable $e) {
            $transaction->abort();

            throw $e;
        }

        $this->failedInserts = array_merge(
            $this->failedInserts,
            $this->partHandler->getFailedNumbers()
        );

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @param  array<string, mixed>  $groupMySQL
     * @return array{collections:list<int>,binaries:list<int>}
     */
    private function processHeaderChunk(
        array $headers,
        array $groupMySQL,
        HeaderStorageTransaction $transaction,
        bool $addToPartRepair,
    ): array {
        $groupId = (int) $groupMySQL['id'];
        $groupName = (string) ($groupMySQL['name'] ?? '');
        $rekey = $this->keying->appliesTo($groupName);

        $totalFilesByIndex = [];
        $fileNumbersByIndex = [];
        $legacyTotalFilesByIndex = [];
        $allocationRequests = [];

        foreach ($headers as $index => $header) {
            [$fileNumber, $totalFiles, $counterIsReal] = $this->extractFileNumberAndTotal($header);

            if (! $counterIsReal && $totalFiles > 0) {
                $this->partCounterKeyedHeaders++;
            }

            if ($rekey && ! $counterIsReal) {
                // The count is a part counter wearing a file counter's clothes,
                // so it is kept out of BOTH the collection key and
                // collections.totalfiles. `totalfiles = 0` is not a special
                // case: it is the existing path for counter-less postings, and
                // runCollectionFileCheckStage0() promotes such collections once
                // the filenumbers are dense.
                if ($totalFiles > 0) {
                    $legacyTotalFilesByIndex[$index] = $totalFiles;
                }
                $totalFiles = 0;

                // The ordinal is allocated below, once the collection is known.
                $fileNumber = 0;
                $allocationRequests[$index] = true;
                $this->rekeyedHeaders++;
            }

            $fileNumbersByIndex[$index] = $fileNumber;
            $totalFilesByIndex[$index] = $totalFiles;
        }

        $collectionIds = $this->collectionHandler->getOrCreateCollections(
            $headers,
            $groupMySQL['id'],
            $groupMySQL['name'],
            $totalFilesByIndex,
            $transaction->getBatchNoise(),
            $this->keying->legacyAdoptionEnabled() ? $legacyTotalFilesByIndex : []
        );

        $allocations = [];
        if ($allocationRequests !== []) {
            foreach (array_keys($allocationRequests) as $index) {
                if (! isset($collectionIds[$index])) {
                    // No collection means the header is about to be failed
                    // anyway; there is nothing to number it inside of.
                    continue;
                }

                $subject = (string) ($headers[$index]['matches'][1] ?? '');
                $allocations[$index] = [
                    'collection_id' => (int) $collectionIds[$index],
                    'hash' => BinaryHandler::binaryHash(
                        $subject,
                        (string) ($headers[$index]['From'] ?? ''),
                        $groupId
                    ),
                    'sort' => $subject,
                ];
            }

            foreach ($this->fileNumbers->allocate($allocations) as $index => $allocatedFileNumber) {
                $fileNumbersByIndex[$index] = $allocatedFileNumber;
            }
        }

        $binaryRecords = [];
        foreach ($headers as $index => $header) {
            if (! isset($collectionIds[$index])) {
                $this->markHeaderFailed($header, $transaction, $addToPartRepair);

                continue;
            }

            $binaryRecords[$index] = [
                'header' => $header,
                'collection_id' => $collectionIds[$index],
                'file_number' => $fileNumbersByIndex[$index],
            ];
        }

        $binaryIds = $this->binaryHandler->getOrCreateBinaries($binaryRecords, $groupMySQL['id']);

        // Before a single part is attributed, prove every allocated ordinal is
        // still ours. A concurrent lane allocating from the same stale
        // MAX(filenumber) resolves silently to ITS binary -- the bulk insert
        // carries ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), so nothing
        // raises -- and this file's parts would then hang off that file's name.
        // Throwing here rolls the chunk back and re-reads MAX on the retry.
        if ($allocations !== []) {
            $this->fileNumbers->assertOrdinalsHeld($allocations, $binaryIds);
        }

        // Acquire/update binary parents before parts take shared foreign-key
        // locks. This preserves collection -> binary -> parts lock order and
        // prevents concurrent writers from deadlocking during late upgrades.
        if (! $this->binaryHandler->flushUpdates($this->config->binariesUpdateChunkSize)) {
            $transaction->markError();

            return ['collections' => [], 'binaries' => []];
        }

        foreach ($binaryRecords as $index => $record) {
            $header = $record['header'];
            if (! isset($binaryIds[$index])) {
                $this->markHeaderFailed($header, $transaction, $addToPartRepair);

                continue;
            }

            if (! $this->partHandler->addPart($binaryIds[$index], $header)) {
                $this->markHeaderFailed($header, $transaction, $addToPartRepair);
            }
        }

        return [
            'collections' => array_values(array_unique(array_map('intval', $collectionIds))),
            'binaries' => array_values(array_unique(array_map('intval', $binaryIds))),
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array{0: int, 1: int}
     */
    /**
     * File number, file count, and whether that count is a REAL file counter.
     *
     * The third value is the whole point. `matches[1]` is the subject with its
     * `(x/y)` PART counter stripped; `matches[0]` still carries it. When the
     * first probe finds nothing the fallback re-reads the raw subject, and what
     * it matches is that part counter -- returned, today, as a file count.
     *
     * It then flows into CollectionHandler::collectionIdentity(), which keys on
     * `$collMatch['name'] . $totalFiles`, so files of ONE posting mint
     * different keys. Live, one alt.binaries.cinemageddon posting:
     *
     *   ..._repost_(Submission.vol001+01.PAR2) yEnc (1/2)  -> totalfiles 2
     *   ..._repost_(Submission.vol009+06.PAR2) yEnc (1/7)  -> totalfiles 7
     *
     * Both clean to the same name (verified against the live cleaner); only the
     * part count differs, and it is enough to split them. Stage 1 then wants
     * COUNT(DISTINCT filenumber) >= totalfiles, which a one-binary fragment
     * never reaches, so the rows stall until retention purges articles that
     * were fully downloaded.
     *
     * WHAT ACTS ON IT. processHeaderChunk() demotes a false count to 0 -- out of
     * the key, out of collections.totalfiles -- and allocates a dense ordinal
     * via CollectionFileNumberAllocator, but ONLY for groups on
     * nntmux.ingest_collection_keying_groups. The allocator is not optional:
     * with `settings.completion` NULL (=100) stage 0 reduces to
     * COUNT(DISTINCT filenumber) == MAX(filenumber), so without it two files of
     * one posting would land in one collection both claiming filenumber 1 and
     * collide on UNIQUE (collections_id, filenumber).
     *
     * For every group NOT on that list the two values below still reach the
     * write path exactly as they did before this classification existed. That
     * is what makes the flag a switch rather than a rewrite, and
     * HeaderFileCounterClassificationTest pins it.
     *
     * @param  array<string, mixed>  $header
     * @return array{0: int, 1: int, 2: bool}
     */
    private function extractFileNumberAndTotal(array $header): array
    {
        if (array_key_exists('collection_file_number', $header) && array_key_exists('collection_total_files', $header)) {
            // Normalizer-supplied. ObfuscatedSubjectNormalizer pins 1 of 1
            // precisely BECAUSE these subjects carry only a part counter, so
            // the value is a deliberate statement about the file rather than a
            // counter that leaked.
            return [
                (int) ($header['collection_file_number'] ?? 0),
                (int) ($header['collection_total_files'] ?? 0),
                true,
            ];
        }

        $fileCount = $this->getFileCount($header['matches'][1]);
        if ($fileCount[1] === 0 && $fileCount[3] === 0) {
            $fileCount = $this->getFileCount($header['matches'][0]);

            // Matched only once the part counter was back in the string, so
            // that is what matched. A 0/0 here is also not a real counter --
            // the subject simply declares nothing.
            return [(int) $fileCount[1], (int) $fileCount[3], false];
        }

        return [(int) $fileCount[1], (int) $fileCount[3], true];
    }

    /** @param  array<string, mixed>  $header */
    private function markHeaderFailed(array $header, HeaderStorageTransaction $transaction, bool $addToPartRepair): void
    {
        $transaction->markError();
        if ($addToPartRepair && isset($header['Number'])) {
            $this->failedInserts[] = $header['Number'];
        }
    }

    /**
     * @return array<int, int|string>
     */
    private function getFileCount(string $subject): array
    {
        $patterns = [
            '/\bFile[\s_]+(\d{1,5})[\s_]+of[\s_]+(\d{1,5})\b/i',
            '/[\[(]\s*(\d{1,5})\s*\/\s*(\d{1,5})\s*[\])]/',
            '/[\[(]\s*(\d{1,5})(?:\s+|_)of(?:\s+|_)(\d{1,5})\s*[\])]/i',
            '/[\[(]\s*(\d{1,5})\s*-\s*(\d{1,5})\s*[\])]/',
            '/(?:^|[\s:])(\d{1,5})\s*\/\s*(\d{1,5})(?:[\s$:)]|$)/',
            '/(?:^|[\s:])(\d{1,5})(?:\s+|_)of(?:\s+|_)(\d{1,5})(?:[\s$:)]|$)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $subject, $fileCount) === 1) {
                $fileCount[3] = $fileCount[2];

                return $fileCount;
            }
        }

        $fileCount[1] = $fileCount[3] = 0;

        return $fileCount;
    }
}
