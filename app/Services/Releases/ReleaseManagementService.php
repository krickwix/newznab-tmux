<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Facades\Search;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\Orchestrator\CurrentForwardWindowLineage;
use App\Services\ReleaseImageService;
use App\Support\ReleaseSearchIndexSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing releases (delete, update, export).
 */
class ReleaseManagementService
{
    private readonly CurrentForwardWindowLineage $currentForwardLineage;

    public function __construct(?CurrentForwardWindowLineage $currentForwardLineage = null)
    {
        $this->currentForwardLineage = $currentForwardLineage ?? new CurrentForwardWindowLineage;
    }

    /**
     * @param  array<string, mixed>  $list
     *
     * @throws \Exception
     */
    public function deleteMultiple(int|array|string $list): void
    {
        $list = (array) $list;

        $nzb = app(NzbService::class);
        $releaseImage = new ReleaseImageService;

        foreach ($list as $identifier) {
            $this->deleteSingleWithService(['g' => $identifier, 'i' => false], $nzb, $releaseImage);
        }
    }

    /**
     * Deletes a single release by GUID, and all the corresponding files.
     *
     * @param  array<string, mixed>  $identifiers  ['g' => Release GUID(mandatory), 'id => ReleaseID(optional, pass
     *                                             false)]
     *
     * @throws \Exception
     */
    public function deleteSingle(array $identifiers, NzbService $nzb, ReleaseImageService $releaseImage): void
    {
        // Resolve and verify one database identity before touching external
        // artifacts so disposition and deletion cannot target different rows.
        if ($identifiers['i'] === false) {
            $release = Release::query()->where('guid', $identifiers['g'])->first(['id']);
            if ($release === null) {
                return;
            }
            $identifiers['i'] = $release->id;
        }

        // A policy deletion must become durable lineage evidence in the same
        // database transaction that removes the release row. Open roots are
        // terminalized before their exact output can disappear.
        DB::transaction(function () use ($identifiers): void {
            $releaseId = (int) ($identifiers['i'] ?? 0);
            $guid = (string) ($identifiers['g'] ?? '');
            if ($releaseId <= 0 || $guid === '') {
                throw new \RuntimeException('Release deletion requires one exact ID and GUID identity.');
            }
            $target = Release::query()
                ->whereKey($releaseId)
                ->where('guid', $guid)
                ->lockForUpdate()
                ->first(['id']);
            if ($target === null) {
                throw new \RuntimeException('Release deletion ID and GUID do not identify the same row.');
            }
            $this->currentForwardLineage->recordReleaseDispositionForDeletion(
                $releaseId,
                (string) ($identifiers['reason'] ?? 'unspecified'),
            );
            $deleted = Release::query()
                ->whereKey($releaseId)
                ->where('guid', $guid)
                ->delete();
            if ($deleted !== 1) {
                throw new \RuntimeException('Release deletion lost its locked target row.');
            }
        }, 3);

        $releaseId = (int) $identifiers['i'];
        $nzbPath = $nzb->nzbPath($identifiers['g']);
        if (! empty($nzbPath)) {
            File::delete($nzbPath);
        }
        $releaseImage->delete($identifiers['g']);
        Search::deleteRelease($releaseId);
        $this->deleteLinkedCollections($releaseId);
    }

