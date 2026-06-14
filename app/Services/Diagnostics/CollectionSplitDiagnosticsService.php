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
            'regexes' => $includeRegexes ? $this->regexBreakdown($groupId, (string) $groupRow->name) : [],
            'cohorts' => $this->cohorts($groupId, (string) $groupRow->name, $limit),
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
    private function regexBreakdown(int $groupId, string $groupName): array
    {
        return DB::query()
            ->fromSub($this->oneBinaryMultifileCollections($groupId, $groupName), 's')
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
    private function cohorts(int $groupId, string $groupName, int $limit): array
    {
        $rows = DB::query()
            ->fromSub($this->oneBinaryMultifileCollections($groupId, $groupName), 's')
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
            ->selectRaw('SUM(CASE WHEN totalparts > 0 AND currentparts >= totalparts THEN 1 ELSE 0 END) AS complete_binaries')
            ->selectRaw('SUM(CASE WHEN totalparts > 0 AND currentparts < totalparts THEN 1 ELSE 0 END) AS incomplete_binaries')
            ->selectRaw('MIN(currentparts) AS min_currentparts')
            ->selectRaw('MAX(currentparts) AS max_currentparts')
            ->selectRaw('ROUND(AVG(currentparts), 2) AS avg_currentparts')
            ->selectRaw('MIN(totalparts) AS min_totalparts')
            ->selectRaw('MAX(totalparts) AS max_totalparts')
            ->selectRaw('ROUND(AVG(totalparts), 2) AS avg_totalparts')
            ->selectRaw('MIN(date) AS min_date')
            ->selectRaw('MAX(date) AS max_date')
            ->selectRaw($this->regexIdsExpression())
            ->when(
                DB::connection()->getDriverName() === 'sqlite',
                fn (Builder $query): Builder => $query->selectRaw($this->xrefsExpression()),
                fn (Builder $query): Builder => $query
                    ->selectRaw('COALESCE(MIN(NULLIF(xref_article, 0)), 0) AS xref_min_article')
                    ->selectRaw('COALESCE(MAX(NULLIF(xref_article, 0)), 0) AS xref_max_article')
                    ->selectRaw('COUNT(DISTINCT NULLIF(xref_article, 0)) AS xref_articles')
            )
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $binaries = (int) $row->binaries;
            $distinctFilenumbers = (int) $row->distinct_filenumbers;
            $totalFiles = (int) $row->totalfiles;
            $xrefArticleStats = $this->resolveXrefArticleStats($row, $groupName);

            $result[] = [
                'noise' => (string) $row->noise,
                'totalfiles' => $totalFiles,
                'collections' => (int) $row->collections,
                'hashes' => (int) $row->hashes,
                'posters' => (int) $row->posters,
                'binaries' => $binaries,
                'distinct_filenumbers' => $distinctFilenumbers,
                'duplicate_filenumbers' => max(0, $binaries - $distinctFilenumbers),
                'filenumber_coverage_percent' => $totalFiles > 0
                    ? round(($distinctFilenumbers / $totalFiles) * 100, 2)
                    : 0.0,
                'min_filenumber' => (int) $row->min_filenumber,
                'max_filenumber' => (int) $row->max_filenumber,
                'filenumber_span' => (int) $row->min_filenumber.','.(int) $row->max_filenumber,
                'xref_min_article' => $xrefArticleStats['min'],
                'xref_max_article' => $xrefArticleStats['max'],
                'xref_article_span' => $xrefArticleStats['span'],
                'xref_articles' => $xrefArticleStats['articles'],
                'xref_missing_articles' => $xrefArticleStats['missing'],
                'xref_article_coverage_percent' => $xrefArticleStats['coverage_percent'],
                'complete_binaries' => (int) $row->complete_binaries,
                'incomplete_binaries' => (int) $row->incomplete_binaries,
                'min_currentparts' => (int) $row->min_currentparts,
                'max_currentparts' => (int) $row->max_currentparts,
                'avg_currentparts' => (float) $row->avg_currentparts,
                'min_totalparts' => (int) $row->min_totalparts,
                'max_totalparts' => (int) $row->max_totalparts,
                'avg_totalparts' => (float) $row->avg_totalparts,
                'classification' => $this->classifyCohort(
                    (int) $row->binaries,
                    (int) $row->complete_binaries,
                    (int) $row->incomplete_binaries,
                    (int) $row->max_currentparts
                ),
                'min_date' => (string) $row->min_date,
                'max_date' => (string) $row->max_date,
                'date_span_seconds' => $this->dateSpanSeconds((string) $row->min_date, (string) $row->max_date),
                'regex_ids' => $this->normalizeRegexIds((string) $row->regex_ids),
            ];
        }

        return $result;
    }

    private function classifyCohort(int $binaries, int $completeBinaries, int $incompleteBinaries, int $maxCurrentParts): string
    {
        if ($binaries > 0 && $incompleteBinaries === $binaries && $maxCurrentParts <= 2) {
            return 'incomplete_part_fragments';
        }

        if ($completeBinaries > 0 && $incompleteBinaries > 0) {
            return 'mixed_completeness';
        }

        if ($completeBinaries > 0 && $incompleteBinaries === 0) {
            return 'complete_split_candidate';
        }

        return 'incomplete_split_candidate';
    }

    private function regexIdsExpression(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return 'GROUP_CONCAT(DISTINCT collection_regexes_id) AS regex_ids';
        }

        return "GROUP_CONCAT(DISTINCT collection_regexes_id ORDER BY collection_regexes_id SEPARATOR ',') AS regex_ids";
    }

    private function xrefsExpression(): string
    {
        return "GROUP_CONCAT(xref, ' ') AS xrefs";
    }

    private function normalizeRegexIds(string $regexIds): string
    {
        $ids = array_filter(explode(',', $regexIds), static fn (string $id): bool => $id !== '');
        $ids = array_map(static fn (string $id): int => (int) $id, $ids);
        sort($ids, SORT_NUMERIC);

        return implode(',', array_map(static fn (int $id): string => (string) $id, $ids));
    }

    /**
     * @return array{min:int,max:int,span:int,articles:int,missing:int,coverage_percent:float}
     */
    private function resolveXrefArticleStats(object $row, string $groupName): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $this->xrefArticleStats($this->extractXrefArticles((string) ($row->xrefs ?? ''), $groupName));
        }

        $min = (int) ($row->xref_min_article ?? 0);
        $max = (int) ($row->xref_max_article ?? 0);
        $count = (int) ($row->xref_articles ?? 0);
        if ($min <= 0 || $max <= 0 || $count <= 0) {
            return $this->xrefArticleStats([]);
        }

        $span = ($max - $min) + 1;

        return [
            'min' => $min,
            'max' => $max,
            'span' => $span,
            'articles' => $count,
            'missing' => max(0, $span - $count),
            'coverage_percent' => $span > 0 ? round(($count / $span) * 100, 2) : 0.0,
        ];
    }

    /**
     * @return list<int>
     */
    private function extractXrefArticles(string $xrefs, string $groupName): array
    {
        $articles = [];
        $groupPattern = preg_quote($groupName, '/');

        if (preg_match_all('/(?:^|\s)'.$groupPattern.':(\d+)/i', $xrefs, $matches) > 0) {
            $articles = array_map('intval', $matches[1]);
        } elseif (preg_match_all('/:(\d+)(?:\s|$)/', $xrefs, $matches) > 0) {
            $articles = array_map('intval', $matches[1]);
        }

        $articles = array_values(array_unique(array_filter($articles, static fn (int $article): bool => $article > 0)));
        sort($articles, SORT_NUMERIC);

        return $articles;
    }

    /**
     * @param  list<int>  $articles
     * @return array{min:int,max:int,span:int,articles:int,missing:int,coverage_percent:float}
     */
    private function xrefArticleStats(array $articles): array
    {
        if ($articles === []) {
            return [
                'min' => 0,
                'max' => 0,
                'span' => 0,
                'articles' => 0,
                'missing' => 0,
                'coverage_percent' => 0.0,
            ];
        }

        $min = min($articles);
        $max = max($articles);
        $span = ($max - $min) + 1;
        $count = count($articles);

        return [
            'min' => $min,
            'max' => $max,
            'span' => $span,
            'articles' => $count,
            'missing' => max(0, $span - $count),
            'coverage_percent' => $span > 0 ? round(($count / $span) * 100, 2) : 0.0,
        ];
    }

    private function dateSpanSeconds(string $minDate, string $maxDate): int
    {
        $minTimestamp = strtotime($minDate);
        $maxTimestamp = strtotime($maxDate);

        if ($minTimestamp === false || $maxTimestamp === false) {
            return 0;
        }

        return max(0, $maxTimestamp - $minTimestamp);
    }

    private function oneBinaryMultifileCollections(int $groupId, ?string $groupName = null): Builder
    {
        $query = DB::table('collections as c')
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
                'c.xref',
            ])
            ->havingRaw('COUNT(b.id) = 1')
            ->selectRaw('c.id, c.noise, c.totalfiles, c.collectionhash, c.fromname, c.collection_regexes_id, c.date, c.xref')
            ->selectRaw('MIN(b.filenumber) AS filenumber')
            ->selectRaw('MIN(b.totalparts) AS totalparts')
            ->selectRaw('MIN(b.currentparts) AS currentparts');

        if (DB::connection()->getDriverName() !== 'sqlite' && $groupName !== null && $groupName !== '') {
            $query->selectRaw(
                "CAST(
                    CASE
                        WHEN LOCATE(?, c.xref) = 0 THEN 0
                        ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(c.xref, ?, -1), ' ', 1)
                    END AS UNSIGNED
                ) AS xref_article",
                [$groupName.':', $groupName.':']
            );
        }

        return $query;
    }
}
