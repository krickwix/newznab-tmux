<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Services\Metrics\SplitCollectionTelemetry;
use App\Services\Orchestrator\CurrentForwardTerminalSplitRepair;
use App\Services\Orchestrator\CurrentForwardWindowLineage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SplitCollectionReconciler
{
    private const int ROTATING_COLLECTIONS_PER_GROUP = 100;

    private const int NEWEST_COLLECTIONS_PER_GROUP = 100;

    private const int DISCOVERY_EDGE_OVERLAP = 20;

    private const int DEFAULT_MAX_PAIRS_PER_PASS = 20;

    private const int MAX_PAIRS_PER_PASS_CEILING = 500;

    private const int DEFAULT_MAX_SOURCE_COLLECTIONS_PER_PASS = 100;

    private const int MAX_SOURCE_COLLECTIONS_PER_PASS_CEILING = 2000;

    private const int MAX_TOTAL_FILES = 200;

    private const int MAX_POSTING_GAP_SECONDS = 120;

    private const int MAX_XREF_ARTICLE_GAP = 1000;

    /**
     * A split anchor is the payload file, so the PAR2 companions follow it by
     * roughly one article per anchor part. Large anchors therefore need a wider
     * per-group override than the small-anchor default.
     */
    private const int MAX_XREF_ARTICLE_GAP_CEILING = 20000;

    private const int MAX_DYNAMIC_PAIR_ARTICLE_GAP = 12000;

    private const int MAX_DYNAMIC_PAIR_TOTAL_PARTS = 12000;

    private const int MIN_DYNAMIC_PAIR_RESIDUAL = -3;

    private const int MAX_DYNAMIC_PAIR_RESIDUAL = 0;

    private const int MAX_MATCHING_COLLECTIONS = 20;

    private const int DEFAULT_MAX_FANOUT_FILES = 20;

    private const int MAX_FANOUT_FILES_CEILING = 200;

    /** @var array<int, string> */
    private array $groupNamesById = [];

    private readonly CurrentForwardWindowLineage $currentForwardLineage;

    private readonly CurrentForwardTerminalSplitRepair $terminalSplitRepair;

    private readonly SplitCollectionTelemetry $telemetry;

    /** @var array<string,array<string,int>> */
    private array $pairXrefDecisionCounts = [];

    public function __construct(
        ?CurrentForwardWindowLineage $currentForwardLineage = null,
        ?SplitCollectionTelemetry $telemetry = null,
        ?CurrentForwardTerminalSplitRepair $terminalSplitRepair = null,
    ) {
        $this->currentForwardLineage = $currentForwardLineage ?? new CurrentForwardWindowLineage;
        $this->telemetry = $telemetry ?? new SplitCollectionTelemetry;
        $this->terminalSplitRepair = $terminalSplitRepair ?? new CurrentForwardTerminalSplitRepair(
            $this->currentForwardLineage,
        );
    }

    public function reconcile(?int $groupId): int
    {
        $this->pairXrefDecisionCounts = [];
        $groupIds = $this->allowedGroupIds($groupId);
        if ($groupIds === []) {
            return 0;
        }

        try {
            $merged = 0;
            $sourceCollectionsMerged = 0;
            $maxPairs = $this->maxPairsPerPass();
            $maxSourceCollections = $this->maxSourceCollectionsPerPass();
            $cutoff = now()->subHours($this->lookbackHours())->toDateTimeString();
            foreach ($groupIds as $allowedGroupId) {
                $collectionIds = $this->expandCandidateCohorts(
                    $allowedGroupId,
                    $this->candidateCollectionIds($allowedGroupId, $cutoff),
                    $cutoff,
                );
                $pairs = $this->uniquePairs($allowedGroupId, $collectionIds);
                $fanouts = $this->uniqueFanoutCohorts($allowedGroupId, $collectionIds);
                foreach ($this->interleavedCandidates($pairs, $fanouts) as $candidate) {
                    $sourceCount = count($candidate['companion_ids']);
                    if ($merged >= $maxPairs
                        || $sourceCollectionsMerged + $sourceCount > $maxSourceCollections
                    ) {
                        return $merged;
                    }
                    $didMerge = $candidate['type'] === 'pair'
                        ? $this->mergePair($allowedGroupId, $candidate['anchor_id'], $candidate['companion_ids'][0])
                        : $this->mergeFanout($allowedGroupId, $candidate['anchor_id'], $candidate['companion_ids']);
                    if ($didMerge) {
                        $merged++;
                        $sourceCollectionsMerged += $sourceCount;
                    }
                }
            }

            return $merged;
        } finally {
            $this->telemetry->record($this->pairXrefDecisionCounts);
        }
    }

    /**
     * Alternate pair and fanout work so either shape can make progress under
     * a sustained backlog. Pairs go first because previous versions always
     * gave fanouts the entire success budget.
     *
     * @param  list<array{anchor_id:int,companion_id:int}>  $pairs
     * @param  list<array{anchor_id:int,companion_ids:list<int>}>  $fanouts
     * @return list<array{type:'pair'|'fanout',anchor_id:int,companion_ids:list<int>}>
     */
    private function interleavedCandidates(array $pairs, array $fanouts): array
    {
        $candidates = [];
        $length = max(count($pairs), count($fanouts));
        for ($index = 0; $index < $length; $index++) {
            if (isset($pairs[$index])) {
                $candidates[] = [
                    'type' => 'pair',
                    'anchor_id' => $pairs[$index]['anchor_id'],
                    'companion_ids' => [$pairs[$index]['companion_id']],
                ];
            }
            if (isset($fanouts[$index])) {
                $candidates[] = [
                    'type' => 'fanout',
                    'anchor_id' => $fanouts[$index]['anchor_id'],
                    'companion_ids' => $fanouts[$index]['companion_ids'],
                ];
            }
        }

        return $candidates;
    }

    /** @return list<int> */
    private function allowedGroupIds(?int $groupId): array
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            (array) config('nntmux.split_collection_reconcile_groups', []),
        ))));
        if ($names === [] || count($names) > 16) {
            return [];
        }

        $groups = DB::table('usenet_groups')
            ->whereIn('name', $names)
            ->when($groupId !== null, static fn ($query) => $query->where('id', $groupId))
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($groups as $group) {
            $this->groupNamesById[(int) $group->id] = (string) $group->name;
        }

        return $groups->map(static fn (object $group): int => (int) $group->id)->all();
    }

    /**
     * Keep discovery bounded while giving new posts low latency and rotating
     * through the complete retained horizon. Edge overlap keeps common
     * cohorts together; exact cohort expansion below makes boundaries safe.
     *
     * @return list<int>
     */
    private function candidateCollectionIds(int $groupId, string $cutoff): array
    {
        $base = DB::table('collections')
            ->where('groups_id', $groupId)
            ->where('filecheck', 0)
            ->whereNull('releases_id')
            ->whereBetween('totalfiles', [2, self::MAX_TOTAL_FILES])
            ->where('dateadded', '>=', $cutoff);

        $cursor = $this->discoveryCursor($groupId);
        $rotatingIds = $this->candidateIdsAfter($base, $cursor);
        if ($rotatingIds === [] && $cursor !== null) {
            $rotatingIds = $this->candidateIdsAfter($base, null);
        }
        if ($rotatingIds === []) {
            $this->forgetDiscoveryCursor($groupId);

            return [];
        }

        $first = $rotatingIds[0];
        $last = $rotatingIds[array_key_last($rotatingIds)];
        $this->storeDiscoveryCursor($groupId, $last);

        $previousIds = (clone $base)
            ->where(static fn (Builder $query) => $query
                ->where('dateadded', '<', $first['dateadded'])
                ->orWhere(static fn (Builder $sameDate) => $sameDate
                    ->where('dateadded', $first['dateadded'])
                    ->where('id', '<', $first['id'])))
            ->orderByDesc('dateadded')
            ->orderByDesc('id')
            ->limit(self::DISCOVERY_EDGE_OVERLAP)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $followingIds = (clone $base)
            ->where(static fn (Builder $query) => $query
                ->where('dateadded', '>', $last['dateadded'])
                ->orWhere(static fn (Builder $sameDate) => $sameDate
                    ->where('dateadded', $last['dateadded'])
                    ->where('id', '>', $last['id'])))
            ->orderBy('dateadded')
            ->orderBy('id')
            ->limit(self::DISCOVERY_EDGE_OVERLAP)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $newestIds = (clone $base)
            ->orderByDesc('dateadded')
            ->orderByDesc('id')
            ->limit(self::NEWEST_COLLECTIONS_PER_GROUP)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $collectionIds = array_values(array_unique([
            ...$previousIds,
            ...array_column($rotatingIds, 'id'),
            ...$followingIds,
            ...$newestIds,
        ]));
        sort($collectionIds, SORT_NUMERIC);

        return $collectionIds;
    }

    /**
     * @param  array{dateadded:string,id:int}|null  $cursor
     * @return list<array{dateadded:string,id:int}>
     */
    private function candidateIdsAfter(Builder $base, ?array $cursor): array
    {
        $rows = (clone $base)
            ->when($cursor !== null, static fn (Builder $query) => $query
                ->where(static fn (Builder $after) => $after
                    ->where('dateadded', '>', $cursor['dateadded'])
                    ->orWhere(static fn (Builder $sameDate) => $sameDate
                        ->where('dateadded', $cursor['dateadded'])
                        ->where('id', '>', $cursor['id']))))
            ->orderBy('dateadded')
            ->orderBy('id')
            ->limit(self::ROTATING_COLLECTIONS_PER_GROUP)
            ->get(['dateadded', 'id']);

        return $rows->map(static fn (object $row): array => [
            'dateadded' => (string) $row->dateadded,
            'id' => (int) $row->id,
        ])->all();
    }

    /**
     * Include the exact bounded identity cohort for every structural sample.
     * This makes page edges safe even when unrelated collection IDs are
     * interleaved between an anchor and its PAR2 companion(s).
     *
     * @param  list<int>  $collectionIds
     * @return list<int>
     */
    private function expandCandidateCohorts(int $groupId, array $collectionIds, string $cutoff): array
    {
        if ($collectionIds === []) {
            return [];
        }

        $expandedIds = $collectionIds;
        $seenCohorts = [];
        foreach ($this->collectionData($collectionIds) as $collection) {
            if (! $this->isAnchor($collection)
                && ! $this->isCompanion($collection)
                && ! $this->isFanoutCompanion($collection)
            ) {
                continue;
            }
            $timestamp = strtotime((string) $collection['date']);
            if ($timestamp === false) {
                continue;
            }
            $cohortKey = implode('|', [
                (string) $collection['fromname'],
                (string) $collection['totalfiles'],
                (string) $timestamp,
            ]);
            if (isset($seenCohorts[$cohortKey])) {
                continue;
            }
            $seenCohorts[$cohortKey] = true;

            $cohortIds = DB::table('collections')
                ->where('groups_id', $groupId)
                ->where('fromname', (string) $collection['fromname'])
                ->where('totalfiles', (int) $collection['totalfiles'])
                ->where('filecheck', 0)
                ->whereNull('releases_id')
                ->where('dateadded', '>=', $cutoff)
                ->whereBetween('date', [
                    date('Y-m-d H:i:s', $timestamp - self::MAX_POSTING_GAP_SECONDS),
                    date('Y-m-d H:i:s', $timestamp + self::MAX_POSTING_GAP_SECONDS),
                ])
                ->orderBy('id')
                ->limit($this->maxCohortRows())
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $expandedIds = [...$expandedIds, ...$cohortIds];
        }

        $expandedIds = array_values(array_unique($expandedIds));
        sort($expandedIds, SORT_NUMERIC);

        return $expandedIds;
    }

    /** @return array{dateadded:string,id:int}|null */
    private function discoveryCursor(int $groupId): ?array
    {
        try {
            $cursor = Cache::store($this->cursorStore())->get($this->cursorKey($groupId));
        } catch (Throwable $error) {
            Log::warning('Split collection discovery cursor could not be read; using bounded oldest/newest sampling.', [
                'group_id' => $groupId,
                'error' => $error->getMessage(),
            ]);

            return null;
        }
        if (! is_array($cursor)
            || ! is_string($cursor['dateadded'] ?? null)
            || strtotime($cursor['dateadded']) === false
            || (int) ($cursor['id'] ?? 0) <= 0
        ) {
            return null;
        }

        return ['dateadded' => $cursor['dateadded'], 'id' => (int) $cursor['id']];
    }

    /** @param array{dateadded:string,id:int} $cursor */
    private function storeDiscoveryCursor(int $groupId, array $cursor): void
    {
        try {
            Cache::store($this->cursorStore())->put($this->cursorKey($groupId), $cursor, now()->addDays(7));
        } catch (Throwable $error) {
            Log::warning('Split collection discovery cursor could not be persisted.', [
                'group_id' => $groupId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function forgetDiscoveryCursor(int $groupId): void
    {
        try {
            Cache::store($this->cursorStore())->forget($this->cursorKey($groupId));
        } catch (Throwable) {
            // Cursor persistence is an optimization; safe bounded sampling remains.
        }
    }

    private function cursorStore(): string
    {
        return trim((string) config('nntmux.split_collection_reconcile_cursor_store', 'array')) ?: 'array';
    }

    private function cursorKey(int $groupId): string
    {
        return sprintf(
            'nntmux:split-collection:discovery-cursor:v1:%dh:%d',
            $this->lookbackHours(),
            $groupId,
        );
    }

    private function lookbackHours(): int
    {
        return min(72, max(24, (int) config('nntmux.split_collection_reconcile_lookback_hours', 24)));
    }

    /**
     * Successful merges allowed per pass. Raising this drains a split backlog
     * faster at the cost of a longer pass; the default keeps steady-state work
     * small.
     */
    private function maxPairsPerPass(): int
    {
        return min(self::MAX_PAIRS_PER_PASS_CEILING, max(1, (int) config(
            'nntmux.split_collection_reconcile_max_pairs_per_pass',
            self::DEFAULT_MAX_PAIRS_PER_PASS,
        )));
    }

    /**
     * Source collections consumed per pass. A fanout merge spends several of
     * these at once, so this bounds total row churn independently of the merge
     * count.
     */
    private function maxSourceCollectionsPerPass(): int
    {
        return min(self::MAX_SOURCE_COLLECTIONS_PER_PASS_CEILING, max(1, (int) config(
            'nntmux.split_collection_reconcile_max_source_collections_per_pass',
            self::DEFAULT_MAX_SOURCE_COLLECTIONS_PER_PASS,
        )));
    }

    /**
     * Largest fanout a single anchor may absorb. Obfuscated posts that split
     * every PAR2 volume into its own collection exceed the small-cohort
     * default, so a deployment can widen this after auditing the group.
     */
    private function maxFanoutFiles(): int
    {
        return min(self::MAX_FANOUT_FILES_CEILING, max(2, (int) config(
            'nntmux.split_collection_max_fanout_files',
            self::DEFAULT_MAX_FANOUT_FILES,
        )));
    }

    /**
     * Row budget for the cohort queries that must observe every member of a
     * fanout plus one extra row to detect an ambiguous over-full cohort.
     */
    private function maxCohortRows(): int
    {
        return max(self::MAX_MATCHING_COLLECTIONS, $this->maxFanoutFiles()) + 1;
    }

    /**
     * @param  list<int>  $collectionIds
     * @return list<array{anchor_id:int,companion_id:int}>
     */
    private function uniquePairs(int $groupId, array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [];
        }

        $collections = $this->collectionData($collectionIds);
        $anchors = array_filter($collections, fn (array $collection): bool => $this->isAnchor($collection));
        $companions = array_filter($collections, fn (array $collection): bool => $this->isCompanion($collection));
        $matchesByAnchor = [];
        $anchorsByCompanion = [];

        foreach ($anchors as $anchorId => $anchor) {
            foreach ($companions as $companionId => $companion) {
                if (! $this->pairMetadataMatches($anchor, $companion, true)) {
                    continue;
                }
                $matchesByAnchor[$anchorId][] = $companionId;
                $anchorsByCompanion[$companionId][] = $anchorId;
            }
        }

        $pairs = [];
        foreach ($matchesByAnchor as $anchorId => $companionIds) {
            if (count($companionIds) !== 1) {
                continue;
            }
            $companionId = (int) $companionIds[0];
            if (count($anchorsByCompanion[$companionId] ?? []) !== 1) {
                continue;
            }
            if (! $this->isUniquePairInDatabase($anchors[$anchorId], $companions[$companionId])) {
                continue;
            }
            $pairs[] = ['anchor_id' => (int) $anchorId, 'companion_id' => $companionId];
        }

        usort($pairs, static fn (array $left, array $right): int => $left['anchor_id'] <=> $right['anchor_id']);

        return array_slice($pairs, 0, $this->maxPairsPerPass());
    }

    /**
     * @param  list<int>  $collectionIds
     * @return list<array{anchor_id:int,companion_ids:list<int>}>
     */
    private function uniqueFanoutCohorts(int $groupId, array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [];
        }

        $collections = $this->collectionData($collectionIds);
        $cohorts = [];
        foreach (array_filter($collections, fn (array $collection): bool => $this->isAnchor($collection)) as $anchor) {
            $companionIds = $this->fanoutCompanionIds($collections, $anchor);
            if ($companionIds === null || ! $this->isUniqueFanoutInDatabase($anchor, $companionIds)) {
                continue;
            }
            $cohorts[] = [
                'anchor_id' => (int) $anchor['id'],
                'companion_ids' => $companionIds,
            ];
        }

        usort($cohorts, static fn (array $left, array $right): int => $left['anchor_id'] <=> $right['anchor_id']);

        return array_slice($cohorts, 0, $this->maxPairsPerPass());
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array<int,array{id:int,groups_id:int,fromname:string,date:string,totalfiles:int,filecheck:int,releases_id:int|null,xref:string,binaries:list<array{id:int,filenumber:int,name:string,currentparts:int,totalparts:int}>}>
     */
    private function collectionData(array $collectionIds, bool $lock = false): array
    {
        $collectionQuery = DB::table('collections')
            ->whereIn('id', $collectionIds)
            ->orderBy('id');
        if ($lock) {
            $collectionQuery->lockForUpdate();
        }

        $collections = [];
        foreach ($collectionQuery->get() as $row) {
            $collections[(int) $row->id] = [
                'id' => (int) $row->id,
                'groups_id' => (int) $row->groups_id,
                'fromname' => (string) $row->fromname,
                'date' => (string) $row->date,
                'totalfiles' => (int) $row->totalfiles,
                'filecheck' => (int) $row->filecheck,
                'releases_id' => $row->releases_id === null ? null : (int) $row->releases_id,
                'xref' => (string) $row->xref,
                'binaries' => [],
            ];
        }

        $binaryQuery = DB::table('binaries')
            ->whereIn('collections_id', array_keys($collections))
            ->orderBy('id');
        if ($lock) {
            $binaryQuery->lockForUpdate();
        }
        foreach ($binaryQuery->get() as $row) {
            $collectionId = (int) $row->collections_id;
            $collections[$collectionId]['binaries'][] = [
                'id' => (int) $row->id,
                'filenumber' => (int) $row->filenumber,
                'name' => (string) $row->name,
                'currentparts' => (int) $row->currentparts,
                'totalparts' => (int) $row->totalparts,
            ];
        }

        return $collections;
    }

    /** @param array<string,mixed> $collection */
    private function isAnchor(array $collection): bool
    {
        $binaries = $collection['binaries'];

        return $this->isMutableCollection($collection)
            && count($binaries) === 1
            && (int) $binaries[0]['filenumber'] === 1
            && $this->isCompleteBinary($binaries[0])
            && ! $this->isPar2((string) $binaries[0]['name']);
    }

    /** @param array<string,mixed> $collection */
    private function isCompanion(array $collection): bool
    {
        if (! $this->isMutableCollection($collection)) {
            return false;
        }
        $expected = range(2, (int) $collection['totalfiles']);
        $actual = array_map(static fn (array $binary): int => (int) $binary['filenumber'], $collection['binaries']);
        sort($actual, SORT_NUMERIC);

        return $actual === $expected
            && count($actual) === count(array_unique($actual))
            && array_all($collection['binaries'], fn (array $binary): bool => $this->isCompleteBinary($binary) && $this->isPar2((string) $binary['name']));
    }

    /** @param array<string,mixed> $collection */
    private function isFanoutCompanion(array $collection): bool
    {
        $binaries = $collection['binaries'];
        if (! $this->isMutableCollection($collection) || count($binaries) !== 1) {
            return false;
        }

        $binary = $binaries[0];
        $fileNumber = (int) $binary['filenumber'];

        return $fileNumber >= 2
            && $fileNumber <= (int) $collection['totalfiles']
            && $this->isCompleteBinary($binary)
            && $this->isPar2((string) $binary['name']);
    }

    /** @param array<string,mixed> $collection */
    private function isMutableCollection(array $collection): bool
    {
        return (int) $collection['filecheck'] === 0
            && $collection['releases_id'] === null
            && (int) $collection['totalfiles'] >= 2
            && (int) $collection['totalfiles'] <= self::MAX_TOTAL_FILES;
    }

    /** @param array<string,mixed> $binary */
    private function isCompleteBinary(array $binary): bool
    {
        return (int) $binary['totalparts'] > 0
            && (int) $binary['currentparts'] >= (int) $binary['totalparts'];
    }

    private function isPar2(string $name): bool
    {
        return str_contains(strtolower($name), '.par2');
    }

    /**
     * @param  array<string, mixed>  $anchor
     * @param  array<string, mixed>  $companion
     */
    private function pairMetadataMatches(array $anchor, array $companion, bool $observe = false): bool
    {
        $anchorTimestamp = strtotime((string) $anchor['date']);
        $companionTimestamp = strtotime((string) $companion['date']);
        $groupName = $this->groupName((int) $anchor['groups_id']);
        if ((int) $anchor['id'] === (int) $companion['id']
            || (int) $anchor['groups_id'] !== (int) $companion['groups_id']
            || ! hash_equals((string) $anchor['fromname'], (string) $companion['fromname'])
            || (int) $anchor['totalfiles'] !== (int) $companion['totalfiles']
            || $anchorTimestamp === false
            || $companionTimestamp === false
            || abs($anchorTimestamp - $companionTimestamp) > self::MAX_POSTING_GAP_SECONDS
        ) {
            return false;
        }

        $decision = $this->pairXrefDecision($anchor, $companion, $groupName);
        if ($observe) {
            $this->pairXrefDecisionCounts[$groupName][$decision['result']] =
                ($this->pairXrefDecisionCounts[$groupName][$decision['result']] ?? 0) + 1;
        }

        return $decision['accepted'];
    }

    /**
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $companion
     * @return array{accepted:bool,result:string}
     */
    private function pairXrefDecision(array $anchor, array $companion, string $groupName): array
    {
        $anchorArticles = $this->xrefArticles((string) $anchor['xref'], $groupName);
        $companionArticles = $this->xrefArticles((string) $companion['xref'], $groupName);
        if ($anchorArticles === [] || $companionArticles === []) {
            return ['accepted' => false, 'result' => 'reject_missing_or_malformed'];
        }

        $anchorMin = min($anchorArticles);
        $anchorMax = max($anchorArticles);
        $companionMin = min($companionArticles);
        if ($companionMin <= $anchorMax) {
            return ['accepted' => false, 'result' => 'reject_direction'];
        }

        $gap = $companionMin - $anchorMax;
        if ($gap <= $this->xrefArticleGapLimit($groupName)) {
            return ['accepted' => true, 'result' => 'static_accept'];
        }

        $parts = (int) ($anchor['binaries'][0]['totalparts'] ?? 0);
        if ($parts < 1 || $parts > self::MAX_DYNAMIC_PAIR_TOTAL_PARTS) {
            return ['accepted' => false, 'result' => 'reject_parts_cap'];
        }
        $span = $anchorMax - $anchorMin + 1;
        if ($span < 1 || $span >= $parts) {
            return ['accepted' => false, 'result' => 'reject_span'];
        }
        if ($gap > self::MAX_DYNAMIC_PAIR_ARTICLE_GAP) {
            return ['accepted' => false, 'result' => 'reject_gap_cap'];
        }

        $expectedGap = max(1, $parts - $span);
        $residual = $gap - $expectedGap;
        if ($residual < self::MIN_DYNAMIC_PAIR_RESIDUAL || $residual > self::MAX_DYNAMIC_PAIR_RESIDUAL) {
            return ['accepted' => false, 'result' => 'reject_residual'];
        }
        if (! $this->dynamicPairGapEnabled($groupName)) {
            return ['accepted' => false, 'result' => 'dynamic_eligible_shadow'];
        }

        return ['accepted' => true, 'result' => 'dynamic_accept'];
    }

    /**
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $companion
     * @return array{group:string,totalparts:int,span:int,gap:int,residual:int}|null
     */
    private function dynamicPairFacts(array $anchor, array $companion, string $groupName): ?array
    {
        $anchorArticles = $this->xrefArticles((string) $anchor['xref'], $groupName);
        $companionArticles = $this->xrefArticles((string) $companion['xref'], $groupName);
        if ($anchorArticles === [] || $companionArticles === []) {
            return null;
        }

        $anchorMin = min($anchorArticles);
        $anchorMax = max($anchorArticles);
        $companionMin = min($companionArticles);
        $parts = (int) ($anchor['binaries'][0]['totalparts'] ?? 0);
        $span = $anchorMax - $anchorMin + 1;
        $gap = $companionMin - $anchorMax;
        $residual = $gap - max(1, $parts - $span);
        if ($companionMin <= $anchorMax
            || $parts < 1
            || $parts > self::MAX_DYNAMIC_PAIR_TOTAL_PARTS
            || $span < 1
            || $span >= $parts
            || $gap <= $this->xrefArticleGapLimit($groupName)
            || $gap > self::MAX_DYNAMIC_PAIR_ARTICLE_GAP
            || $residual < self::MIN_DYNAMIC_PAIR_RESIDUAL
            || $residual > self::MAX_DYNAMIC_PAIR_RESIDUAL
        ) {
            return null;
        }

        return [
            'group' => $groupName,
            'totalparts' => $parts,
            'span' => $span,
            'gap' => $gap,
            'residual' => $residual,
        ];
    }

    private function dynamicPairGapEnabled(string $groupName): bool
    {
        $groups = array_values(array_unique(array_filter(array_map(
            static fn (mixed $group): string => trim((string) $group),
            (array) config('nntmux.split_collection_dynamic_pair_gap_groups', []),
        ))));

        return count($groups) <= 16 && in_array($groupName, $groups, true);
    }

    /**
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $companion
     */
    private function fanoutMetadataMatches(array $anchor, array $companion): bool
    {
        $anchorTimestamp = strtotime((string) $anchor['date']);
        $companionTimestamp = strtotime((string) $companion['date']);
        $groupName = $this->groupName((int) $anchor['groups_id']);
        $anchorXrefMax = $this->xrefArticleMax((string) $anchor['xref'], $groupName);
        $companionArticles = $this->xrefArticles((string) $companion['xref'], $groupName);

        return (int) $anchor['id'] !== (int) $companion['id']
            && (int) $anchor['groups_id'] === (int) $companion['groups_id']
            && hash_equals((string) $anchor['fromname'], (string) $companion['fromname'])
            && (int) $anchor['totalfiles'] === (int) $companion['totalfiles']
            && $anchorTimestamp !== false
            && $companionTimestamp !== false
            && abs($anchorTimestamp - $companionTimestamp) <= self::MAX_POSTING_GAP_SECONDS
            && $anchorXrefMax > 0
            && $companionArticles !== []
            && min(array_map(
                static fn (int $article): int => abs($article - $anchorXrefMax),
                $companionArticles,
            )) <= $this->xrefArticleGapLimit($groupName);
    }

    /**
     * @param  array<int,array<string,mixed>>  $collections
     * @param  array<string,mixed>  $anchor
     * @return list<int>|null
     */
    private function fanoutCompanionIds(array $collections, array $anchor): ?array
    {
        $companionsByFileNumber = [];
        foreach ($collections as $collection) {
            if (! $this->isFanoutCompanion($collection) || ! $this->fanoutMetadataMatches($anchor, $collection)) {
                continue;
            }
            $fileNumber = (int) $collection['binaries'][0]['filenumber'];
            if (array_key_exists($fileNumber, $companionsByFileNumber)) {
                return null;
            }
            $companionsByFileNumber[$fileNumber] = (int) $collection['id'];
        }

        ksort($companionsByFileNumber, SORT_NUMERIC);
        if (array_keys($companionsByFileNumber) !== range(2, (int) $anchor['totalfiles'])) {
            return null;
        }

        return array_values($companionsByFileNumber);
    }

    private function xrefArticleGapLimit(string $groupName): int
    {
        $configured = config('nntmux.split_collection_xref_gap_overrides', []);
        if (! is_array($configured)) {
            return self::MAX_XREF_ARTICLE_GAP;
        }

        return min(self::MAX_XREF_ARTICLE_GAP_CEILING, max(
            self::MAX_XREF_ARTICLE_GAP,
            (int) ($configured[$groupName] ?? self::MAX_XREF_ARTICLE_GAP),
        ));
    }

    /**
     * Recheck uniqueness outside the bounded discovery page. The time range is
     * intentionally not constrained by dateadded so an older matching row
     * cannot be hidden by the recent-candidate filter.
     *
     * @param  array<string, mixed>  $anchor
     * @param  array<string, mixed>  $companion
     */
    private function isUniquePairInDatabase(array $anchor, array $companion, bool $lock = false): bool
    {
        $anchorTimestamp = strtotime((string) $anchor['date']);
        $companionTimestamp = strtotime((string) $companion['date']);
        if ($anchorTimestamp === false || $companionTimestamp === false) {
            return false;
        }

        $query = DB::table('collections')
            ->where('groups_id', (int) $anchor['groups_id'])
            ->where('fromname', (string) $anchor['fromname'])
            ->where('totalfiles', (int) $anchor['totalfiles'])
            ->where('filecheck', 0)
            ->whereNull('releases_id')
            ->whereBetween('date', [
                date('Y-m-d H:i:s', min($anchorTimestamp, $companionTimestamp) - self::MAX_POSTING_GAP_SECONDS),
                date('Y-m-d H:i:s', max($anchorTimestamp, $companionTimestamp) + self::MAX_POSTING_GAP_SECONDS),
            ])
            ->orderBy('id')
            ->limit(self::MAX_MATCHING_COLLECTIONS + 1);
        if ($lock) {
            $query->lockForUpdate();
        }
        $ids = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if (count($ids) > self::MAX_MATCHING_COLLECTIONS) {
            return false;
        }

        $collections = $this->collectionData($ids, $lock);
        $anchors = array_filter($collections, fn (array $collection): bool => $this->isAnchor($collection));
        $companions = array_filter($collections, fn (array $collection): bool => $this->isCompanion($collection));
        $anchorMatches = array_keys(array_filter(
            $companions,
            fn (array $candidate): bool => $this->pairMetadataMatches($anchor, $candidate),
        ));
        $companionMatches = array_keys(array_filter(
            $anchors,
            fn (array $candidate): bool => $this->pairMetadataMatches($candidate, $companion),
        ));

        return $anchorMatches === [(int) $companion['id']]
            && $companionMatches === [(int) $anchor['id']];
    }

    /**
     * @param  array<string,mixed>  $anchor
     * @param  list<int>  $companionIds
     */
    private function isUniqueFanoutInDatabase(array $anchor, array $companionIds, bool $lock = false): bool
    {
        $anchorTimestamp = strtotime((string) $anchor['date']);
        if ($anchorTimestamp === false || (int) $anchor['totalfiles'] > $this->maxFanoutFiles()) {
            return false;
        }

        $query = DB::table('collections')
            ->where('groups_id', (int) $anchor['groups_id'])
            ->where('fromname', (string) $anchor['fromname'])
            ->where('totalfiles', (int) $anchor['totalfiles'])
            ->where('filecheck', 0)
            ->whereNull('releases_id')
            ->whereBetween('date', [
                date('Y-m-d H:i:s', $anchorTimestamp - self::MAX_POSTING_GAP_SECONDS),
                date('Y-m-d H:i:s', $anchorTimestamp + self::MAX_POSTING_GAP_SECONDS),
            ])
            ->orderBy('id')
            ->limit($this->maxCohortRows());
        if ($lock) {
            $query->lockForUpdate();
        }
        $ids = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if (count($ids) !== (int) $anchor['totalfiles']) {
            return false;
        }

        $collections = $this->collectionData($ids, $lock);
        $anchors = array_values(array_filter($collections, fn (array $collection): bool => $this->isAnchor($collection)));
        if (count($anchors) !== 1 || (int) $anchors[0]['id'] !== (int) $anchor['id']) {
            return false;
        }

        $databaseCompanionIds = $this->fanoutCompanionIds($collections, $anchors[0]);
        sort($companionIds, SORT_NUMERIC);
        if ($databaseCompanionIds !== null) {
            sort($databaseCompanionIds, SORT_NUMERIC);
        }

        return $databaseCompanionIds === $companionIds;
    }

    private function mergePair(int $groupId, int $anchorId, int $companionId): bool
    {
        return DB::transaction(function () use ($groupId, $anchorId, $companionId): bool {
            $preflight = $this->collectionData([$anchorId, $companionId]);
            $preflightAnchor = $preflight[$anchorId] ?? null;
            $preflightCompanion = $preflight[$companionId] ?? null;
            if ($preflightAnchor === null
                || $preflightCompanion === null
                || ! $this->isUniquePairInDatabase($preflightAnchor, $preflightCompanion, true)
            ) {
                return false;
            }

            $collections = $this->collectionData([$anchorId, $companionId], true);
            $anchor = $collections[$anchorId] ?? null;
            $companion = $collections[$companionId] ?? null;
            if ($anchor === null
                || $companion === null
                || (int) $anchor['groups_id'] !== $groupId
                || ! $this->sameCohortIdentity($preflightAnchor, $anchor)
                || ! $this->sameCohortIdentity($preflightCompanion, $companion)
                || ! $this->isAnchor($anchor)
                || ! $this->isCompanion($companion)
                || ! $this->pairMetadataMatches($anchor, $companion)
            ) {
                return false;
            }

            $groupName = $this->groupName($groupId);
            $decision = $this->pairXrefDecision($anchor, $companion, $groupName);
            $terminalContext = null;
            if ($decision['result'] === 'dynamic_accept') {
                $facts = $this->dynamicPairFacts($anchor, $companion, $groupName);
                if ($facts === null) {
                    throw new \RuntimeException('Dynamic split collection evidence changed during reconciliation.');
                }
                $terminalContext = $this->terminalSplitRepair->beginPairRepair(
                    $companionId,
                    $anchorId,
                    $facts,
                );
            }
            if ($terminalContext === null) {
                $this->currentForwardLineage->recordCollectionHandoffsForMerge(
                    [$companionId],
                    $anchorId,
                    'split collection merge',
                );
            }

            $updated = DB::table('binaries')
                ->where('collections_id', $companionId)
                ->update(['collections_id' => $anchorId]);
            if ($updated !== count($companion['binaries'])) {
                throw new \RuntimeException('Split collection companion changed during reconciliation.');
            }

            DB::table('collections')->where('id', $anchorId)->update([
                'xref' => $this->mergedXref((string) $anchor['xref'], (string) $companion['xref']),
            ]);
            $deleted = DB::table('collections')
                ->where('id', $companionId)
                ->where('filecheck', 0)
                ->whereNull('releases_id')
                ->whereNotExists(static fn ($query) => $query
                    ->selectRaw('1')
                    ->from('binaries')
                    ->whereColumn('binaries.collections_id', 'collections.id'))
                ->delete();
            if ($deleted !== 1) {
                throw new \RuntimeException('Split collection companion could not be removed safely.');
            }
            if ($terminalContext !== null) {
                $this->terminalSplitRepair->finishPairRepair($terminalContext);
            }

            Log::notice('Reconciled split main and PAR2 collections', [
                'group_id' => $groupId,
                'anchor_collection_id' => $anchorId,
                'companion_collection_id' => $companionId,
                'total_files' => (int) $anchor['totalfiles'],
            ]);

            return true;
        }, 5);
    }

    /** @param list<int> $companionIds */
    private function mergeFanout(int $groupId, int $anchorId, array $companionIds): bool
    {
        return DB::transaction(function () use ($groupId, $anchorId, $companionIds): bool {
            $collectionIds = [$anchorId, ...$companionIds];
            $preflight = $this->collectionData($collectionIds);
            $preflightAnchor = $preflight[$anchorId] ?? null;
            if ($preflightAnchor === null
                || ! $this->isUniqueFanoutInDatabase($preflightAnchor, $companionIds, true)
            ) {
                return false;
            }

            $collections = $this->collectionData($collectionIds, true);
            $anchor = $collections[$anchorId] ?? null;
            if ($anchor === null
                || (int) $anchor['groups_id'] !== $groupId
                || ! $this->sameCohortIdentity($preflightAnchor, $anchor)
                || ! $this->isAnchor($anchor)
            ) {
                return false;
            }

            $mergedXref = (string) $anchor['xref'];
            foreach ($companionIds as $companionId) {
                $preflightCompanion = $preflight[$companionId] ?? null;
                $companion = $collections[$companionId] ?? null;
                if ($preflightCompanion === null
                    || $companion === null
                    || ! $this->sameCohortIdentity($preflightCompanion, $companion)
                    || ! $this->isFanoutCompanion($companion)
                    || ! $this->fanoutMetadataMatches($anchor, $companion)
                ) {
                    return false;
                }

                $mergedXref = $this->mergedXref($mergedXref, (string) $companion['xref']);
            }

            $this->currentForwardLineage->recordCollectionHandoffsForMerge(
                $companionIds,
                $anchorId,
                'split collection fanout merge',
            );

            foreach ($companionIds as $companionId) {
                $updated = DB::table('binaries')
                    ->where('collections_id', $companionId)
                    ->update(['collections_id' => $anchorId]);
                if ($updated !== 1) {
                    throw new \RuntimeException('Split collection fanout companion changed during reconciliation.');
                }
            }

            DB::table('collections')->where('id', $anchorId)->update(['xref' => $mergedXref]);
            foreach ($companionIds as $companionId) {
                $deleted = DB::table('collections')
                    ->where('id', $companionId)
                    ->where('filecheck', 0)
                    ->whereNull('releases_id')
                    ->whereNotExists(static fn ($query) => $query
                        ->selectRaw('1')
                        ->from('binaries')
                        ->whereColumn('binaries.collections_id', 'collections.id'))
                    ->delete();
                if ($deleted !== 1) {
                    throw new \RuntimeException('Split collection fanout companion could not be removed safely.');
                }
            }

            Log::notice('Reconciled split main and PAR2 fanout collections', [
                'group_id' => $groupId,
                'anchor_collection_id' => $anchorId,
                'companion_collection_ids' => $companionIds,
                'total_files' => (int) $anchor['totalfiles'],
            ]);

            return true;
        }, 5);
    }

    private function mergedXref(string $left, string $right): string
    {
        $tokens = array_values(array_unique(array_filter(preg_split('/\s+/', trim($left.' '.$right)) ?: [])));

        return substr(implode(' ', $tokens), 0, 2000);
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $locked
     */
    private function sameCohortIdentity(array $preflight, array $locked): bool
    {
        return (int) $preflight['id'] === (int) $locked['id']
            && (int) $preflight['groups_id'] === (int) $locked['groups_id']
            && hash_equals((string) $preflight['fromname'], (string) $locked['fromname'])
            && (int) $preflight['totalfiles'] === (int) $locked['totalfiles']
            && hash_equals((string) $preflight['date'], (string) $locked['date'])
            && hash_equals((string) $preflight['xref'], (string) $locked['xref']);
    }

    private function groupName(int $groupId): string
    {
        if (! array_key_exists($groupId, $this->groupNamesById)) {
            $this->groupNamesById[$groupId] = (string) (DB::table('usenet_groups')
                ->where('id', $groupId)
                ->value('name') ?? '');
        }

        return $this->groupNamesById[$groupId];
    }

    private function xrefArticleMax(string $xref, string $groupName): int
    {
        $articles = $this->xrefArticles($xref, $groupName);

        return $articles === [] ? 0 : max($articles);
    }

    /** @return list<int> */
    private function xrefArticles(string $xref, string $groupName): array
    {
        if ($groupName === '' || preg_match_all(
            '/(?:^|\s)'.preg_quote($groupName, '/').':(\d+)(?=\s|$)/i',
            $xref,
            $matches,
        ) === 0) {
            return [];
        }

        $articles = [];
        $maximum = (string) PHP_INT_MAX;
        foreach ($matches[1] as $digits) {
            $normalized = ltrim((string) $digits, '0');
            if ($normalized === ''
                || strlen($normalized) > strlen($maximum)
                || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
            ) {
                return [];
            }
            $article = (int) $normalized;
            if ($article < 1 || (string) $article !== $normalized) {
                return [];
            }
            $articles[] = $article;
        }
        $articles = array_values(array_unique($articles));
        sort($articles, SORT_NUMERIC);

        return $articles;
    }
}