    private function deleteLinkedCollections(int $releaseId): void
    {
        $collectionIds = DB::table('collections')
            ->where('releases_id', $releaseId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($collectionIds === []) {
            return;
        }

        app(CollectionCleanupService::class)->deleteCollectionsAndDescendants(
            $collectionIds,
            'Release delete cleanup',
            false
        );
    }

    /**
     * Alias for deleteSingle for backwards compatibility.
     *
     * @param  array<string, mixed>  $identifiers  ['g' => Release GUID(mandatory), 'i => ReleaseID(optional, pass false)]
     *
     * @throws \Exception
     */
    public function deleteSingleWithService(array $identifiers, NzbService $nzb, ReleaseImageService $releaseImage): void
    {
        $this->deleteSingle($identifiers, $nzb, $releaseImage);
    }

    /**
     * @return bool|int
     */
    public function updateMulti(mixed $guids, mixed $category, mixed $grabs, mixed $videoId, mixed $episodeId, mixed $anidbId, mixed $imdbId)
    {
        if (! \is_array($guids) || \count($guids) < 1) {
            return false;
        }

        $update = [
            'categories_id' => $category === -1 ? 'categories_id' : $category,
            'grabs' => $grabs,
            'videos_id' => $videoId,
            'tv_episodes_id' => $episodeId,
            'anidbid' => $anidbId,
            'imdbid' => $imdbId,
        ];

        $releaseIds = Release::query()->whereIn('guid', $guids)->pluck('id');
        $updated = Release::query()->whereIn('guid', $guids)->update($update);
        ReleaseSearchIndexSync::forIds($releaseIds);

        return $updated;
    }

    /**
     * @param  list<string>  $guids
     */
    public function bulkUpdateCategory(array $guids, int $categoryId): int
    {
        $guids = array_values(array_filter($guids));
        if ($guids === [] || $categoryId <= 0) {
            return 0;
        }

        $updated = 0;

        DB::transaction(function () use ($guids, $categoryId, &$updated): void {
            $releaseIds = Release::query()
                ->whereIn('guid', $guids)
                ->pluck('id');

            $updated = Release::query()
                ->whereIn('guid', $guids)
                ->update(['categories_id' => $categoryId, 'iscategorized' => 1]);

            if ($updated > 0) {
                $this->syncReleasesToSearchIndex($releaseIds);
                Release::clearAdminReleasesRangeCache();
            }
        });

        return $updated;
    }

    /**
     * Re-index releases after query-builder updates that bypass {@see ReleaseObserver}.
     *
     * @param  Collection<int, int|string>|iterable<int|string>  $releaseIds
     */
    private function syncReleasesToSearchIndex(iterable $releaseIds): void
    {
        foreach ($releaseIds as $releaseId) {
            $intId = (int) $releaseId;
            if ($intId <= 0) {
                continue;
            }

            try {
                Search::updateRelease($intId);
            } catch (\Throwable $e) {
                Log::error('ReleaseManagementService: Failed to sync release to search index after category change', [
                    'release_id' => $intId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return Release[]|Builder[]|\Illuminate\Database\Eloquent\Collection<int, mixed>|\Illuminate\Database\Query\Builder[]|Collection<int, mixed>
     */
    public function getForExport(string $postFrom = '', string $postTo = '', string $groupID = '') // @phpstan-ignore missingType.generics
    {
        $query = Release::query()
            ->select(['r.searchname', 'r.guid', 'g.name as gname', DB::raw("CONCAT(cp.title,'_',c.title) AS catName")])
            ->from('releases as r')
            ->leftJoin('categories as c', 'c.id', '=', 'r.categories_id')
            ->leftJoin('root_categories as cp', 'cp.id', '=', 'c.root_categories_id')
            ->leftJoin('usenet_groups as g', 'g.id', '=', 'r.groups_id');

        if ($groupID !== '') {
            $query->where('r.groups_id', $groupID);
        }

        if ($postFrom !== '') {
            $dateParts = explode('/', $postFrom);
            if (\count($dateParts) === 3) {
                $query->where('r.postdate', '>', $dateParts[2].'-'.$dateParts[1].'-'.$dateParts[0].'00:00:00');
            }
        }

        if ($postTo !== '') {
            $dateParts = explode('/', $postTo);
            if (\count($dateParts) === 3) {
                $query->where('r.postdate', '<', $dateParts[2].'-'.$dateParts[1].'-'.$dateParts[0].'23:59:59');
            }
        }

        return $query->get();
    }

    /**
     * @return mixed|string
     */
    public function getEarliestUsenetPostDate(): mixed
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(min(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * @return mixed|string
     */
    public function getLatestUsenetPostDate(): mixed
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(max(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getReleasedGroupsForSelect(bool $blnIncludeAll = true): array
    {
        $groups = Release::query()
            ->selectRaw('DISTINCT g.id, g.name')
            ->leftJoin('usenet_groups as g', 'g.id', '=', 'releases.groups_id')
            ->get();
        $temp_array = [];

        if ($blnIncludeAll) {
            $temp_array[-1] = '--All Groups--';
        }

        foreach ($groups as $group) {
            $temp_array[$group['id']] = $group['name'];
        }

        return $temp_array;
    }
}
