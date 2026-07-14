<?php

declare(strict_types=1);

namespace App\Services\Releases;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SplitCollectionReconciler
{
    private const int MAX_COLLECTIONS_PER_GROUP = 200;

    private const int MAX_PAIRS_PER_PASS = 20;

    private const int MAX_TOTAL_FILES = 200;

    private const int MAX_POSTING_GAP_SECONDS = 120;

    private const int MAX_XREF_ARTICLE_GAP = 1000;

    private const int MAX_MATCHING_COLLECTIONS = 20;

    /** @var array<int, string> */
    private array $groupNamesById = [];

    public function reconcile(?int $groupId): int
    {
        $groupIds = $this->allowedGroupIds($groupId);
        if ($groupIds === []) {
            return 0;
        }

        $merged = 0;
        foreach ($groupIds as $allowedGroupId) {
            foreach ($this->uniquePairs($allowedGroupId) as $pair) {
                if ($merged >= self::MAX_PAIRS_PER_PASS) {
                    return $merged;
                }
                if ($this->mergePair($allowedGroupId, $pair['anchor_id'], $pair['companion_id'])) {
                    $merged++;
                }
            }
        }

        return $merged;
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

    /** @return list<array{anchor_id:int,companion_id:int}> */
    private function uniquePairs(int $groupId): array
    {
        $collectionIds = DB::table('collections')
            ->where('groups_id', $groupId)
            ->where('filecheck', 0)
            ->whereNull('releases_id')
            ->whereBetween('totalfiles', [2, self::MAX_TOTAL_FILES])
            ->where('dateadded', '>=', now()->subDay())
            ->orderByDesc('id')
            ->limit(self::MAX_COLLECTIONS_PER_GROUP)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
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
                if (! $this->pairMetadataMatches($anchor, $companion)) {
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

        return array_slice($pairs, 0, self::MAX_PAIRS_PER_PASS);
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
    private function pairMetadataMatches(array $anchor, array $companion): bool
    {
        $anchorTimestamp = strtotime((string) $anchor['date']);
        $companionTimestamp = strtotime((string) $companion['date']);
        $groupName = $this->groupName((int) $anchor['groups_id']);
        $anchorXrefMax = $this->xrefArticleMax((string) $anchor['xref'], $groupName);
        $companionXrefMin = $this->xrefArticleMin((string) $companion['xref'], $groupName);

        return (int) $anchor['id'] !== (int) $companion['id']
            && (int) $anchor['groups_id'] === (int) $companion['groups_id']
            && hash_equals((string) $anchor['fromname'], (string) $companion['fromname'])
            && (int) $anchor['totalfiles'] === (int) $companion['totalfiles']
            && $anchorTimestamp !== false
            && $companionTimestamp !== false
            && abs($anchorTimestamp - $companionTimestamp) <= self::MAX_POSTING_GAP_SECONDS
            && $anchorXrefMax > 0
            && $companionXrefMin > $anchorXrefMax
            && $companionXrefMin - $anchorXrefMax <= $this->xrefArticleGapLimit($groupName);
    }

    private function xrefArticleGapLimit(string $groupName): int
    {
        $configured = config('nntmux.split_collection_xref_gap_overrides', []);
        if (! is_array($configured)) {
            return self::MAX_XREF_ARTICLE_GAP;
        }

        return min(2000, max(
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

            Log::notice('Reconciled split main and PAR2 collections', [
                'group_id' => $groupId,
                'anchor_collection_id' => $anchorId,
                'companion_collection_id' => $companionId,
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

    private function xrefArticleMin(string $xref, string $groupName): int
    {
        $articles = $this->xrefArticles($xref, $groupName);

        return $articles === [] ? 0 : min($articles);
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

        $articles = array_values(array_unique(array_filter(
            array_map('intval', $matches[1]),
            static fn (int $article): bool => $article > 0,
        )));
        sort($articles, SORT_NUMERIC);

        return $articles;
    }
}
