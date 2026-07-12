<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BodyPreambleFragmentRequeueService
{
    /**
     * @param  list<int>  $regexIds
     * @return array{
     *     group: array{id:int,name:string},
     *     updated: bool,
     *     candidates: int,
     *     inserted: int,
     *     skipped_existing: int,
     *     skipped_without_article: int,
     *     after_collection_id: int,
     *     batch: array{collection_id_min:int|null,collection_id_max:int|null,next_after_collection_id:int},
     *     candidate_numberids: list<int>,
     *     inserted_numberids: list<int>,
     *     skipped_existing_numberids: list<int>,
     *     sample: list<array<string,mixed>>
     * }
     */
    public function requeue(
        int|string $group,
        array $regexIds,
        int $limit,
        int $maxCurrentParts,
        int $minTotalParts,
        ?string $before,
        int $afterCollectionId,
        bool $update
    ): array {
        if ($update && $regexIds === []) {
            throw new InvalidArgumentException('Update mode requires at least one --regex selector.');
        }

        if ($update && ($before === null || $before === '')) {
            throw new InvalidArgumentException('Update mode requires --before to avoid requeueing newly gathered collections.');
        }

        $groupRow = $this->resolveGroup($group);
        $groupId = (int) $groupRow->id;
        $groupName = (string) $groupRow->name;
        $limit = max(1, min(10000, $limit));
        $maxCurrentParts = max(1, $maxCurrentParts);
        $minTotalParts = max(1, $minTotalParts);
        $afterCollectionId = max(0, $afterCollectionId);

        $rows = $this->candidateRows(
            $groupId,
            $regexIds,
            $limit,
            $maxCurrentParts,
            $minTotalParts,
            $before,
            $afterCollectionId,
            true,
        );

        $candidates = [];
        $skippedWithoutArticle = 0;
        foreach ($rows as $row) {
            $article = $this->extractGroupArticle((string) $row->xref, $groupName);
            if ($article === null) {
                $skippedWithoutArticle++;

                continue;
            }

            $candidates[] = [
                'collection_id' => (int) $row->collection_id,
                'binary_id' => (int) $row->binary_id,
                'article' => $article,
                'regex_id' => (int) $row->collection_regexes_id,
                'filenumber' => (int) $row->filenumber,
                'currentparts' => (int) $row->currentparts,
                'totalparts' => (int) $row->totalparts,
                'totalfiles' => (int) $row->totalfiles,
            ];
        }

        $existing = $this->existingMissedPartLookup($groupId, array_column($candidates, 'article'));
        $candidateNumberIds = array_values(array_map('intval', array_column($candidates, 'article')));
        $skippedExistingNumberIds = array_values(array_filter(
            $candidateNumberIds,
            static fn (int $article): bool => isset($existing[$article])
        ));
        $insertedNumberIds = [];
        $inserted = 0;
        if ($update) {
            $inserted = $this->insertMissingParts($groupId, $candidates, $existing);
            $insertedNumberIds = array_values(array_filter(
                $candidateNumberIds,
                static fn (int $article): bool => ! isset($existing[$article])
            ));
        }

        return [
            'group' => ['id' => $groupId, 'name' => (string) $groupRow->name],
            'updated' => $update,
            'candidates' => \count($candidates),
            'inserted' => $inserted,
            'skipped_existing' => \count($existing),
            'skipped_without_article' => $skippedWithoutArticle,
            'after_collection_id' => $afterCollectionId,
            'batch' => $this->batchSummary($rows, $afterCollectionId),
            'candidate_numberids' => $candidateNumberIds,
            'inserted_numberids' => $insertedNumberIds,
            'skipped_existing_numberids' => $skippedExistingNumberIds,
            'sample' => array_slice($candidates, 0, 25),
        ];
    }

    /**
     * @param  list<int>  $regexIds
     * @return array<string, mixed>
     */
    public function pruneRecovered(
        int|string $group,
        array $regexIds,
        int $limit,
        int $maxCurrentParts,
        int $minTotalParts,
        ?string $before,
        int $afterCollectionId,
        bool $update,
        ?string $expectedManifestHash = null,
    ): array {
        if ($regexIds === []) {
            throw new InvalidArgumentException('Prune mode requires at least one --regex selector.');
        }
        if ($before === null || $before === '') {
            throw new InvalidArgumentException('Prune mode requires --before to protect newly gathered collections.');
        }
        try {
            $cutoff = CarbonImmutable::parse($before);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Prune mode requires a valid --before timestamp.');
        }
        if (! $cutoff->isPast()) {
            throw new InvalidArgumentException('Prune mode requires --before to be in the past.');
        }

        $groupRow = $this->resolveGroup($group);
        $groupId = (int) $groupRow->id;
        $groupName = (string) $groupRow->name;
        $rows = $this->candidateRows(
            $groupId,
            $regexIds,
            max(1, min(10000, $limit)),
            max(1, $maxCurrentParts),
            max(1, $minTotalParts),
            $cutoff->format('Y-m-d H:i:s'),
            max(0, $afterCollectionId),
            true,
        );
        $sourceCollectionIds = $rows->pluck('collection_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $recoveredCollectionIds = $this->fullyRecoveredCollectionIds($groupId, $sourceCollectionIds);
        $manifestHash = $this->manifestHash($recoveredCollectionIds);
        if ($update && ($expectedManifestHash === null || ! hash_equals($manifestHash, $expectedManifestHash))) {
            throw new InvalidArgumentException('Update requires the exact --manifest-hash emitted by a matching dry-run.');
        }
        $deleted = 0;
        if ($update && $recoveredCollectionIds !== []) {
            $deleted = $this->deleteProvenRecovered(
                $groupId,
                $recoveredCollectionIds,
                $regexIds,
                $cutoff->format('Y-m-d H:i:s'),
                max(1, $maxCurrentParts),
                max(1, $minTotalParts),
            );
        }

        return [
            'group' => ['id' => $groupId, 'name' => $groupName],
            'updated' => $update,
            'candidates' => $rows->count(),
            'recovered' => count($recoveredCollectionIds),
            'deleted' => $deleted,
            'recovered_collection_ids' => $recoveredCollectionIds,
            'manifest_hash' => $manifestHash,
            'batch' => $this->batchSummary($rows, max(0, $afterCollectionId)),
        ];
    }

    /**
     * @param  list<int>  $sourceCollectionIds
     * @return list<int>
     */
    private function fullyRecoveredCollectionIds(int $groupId, array $sourceCollectionIds): array
    {
        if ($sourceCollectionIds === []) {
            return [];
        }
        $sourceQuery = DB::table('parts as p')
            ->join('binaries as b', 'b.id', '=', 'p.binaries_id')
            ->whereIn('b.collections_id', $sourceCollectionIds)
            ->select(['b.collections_id', 'p.number'])
            ->orderBy('b.collections_id')
            ->orderBy('p.number');
        $partsByCollection = [];
        foreach ($sourceQuery->get() as $part) {
            $partsByCollection[(int) $part->collections_id][] = (int) $part->number;
        }
        $articles = array_values(array_unique(array_merge(...array_values($partsByCollection))));
        $recoveredArticles = $this->recoveredArticleLookup($groupId, $articles, $sourceCollectionIds);

        return array_values(array_filter(
            $sourceCollectionIds,
            static fn (int $collectionId): bool => self::hasCoherentDestination(
                $partsByCollection[$collectionId] ?? [],
                $recoveredArticles,
            ),
        ));
    }

    /**
     * @param  list<int>  $articles
     * @param  list<int>  $sourceCollectionIds
     * @return array<int, array<int, true>>
     */
    private function recoveredArticleLookup(int $groupId, array $articles, array $sourceCollectionIds, bool $lock = false): array
    {
        $lookup = [];
        foreach (array_chunk(array_values(array_unique($articles)), 500) as $chunk) {
            $query = DB::table('parts as p')
                ->join('binaries as b', 'b.id', '=', 'p.binaries_id')
                ->join('collections as c', 'c.id', '=', 'b.collections_id')
                ->where('c.groups_id', $groupId)
                ->whereIn('p.number', $chunk)
                ->whereNotIn('c.id', $sourceCollectionIds)
                ->where('c.subject', 'not like', '[PRiVATE]%');
            if ($lock) {
                $query->lockForUpdate();
            }
            foreach ($query->select(['p.number', 'c.id as collection_id'])->get() as $part) {
                $lookup[(int) $part->number][(int) $part->collection_id] = true;
            }
        }

        return $lookup;
    }

    /**
     * @param  list<int>  $parts
     * @param  array<int, array<int, true>>  $destinationsByArticle
     */
    private static function hasCoherentDestination(array $parts, array $destinationsByArticle): bool
    {
        if ($parts === []) {
            return false;
        }
        $common = null;
        foreach ($parts as $article) {
            $destinations = array_keys($destinationsByArticle[$article] ?? []);
            $common = $common === null ? $destinations : array_values(array_intersect($common, $destinations));
            if ($common === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<int>  $sourceCollectionIds
     * @param  list<int>  $regexIds
     */
    private function deleteProvenRecovered(
        int $groupId,
        array $sourceCollectionIds,
        array $regexIds,
        string $before,
        int $maxCurrentParts,
        int $minTotalParts,
    ): int {
        return DB::transaction(function () use (
            $groupId,
            $sourceCollectionIds,
            $regexIds,
            $before,
            $maxCurrentParts,
            $minTotalParts,
        ): int {
            $lockedCollectionIds = DB::table('collections')
                ->whereIn('id', $sourceCollectionIds)
                ->where('groups_id', $groupId)
                ->where('filecheck', 0)
                ->where('totalfiles', '>', 1)
                ->whereIn('collection_regexes_id', $regexIds)
                ->where('dateadded', '<', $before)
                ->where('subject', 'like', '[PRiVATE]%[newzNZB]%')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $binaryRows = DB::table('binaries')
                ->whereIn('collections_id', $lockedCollectionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'collections_id', 'currentparts', 'totalparts']);
            $binariesByCollection = [];
            foreach ($binaryRows as $binary) {
                $binariesByCollection[(int) $binary->collections_id][] = $binary;
            }
            $eligibleCollectionIds = array_values(array_filter(
                $lockedCollectionIds,
                static fn (int $collectionId): bool => count($binariesByCollection[$collectionId] ?? []) === 1
                    && (int) $binariesByCollection[$collectionId][0]->currentparts <= $maxCurrentParts
                    && (int) $binariesByCollection[$collectionId][0]->totalparts >= $minTotalParts,
            ));
            $binaryIds = array_map(
                static fn (int $collectionId): int => (int) $binariesByCollection[$collectionId][0]->id,
                $eligibleCollectionIds,
            );
            $sourceParts = [];
            foreach (DB::table('parts')
                ->whereIn('binaries_id', $binaryIds)
                ->orderBy('binaries_id')
                ->orderBy('number')
                ->lockForUpdate()
                ->get(['binaries_id', 'number']) as $part) {
                $sourceParts[(int) $part->binaries_id][] = (int) $part->number;
            }
            $articles = array_values(array_unique(array_merge(...array_values($sourceParts))));
            $destinations = $this->recoveredArticleLookup($groupId, $articles, $eligibleCollectionIds, true);
            $deleteCollectionIds = [];
            $deleteBinaryIds = [];
            foreach ($eligibleCollectionIds as $index => $collectionId) {
                $binaryId = $binaryIds[$index];
                if (self::hasCoherentDestination($sourceParts[$binaryId] ?? [], $destinations)) {
                    $deleteCollectionIds[] = $collectionId;
                    $deleteBinaryIds[] = $binaryId;
                }
            }
            DB::table('parts')->whereIn('binaries_id', $deleteBinaryIds)->delete();
            DB::table('binaries')->whereIn('id', $deleteBinaryIds)->delete();

            return DB::table('collections')->whereIn('id', $deleteCollectionIds)->delete();
        });
    }

    /** @param list<int> $collectionIds */
    private function manifestHash(array $collectionIds): string
    {
        sort($collectionIds, SORT_NUMERIC);

        return hash('sha256', implode(',', $collectionIds));
    }

    /**
     * @param  Collection<int,\stdClass>  $rows
     * @return array{collection_id_min:int|null,collection_id_max:int|null,next_after_collection_id:int}
     */
    private function batchSummary(Collection $rows, int $afterCollectionId): array
    {
        $collectionIds = $rows
            ->pluck('collection_id')
            ->map(static fn (mixed $id): int => (int) $id);
        $maxCollectionId = $collectionIds->max();

        return [
            'collection_id_min' => $collectionIds->isEmpty() ? null : (int) $collectionIds->min(),
            'collection_id_max' => $collectionIds->isEmpty() ? null : (int) $maxCollectionId,
            'next_after_collection_id' => $maxCollectionId === null ? $afterCollectionId : (int) $maxCollectionId,
        ];
    }

    private function resolveGroup(int|string $group): object
    {
        $query = DB::table('usenet_groups')->select(['id', 'name']);
        $row = is_numeric($group)
            ? $query->where('id', (int) $group)->first()
            : $query->where('name', (string) $group)->first();

        if ($row === null) {
            throw new InvalidArgumentException('Unknown Usenet group: '.(string) $group);
        }

        return $row;
    }

    /**
     * @param  list<int>  $regexIds
     * @return Collection<int,\stdClass>
     */
    private function candidateRows(
        int $groupId,
        array $regexIds,
        int $limit,
        int $maxCurrentParts,
        int $minTotalParts,
        ?string $before,
        int $afterCollectionId,
        bool $privateOnly = false,
    ): Collection {
        return DB::table('collections as c')
            ->join('binaries as b', 'b.collections_id', '=', 'c.id')
            ->select([
                'c.id as collection_id',
                'c.subject',
                'c.xref',
                'c.totalfiles',
                'c.collection_regexes_id',
                'b.id as binary_id',
                'b.filenumber',
                'b.currentparts',
                'b.totalparts',
            ])
            ->where('c.groups_id', $groupId)
            ->where('c.filecheck', 0)
            ->where('c.totalfiles', '>', 1)
            ->where('c.id', '>', $afterCollectionId)
            ->where('b.currentparts', '<=', $maxCurrentParts)
            ->where('b.totalparts', '>=', $minTotalParts)
            ->whereRaw('(SELECT COUNT(*) FROM binaries b2 WHERE b2.collections_id = c.id) = 1')
            ->when($regexIds !== [], static fn ($query) => $query->whereIn('c.collection_regexes_id', $regexIds))
            ->when($privateOnly, static fn ($query) => $query->where('c.subject', 'like', '[PRiVATE]%[newzNZB]%'))
            ->when($before !== null && $before !== '', static fn ($query) => $query->where('c.dateadded', '<', $before))
            ->orderBy('c.id')
            ->limit($limit)
            ->get();
    }

    private function extractGroupArticle(string $xref, string $groupName): ?int
    {
        if (preg_match('/(?:^|\s)'.preg_quote($groupName, '/').':(\d+)/i', $xref, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * @param  list<int>  $articles
     * @return array<int,true>
     */
    private function existingMissedPartLookup(int $groupId, array $articles): array
    {
        $articles = array_values(array_unique(array_map('intval', $articles)));
        if ($articles === []) {
            return [];
        }

        $lookup = [];
        foreach (DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->whereIn('numberid', $articles)
            ->pluck('numberid') as $article) {
            $lookup[(int) $article] = true;
        }

        return $lookup;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  array<int,true>  $existing
     */
    private function insertMissingParts(int $groupId, array $candidates, array $existing): int
    {
        $now = now();
        $rows = [];
        foreach ($candidates as $candidate) {
            $article = (int) $candidate['article'];
            $rows[$article] = [
                'numberid' => $article,
                'groups_id' => $groupId,
                'attempts' => 0,
                'recovery_kind' => 'body_preamble',
                'recovery_source_collection_id' => (int) $candidate['collection_id'],
                'recovery_source_binary_id' => (int) $candidate['binary_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        if (DB::getDriverName() === 'mysql') {
            foreach (array_chunk(array_values($rows), 250) as $chunk) {
                $bindings = [];
                $placeholders = [];
                foreach ($chunk as $row) {
                    $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push(
                        $bindings,
                        $row['numberid'],
                        $row['groups_id'],
                        $row['attempts'],
                        $row['recovery_kind'],
                        $row['recovery_source_collection_id'],
                        $row['recovery_source_binary_id'],
                        $row['created_at'],
                        $row['updated_at'],
                    );
                }
                DB::statement(
                    'INSERT INTO missed_parts '
                    .'(numberid, groups_id, attempts, recovery_kind, recovery_source_collection_id, recovery_source_binary_id, created_at, updated_at) VALUES '
                    .implode(',', $placeholders)
                    .' ON DUPLICATE KEY UPDATE '
                    .'recovery_kind = COALESCE(recovery_kind, VALUES(recovery_kind)), '
                    .'recovery_source_collection_id = COALESCE(recovery_source_collection_id, VALUES(recovery_source_collection_id)), '
                    .'recovery_source_binary_id = COALESCE(recovery_source_binary_id, VALUES(recovery_source_binary_id)), '
                    .'updated_at = VALUES(updated_at)',
                    $bindings,
                );
            }
        } else {
            foreach ($rows as $row) {
                DB::table('missed_parts')
                    ->where('groups_id', $groupId)
                    ->where('numberid', $row['numberid'])
                    ->whereNull('recovery_kind')
                    ->update([
                        'recovery_kind' => $row['recovery_kind'],
                        'recovery_source_collection_id' => $row['recovery_source_collection_id'],
                        'recovery_source_binary_id' => $row['recovery_source_binary_id'],
                        'updated_at' => $row['updated_at'],
                    ]);
            }
            DB::table('missed_parts')->insertOrIgnore(array_values($rows));
        }

        return count(array_diff(array_keys($rows), array_keys($existing)));
    }
}
