<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CollectionFileCheckStatus;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Predb;
use App\Models\Release;
use App\Models\ReleaseRegex;
use App\Models\ReleasesGroups;
use App\Models\UsenetGroup;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\Nzb\NzbService;
use App\Services\Orchestrator\CurrentForwardTerminalSplitRepair;
use App\Services\Orchestrator\CurrentForwardWindowLineage;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Support\Utf8;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReleaseCreationService
{
    public function __construct(
        private readonly ReleaseCleaningService $releaseCleaning,
        private readonly CollectionCleanupService $collectionCleanupService,
        private readonly ReleaseDuplicateFinder $releaseDuplicateFinder,
    ) {}

    /**
     * Create releases from complete collections.
     *
     * @return array{added:int,dupes:int}
     *
     * @throws \Throwable
     */
    public function createReleases(int|string|null $groupID, int $limit, bool $echoCLI): array
    {
        return $this->createReleasesFromSelection($groupID, $limit, $echoCLI);
    }

    /**
     * Create releases only for the cohort that survived bounded filtering.
     *
     * @param  list<int>  $collectionIds
     * @return array{added:int,dupes:int}
     */
    public function createReleasesForCollectionIds(
        int|string|null $groupID,
        array $collectionIds,
        bool $echoCLI,
        ?float $deadlineAt = null,
    ): array {
        $collectionIds = array_values(array_unique(array_map('intval', $collectionIds)));
        if ($collectionIds === []) {
            return ['added' => 0, 'dupes' => 0];
        }

        return $this->createReleasesFromSelection(
            $groupID,
            \count($collectionIds),
            $echoCLI,
            $collectionIds,
            $deadlineAt,
        );
    }

    /**
     * @param  list<int>|null  $collectionIds
     * @return array{added:int,dupes:int}
     *
     * @throws \Throwable
     */
    private function createReleasesFromSelection(
        int|string|null $groupID,
        int $limit,
        bool $echoCLI,
        ?array $collectionIds = null,
        ?float $deadlineAt = null,
    ): array {
        $startTime = now()->toImmutable();
        $categorize = new CategorizationService;
        $returnCount = 0;
        $duplicate = 0;

        if ($echoCLI) {
            cli()->header('Process Releases -> Create releases from complete collections.');
        }

        $collectionsQuery = Collection::query()
            ->where('collections.filecheck', CollectionFileCheckStatus::Sized->value)
            ->where('collections.filesize', '>', 0);
        if ($collectionIds !== null) {
            $collectionsQuery->whereIn('collections.id', $collectionIds);
        }
        if (! empty($groupID)) {
            $collectionsQuery->where('collections.groups_id', $groupID);
        }
        $collectionsQuery->select(['collections.*', 'usenet_groups.name as gname'])
            ->join('usenet_groups', 'usenet_groups.id', '=', 'collections.groups_id')
            ->orderBy('collections.id')
            ->limit($limit);
        $collections = $collectionsQuery->get();

        if ($echoCLI && $collections->count() > 0) {
            cli()->primary(\count($collections).' Collections ready to be converted to releases.', true);
        }

        foreach ($collections as $collection) {
            if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
                break;
            }
            $decodedSubject = (new ObfuscatedSubjectExtractor)->decodeRot13Subject((string) $collection->subject);
            $cleanRelName = Utf8::clean(str_replace(['#', '@', '$', '%', '^', '§', '¨', '©', 'Ö'], '', $decodedSubject));
            $fromName = Utf8::clean(trim($collection->fromname, "'"));

            $cleanedMeta = $this->releaseCleaning->releaseCleaner(
                $decodedSubject,
                $collection->fromname,
                $collection->gname
            );

            $namingRegexId = 0;
            if (\is_array($cleanedMeta)) {
                $namingRegexId = isset($cleanedMeta['id']) ? (int) $cleanedMeta['id'] : 0;
            }

            if (\is_array($cleanedMeta)) {
                $properName = $cleanedMeta['properlynamed'] ?? false;
                $preID = $cleanedMeta['predb'] ?? false;
                $cleanedName = $cleanedMeta['cleansubject'] ?? $cleanRelName;
            } else {
                $properName = true;
                $preID = false;
                $cleanedName = $cleanRelName;
            }

            if ($preID === false && $cleanedName !== '') {
                $preMatch = Predb::matchPre($cleanedName);
                if ($preMatch !== false) {
                    $cleanedName = $preMatch['title'];
                    $preID = $preMatch['predb_id'];
                    $properName = true;
                }
            }

            $searchName = ! empty($cleanedName) ? Utf8::clean($cleanedName) : $cleanRelName;
            $predbIdInt = $preID === false ? 0 : (int) $preID;

            [$dupeCheck, $dupeReason] = $this->releaseDuplicateFinder->findDuplicate(
                $cleanRelName,
                $searchName,
                $predbIdInt,
                (int) $collection->filesize
            );

            if ($dupeCheck === null) {
                $determinedCategory = $categorize->determineCategory($collection->groups_id, $cleanedName, $fromName);

                $releaseID = DB::transaction(function () use (
                    $cleanRelName,
                    $searchName,
                    $collection,
                    $fromName,
                    $determinedCategory,
                    $properName,
                    $predbIdInt,
                    $groupID,
                ): ?int {
                    $eligibleCollection = Collection::query()
                        ->whereKey($collection->id)
                        ->where('filecheck', CollectionFileCheckStatus::Sized->value)
                        ->where('filesize', '>', 0)
                        ->where(function ($query): void {
                            $query->whereNull('releases_id')->orWhere('releases_id', 0);
                        })
                        ->when(! empty($groupID), static fn ($q) => $q->where('groups_id', $groupID))
                        ->lockForUpdate()
                        ->first();
                    if ($eligibleCollection === null) {
                        return null;
                    }

                    $releaseID = Release::insertRelease([
                        'name' => $cleanRelName,
                        'searchname' => $searchName,
                        'totalpart' => $eligibleCollection->totalfiles,
                        'groups_id' => $eligibleCollection->groups_id,
                        'guid' => Str::uuid()->toString(),
                        'postdate' => $eligibleCollection->date,
                        'fromname' => $fromName,
                        'size' => $eligibleCollection->filesize,
                        'categories_id' => $determinedCategory['categories_id'] ?? Category::OTHER_MISC,
                        'isrenamed' => $properName === true ? 1 : 0,
                        'predb_id' => $predbIdInt,
                        'nzbstatus' => NzbService::NZB_NONE,
                    ], false);
                    if ($releaseID === null) {
                        return null;
                    }

                    Collection::query()->where('id', $eligibleCollection->id)->update([
                        'filecheck' => CollectionFileCheckStatus::Inserted->value,
                        'releases_id' => $releaseID,
                    ]);
                    if (! (new CurrentForwardTerminalSplitRepair)->recordReleaseAttribution(
                        (int) $eligibleCollection->id,
                        (int) $releaseID,
                    )) {
                        (new CurrentForwardWindowLineage)->recordReleaseForCollection(
                            (int) $eligibleCollection->id,
                            (int) $releaseID,
                        );
                    }

                    return (int) $releaseID;
                }, 10);

                if ($releaseID !== null) {
                    Search::updateRelease($releaseID);
                    ReleaseRegex::insertOrIgnore([
                        'releases_id' => $releaseID,
                        'collection_regex_id' => $collection->collection_regexes_id,
                        'naming_regex_id' => $namingRegexId,
                    ]);

                    self::linkReleaseToGroup($releaseID, (int) $collection->groups_id);

                    if (preg_match_all('#(\S+):\S+#', $collection->xref, $hits)) {
                        foreach ($hits[1] as $grp) {
                            $grpTmp = UsenetGroup::isValidGroup($grp);
                            if ($grpTmp !== false) {
                                $xrefGrpID = UsenetGroup::getIDByName($grpTmp);
                                if ($xrefGrpID === false) {
                                    continue;
                                }

                                self::linkReleaseToGroup($releaseID, (int) $xrefGrpID);
                            }
                        }
                    }

                    $returnCount++;
                    if ($echoCLI) {
                        echo "Added $returnCount releases.\r";
                    }
                }
            } else {
                Log::info('Release import skipped as duplicate', [
                    'reason' => $dupeReason,
                    'matched_release_id' => $dupeCheck->id,
                    'new_searchname' => $searchName,
                    'existing_searchname' => $dupeCheck->searchname,
                    'new_size' => (int) $collection->filesize,
                    'existing_size' => (int) $dupeCheck->size,
                    'new_fromname' => $fromName,
                    'existing_fromname' => $dupeCheck->fromname,
                    'new_name' => $cleanRelName,
                    'existing_name' => $dupeCheck->name,
                ]);

                $this->collectionCleanupService->deleteCollectionsAndDescendants(
                    [$collection->id],
                    'Duplicate cleanup',
                    $echoCLI
                );

                $duplicate++;
            }
        }

        $totalTime = now()->diffInSeconds($startTime, true);
        if ($echoCLI) {
            cli()->primary(
                PHP_EOL.
                number_format($returnCount).
                ' Releases added and '.
                number_format($duplicate).
                ' duplicate collections deleted in '.
                $totalTime.Str::plural(' second', (int) $totalTime),
                true
            );
        }

        return ['added' => $returnCount, 'dupes' => $duplicate];
    }

    private static function linkReleaseToGroup(int $releaseID, int $groupID): void
    {
        if ($releaseID <= 0 || $groupID <= 0) {
            return;
        }

        ReleasesGroups::query()->insertOrIgnore([
            'releases_id' => $releaseID,
            'groups_id' => $groupID,
        ]);
    }
}
