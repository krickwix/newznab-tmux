<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NzbBacklogCreationService
{
    private const int MAX_CANDIDATE_SCAN = 5000;

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
        foreach ($pendingIds->take($scanCap) as $pendingId) {
            $scanned++;
            $release = $this->eligibleReleaseById((int) $pendingId, $completion);
            if ($release === null) {
                continue;
            }

            $eligibleCount++;
            if (count($releases) < $limit) {
                $releases[] = $release;
            }

            if (! $countCandidates && count($releases) >= $limit) {
                break;
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

    private function eligibleReleaseById(int $releaseId, int $completion): ?Release
    {
        $query = Release::query();
        if (DB::getDriverName() !== 'sqlite') {
            $query->getQuery()->forceIndex('PRIMARY');
        }

        return $query
            ->with('category.parent')
            ->whereKey($releaseId)
            ->where('nzbstatus', '=', NzbService::NZB_NONE)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('collections')
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->join('parts', 'parts.binaries_id', '=', 'binaries.id')
                    ->whereColumn('collections.releases_id', 'releases.id')
                    ->limit(1);
            })
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
            ->select(['id', 'guid', 'name', 'categories_id', 'groups_id', 'leftguid', 'nzbstatus'])
            ->first();
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
