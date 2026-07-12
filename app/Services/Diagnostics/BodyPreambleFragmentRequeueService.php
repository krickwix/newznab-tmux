<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

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

        $rows = $this->candidateRows($groupId, $regexIds, $limit, $maxCurrentParts, $minTotalParts, $before, $afterCollectionId);

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
            'group' => ['id' => $groupId, 'name' => $groupName],
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
        int $afterCollectionId
    ): Collection {
        return DB::table('collections as c')
            ->join('binaries as b', 'b.collections_id', '=', 'c.id')
            ->select([
                'c.id as collection_id',
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
            if (isset($existing[$article])) {
                continue;
            }

            $rows[$article] = [
                'numberid' => $article,
                'groups_id' => $groupId,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        return DB::table('missed_parts')->insertOrIgnore(array_values($rows));
    }
}
