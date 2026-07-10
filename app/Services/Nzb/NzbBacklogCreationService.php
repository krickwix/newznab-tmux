<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NzbBacklogCreationService
{
    public function __construct(private readonly NzbService $nzb) {}

    /**
     * @param  array<int, int|string>  $groups
     * @param  array<int, string>  $leftGuids
     * @param  callable(int, int): void|null  $onCreated
     * @return array{candidate_total: int, selected: int, attempted: int, created: int, failed: int, marked_failed: int}
     */
    public function create(
        array $groups = [],
        array $leftGuids = [],
        int $limit = 250,
        bool $markFailed = false,
        string $order = 'asc',
        bool $countCandidates = false,
        ?callable $onCreated = null
    ): array {
        $limit = max(1, min(5000, $limit));
        $order = strtolower($order) === 'desc' ? 'desc' : 'asc';

        $completion = $this->requiredCompletionPercent();

        $query = $this->basePendingQuery($completion);
        $this->applyGroupFilter($query, $groups);
        $this->applyLeftGuidFilter($query, $leftGuids);

        $candidateTotal = $countCandidates ? (clone $query)->count() : 0;
        $releases = $query
            ->orderBy('id', $order)
            ->limit($limit)
            ->get();
        if (! $countCandidates) {
            $candidateTotal = $releases->count();
        }

        $result = [
            'candidate_total' => $candidateTotal,
            'selected' => $releases->count(),
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
     * @return Builder<Release>
     */
    private function basePendingQuery(int $completion): Builder
    {
        $query = Release::query();

        // MariaDB otherwise materializes the incomplete-binary NOT EXISTS
        // branch across the full collections/binaries/parts backlog. Keep the
        // outer scan release-driven; group-scoped passes switch below to the
        // partition index before adding their group filter.
        $query->getQuery()->forceIndex('ix_releases_nzbstatus_id');

        return $query
            ->with('category.parent')
            ->where('nzbstatus', '=', NzbService::NZB_NONE)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('collections')
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->join('parts', 'parts.binaries_id', '=', 'binaries.id')
                    ->whereColumn('collections.releases_id', 'releases.id')
                    ->limit(1);
            })
            // COUNT(*) remains correlated to the outer release on MariaDB.
            // NOT EXISTS was decorrelated into a global materialized scan,
            // which repeated for every release-worker group and took minutes.
            ->where(function ($query) use ($completion): void {
                $query->selectRaw('COUNT(*)')
                    ->from('collections')
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->whereColumn('collections.releases_id', 'releases.id')
                    ->where(function ($query) use ($completion): void {
                        $query->whereRaw('binaries.currentparts < CEIL(binaries.totalparts * ? / 100)', [$completion])
                            ->orWhere('binaries.totalparts', '<=', 0)
                            ->orWhereNotExists(function ($query): void {
                                $query->selectRaw('1')
                                    ->from('parts')
                                    ->whereColumn('parts.binaries_id', 'binaries.id')
                                    ->limit(1);
                            });
                    });
            }, '=', 0)
            ->select(['id', 'guid', 'name', 'categories_id', 'groups_id', 'leftguid', 'nzbstatus']);
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
