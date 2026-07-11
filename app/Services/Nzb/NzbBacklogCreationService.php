<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

final class NzbBacklogCreationService
{
    private const int MAX_CANDIDATE_SCAN = 10000;

    private const int ELIGIBILITY_CHUNK_SIZE = 100;

    public function __construct(private readonly NzbService $nzb) {}

    /**
     * @param  array<int, int|string>  $groups
     * @param  array<int, string>  $leftGuids
     * @param  callable(int, int): void|null  $onCreated
     *
     * When candidate counting is requested, candidate_total is exact within
     * the bounded pending-ID scan rather than an unbounded global backlog count.
     * @return array{candidate_total: int, selected: int, scanned: int, scan_exhausted: bool, selection_duration_seconds: float, attempted: int, created: int, failed: int, marked_failed: int}
     */
    public function create(
        array $groups = [],
        array $leftGuids = [],
        int $limit = 250,
        bool $markFailed = false,
        string $order = 'asc',
        bool $countCandidates = false,
        ?callable $onCreated = null,
        ?int $scanCap = null
    ): array {
        $limit = max(1, min(5000, $limit));
        $configuredScanCap = max(
            1,
            min(self::MAX_CANDIDATE_SCAN, (int) config('nntmux.distributed_nzb_scan_cap', self::MAX_CANDIDATE_SCAN))
        );
        $scanCap = $scanCap === null
            ? $configuredScanCap
            : max(1, min($configuredScanCap, $scanCap));
        $order = strtolower($order) === 'desc' ? 'desc' : 'asc';

        $completion = $this->requiredCompletionPercent();
        $selectionStartedAt = hrtime(true);

        $query = $this->pendingIdQuery();
        $this->applyGroupFilter($query, $groups);
        $this->applyLeftGuidFilter($query, $leftGuids);

        $pendingIds = $query
            ->orderBy('id', $order)
            ->limit($scanCap + 1)
            ->pluck('id');
        $hasMorePending = $pendingIds->count() > $scanCap;

        $releases = [];
        $eligibleCount = 0;
        $scanned = 0;
        $pendingIdValues = $pendingIds
            ->take($scanCap)
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
        foreach (array_chunk($pendingIdValues, self::ELIGIBILITY_CHUNK_SIZE) as $pendingIdChunk) {
            $eligibleById = $this->eligibleReleasesByIds($pendingIdChunk, $completion)->keyBy('id');

            foreach ($pendingIdChunk as $pendingId) {
                $scanned++;
                $release = $eligibleById->get($pendingId);
                if (! $release instanceof Release) {
                    continue;
                }

                $eligibleCount++;
                if (count($releases) < $limit) {
                    $releases[] = $release;
                }

                if (! $countCandidates && count($releases) >= $limit) {
                    break 2;
                }
            }
        }
        $candidateTotal = $countCandidates ? $eligibleCount : count($releases);
        $scanExhausted = $hasMorePending && $scanned >= $scanCap && count($releases) < $limit;
        $selectionDurationSeconds = max(0.0, (hrtime(true) - $selectionStartedAt) / 1_000_000_000);

        $result = [
            'candidate_total' => $candidateTotal,
            'selected' => count($releases),
            'scanned' => $scanned,
            'scan_exhausted' => $scanExhausted,
            'selection_duration_seconds' => $selectionDurationSeconds,
            'attempted' => 0,
            'created' => 0,
            'failed' => 0,
            'marked_failed' => 0,
        ];

        foreach ($releases as $release) {
            $result['attempted']++;
            if ($this->nzb->writeNzbForReleaseId($release)) {
                $result['created']++;
                if ($onCreated !== null) {
                    $onCreated($result['created'], $candidateTotal);
                }

                continue;
            }

            $result['failed']++;
            if ($markFailed && $this->markReleaseFailed((int) $release->id)) {
                $result['marked_failed']++;
            }
        }

        return $result;
    }

    /**
     * Count exact actionable candidates within the same bounded selector used
     * by the worker, without creating files or mutating release state.
     */
    public function eligibleCandidateCount(int $scanCap = self::MAX_CANDIDATE_SCAN): int
    {
        $scanCap = max(1, min(self::MAX_CANDIDATE_SCAN, $scanCap));
        $completion = $this->requiredCompletionPercent();
        $pendingIds = $this->pendingIdQuery()
            ->orderByDesc('id')
            ->limit($scanCap)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $eligible = 0;
        foreach (array_chunk($pendingIds, self::ELIGIBILITY_CHUNK_SIZE) as $chunk) {
            $eligible += $this->eligibleReleasesByIds($chunk, $completion)->count();
        }

        return $eligible;
    }

