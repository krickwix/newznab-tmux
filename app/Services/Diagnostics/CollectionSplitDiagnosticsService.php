<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CollectionSplitDiagnosticsService
{
    /**
     * @return array{
     *     group: array{id:int,name:string},
     *     totals: array<string,int>,
     *     regexes: list<array{collection_regexes_id:int,one_binary_multifile:int}>,
     *     cohorts: list<array<string,mixed>>
     * }
     */
    public function summarizeGroup(
        int|string $group,
        int $limit = 10,
        bool $includeTotals = true,
        bool $includeRegexes = true
    ): array {
        $groupRow = $this->resolveGroup($group);
        $groupId = (int) $groupRow->id;
        $limit = max(1, min(100, $limit));

        return [
            'group' => [
                'id' => $groupId,
                'name' => (string) $groupRow->name,
            ],
            'totals' => $includeTotals ? $this->totals($groupId) : [],
            'regexes' => $includeRegexes ? $this->regexBreakdown($groupId) : [],
            'cohorts' => $this->cohorts($groupId, $limit),
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
     * @return array<string,int>
     */
    private function totals(int $groupId): array
    {
        $perCollection = DB::table('collections as c')
            ->leftJoin('binaries as b', 'b.collections_id', '=', 'c.id')
            ->where('c.groups_id', $groupId)
            ->groupBy(['c.id', 'c.totalfiles'])
            ->selectRaw('c.id, c.totalfiles, COUNT(b.id) AS binary_count');

        $row = DB::query()
            ->fromSub($perCollection, 's')
            ->selectRaw('COUNT(*) AS collections')
            ->selectRaw('SUM(CASE WHEN totalfiles > 1 THEN 1 ELSE 0 END) AS multifile_collections')
            ->selectRaw('SUM(CASE WHEN binary_count = 1 THEN 1 ELSE 0 END) AS one_binary')
            ->selectRaw('SUM(CASE WHEN binary_count = 1 AND totalfiles > 1 THEN 1 ELSE 0 END) AS one_binary_multifile')
            ->selectRaw('SUM(CASE WHEN binary_count > 1 THEN 1 ELSE 0 END) AS multi_binary')
            ->first();

        return [
            'collections' => (int) ($row->collections ?? 0),
            'multifile_collections' => (int) ($row->multifile_collections ?? 0),
            'one_binary' => (int) ($row->one_binary ?? 0),
            'one_binary_multifile' => (int) ($row->one_binary_multifile ?? 0),
            'multi_binary' => (int) ($row->multi_binary ?? 0),
        ];
    }

    /**
     * @return list<array{collection_regexes_id:int,one_binary_multifile:int}>
     */
    private function regexBreakdown(int $groupId): array
    {
        return DB::query()
            ->fromSub($this->oneBinaryMultifileCollections($groupId), 's')
            ->groupBy('collection_regexes_id')
            ->orderByDesc('one_binary_multifile')
            ->selectRaw('collection_regexes_id, COUNT(*) AS one_binary_multifile')
            ->get()
            ->map(
                static fn (object $row): array => [
                    'collection_regexes_id' => (int) $row->collection_regexes_id,
                    'one_binary_multifile' => (int) $row->one_binary_multifile,
                ]
            )
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function cohorts(int $groupId, int $limit): array
    {
        $rows = DB::query()
            ->fromSub($this->oneBinaryMultifileCollections($groupId), 's')
            ->groupBy(['noise', 'totalfiles'])
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit($limit)
            ->selectRaw('noise, totalfiles')
            ->selectRaw('COUNT(*) AS collections')
            ->selectRaw('COUNT(DISTINCT collectionhash) AS hashes')
            ->selectRaw('COUNT(DISTINCT fromname) AS posters')
            ->selectRaw('COUNT(*) AS binaries')
            ->selectRaw('COUNT(DISTINCT filenumber) AS distinct_filenumbers')
            ->selectRaw('MIN(filenumber) AS min_filenumber')
            ->selectRaw('MAX(filenumber) AS max_filenumber')
            ->selectRaw('SUM(CASE WHEN totalparts > 0 AND currentparts < totalparts THEN 1 ELSE 0 END) AS incomplete_binaries')
            ->selectRaw('MIN(date) AS min_date')
            ->selectRaw('MAX(date) AS max_date')
            ->selectRaw($this->regexIdsExpression())
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'noise' => (string) $row->noise,
                'totalfiles' => (int) $row->totalfiles,
                'collections' => (int) $row->collections,
                'hashes' => (int) $row->hashes,
                'posters' => (int) $row->posters,
                'binaries' => (int) $row->binaries,
                'distinct_filenumbers' => (int) $row->distinct_filenumbers,
                'min_filenumber' => (int) $row->min_filenumber,
                'max_filenumber' => (int) $row->max_filenumber,
                'filenumber_span' => (int) $row->min_filenumber.','.(int) $row->max_filenumber,
                'incomplete_binaries' => (int) $row->incomplete_binaries,
                'min_date' => (string) $row->min_date,
                'max_date' => (string) $row->max_date,
                'regex_ids' => $this->normalizeRegexIds((string) $row->regex_ids),
            ];
        }

        return $result;
    }

    private function regexIdsExpression(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return 'GROUP_CONCAT(DISTINCT collection_regexes_id) AS regex_ids';
        }

        return "GROUP_CONCAT(DISTINCT collection_regexes_id ORDER BY collection_regexes_id SEPARATOR ',') AS regex_ids";
    }

    private function normalizeRegexIds(string $regexIds): string
    {
        $ids = array_filter(explode(',', $regexIds), static fn (string $id): bool => $id !== '');
        $ids = array_map(static fn (string $id): int => (int) $id, $ids);
        sort($ids, SORT_NUMERIC);

        return implode(',', array_map(static fn (int $id): string => (string) $id, $ids));
    }

    private function oneBinaryMultifileCollections(int $groupId): Builder
    {
        return DB::table('collections as c')
            ->join('binaries as b', 'b.collections_id', '=', 'c.id')
            ->where('c.groups_id', $groupId)
            ->where('c.totalfiles', '>', 1)
            ->groupBy([
                'c.id',
                'c.noise',
                'c.totalfiles',
                'c.collectionhash',
                'c.fromname',
                'c.collection_regexes_id',
                'c.date',
            ])
            ->havingRaw('COUNT(b.id) = 1')
            ->selectRaw('c.id, c.noise, c.totalfiles, c.collectionhash, c.fromname, c.collection_regexes_id, c.date')
            ->selectRaw('MIN(b.filenumber) AS filenumber')
            ->selectRaw('MIN(b.totalparts) AS totalparts')
            ->selectRaw('MIN(b.currentparts) AS currentparts');
    }
}
