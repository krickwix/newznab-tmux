<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Exact, bounded provenance for ledger-backed current-forward windows.
 *
 * Header writes call this while their database transaction is still open, so
 * provenance and its hard budgets commit or roll back with the inserted parts.
 */
final class CurrentForwardWindowLineage
{
    /** @var list<string> */
    private const array TERMINAL_CHAIN_STATES = ['PRODUCTIVE', 'QUARANTINED'];

    /** @var list<string> */
    private const array OPEN_CHAIN_STATES = [
        'OFFERED',
        'CLAIMED',
        'INGESTED',
        'ATTRIBUTING',
        'CONTINUATION_PENDING',
        'CHAINED',
    ];

    /** @var list<string> */
    private const array COLLECTION_HANDOFF_STATES = [
        'INGESTED',
        'ATTRIBUTING',
        'CONTINUATION_PENDING',
    ];

    public const string COLLECTION = 'COLLECTION';

    public const string BINARY = 'BINARY';

    public const string RELEASE = 'RELEASE';

    public function enabled(): bool
    {
        return (bool) config('nntmux.orchestrator.current_forward_continuation_enabled', false);
    }

    public function schemaReady(): bool
    {
        return Schema::hasTable('current_forward_window_objects')
            && Schema::hasTable('current_forward_object_owners')
            && Schema::hasTable('current_forward_continuation_observations')
            && Schema::hasColumns('current_forward_windows', [
                'chain_root_id',
                'parent_window_id',
                'chain_ordinal',
                'continuation_deadline_at',
            ]);
    }

    /**
     * @param  list<int>  $collectionIds
     * @param  list<int>  $createdCollectionIds
     * @param  list<int>  $binaryIds
     * @param  list<int>  $createdBinaryIds
     * @param  list<array{binaries_id:int,number:int}>  $insertedParts
     */
    public function recordHeaderChunk(
        int $generation,
        array $collectionIds,
        array $createdCollectionIds,
        array $binaryIds,
        array $createdBinaryIds,
        array $insertedParts,
    ): void {
        if (! $this->enabled()) {
            return;
        }
        if (! $this->schemaReady()) {
            throw new RuntimeException('Current-forward continuation schema is unavailable.');
        }
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Current-forward lineage mutation requires an active transaction.');
        }

        $window = DB::table('current_forward_windows')
            ->where('generation', $generation)
            ->first();
        if ($window === null || (string) $window->state !== 'CLAIMED') {
            throw new RuntimeException('Current-forward lineage window is absent or no longer claimed.');
        }

        $windowId = (int) $window->id;
        $rootId = (int) ($window->chain_root_id ?: $windowId);
        $createdCollections = array_fill_keys(array_map('intval', $createdCollectionIds), true);
        $createdBinaries = array_fill_keys(array_map('intval', $createdBinaryIds), true);
        $partsByBinary = [];
        foreach ($insertedParts as $part) {
            $binaryId = (int) ($part['binaries_id'] ?? 0);
            if ($binaryId > 0) {
                $partsByBinary[$binaryId] = ($partsByBinary[$binaryId] ?? 0) + 1;
            }
        }

        $collectionIds = $this->positiveUnique($collectionIds);
        $binaryIds = $this->positiveUnique($binaryIds);
        $binaryParents = $binaryIds === []
            ? []
            : DB::table('binaries')->whereIn('id', $binaryIds)->pluck('collections_id', 'id')->all();

        foreach ($collectionIds as $collectionId) {
            $this->upsertObject(
                $windowId,
                $rootId,
                self::COLLECTION,
                $collectionId,
                null,
                0,
                isset($createdCollections[$collectionId]),
            );
        }
        foreach ($binaryIds as $binaryId) {
            $parentId = (int) ($binaryParents[$binaryId] ?? 0);
            if ($parentId <= 0 || ! in_array($parentId, $collectionIds, true)) {
                throw new RuntimeException('Current-forward binary lineage has no collection parent.');
            }
            $this->upsertObject(
                $windowId,
                $rootId,
                self::BINARY,
                $binaryId,
                $parentId,
                (int) ($partsByBinary[$binaryId] ?? 0),
                isset($createdBinaries[$binaryId]),
            );
        }