    /**
     * @return Builder<Release>
     */
    private function pendingIdQuery(): Builder
    {
        $query = Release::query();
        $query->getQuery()->forceIndex('ix_releases_nzbstatus_id');

        return $query
            ->where('nzbstatus', '=', NzbService::NZB_NONE)
            ->select('id');
    }

    /**
     * @param  list<int>  $releaseIds
     * @return EloquentCollection<int, Release>
     */
    private function eligibleReleasesByIds(array $releaseIds, int $completion): EloquentCollection
    {
        $releaseIds = array_values(array_unique(array_map('intval', $releaseIds)));
        if ($releaseIds === []) {
            return new EloquentCollection;
        }

        $counterCompleteIds = $this->counterCompleteReleaseIds($releaseIds, $completion);
        if ($counterCompleteIds === []) {
            return new EloquentCollection;
        }

        $query = Release::query();
        if (DB::getDriverName() !== 'sqlite') {
            $query->getQuery()->forceIndex('PRIMARY');
        }

        return $query
            ->with('category.parent')
            ->whereIn('releases.id', $counterCompleteIds)
            ->where('nzbstatus', '=', NzbService::NZB_NONE)
            ->where(function ($query): void {
                $query->selectRaw('1')
                    ->from('collections')
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->whereColumn('collections.releases_id', 'releases.id')
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('parts')
                            ->whereColumn('parts.binaries_id', 'binaries.id')
                            ->limit(1);
                    })
                    ->limit(1);
            }, '=', null)
            ->select(['id', 'guid', 'name', 'categories_id', 'groups_id', 'leftguid', 'nzbstatus'])
            ->get();
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<int>
     */
    private function counterCompleteReleaseIds(array $releaseIds, int $completion): array
    {
        $query = Release::query();
        if (DB::getDriverName() !== 'sqlite') {
            $query->getQuery()->forceIndex('PRIMARY');
        }

        return $query
            ->whereIn('releases.id', $releaseIds)
            ->where('nzbstatus', '=', NzbService::NZB_NONE)
            ->where(function ($query): void {
                $query->selectRaw('1')
                    ->from('collections')
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->whereColumn('collections.releases_id', 'releases.id')
                    ->limit(1);
            }, '!=', null)
            ->where(function ($query) use ($completion): void {
                $query->selectRaw('1')
                    ->from('collections')
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->whereColumn('collections.releases_id', 'releases.id')
                    ->where(function ($query) use ($completion): void {
                        $query->whereRaw('binaries.currentparts < CEIL(binaries.totalparts * ? / 100)', [$completion])
                            ->orWhere('binaries.totalparts', '<=', 0);
                    })
                    ->limit(1);
            }, '=', null)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function requiredCompletionPercent(): int
    {
        $completion = (int) Settings::settingValue('completionpercent');
        if ($completion <= 0) {
            return 100;
        }

        return min(100, $completion);
    }

    /**
     * @param  Builder<Release>  $query
     * @param  array<int, int|string>  $groups
     */
    private function applyGroupFilter(Builder $query, array $groups): void
    {
        $groups = array_values(array_filter(array_map(
            static fn (int|string $group): string => trim((string) $group),
            $groups
        )));
        if ($groups === []) {
            return;
        }

        $groupIds = [];
        $groupNames = [];
        foreach ($groups as $group) {
            if (is_numeric($group)) {
                $groupIds[] = (int) $group;
            } else {
                $groupNames[] = $group;
            }
        }

        if ($groupNames !== []) {
            $groupIds = array_merge(
                $groupIds,
                DB::table('usenet_groups')
                    ->whereIn('name', $groupNames)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all()
            );
        }

        $query->getQuery()->forceIndex('ix_releases_nzb_backlog_partition');
        $query->whereIn('groups_id', array_values(array_unique($groupIds)));
    }

    /**
     * @param  Builder<Release>  $query
     * @param  array<int, string>  $leftGuids
     */
    private function applyLeftGuidFilter(Builder $query, array $leftGuids): void
    {
        $leftGuids = array_values(array_unique(array_filter(array_map(
            static fn (string $leftGuid): string => strtolower(trim($leftGuid)),
            $leftGuids
        ))));
        if ($leftGuids === []) {
            return;
        }

        $query->whereIn('leftguid', $leftGuids);
    }

    private function markReleaseFailed(int $releaseId): bool
    {
        return DB::table('releases')
            ->where('id', $releaseId)
            ->where('nzbstatus', NzbService::NZB_NONE)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('collections')
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->join('parts', 'parts.binaries_id', '=', 'binaries.id')
                    ->whereColumn('collections.releases_id', 'releases.id')
                    ->limit(1);
            })
            ->update(['nzbstatus' => NzbService::NZB_FAILED]) === 1;
    }
}