        $this->assertBudgets($rootId);
    }

    public function recordReleaseForCollection(int $collectionId, int $releaseId): void
    {
        if (! $this->schemaReady() || $collectionId <= 0 || $releaseId <= 0) {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Current-forward lineage mutation requires an active transaction.');
        }

        $owner = DB::table('current_forward_object_owners')
            ->where('object_type', self::COLLECTION)
            ->where('object_id', $collectionId)
            ->lockForUpdate()
            ->first();
        if ($owner === null) {
            return;
        }

        $rootId = (int) $owner->chain_root_id;
        $rootWindow = DB::table('current_forward_windows')
            ->where('id', $rootId)
            ->lockForUpdate()
            ->first();
        if ($rootWindow === null
            || ! in_array((string) $rootWindow->state, ['ATTRIBUTING', 'CONTINUATION_PENDING'], true)
        ) {
            throw new RuntimeException('Current-forward lineage is no longer open for release attribution.');
        }
        $windowId = (int) DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::COLLECTION)
            ->where('object_id', $collectionId)
            ->max('window_id');
        if ($windowId > 0) {
            $this->upsertObject(
                $windowId,
                $rootId,
                self::RELEASE,
                $releaseId,
                $collectionId,
                0,
                true,
            );
        }
    }

    /**
     * Persist an exact, same-root collection handoff before split-collection
     * reconciliation reparents the source binaries and removes the source.
     * The caller must keep the source/target collections and binaries locked
     * and perform the entire merge in this same transaction.
     *
     * @param  list<int>  $sourceCollectionIds
     * @return int number of immutable handoffs verified
     */
    public function recordCollectionHandoffsForMerge(
        array $sourceCollectionIds,
        int $targetCollectionId,
        string $reason,
    ): int {
        $sourceCollectionIds = $this->positiveUnique($sourceCollectionIds);
        if ($sourceCollectionIds === [] || ! $this->schemaReady()) {
            return 0;
        }
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Current-forward collection handoff requires an active transaction.');
        }
        if ($targetCollectionId <= 0 || in_array($targetCollectionId, $sourceCollectionIds, true)) {
            throw new RuntimeException('Current-forward collection handoff target is invalid.');
        }

        $sourceOwners = DB::table('current_forward_object_owners')
            ->where('object_type', self::COLLECTION)
            ->whereIn('object_id', $sourceCollectionIds)
            ->orderBy('object_id')
            ->lockForUpdate()
            ->get(['object_id', 'chain_root_id']);
        $targetOwner = DB::table('current_forward_object_owners')
            ->where('object_type', self::COLLECTION)
            ->where('object_id', $targetCollectionId)
            ->lockForUpdate()
            ->first(['chain_root_id']);
        if ($sourceOwners->isEmpty() && $targetOwner === null) {
            return 0;
        }
        if ($sourceOwners->count() !== count($sourceCollectionIds)) {
            throw new RuntimeException('Current-forward collection handoff source ownership is incomplete.');
        }
        if (! Schema::hasTable('current_forward_collection_handoffs')) {
            throw new RuntimeException('Current-forward collection handoff schema is unavailable.');
        }

        $rootIds = $this->positiveUnique($sourceOwners->pluck('chain_root_id')->all());
        if ($targetOwner === null || count($rootIds) !== 1 || (int) $targetOwner->chain_root_id !== $rootIds[0]) {
            throw new RuntimeException('Current-forward collection handoff crosses lineage roots.');
        }
        $rootId = $rootIds[0];
        $root = DB::table('current_forward_windows')->where('id', $rootId)->lockForUpdate()->first();
        if ($root === null || ! in_array((string) $root->state, self::COLLECTION_HANDOFF_STATES, true)) {
            throw new RuntimeException('Current-forward collection handoff root is not open.');
        }
        $targetMembership = DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::COLLECTION)
            ->where('object_id', $targetCollectionId)
            ->orderByDesc('window_id')
            ->lockForUpdate()
            ->first(['window_id']);
        if ($targetMembership === null || ! DB::table('collections')->where('id', $targetCollectionId)->exists()) {
            throw new RuntimeException('Current-forward collection handoff target membership is incomplete.');
        }

        $normalizedReason = $this->normalizeDispositionReason($reason);
        if (! in_array($normalizedReason, ['split_collection_merge', 'split_collection_fanout_merge'], true)) {
            throw new RuntimeException('Current-forward collection handoff reason is unsupported.');
        }
        $verified = 0;

        foreach ($sourceCollectionIds as $sourceCollectionId) {
            $sourceMembership = DB::table('current_forward_window_objects')
                ->where('chain_root_id', $rootId)
                ->where('object_type', self::COLLECTION)
                ->where('object_id', $sourceCollectionId)
                ->orderByDesc('window_id')
                ->lockForUpdate()
                ->first(['window_id']);
            $movedBinaryIds = DB::table('binaries')
                ->where('collections_id', $sourceCollectionId)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $lineageBinaryIds = DB::table('current_forward_window_objects')
                ->where('chain_root_id', $rootId)
                ->where('object_type', self::BINARY)
                ->where('parent_object_id', $sourceCollectionId)
                ->distinct()
                ->orderBy('object_id')
                ->pluck('object_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            if ($sourceMembership === null
                || $movedBinaryIds === []
                || $movedBinaryIds !== $lineageBinaryIds
            ) {
                throw new RuntimeException('Current-forward collection handoff binary cohort is incomplete.');
            }
            $this->assertCollectionHandoffAcyclic($rootId, $sourceCollectionId, $targetCollectionId);
            $binaryHash = hash('sha256', json_encode($movedBinaryIds, JSON_THROW_ON_ERROR));
            $handoff = [
                'source_collection_id' => $sourceCollectionId,
                'target_collection_id' => $targetCollectionId,
                'chain_root_id' => $rootId,
                'source_window_id' => (int) $sourceMembership->window_id,
                'target_window_id' => (int) $targetMembership->window_id,
                'moved_binary_count' => count($movedBinaryIds),
                'moved_binary_ids_hash' => $binaryHash,
                'reason' => $normalizedReason,
                'handed_off_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('current_forward_collection_handoffs')->insertOrIgnore($handoff);
            $existing = DB::table('current_forward_collection_handoffs')
                ->where('chain_root_id', $rootId)
                ->where('source_collection_id', $sourceCollectionId)
                ->lockForUpdate()
                ->first();
            if ($existing === null
                || (int) $existing->target_collection_id !== $targetCollectionId
                || (int) $existing->source_window_id !== (int) $sourceMembership->window_id
                || (int) $existing->target_window_id !== (int) $targetMembership->window_id
                || (int) $existing->moved_binary_count !== count($movedBinaryIds)
                || ! hash_equals((string) $existing->moved_binary_ids_hash, $binaryHash)
                || (string) $existing->reason !== $normalizedReason
            ) {
                throw new RuntimeException('Current-forward collection handoff identity drift detected.');
            }
            $verified++;
        }

        return $verified;
    }

    /**
     * Persist the terminal policy disposition before a lineage-owned release
     * is removed. The caller must delete the release in this same transaction.
     *
     * @return array{chain_root_id:int,window_id:int,parent_collection_id:int,reason:string}|null
     */
    public function recordReleaseDispositionForDeletion(int $releaseId, string $reason): ?array
    {
        // Deletion integrity is independent of whether new continuation work
        // is enabled. An older/specialized cleanup worker must still honor an
        // owner that already exists in the shared ledger.
        if ($releaseId <= 0 || ! $this->schemaReady()) {
            return null;
        }
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Current-forward release disposition requires an active transaction.');
        }

        $owner = DB::table('current_forward_object_owners')
            ->where('object_type', self::RELEASE)
            ->where('object_id', $releaseId)
            ->lockForUpdate()
            ->first();
        if ($owner === null) {
            return null;
        }
        if (! Schema::hasTable('current_forward_release_dispositions')) {
            throw new RuntimeException('Current-forward release disposition schema is unavailable.');
        }

        $rootId = (int) $owner->chain_root_id;
        $membership = DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::RELEASE)
            ->where('object_id', $releaseId)
            ->orderByDesc('window_id')
            ->lockForUpdate()
            ->first();
        $root = DB::table('current_forward_windows')
            ->where('id', $rootId)
            ->lockForUpdate()
            ->first();
        $release = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
        if ($membership === null || $root === null || $release === null) {
            throw new RuntimeException('Current-forward release disposition identity is incomplete.');
        }

        $normalizedReason = $this->normalizeDispositionReason($reason);
        $disposition = [
            'release_id' => $releaseId,
            'chain_root_id' => $rootId,
            'window_id' => (int) $membership->window_id,
            'parent_collection_id' => (int) ($membership->parent_object_id ?? 0) ?: null,
            'reason' => $normalizedReason,
            'categories_id' => isset($release->categories_id) ? (int) $release->categories_id : null,
            'nzbstatus' => isset($release->nzbstatus) ? (int) $release->nzbstatus : null,
            'size' => isset($release->size) ? (int) $release->size : null,
            'disposed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('current_forward_release_dispositions')->insertOrIgnore($disposition);
        $existing = DB::table('current_forward_release_dispositions')
            ->where('release_id', $releaseId)
            ->lockForUpdate()
            ->first();
        if ($existing === null
            || (int) $existing->chain_root_id !== $rootId
            || (int) $existing->window_id !== (int) $membership->window_id
            || (int) ($existing->parent_collection_id ?? 0) !== (int) ($membership->parent_object_id ?? 0)
            || (string) $existing->reason !== $normalizedReason
            || (isset($release->categories_id)
                && (int) ($existing->categories_id ?? -1) !== (int) $release->categories_id)
            || (isset($release->nzbstatus)
                && (int) ($existing->nzbstatus ?? PHP_INT_MIN) !== (int) $release->nzbstatus)
            || (isset($release->size)
                && (int) ($existing->size ?? -1) !== (int) $release->size)
        ) {
            throw new RuntimeException('Current-forward release disposition identity drift detected.');
        }

        if (in_array((string) $root->state, self::OPEN_CHAIN_STATES, true)) {
            $failureReason = substr('current_forward_release_removed_'.$normalizedReason, 0, 120);
            $affectedGenerations = DB::table('current_forward_windows')
                ->where(static fn ($query) => $query
                    ->where('id', $rootId)
                    ->orWhere('chain_root_id', $rootId))
                ->whereIn('state', self::OPEN_CHAIN_STATES)
                ->whereNotNull('generation')
                ->lockForUpdate()
                ->pluck('generation')
                ->map(static fn (mixed $generation): int => (int) $generation)
                ->filter(static fn (int $generation): bool => $generation > 0)
                ->values()
                ->all();
            DB::table('current_forward_windows')
                ->where(static fn ($query) => $query
                    ->where('id', $rootId)
                    ->orWhere('chain_root_id', $rootId))
                ->whereIn('state', self::OPEN_CHAIN_STATES)
                ->update([
                    'state' => 'QUARANTINED',
                    'failure_reason' => $failureReason,
                    'settled_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->reconcileTerminalizedGeneration($affectedGenerations, $failureReason);
            if (Schema::hasColumn('current_forward_windows', 'source_id')
                && Schema::hasTable('current_forward_sources')
            ) {
                DB::table('current_forward_sources')->where('id', $root->source_id)->update([
                    'last_reason' => $failureReason,
                    'updated_at' => now(),
                ]);
            }
        }

        return [
            'chain_root_id' => $rootId,
            'window_id' => (int) $membership->window_id,
            'parent_collection_id' => (int) ($membership->parent_object_id ?? 0),
            'reason' => $normalizedReason,
        ];
    }

    /** @param list<int> $collectionIds */
    public function seedRecoveredWindow(
        int $windowId,
        array $collectionIds,
        int $firstArticle,
        int $lastArticle,
    ): void {
        if (! $this->schemaReady()
            || DB::transactionLevel() < 1
            || $firstArticle <= 0
            || $lastArticle < $firstArticle
        ) {
            throw new RuntimeException('Recovered current-forward lineage requires its schema and an active transaction.');
        }
        $collectionIds = $this->positiveUnique($collectionIds);
        $window = DB::table('current_forward_windows')->where('id', $windowId)->first();
        if ($window === null || $collectionIds === []) {
            throw new RuntimeException('Recovered current-forward lineage has no window or collections.');
        }
        $rootId = (int) ($window->chain_root_id ?: $windowId);
        $rows = DB::table('binaries as b')
            ->join('parts as p', 'p.binaries_id', '=', 'b.id')
            ->whereIn('b.collections_id', $collectionIds)
            ->whereBetween('p.number', [$firstArticle, $lastArticle])
            ->groupBy(['b.id', 'b.collections_id'])
            ->orderBy('id')
            ->get([
                'b.id',
                'b.collections_id',
                DB::raw('COUNT(*) as inserted_parts'),
            ]);
        if ($rows->isEmpty()) {
            throw new RuntimeException('Recovered current-forward lineage has no binaries.');
        }
        foreach ($collectionIds as $collectionId) {
            $this->upsertObject($windowId, $rootId, self::COLLECTION, $collectionId, null, 0, true);
        }
        foreach ($rows as $row) {
            $this->upsertObject(
                $windowId,
                $rootId,
                self::BINARY,
                (int) $row->id,
                (int) $row->collections_id,
                (int) $row->inserted_parts,
                true,
            );
        }
        $this->assertBudgets($rootId);
    }

    /**
     * @return array{
     *   root_id:int,parts:int,binaries:int,collections:int,releases:int,release_ids:list<int>,ready_nzbs:int,
     *   target:int,non_target:int,uncategorized:int,target_bytes:int,non_target_bytes:int,uncategorized_bytes:int,
     *   original_expected_parts:int,original_present_parts:int,original_expected_files:int,
     *   original_observed_files:int,original_complete_files:int,unresolved_collections:int,
     *   integrity_ok:bool,orphan_release_ids:list<int>,disposed_release_ids:list<int>,handed_off_collection_ids:list<int>,invalid_collection_handoff_ids:list<int>,
     *   missing_collection_ids:list<int>,missing_binary_ids:list<int>,dangling_collection_release_ids:list<int>,
     *   release_high:int,hash:string
     * }
     */
    public function observe(int $rootId): array
    {
        if (! $this->schemaReady() || $rootId <= 0) {
            throw new RuntimeException('Current-forward lineage observation is unavailable.');
        }

        $parts = (int) DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::BINARY)
            ->sum('inserted_parts');
        $binaries = (int) DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::BINARY)
            ->distinct()
            ->count('object_id');
        $collections = (int) DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::COLLECTION)
            ->distinct()
            ->count('object_id');

        $releaseIds = DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::RELEASE)
            ->distinct()
            ->pluck('object_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->sort()
            ->values()
            ->all();
        $releaseRows = $releaseIds === []
            ? collect()
            : DB::table('releases')
                ->whereIn('id', $releaseIds)
                ->select(['id', 'categories_id', 'nzbstatus', 'size'])
                ->orderBy('id')
                ->get();
        $target = 0;
        $nonTarget = 0;
        $uncategorized = 0;
        $targetBytes = 0;
        $nonTargetBytes = 0;
        $uncategorizedBytes = 0;
        $ready = 0;
        $releaseHigh = 0;
        foreach ($releaseRows as $release) {
            $releaseHigh = max($releaseHigh, (int) $release->id);
            if ((int) $release->nzbstatus !== 1) {
                continue;
            }
            $ready++;
            $category = (int) $release->categories_id;
            $root = intdiv($category, 1000);
            if ($category === 5999 || ! in_array($root, [2, 5], true)) {
                if ($category <= 10 || $category === 5999) {
                    $uncategorized++;
                    $uncategorizedBytes += max(0, (int) $release->size);
                } else {
                    $nonTarget++;
                    $nonTargetBytes += max(0, (int) $release->size);
                }
            } else {
                $target++;
                $targetBytes += max(0, (int) $release->size);
            }
        }

        $originalIds = DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::COLLECTION)
            ->distinct()
            ->pluck('object_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
        $completeness = $this->collectionCompleteness($originalIds);
        $integrity = $this->integrity($rootId);
        $canonical = [
            'root_id' => $rootId,
            'parts' => $parts,
            'binaries' => $binaries,
            'collections' => $collections,
            'releases' => $releaseRows->count(),
            'release_ids' => $releaseRows->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all(),
            'ready_nzbs' => $ready,
            'target' => $target,
            'non_target' => $nonTarget,
            'uncategorized' => $uncategorized,
            'target_bytes' => $targetBytes,
            'non_target_bytes' => $nonTargetBytes,
            'uncategorized_bytes' => $uncategorizedBytes,
            ...$completeness,
            'release_high' => $releaseHigh,
            ...$integrity,
        ];

        return [...$canonical, 'hash' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR))];
    }

    /**
     * @return array{
     *   integrity_ok:bool,orphan_release_ids:list<int>,disposed_release_ids:list<int>,
     *   handed_off_collection_ids:list<int>,invalid_collection_handoff_ids:list<int>,
     *   missing_collection_ids:list<int>,missing_binary_ids:list<int>,dangling_collection_release_ids:list<int>
     * }
     */
    public function integrity(int $rootId): array
    {
        if (! $this->schemaReady() || $rootId <= 0) {
            throw new RuntimeException('Current-forward lineage integrity observation is unavailable.');
        }

        $objects = DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->whereIn('object_type', [self::COLLECTION, self::BINARY, self::RELEASE])
            ->get(['object_type', 'object_id', 'parent_object_id']);
        $releaseIds = $this->positiveUnique($objects
            ->where('object_type', self::RELEASE)
            ->pluck('object_id')
            ->all());
        $collectionIds = $this->positiveUnique($objects
            ->where('object_type', self::COLLECTION)
            ->pluck('object_id')
            ->all());
        $binaryObjects = $objects->where('object_type', self::BINARY)->values();

        $liveReleaseIds = $releaseIds === []
            ? []
            : DB::table('releases')->whereIn('id', $releaseIds)->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)->all();
        $dispositions = Schema::hasTable('current_forward_release_dispositions') && $releaseIds !== []
            ? DB::table('current_forward_release_dispositions')
                ->where('chain_root_id', $rootId)
                ->whereIn('release_id', $releaseIds)
                ->get(['release_id', 'parent_collection_id'])
            : collect();
        $disposedReleaseIds = $this->positiveUnique($dispositions->pluck('release_id')->all());
        $orphanReleaseIds = array_values(array_diff(
            $releaseIds,
            $this->positiveUnique([...$liveReleaseIds, ...$disposedReleaseIds]),
        ));

        $handoffs = Schema::hasTable('current_forward_collection_handoffs') && $collectionIds !== []
            ? DB::table('current_forward_collection_handoffs')
                ->where('chain_root_id', $rootId)
                ->whereIn('source_collection_id', $collectionIds)
                ->get(['source_collection_id', 'target_collection_id', 'moved_binary_count', 'moved_binary_ids_hash'])
            : collect();
        $handoffSources = $this->positiveUnique($handoffs->pluck('source_collection_id')->all());
        $validCollectionIds = array_fill_keys($collectionIds, true);
        $invalidHandoffIds = $handoffs
            ->filter(function (object $handoff) use ($objects, $validCollectionIds): bool {
                $sourceId = (int) $handoff->source_collection_id;
                $binaryIds = $this->positiveUnique($objects
                    ->where('object_type', self::BINARY)
                    ->filter(static fn (object $binary): bool => (int) ($binary->parent_object_id ?? 0) === $sourceId)
                    ->pluck('object_id')
                    ->all());

                return ! isset($validCollectionIds[(int) $handoff->target_collection_id])
                    || count($binaryIds) !== (int) $handoff->moved_binary_count
                    || ! hash_equals(
                        (string) $handoff->moved_binary_ids_hash,
                        hash('sha256', json_encode($binaryIds, JSON_THROW_ON_ERROR)),
                    );
            })
            ->pluck('source_collection_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $resolvedParentIds = $objects
            ->where('object_type', self::RELEASE)
            ->filter(static fn (object $object): bool => in_array(
                (int) $object->object_id,
                [...$liveReleaseIds, ...$disposedReleaseIds],
                true,
            ))
            ->pluck('parent_object_id')
            ->merge($dispositions->pluck('parent_collection_id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $validHandoffs = $handoffs->reject(
            static fn (object $handoff): bool => in_array((int) $handoff->source_collection_id, $invalidHandoffIds, true),
        );
        do {
            $priorCount = count($resolvedParentIds);
            foreach ($validHandoffs as $handoff) {
                if (in_array((int) $handoff->target_collection_id, $resolvedParentIds, true)) {
                    $resolvedParentIds[] = (int) $handoff->source_collection_id;
                }
            }
            $resolvedParentIds = $this->positiveUnique($resolvedParentIds);
        } while (count($resolvedParentIds) > $priorCount);
        $liveCollections = $collectionIds === []
            ? collect()
            : DB::table('collections')->whereIn('id', $collectionIds)->get(['id', 'releases_id']);
        $liveCollectionIds = $this->positiveUnique($liveCollections->pluck('id')->all());
        $missingCollectionIds = array_values(array_filter(
            array_diff($collectionIds, $liveCollectionIds),
            static fn (int $id): bool => ! in_array($id, $resolvedParentIds, true)
                && ! in_array($id, $handoffSources, true),
        ));
        $danglingCollectionReleaseIds = $liveCollections
            ->filter(static function (object $collection) use ($liveReleaseIds, $disposedReleaseIds): bool {
                $releaseId = (int) ($collection->releases_id ?? 0);

                return $releaseId > 0
                    && ! in_array($releaseId, $liveReleaseIds, true)
                    && ! in_array($releaseId, $disposedReleaseIds, true);
            })
            ->pluck('releases_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $binaryIds = $this->positiveUnique($binaryObjects->pluck('object_id')->all());
        $liveBinaryIds = $binaryIds === []
            ? []
            : DB::table('binaries')->whereIn('id', $binaryIds)->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)->all();
        $missingBinaryIds = $binaryObjects
            ->filter(static fn (object $binary): bool => ! in_array((int) $binary->object_id, $liveBinaryIds, true)
                && ! in_array((int) ($binary->parent_object_id ?? 0), $resolvedParentIds, true))
            ->pluck('object_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'integrity_ok' => $orphanReleaseIds === []
                && $missingCollectionIds === []
                && $missingBinaryIds === []
                && $invalidHandoffIds === []
                && $danglingCollectionReleaseIds === [],
            'orphan_release_ids' => $orphanReleaseIds,
            'disposed_release_ids' => $disposedReleaseIds,
            'handed_off_collection_ids' => $handoffSources,
            'invalid_collection_handoff_ids' => $invalidHandoffIds,
            'missing_collection_ids' => $missingCollectionIds,
            'missing_binary_ids' => $missingBinaryIds,
            'dangling_collection_release_ids' => $danglingCollectionReleaseIds,
        ];
    }

    /** @param array<string,mixed> $observation */
    public function recordObservation(
        int $rootId,
        int $windowId,
        int $ordinal,
        array $observation,
        string $decision,
        string $reason,
        int $baselinePresentParts,
        int $usefulProgressParts,
        string $pipelineHash = '',
    ): void {
        if (! $this->schemaReady()) {
            throw new RuntimeException('Current-forward continuation observation schema is unavailable.');
        }
        $idempotency = hash('sha256', implode('|', [
            $rootId,
            $windowId,
            $ordinal,
            $decision,
            $reason,
            (string) ($observation['hash'] ?? ''),
        ]));
        $inserted = DB::table('current_forward_continuation_observations')->insertOrIgnore([
            'chain_root_id' => $rootId,
            'window_id' => $windowId,
            'chain_ordinal' => $ordinal,
            'cumulative_parts' => (int) ($observation['parts'] ?? 0),
            'cumulative_binaries' => (int) ($observation['binaries'] ?? 0),
            'cumulative_collections' => (int) ($observation['collections'] ?? 0),
            'expected_parts' => (int) ($observation['original_expected_parts'] ?? 0),
            'baseline_present_parts' => $baselinePresentParts,
            'current_present_parts' => (int) ($observation['original_present_parts'] ?? 0),
            'useful_progress_parts' => $usefulProgressParts,
            'observed_files' => (int) ($observation['original_observed_files'] ?? 0),
            'complete_files' => (int) ($observation['original_complete_files'] ?? 0),
            'unresolved_collections' => (int) ($observation['unresolved_collections'] ?? 0),
            'cumulative_releases' => (int) ($observation['releases'] ?? 0),
            'cumulative_ready_nzbs' => (int) ($observation['ready_nzbs'] ?? 0),
            'decision' => substr($decision, 0, 32),
            'reason' => substr($reason, 0, 120),
            'pipeline_hash' => substr($pipelineHash, 0, 64),
            'cohort_hash' => (string) ($observation['hash'] ?? ''),
            'idempotency_key' => $idempotency,
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            $existing = DB::table('current_forward_continuation_observations')
                ->where('window_id', $windowId)
                ->lockForUpdate()
                ->first();
            if ($existing === null
                || ! hash_equals((string) $existing->idempotency_key, $idempotency)
                || ! hash_equals((string) $existing->cohort_hash, (string) ($observation['hash'] ?? ''))
                || (string) $existing->decision !== substr($decision, 0, 32)
            ) {
                throw new RuntimeException('Current-forward continuation observation identity drift detected.');
            }
        }
    }

    public function priorPresentParts(int $rootId): int
    {
        return (int) (DB::table('current_forward_continuation_observations')
            ->where('chain_root_id', $rootId)
            ->orderByDesc('chain_ordinal')
            ->orderByDesc('id')
            ->value('current_present_parts') ?? 0);
    }

    private function upsertObject(
        int $windowId,
        int $rootId,
        string $type,
        int $objectId,
        ?int $parentId,
        int $insertedParts,
        bool $created,
    ): void {
        $this->claimOwnership($rootId, $type, $objectId);
        $existing = DB::table('current_forward_window_objects')
            ->where('window_id', $windowId)
            ->where('object_type', $type)
            ->where('object_id', $objectId)
            ->first();
        if ($existing === null) {
            DB::table('current_forward_window_objects')->insert([
                'window_id' => $windowId,
                'chain_root_id' => $rootId,
                'object_type' => $type,
                'object_id' => $objectId,
                'parent_object_id' => $parentId,
                'inserted_parts' => max(0, $insertedParts),
                'created_in_window' => $created,
                'touched_in_window' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }
        if ((int) $existing->chain_root_id !== $rootId
            || ($parentId !== null && (int) $existing->parent_object_id !== $parentId)
        ) {
            throw new RuntimeException('Current-forward lineage identity drift detected.');
        }
        DB::table('current_forward_window_objects')->where('id', $existing->id)->update([
            'inserted_parts' => (int) $existing->inserted_parts + max(0, $insertedParts),
            'created_in_window' => (bool) $existing->created_in_window || $created,
            'touched_in_window' => true,
            'updated_at' => now(),
        ]);
    }

    private function claimOwnership(int $rootId, string $type, int $objectId): void
    {
        DB::table('current_forward_object_owners')->insertOrIgnore([
            'object_type' => $type,
            'object_id' => $objectId,
            'chain_root_id' => $rootId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owner = DB::table('current_forward_object_owners')
            ->where('object_type', $type)
            ->where('object_id', $objectId)
            ->lockForUpdate()
            ->first();
        if ($owner === null) {
            throw new RuntimeException('Current-forward object is already owned by another lineage root.');
        }
        $priorRootId = (int) $owner->chain_root_id;
        if ($priorRootId === $rootId) {
            return;
        }

        $priorWindows = DB::table('current_forward_windows')
            ->where(function ($query) use ($priorRootId): void {
                $query->where('chain_root_id', $priorRootId)
                    ->orWhere('id', $priorRootId);
            })
            ->lockForUpdate()
            ->get(['id', 'state']);
        $priorRoot = $priorWindows->first(
            static fn (object $window): bool => (int) $window->id === $priorRootId,
        );
        if ($priorRoot === null
            || ! in_array((string) $priorRoot->state, self::TERMINAL_CHAIN_STATES, true)
        ) {
            throw new RuntimeException('Current-forward object is already owned by another lineage root.');
        }
        $allowedChildStates = (string) $priorRoot->state === 'PRODUCTIVE'
            ? ['CHAINED', 'QUARANTINED']
            : ['QUARANTINED'];
        if ($priorWindows->contains(
            static fn (object $window): bool => (int) $window->id !== $priorRootId
                && ! in_array((string) $window->state, $allowedChildStates, true),
        )) {
            throw new RuntimeException('Current-forward object is already owned by another lineage root.');
        }

        $updated = DB::table('current_forward_object_owners')
            ->where('id', $owner->id)
            ->where('chain_root_id', $priorRootId)
            ->update([
                'chain_root_id' => $rootId,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Current-forward object ownership changed concurrently.');
        }
    }

    private function assertBudgets(int $rootId): void
    {
        $parts = (int) DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::BINARY)
            ->sum('inserted_parts');
        $binaries = (int) DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::BINARY)
            ->distinct()
            ->count('object_id');
        $collections = (int) DB::table('current_forward_window_objects')
            ->where('chain_root_id', $rootId)
            ->where('object_type', self::COLLECTION)
            ->distinct()
            ->count('object_id');
        if ($parts > $this->maxParts()
            || $binaries > $this->maxBinaries()
            || $collections > $this->maxCollections()
        ) {
            throw new RuntimeException('Current-forward continuation hard budget exceeded.');
        }
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array{original_expected_parts:int,original_present_parts:int,original_expected_files:int,original_observed_files:int,original_complete_files:int,unresolved_collections:int}
     */
    private function collectionCompleteness(array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [
                'original_expected_parts' => 0,
                'original_present_parts' => 0,
                'original_expected_files' => 0,
                'original_observed_files' => 0,
                'original_complete_files' => 0,
                'unresolved_collections' => 0,
            ];
        }
        $rows = DB::table('collections as c')
            ->leftJoin('binaries as b', 'b.collections_id', '=', 'c.id')
            ->whereIn('c.id', $collectionIds)
            ->groupBy(['c.id', 'c.totalfiles', 'c.releases_id'])
            ->selectRaw('c.id, c.totalfiles, c.releases_id, COALESCE(SUM(b.totalparts),0) expected_parts, COALESCE(SUM(b.currentparts),0) present_parts, COUNT(b.id) observed_files, COALESCE(SUM(CASE WHEN b.currentparts >= b.totalparts AND b.totalparts > 0 THEN 1 ELSE 0 END),0) complete_files')
            ->get();
        $expectedParts = 0;
        $presentParts = 0;
        $expectedFiles = 0;
        $observedFiles = 0;
        $completeFiles = 0;
        $unresolved = 0;
        foreach ($rows as $row) {
            $expectedParts += (int) $row->expected_parts;
            $presentParts += (int) $row->present_parts;
            $expectedFiles += (int) $row->totalfiles;
            $observedFiles += (int) $row->observed_files;
            $completeFiles += (int) $row->complete_files;
            // Every collection owned by an open chain needs a terminal release
            // disposition before the root can close. A complete-but-unreleased
            // child is still pending attribution.
            if ($row->releases_id === null) {
                $unresolved++;
            }
        }

        return [
            'original_expected_parts' => $expectedParts,
            'original_present_parts' => $presentParts,
            'original_expected_files' => $expectedFiles,
            'original_observed_files' => $observedFiles,
            'original_complete_files' => $completeFiles,
            'unresolved_collections' => $unresolved,
        ];
    }

    /** @param list<int> $ids
     * @return list<int>
     */
    private function positiveUnique(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function normalizeDispositionReason(string $reason): string
    {
        $reason = strtolower(trim($reason));
        $reason = preg_replace('/[^a-z0-9]+/', '_', $reason) ?? '';
        $reason = trim($reason, '_');

        return substr($reason === '' ? 'unspecified' : $reason, 0, 80);
    }

    private function assertCollectionHandoffAcyclic(
        int $rootId,
        int $sourceCollectionId,
        int $targetCollectionId,
    ): void {
        $edges = DB::table('current_forward_collection_handoffs')
            ->where('chain_root_id', $rootId)
            ->pluck('target_collection_id', 'source_collection_id')
            ->mapWithKeys(static fn (mixed $target, mixed $source): array => [(int) $source => (int) $target])
            ->all();
        $cursor = $targetCollectionId;
        for ($step = 0; $step <= count($edges); $step++) {
            if ($cursor === $sourceCollectionId) {
                throw new RuntimeException('Current-forward collection handoff cycle detected.');
            }
            if (! isset($edges[$cursor])) {
                return;
            }
            $cursor = (int) $edges[$cursor];
        }

        throw new RuntimeException('Current-forward collection handoff cycle detected.');
    }

    /** @param list<int> $affectedGenerations */
    private function reconcileTerminalizedGeneration(array $affectedGenerations, string $failureReason): void
    {
        if ($affectedGenerations === [] || ! Schema::hasTable('settings')) {
            return;
        }
        $names = [
            'orchestrator_cf_permit',
            'orchestrator_cf_claimed',
            'orchestrator_cf_completed',
            'orchestrator_cf_failed',
            'orchestrator_cf_failure',
        ];
        $settings = DB::table('settings')
            ->whereIn('name', $names)
            ->orderBy('name')
            ->lockForUpdate()
            ->pluck('value', 'name');
        $permit = (int) $settings->get('orchestrator_cf_permit', 0);
        $claimed = (int) $settings->get('orchestrator_cf_claimed', 0);
        $completed = (int) $settings->get('orchestrator_cf_completed', 0);
        $failed = (int) $settings->get('orchestrator_cf_failed', 0);
        $terminalized = in_array($permit, $affectedGenerations, true)
            ? $permit
            : ($claimed !== $completed
                && $claimed !== $failed
                && in_array($claimed, $affectedGenerations, true)
                    ? $claimed
                    : 0);
        if ($terminalized <= 0) {
            return;
        }
        if ($permit === $terminalized) {
            DB::table('settings')->updateOrInsert(
                ['name' => 'orchestrator_cf_permit'],
                ['value' => '0'],
            );
        }
        DB::table('settings')->updateOrInsert(
            ['name' => 'orchestrator_cf_failed'],
            ['value' => (string) $terminalized],
        );
        DB::table('settings')->updateOrInsert(
            ['name' => 'orchestrator_cf_failure'],
            ['value' => $failureReason],
        );
    }

    public function maxWindows(): int
    {
        return (int) config('nntmux.orchestrator.current_forward_continuation_max_windows', 3);
    }

    public function maxParts(): int
    {
        return (int) config('nntmux.orchestrator.current_forward_continuation_max_parts', 30_000);
    }

    public function maxBinaries(): int
    {
        return (int) config('nntmux.orchestrator.current_forward_continuation_max_binaries', 1_500);
    }

    public function maxCollections(): int
    {
        return (int) config('nntmux.orchestrator.current_forward_continuation_max_collections', 300);
    }

    public function deadlineSeconds(): int
    {
        return (int) config('nntmux.orchestrator.current_forward_continuation_deadline_seconds', 7_200);
    }

    public function minimumProgressParts(): int
    {
        return (int) config('nntmux.orchestrator.current_forward_continuation_min_progress_parts', 100);
    }
}
