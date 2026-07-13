<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CollectionFileCheckStatus;
use App\Enums\FileCompletionStatus;
use App\Models\Category;
use App\Models\Collection;
use App\Models\MusicInfo;
use App\Models\Release;
use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\Categorization\CategorizationService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbBacklogCreationService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\ReleaseManagementService;
use App\Support\Data\ProcessReleasesSettings;
use App\Support\Data\ReleaseCreationResult;
use App\Support\Data\ReleaseDeleteStats;
use App\Support\ReleaseSearchIndexSync;
use App\Support\TransientDatabaseError;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Service for processing collections into releases and creating NZB files.
 *
 * This service handles the complete release processing pipeline:
 * - Finding complete collections
 * - Calculating collection sizes
 * - Creating releases from collections
 * - Generating NZB files
 * - Categorizing releases
 * - Cleanup of old/unwanted releases
 */
final class ReleaseProcessingService
{
    private const int BATCH_SIZE = 500;

    private const int MAX_RETRIES = 5;

    private const int RETRY_BASE_DELAY_US = 20000;

    private const int BATCH_PAUSE_US = 10000;

    private const int CATEGORIZE_CHUNK_SIZE = 1000;

    private bool $echoCLI;

    private readonly ProcessReleasesSettings $settings;

    private readonly NzbService $nzb;

    private readonly ReleaseCleaningService $releaseCleaning;

    private readonly ReleaseManagementService $releaseManagement;

    private readonly ReleaseImageService $releaseImage;

    private readonly ReleaseCreationService $releaseCreationService;

    private readonly CollectionCleanupService $collectionCleanupService;

    private readonly ?PostProcessService $postProcessService;

    public function __construct(
        ?NzbService $nzb = null,
        ?ReleaseCleaningService $releaseCleaning = null,
        ?ReleaseManagementService $releaseManagement = null,
        ?ReleaseImageService $releaseImage = null,
        ?ReleaseCreationService $releaseCreationService = null,
        ?CollectionCleanupService $collectionCleanupService = null,
        ?PostProcessService $postProcessService = null,
    ) {
        $this->echoCLI = (bool) config('nntmux.echocli');

        $this->nzb = $nzb ?? app(NzbService::class);
        $this->releaseCleaning = $releaseCleaning ?? new ReleaseCleaningService;
        $this->releaseManagement = $releaseManagement ?? app(ReleaseManagementService::class);
        $this->releaseImage = $releaseImage ?? new ReleaseImageService;
        $this->collectionCleanupService = $collectionCleanupService
            ?? new CollectionCleanupService;

        $this->releaseCreationService = $releaseCreationService
            ?? new ReleaseCreationService(
                $this->releaseCleaning,
                $this->collectionCleanupService,
                app(ReleaseDuplicateFinder::class)
            );
        $this->postProcessService = $postProcessService;

        $this->settings = $this->loadSettings();
        $this->validateSettings();
    }

    /**
     * Load all required settings from database.
     */
    private function loadSettings(): ProcessReleasesSettings
    {
        $settingKeys = [
            'delaytime', 'crossposttime', 'maxnzbsprocessed', 'completionpercent',
            'collection_timeout', 'maxsizetoformrelease', 'minsizetoformrelease',
            'minfilestoformrelease', 'releaseretentiondays', 'deletepasswordedrelease',
            'miscotherretentionhours', 'mischashedretentionhours', 'partretentionhours',
            'last_run_time',
        ];

        $dbSettings = [];
        foreach ($settingKeys as $key) {
            $dbSettings[$key] = Settings::settingValue($key);
        }

        return ProcessReleasesSettings::forDatabase($dbSettings);
    }

    /**
     * Validate loaded settings and warn about invalid configurations.
     */
    private function validateSettings(): void
    {
        if (! $this->settings->hasValidCompletion()) {
            cli()->error(
                PHP_EOL.'Invalid completion setting. Value must be between 0 and 100.'
            );
        }
    }

    // ========================================================================
    // Public API
    // ========================================================================

    /**
     * Get the current completion percentage setting.
     */
    public function getCompletion(): int
    {
        return $this->settings->completion;
    }

    /**
     * Get the release creation limit.
     */
    public function getReleaseCreationLimit(): int
    {
        return $this->settings->releaseCreationLimit;
    }

    /**
     * Get the collection delay time in hours.
     */
    public function getCollectionDelayTime(): int
    {
        return $this->settings->collectionDelayTime;
    }

    /**
     * Get the cross-post detection time window in hours.
     */
    public function getCrossPostTime(): int
    {
        return $this->settings->crossPostTime;
    }

    /**
     * Check if CLI echo is enabled.
     */
    public function isEchoCLI(): bool
    {
        return $this->echoCLI;
    }

    /**
     * Set CLI echo mode.
     */
    public function setEchoCLI(bool $echo): self
    {
        $this->echoCLI = $echo;

        return $this;
    }

    /**
     * Main method for creating releases/NZB files from collections.
     *
     * @param  int  $categorize  Categorization type (1=name, 2=searchname)
     * @param  int  $postProcess  Whether to run post-processing (1=yes)
     * @param  string  $groupName  Optional group name to filter processing
     * @param  NNTPService  $nntp  NNTP connection for post-processing
     * @return int Total number of releases added
     *
     * @throws Throwable
     */
    public function processReleases(
        int $categorize,
        int $postProcess,
        string $groupName,
        NNTPService $nntp
    ): int {
        $this->echoCLI = (bool) config('nntmux.echocli');
        $overallStartTime = now()->toImmutable();

        $this->outputBanner();
        if (! $this->validateNzbPath()) {
            return 0;
        }

        $groupID = $this->resolveGroupId($groupName);
        $normalizedGroupId = $this->normalizeGroupId($groupID);

        if ($this->echoCLI && $groupName !== '') {
            $this->outputInfo("Processing group: {$groupName}");
        }

        // Phase 1: Collection processing
        $this->outputHeader('Phase 1: Collection Processing');
        $this->processIncompleteCollections($normalizedGroupId);
        $this->processCollectionSizes($normalizedGroupId);
        $this->deleteUnwantedCollections($normalizedGroupId);

        // Phase 2: Release creation loop
        $this->outputHeader('Phase 2: Release Creation');
        $totals = $this->runReleaseCreationLoop($normalizedGroupId, $categorize, $postProcess, $nntp);

        // Phase 3: Cleanup
        $this->outputHeader('Phase 3: Cleanup');
        $this->deleteReleases();

        $this->outputFinalSummary(
            $totals['releases'],
            $totals['nzbs'],
            $totals['dupes'],
            $totals['iterations'],
            $overallStartTime
        );

        return $totals['releases'];
    }

    /**
     * Run the release creation loop.
     *
     * @return array{releases: int, nzbs: int, dupes: int, iterations: int}
     *
     * @throws Throwable
     */
    private function runReleaseCreationLoop(
        ?int $normalizedGroupId,
        int $categorize,
        int $postProcess,
        NNTPService $nntp
    ): array {
        $totals = ['releases' => 0, 'nzbs' => 0, 'dupes' => 0, 'iterations' => 0];
        $limit = $this->settings->releaseCreationLimit;

        do {
            $totals['iterations']++;

            $result = $this->createReleases($normalizedGroupId);
            $totals['releases'] += $result->added;
            $totals['dupes'] += $result->dupes;

            $nzbFilesAdded = $this->createNZBsIfEnabled($normalizedGroupId);
            $totals['nzbs'] += $nzbFilesAdded;

            $this->categorizeReleases($categorize, $normalizedGroupId);
            $this->postProcessReleases($postProcess, $nntp);
            $this->deleteCollections($normalizedGroupId);

            $shouldContinue = $result->total() >= $limit || $nzbFilesAdded >= $limit;
        } while ($shouldContinue);

        return $totals;
    }

    /**
     * Reset all releases to other->misc category.
     */
    public function resetCategorize(string $where = ''): void
    {
        if ($where !== '') {
            DB::update(
                'UPDATE releases SET categories_id = ?, iscategorized = 0 '.$where,
                [Category::OTHER_MISC]
            );
        } else {
            Release::query()->update([
                'categories_id' => Category::OTHER_MISC,
                'iscategorized' => 0,
            ]);
        }

        ReleaseSearchIndexSync::reindexMatchingWhere($where);
    }

    /**
     * Categorize a release using the specified type.
     *
     * @throws \Exception
     */
    public function categorizeRelease(string $type, int|string|null $groupId): int
    {
        $categorizer = new CategorizationService;
        $categorized = 0;

        $query = Release::query()
            ->where('categories_id', Category::OTHER_MISC)
            ->where('iscategorized', 0)
            ->select(['id', 'fromname', 'groups_id', $type]);

        if (! empty($groupId)) {
            $query->where('groups_id', $groupId);
        }

        $total = $query->count();
        if ($total === 0) {
            return 0;
        }

        $this->outputSubHeader('Categorizing Releases');

        $query->chunkById(self::CATEGORIZE_CHUNK_SIZE, function ($releases) use ($categorizer, $type, &$categorized, $total): bool {
            foreach ($releases as $release) {
                $categoryResult = $categorizer->determineCategory(
                    $release->groups_id,
                    $release->{$type},
                    $release->fromname
                );

                Release::query()
                    ->where('id', $release->id)
                    ->update([
                        'categories_id' => $categoryResult['categories_id'],
                        'iscategorized' => 1,
                    ]);

                ReleaseSearchIndexSync::forIds([(int) $release->id]);

                $categorized++;
                $this->outputProgress($categorized, $total, 'Categorizing');
            }

            return true;
        });

        return $categorized;
    }

    /**
     * Process incomplete collections to find complete ones.
     *
     * @throws Throwable
     */
    public function processIncompleteCollections(int|string|null $groupID): void
    {
        $startTime = now()->toImmutable();
        $this->outputSubHeader('Finding Complete Collections');

        $normalizedGroupId = $this->normalizeGroupId($groupID);
        $whereSql = $this->buildGroupWhereSql($normalizedGroupId, 'c');

        $this->processStuckCollections($normalizedGroupId ?? 0);
        $this->runCollectionFileCheckStage0($normalizedGroupId);
        $this->repairObservedTotalFilesForDefaultCollections($normalizedGroupId);
        $this->runCollectionFileCheckStage1($normalizedGroupId ?? 0);
        $this->runCollectionFileCheckStage2($normalizedGroupId ?? 0);
        $this->runCollectionFileCheckStage3($normalizedGroupId);
        $this->runCollectionFileCheckStage4($normalizedGroupId);
        $this->runCollectionFileCheckStage5($normalizedGroupId ?? 0);
        $this->runCollectionFileCheckStage6($whereSql);

        $count = $this->countCompleteCollections($normalizedGroupId);
        $this->outputStat('Complete collections found', $count);
        $this->outputElapsedTime($startTime);
    }

    /**
     * Calculate sizes for complete collections.
     *
     * @throws Throwable
     */
    public function processCollectionSizes(int|string|null $groupID): void
    {
        $startTime = now()->toImmutable();
        $this->outputSubHeader('Calculating Collection Sizes');

        $updated = 0;
        DB::transaction(function () use ($groupID, &$updated): void {
            $normalizedGroupId = $this->normalizeGroupId($groupID);
            $whereSql = $normalizedGroupId !== null
                ? " AND c.groups_id = {$normalizedGroupId} "
                : ' ';

            $sql = <<<SQL
                UPDATE collections c
                SET c.filesize = (
                    SELECT COALESCE(SUM(b.partsize), 0)
                    FROM binaries b
                    WHERE b.collections_id = c.id
                ),
                c.filecheck = ?
                WHERE c.filecheck = ?
                AND c.filesize = 0{$whereSql}
            SQL;

            $updated = DB::update($sql, [
                CollectionFileCheckStatus::Sized->value,
                CollectionFileCheckStatus::CompleteParts->value,
            ]);
        }, 10);

        $this->outputStat('Collections sized', $updated);
        $this->outputElapsedTime($startTime);
    }

    /**
     * Delete collections that don't meet size/file count requirements.
     *
     * @throws Throwable
     */
    public function deleteUnwantedCollections(int|string|null $groupID): void
    {
        $startTime = now()->toImmutable();
        $this->outputSubHeader('Filtering Collections by Size/File Count');

        $normalizedGroupId = $this->normalizeGroupId($groupID);
        $groupIDs = $normalizedGroupId === null
            ? UsenetGroup::getActiveIDs()
            : [['id' => $normalizedGroupId]];

        $stats = ['minSize' => 0, 'maxSize' => 0, 'minFiles' => 0, 'par2Only' => 0];

        // Delete collections where ALL binaries are par2 files (no actual content)
        $par2OnlyQuery = DB::table('collections as c')
            ->join('binaries as b', 'c.id', '=', 'b.collections_id')
            ->where('c.filecheck', CollectionFileCheckStatus::Sized->value)
            ->where('c.filesize', '>', 0)
            ->groupBy('c.id')
            ->havingRaw("COUNT(b.id) = SUM(CASE WHEN b.name REGEXP '\\\\.(vol[0-9]+\\\\+[0-9]+\\\\.par2|par2)' THEN 1 ELSE 0 END)");

        if ($normalizedGroupId !== null) {
            $par2OnlyQuery->where('c.groups_id', $normalizedGroupId);
        }

        $par2OnlyCollectionIds = $par2OnlyQuery
            ->pluck('c.id')
            ->all();

        if ($par2OnlyCollectionIds !== []) {
            $stats['par2Only'] += $this->collectionCleanupService->deleteCollectionsAndDescendants(
                $par2OnlyCollectionIds,
                'Par2-only cleanup',
                $this->echoCLI
            );
        }

        foreach ($groupIDs as $grpID) {
            $currentGroupId = (int) $grpID['id'];
            $groupSettings = UsenetGroup::getGroupByID($currentGroupId);

            if (! $this->hasSizedCollections($currentGroupId)) {
                continue;
            }

            $effectiveMinSize = $this->effectiveGroupThreshold(
                $groupSettings?->getAttribute('minsizetoformrelease'),
                $this->settings->minSizeToFormRelease
            );
            if ($effectiveMinSize > 0) {
                $ids = Collection::query()
                    ->where('groups_id', $currentGroupId)
                    ->where('filecheck', CollectionFileCheckStatus::Sized->value)
                    ->where('filesize', '>', 0)
                    ->where('filesize', '<', $effectiveMinSize)
                    ->pluck('id')
                    ->all();
                $stats['minSize'] += $this->collectionCleanupService->deleteCollectionsAndDescendants(
                    $ids,
                    'Min-size cleanup',
                    $this->echoCLI
                );
            }

            if ($this->settings->maxSizeToFormRelease > 0) {
                $ids = Collection::query()
                    ->where('groups_id', $currentGroupId)
                    ->where('filecheck', CollectionFileCheckStatus::Sized->value)
                    ->where('filesize', '>', $this->settings->maxSizeToFormRelease)
                    ->pluck('id')
                    ->all();
                $stats['maxSize'] += $this->collectionCleanupService->deleteCollectionsAndDescendants(
                    $ids,
                    'Max-size cleanup',
                    $this->echoCLI
                );
            }

            $effectiveMinFiles = $this->effectiveGroupThreshold(
                $groupSettings?->getAttribute('minfilestoformrelease'),
                $this->settings->minFilesToFormRelease
            );
            if ($effectiveMinFiles > 0) {
                $ids = Collection::query()
                    ->where('groups_id', $currentGroupId)
                    ->where('filecheck', CollectionFileCheckStatus::Sized->value)
                    ->where('filesize', '>', 0)
                    ->where('totalfiles', '<', $effectiveMinFiles)
                    ->pluck('id')
                    ->all();
                $stats['minFiles'] += $this->collectionCleanupService->deleteCollectionsAndDescendants(
                    $ids,
                    'Min-files cleanup',
                    $this->echoCLI
                );
            }
        }

        $this->outputCollectionDeleteStats($stats, $startTime);
    }

    /**
     * Create releases from complete collections.
     *
     * @throws Throwable
     */
    public function createReleases(int|string|null $groupID): ReleaseCreationResult
    {
        $result = $this->releaseCreationService->createReleases(
            $groupID,
            $this->settings->releaseCreationLimit,
            $this->echoCLI
        );

        return ReleaseCreationResult::from($result);
    }

    /**
     * Create NZB files from releases that don't have them yet.
     *
     * @throws Throwable
     */
    public function createNZBs(int|string|null $groupID): int
    {
        $startTime = now()->toImmutable();
        $this->outputSubHeader('Creating NZB Files');

        $result = app(NzbBacklogCreationService::class)->create(
            groups: ! empty($groupID) ? [(string) $groupID] : [],
            limit: $this->settings->releaseCreationLimit,
            order: 'asc',
            onCreated: fn (int $created, int $total): null => $this->outputProgress($created, $total, 'Creating NZBs')
        );

        if ($this->echoCLI && $result['failed'] > 0) {
            cli()->warning('NZBs skipped or failed this pass: '.number_format($result['failed']));
        }

        $this->outputStat('NZBs created', $result['created']);
        $this->outputElapsedTime($startTime);

        return $result['created'];
    }

    /**
     * Keep historical NZB backlog work optional on release-formation lanes.
     * Distributed deployments run it in a separately bounded worker.
     */
    public function createNZBsIfEnabled(int|string|null $groupID): int
    {
        if (! (bool) config('nntmux.inline_nzb_creation', true)) {
            return 0;
        }

        return $this->createNZBs($groupID);
    }

    /**
     * Categorize releases based on the specified field.
     *
     * @throws \Exception
     */
    public function categorizeReleases(int $categorize, int|string|null $groupID = null): void
    {
        $startTime = now()->toImmutable();

        $type = match ($categorize) {
            2 => 'searchname',
            default => 'name',
        };

        $count = $this->categorizeRelease($type, $groupID);

        if ($count > 0) {
            $this->outputStat('Releases categorized', $count);
            $this->outputElapsedTime($startTime);
        }
    }

    /**
     * Run post-processing on releases.
     *
     * @throws \Exception
     */
    public function postProcessReleases(int $postProcess, NNTPService $nntp): void
    {
        if ($postProcess !== 1) {
            return;
        }

        $this->outputSubHeader('Post-Processing Releases');

        $service = $this->postProcessService ?? new PostProcessService;
        $service->processAll($nntp);
    }

    /**
     * Delete finished and orphaned collections.
     *
     * @throws Throwable
     */
    public function deleteCollections(int|string|null $groupID): void
    {
        $normalizedGroupId = $this->normalizeGroupId($groupID);
        if ($groupID !== null && $groupID !== '' && $normalizedGroupId === null) {
            return;
        }

        $this->collectionCleanupService->deleteFinishedAndOrphans($this->echoCLI, $normalizedGroupId);
    }

    /**
     * Delete unwanted releases based on group-specific settings.
     *
     * @throws \Exception
     */
    public function deletedReleasesByGroup(int|string $groupID = ''): void
    {
        $startTime = now()->toImmutable();
        $stats = ['minSize' => 0, 'maxSize' => 0, 'minFiles' => 0];

        if ($this->echoCLI) {
            cli()->header(
                'Process Releases -> Delete releases smaller/larger than minimum size/file count from group/site setting.'
            );
        }

        $groupIDs = $groupID === ''
            ? UsenetGroup::getActiveIDs()
            : [['id' => $groupID]];

        foreach ($groupIDs as $grpID) {
            $this->deleteReleasesUnderMinSize($grpID['id'], $stats);
            $this->deleteReleasesOverMaxSize($grpID['id'], $stats);
            $this->deleteReleasesUnderMinFiles($grpID['id'], $stats);
        }

        $this->outputReleaseDeleteByGroupStats($stats, $startTime);
    }

    /**
     * Delete releases based on site-wide settings.
     *
     * @throws \Exception
     */
    public function deleteReleases(): void
    {
        $startTime = now()->toImmutable();
        $this->outputSubHeader('Removing Unwanted Releases');

        $stats = new ReleaseDeleteStats;

        $stats = $this->deleteReleasesOverRetention($stats);
        $stats = $this->deletePasswordedReleases($stats);
        $stats = $this->deleteCrossPostedReleases($stats);
        $stats = $this->deleteIncompleteReleases($stats);
        $stats = $this->deleteDisabledCategoryReleases($stats);
        $stats = $this->deleteCategoryMinSizeReleases($stats);
        $stats = $this->deleteDisabledGenreReleases($stats);
        $stats = $this->deleteMiscReleases($stats);

        $this->outputReleaseDeleteStats($stats, $startTime);
    }

    // ========================================================================
    // Private Helper Methods
    // ========================================================================

    private function validateNzbPath(): bool
    {
        $nzbPath = config('nntmux_settings.path_to_nzbs');

        if (! file_exists($nzbPath)) {
            if ($this->echoCLI) {
                cli()->error("Bad or missing NZB directory - {$nzbPath}");
            }

            return false;
        }

        return true;
    }

    private function resolveGroupId(string $groupName): string
    {
        if ($groupName === '') {
            return '';
        }

        $groupInfo = UsenetGroup::getByName($groupName);

        return $groupInfo !== null ? (string) $groupInfo['id'] : '';
    }

    private function countCompleteCollections(?int $groupId): int
    {
        $query = Collection::query()
            ->where('filecheck', CollectionFileCheckStatus::CompleteParts->value);

        if ($groupId !== null) {
            $query->where('groups_id', $groupId);
        }

        return $query->count('id');
    }

    private function hasSizedCollections(?int $groupId = null): bool
    {
        $query = Collection::query()
            ->where('filecheck', CollectionFileCheckStatus::Sized->value)
            ->where('filesize', '>', 0);

        if ($groupId !== null) {
            $query->where('groups_id', $groupId);
        }

        return $query->exists();
    }

    private function normalizeGroupId(int|string|null $groupID): ?int
    {
        if ($groupID === null || $groupID === '') {
            return null;
        }

        if (is_numeric($groupID)) {
            return (int) $groupID;
        }

        $groupInfo = UsenetGroup::getByName($groupID);

        return $groupInfo !== null ? (int) $groupInfo['id'] : null;
    }

    private function buildGroupWhereSql(?int $groupID, string $alias = 'c'): string
    {
        return $groupID !== null ? " AND {$alias}.groups_id = {$groupID} " : ' ';
    }

    private function requiredCompletionPercent(): int
    {
        if ($this->settings->completion <= 0) {
            return 100;
        }

        return min(100, $this->settings->completion);
    }

    /**
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     *
     * @throws Throwable
     */
    private function retryTransientCollectionOperation(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (Throwable $e) {
                if (! TransientDatabaseError::is($e) || $attempt >= self::MAX_RETRIES) {
                    throw $e;
                }

                $attempt++;
                usleep(self::RETRY_BASE_DELAY_US * (2 ** $attempt));
            }
        }
    }

    // ========================================================================
    // Collection Processing Stages
    // ========================================================================

    /**
     * Backfill collection totals from stored binary file numbers without
     * advancing filecheck. Older BODY-recovered rows can have usable filenumber
     * data while collections.totalfiles is still zero, which keeps them out of
     * later completion predicates indefinitely.
     *
     * @throws Throwable
     */
    private function repairObservedTotalFilesForDefaultCollections(?int $groupID): void
    {
        $lastCollectionId = 0;

        do {
            $collectionIds = $this->retryTransientCollectionOperation(
                static fn () => Collection::query()
                    ->select(['collections.id'])
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->where('collections.id', '>', $lastCollectionId)
                    ->where('collections.totalfiles', '=', 0)
                    ->where('collections.filecheck', '=', CollectionFileCheckStatus::Default->value)
                    ->where('binaries.filenumber', '>', 0)
                    ->when($groupID !== null, static fn ($q) => $q->where('collections.groups_id', $groupID))
                    ->groupBy(['collections.id'])
                    ->orderBy('collections.id')
                    ->limit(self::BATCH_SIZE)
                    ->pluck('collections.id')
            );

            if ($collectionIds->isEmpty()) {
                break;
            }

            $this->retryTransientCollectionOperation(
                static fn () => DB::transaction(static function () use ($collectionIds): void {
                    Collection::query()
                        ->whereIn('id', $collectionIds->all())
                        ->update([
                            'totalfiles' => DB::raw(
                                '(SELECT MAX(NULLIF(b2.filenumber, 0)) FROM binaries b2 WHERE b2.collections_id = collections.id)'
                            ),
                        ]);
                }, 10)
            );

            $lastCollectionId = (int) $collectionIds->max();

            usleep(self::BATCH_PAUSE_US);
        } while ($collectionIds->count() === self::BATCH_SIZE);
    }

    /**
     * @throws Throwable
     */
    private function runCollectionFileCheckStage0(?int $groupID): void
    {
        $completion = $this->requiredCompletionPercent();

        $lastCollectionId = 0;

        do {
            $collectionIds = $this->retryTransientCollectionOperation(
                static fn () => Collection::query()
                    ->select(['collections.id'])
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->where('collections.id', '>', $lastCollectionId)
                    ->where('collections.totalfiles', '=', 0)
                    ->where('collections.filecheck', '=', CollectionFileCheckStatus::Default->value)
                    ->where('binaries.filenumber', '>', 0)
                    ->when($groupID !== null, static fn ($q) => $q->where('collections.groups_id', $groupID))
                    ->groupBy(['collections.id'])
                    ->havingRaw(
                        'COUNT(DISTINCT binaries.filenumber) >= GREATEST(1, CEIL(MAX(binaries.filenumber) * ? / 100))',
                        [$completion]
                    )
                    ->orderBy('collections.id')
                    ->limit(self::BATCH_SIZE)
                    ->pluck('collections.id')
            );

            if ($collectionIds->isEmpty()) {
                break;
            }

            $this->retryTransientCollectionOperation(
                static fn () => DB::transaction(static function () use ($collectionIds): void {
                    Collection::query()
                        ->whereIn('id', $collectionIds->all())
                        ->update([
                            'filecheck' => CollectionFileCheckStatus::CompleteCollection->value,
                            'totalfiles' => DB::raw(
                                '(SELECT MAX(NULLIF(b2.filenumber, 0)) FROM binaries b2 WHERE b2.collections_id = collections.id)'
                            ),
                        ]);
                }, 10)
            );

            $lastCollectionId = (int) $collectionIds->max();

            usleep(self::BATCH_PAUSE_US);
        } while ($collectionIds->count() === self::BATCH_SIZE);
    }

    /**
     * @throws Throwable
     */
    private function runCollectionFileCheckStage1(int $groupID): void
    {
        $completion = $this->requiredCompletionPercent();

        $lastCollectionId = 0;

        do {
            $collectionIds = $this->retryTransientCollectionOperation(
                static fn () => Collection::query()
                    ->select(['collections.id'])
                    ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                    ->where('collections.id', '>', $lastCollectionId)
                    ->where('collections.totalfiles', '>', 0)
                    ->where('collections.filecheck', '=', CollectionFileCheckStatus::Default->value)
                    ->when($groupID !== 0, static fn ($q) => $q->where('collections.groups_id', $groupID))
                    ->groupBy(['binaries.collections_id', 'collections.totalfiles', 'collections.id'])
                    ->havingRaw(
                        'COUNT(DISTINCT CASE WHEN binaries.filenumber > 0 THEN binaries.filenumber ELSE binaries.id END) >= GREATEST(1, CEIL(collections.totalfiles * ? / 100))',
                        [$completion]
                    )
                    ->orderBy('collections.id')
                    ->limit(self::BATCH_SIZE)
                    ->pluck('collections.id')
            );

            if ($collectionIds->isEmpty()) {
                break;
            }

            foreach ($collectionIds->chunk(self::BATCH_SIZE) as $ids) {
                $this->retryTransientCollectionOperation(
                    static fn () => DB::transaction(static function () use ($ids, $completion): void {
                        $eligibleCollectionsQuery = Collection::query()
                            ->select(['collections.id'])
                            ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                            ->whereIn('collections.id', $ids->all())
                            ->where('collections.totalfiles', '>', 0)
                            ->where('collections.filecheck', '=', CollectionFileCheckStatus::Default->value)
                            ->groupBy(['binaries.collections_id', 'collections.totalfiles', 'collections.id'])
                            ->havingRaw(
                                'COUNT(DISTINCT CASE WHEN binaries.filenumber > 0 THEN binaries.filenumber ELSE binaries.id END) >= GREATEST(1, CEIL(collections.totalfiles * ? / 100))',
                                [$completion]
                            );

                        Collection::query()
                            ->joinSub(
                                $eligibleCollectionsQuery,
                                'eligible_collections',
                                static fn ($join) => $join->on('collections.id', '=', 'eligible_collections.id')
                            )
                            ->update(['collections.filecheck' => CollectionFileCheckStatus::CompleteCollection->value]);
                    }, 10)
                );
            }

            $lastCollectionId = (int) $collectionIds->max();

            usleep(self::BATCH_PAUSE_US);
        } while ($collectionIds->count() === self::BATCH_SIZE);
    }

    /**
     * @throws Throwable
     */
    private function runCollectionFileCheckStage2(int $groupID): void
    {
        $this->retryTransientCollectionOperation(static fn () => DB::transaction(static function () use ($groupID): void {
            $collectionsQuery = Collection::query()
                ->select(['collections.id'])
                ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
                ->where('binaries.filenumber', '=', 0)
                ->where('collections.totalfiles', '>', 0)
                ->where('collections.filecheck', '=', CollectionFileCheckStatus::CompleteCollection->value)
                ->groupBy(['collections.id']);

            if ($groupID !== 0) {
                $collectionsQuery->where('collections.groups_id', $groupID);
            }

            Collection::query()
                ->joinSub($collectionsQuery, 'r', static fn ($join) => $join->on('collections.id', '=', 'r.id'))
                ->update(['collections.filecheck' => CollectionFileCheckStatus::ZeroPart->value]);
        }, 10));

        $this->updateCollectionsFilecheckInChunks(
            $groupID,
            CollectionFileCheckStatus::CompleteCollection->value,
            CollectionFileCheckStatus::TempComplete->value
        );
    }

    /**
     * Update collections filecheck in small chunks to reduce deadlock risk.
     * Retries on deadlock (1213) with exponential backoff.
     *
     * @throws Throwable
     */
    private function updateCollectionsFilecheckInChunks(int $groupID, int $fromStatus, int $toStatus): void
    {
        $attempt = 0;
        $maxAttempts = self::MAX_RETRIES + 1;

        while ($attempt < $maxAttempts) {
            try {
                $updated = 0;
                do {
                    $ids = Collection::query()
                        ->where('filecheck', $fromStatus)
                        ->when($groupID !== 0, static fn ($q) => $q->where('groups_id', $groupID))
                        ->orderBy('id')
                        ->limit(self::BATCH_SIZE)
                        ->pluck('id')
                        ->all();

                    if ($ids === []) {
                        break;
                    }

                    DB::transaction(static function () use ($ids, $toStatus): void {
                        Collection::query()
                            ->whereIn('id', $ids)
                            ->update(['filecheck' => $toStatus]);
                    }, 10);

                    $updated = \count($ids);
                } while ($updated === self::BATCH_SIZE);

                return;
            } catch (Throwable $e) {
                if (TransientDatabaseError::is($e) && $attempt < $maxAttempts - 1) {
                    $attempt++;
                    usleep(self::RETRY_BASE_DELAY_US * (2 ** $attempt));
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * @throws Throwable
     */
    private function runCollectionFileCheckStage3(?int $groupID): void
    {
        $completion = $this->requiredCompletionPercent();

        $this->markCompleteBinaries(
            CollectionFileCheckStatus::TempComplete,
            $groupID,
            $completion
        );
        $this->markCompleteBinaries(
            CollectionFileCheckStatus::ZeroPart,
            $groupID,
            $completion
        );
    }

    /**
     * @throws Throwable
     */
    private function runCollectionFileCheckStage4(?int $groupID): void
    {
        $completion = $this->requiredCompletionPercent();

        $lastCollectionId = 0;

        do {
            $collectionIds = $this->retryTransientCollectionOperation(
                static fn () => DB::table('collections as c')
                    ->select(['c.id'])
                    ->join('binaries as b', 'c.id', '=', 'b.collections_id')
                    ->where('c.id', '>', $lastCollectionId)
                    ->where('b.partcheck', FileCompletionStatus::Complete->value)
                    ->whereIn('c.filecheck', [
                        CollectionFileCheckStatus::TempComplete->value,
                        CollectionFileCheckStatus::ZeroPart->value,
                    ])
                    ->when($groupID !== null, static fn ($q) => $q->where('c.groups_id', $groupID))
                    ->groupBy(['b.collections_id', 'c.totalfiles', 'c.id'])
                    ->havingRaw(
                        'COUNT(b.id) >= GREATEST(1, CEIL(c.totalfiles * ? / 100))',
                        [$completion]
                    )
                    ->orderBy('c.id')
                    ->limit(self::BATCH_SIZE)
                    ->pluck('c.id')
            );

            if ($collectionIds->isEmpty()) {
                break;
            }

            $this->retryTransientCollectionOperation(
                static fn () => DB::transaction(static function () use ($collectionIds): void {
                    Collection::query()
                        ->whereIn('id', $collectionIds->all())
                        ->update([
                            'filecheck' => CollectionFileCheckStatus::CompleteParts->value,
                            'totalfiles' => DB::raw(
                                '(SELECT COUNT(b2.id) FROM binaries b2 WHERE b2.collections_id = collections.id)'
                            ),
                        ]);
                }, 10)
            );

            $lastCollectionId = (int) $collectionIds->max();

            usleep(self::BATCH_PAUSE_US);
        } while ($collectionIds->count() === self::BATCH_SIZE);
    }

    private function markCompleteBinaries(
        CollectionFileCheckStatus $collectionStatus,
        ?int $groupID,
        int $completion
    ): void {
        $lastBinaryId = 0;

        do {
            $binaryIds = $this->retryTransientCollectionOperation(
                static fn () => DB::table('binaries as b')
                    ->select(['b.id'])
                    ->join('collections as c', 'c.id', '=', 'b.collections_id')
                    ->where('b.id', '>', $lastBinaryId)
                    ->where('c.filecheck', $collectionStatus->value)
                    ->where('b.partcheck', FileCompletionStatus::Incomplete->value)
                    ->where('b.totalparts', '>', 0)
                    ->whereRaw('b.currentparts >= CEIL(b.totalparts * ? / 100)', [$completion])
                    ->when($groupID !== null, static fn ($q) => $q->where('c.groups_id', $groupID))
                    ->groupBy(['b.id', 'b.totalparts'])
                    ->orderBy('b.id')
                    ->limit(self::BATCH_SIZE)
                    ->pluck('b.id')
            );

            if ($binaryIds->isEmpty()) {
                break;
            }

            $this->retryTransientCollectionOperation(
                static fn () => DB::transaction(static function () use ($binaryIds): void {
                    DB::table('binaries')
                        ->whereIn('id', $binaryIds->all())
                        ->update(['partcheck' => FileCompletionStatus::Complete->value]);
                }, 10)
            );

            $lastBinaryId = (int) $binaryIds->max();

            usleep(self::BATCH_PAUSE_US);
        } while ($binaryIds->count() === self::BATCH_SIZE);
    }

    /**
     * @throws Throwable
     */
    private function runCollectionFileCheckStage5(int $groupId): void
    {
        $this->retryTransientCollectionOperation(static fn () => DB::transaction(static function () use ($groupId): void {
            $query = Collection::query()
                ->whereIn('filecheck', [
                    CollectionFileCheckStatus::TempComplete->value,
                    CollectionFileCheckStatus::ZeroPart->value,
                ]);

            if ($groupId !== 0) {
                $query->where('groups_id', $groupId);
            }

            $query->update(['filecheck' => CollectionFileCheckStatus::CompleteCollection->value]);
        }, 10));
    }

    /**
     * @throws Throwable
     */
    private function runCollectionFileCheckStage6(string $whereSql): void
    {
        $normalizedGroupId = $this->extractGroupIdFromWhereSql($whereSql);
        $completion = $this->requiredCompletionPercent();

        $lastCollectionId = 0;

        do {
            $candidateIds = $this->stage6CandidateCollectionIds($lastCollectionId, $normalizedGroupId);

            if ($candidateIds === []) {
                break;
            }

            $completeIds = $this->retryTransientCollectionOperation(
                fn () => $this->filterStage6CompleteCollectionIds($candidateIds, $completion)
            );

            if ($completeIds !== []) {
                $this->retryTransientCollectionOperation(
                    static fn () => DB::transaction(static function () use ($completeIds): void {
                        Collection::query()
                            ->whereIn('id', $completeIds)
                            ->update([
                                'filecheck' => CollectionFileCheckStatus::CompleteParts->value,
                                'totalfiles' => DB::raw(
                                    '(SELECT COUNT(b.id) FROM binaries b WHERE b.collections_id = collections.id)'
                                ),
                            ]);
                    }, 10)
                );
            }

            $lastCollectionId = max($candidateIds);

            usleep(self::BATCH_PAUSE_US);
        } while (\count($candidateIds) === self::BATCH_SIZE);
    }

    /**
     * @return list<int>
     */
    private function stage6CandidateCollectionIds(int $lastCollectionId, ?int $normalizedGroupId): array
    {
        return $this->retryTransientCollectionOperation(
            fn (): array => DB::table('collections')
                ->select('collections.id')
                ->where('collections.id', '>', $lastCollectionId)
                ->where('collections.dateadded', '<', now()->subHours($this->settings->collectionDelayTime))
                ->whereIn('collections.filecheck', [
                    CollectionFileCheckStatus::Default->value,
                    CollectionFileCheckStatus::CompleteCollection->value,
                    10,
                ])
                ->when($normalizedGroupId !== null, static fn ($q) => $q->where('collections.groups_id', $normalizedGroupId))
                ->orderBy('collections.id')
                ->limit(self::BATCH_SIZE)
                ->pluck('collections.id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
        );
    }

    /**
     * @param  list<int>  $candidateIds
     * @return list<int>
     */
    private function filterStage6CompleteCollectionIds(array $candidateIds, int $completion): array
    {
        if ($candidateIds === []) {
            return [];
        }

        return DB::table('collections')
            ->select('collections.id')
            ->join('binaries as existing', 'existing.collections_id', '=', 'collections.id')
            ->leftJoin('binaries as incomplete', static function ($join) use ($completion): void {
                $join->on('incomplete.collections_id', '=', 'collections.id')
                    ->whereRaw(
                        '(incomplete.currentparts < CEIL(incomplete.totalparts * ? / 100) OR incomplete.totalparts <= 0)',
                        [$completion],
                    );
            })
            ->whereIn('collections.id', $candidateIds)
            ->whereNull('incomplete.id')
            ->groupBy(['collections.id', 'collections.totalfiles'])
            ->havingRaw(
                'COUNT(DISTINCT CASE WHEN existing.filenumber > 0 THEN existing.filenumber ELSE existing.id END) >= GREATEST(1, CEIL(GREATEST(GREATEST(COALESCE(NULLIF(collections.totalfiles, 0), 0), COALESCE(MAX(NULLIF(existing.filenumber, 0)), 0)), COUNT(DISTINCT existing.id)) * ? / 100))',
                [$completion],
            )
            ->orderBy('collections.id')
            ->pluck('collections.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function extractGroupIdFromWhereSql(string $whereSql): ?int
    {
        if (preg_match('/groups_id\\s*=\\s*(\\d+)/', $whereSql, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @throws Throwable
     */
    private function processStuckCollections(int $groupID): void
    {
        $cutoff = $this->calculateStuckCollectionsCutoff();
        $totalDeleted = 0;

        do {
            $affected = $this->deleteStuckCollectionBatch($groupID, $cutoff);
            $totalDeleted += $affected;

            if ($affected < self::BATCH_SIZE) {
                break;
            }

            usleep(self::BATCH_PAUSE_US);
        } while (true);

        if ($this->echoCLI && $totalDeleted > 0) {
            cli()->primary("Deleted {$totalDeleted} broken/stuck collections.", true);
        }
    }

    private function calculateStuckCollectionsCutoff(): Carbon
    {
        $lastRun = $this->settings->lastRunTime;
        $threshold = null;

        if ($lastRun !== null) {
            try {
                $threshold = Carbon::createFromFormat('Y-m-d H:i:s', $lastRun);
            } catch (Throwable) {
                $threshold = null;
            }
        }

        return ($threshold ?? now())->copy()->subHours($this->settings->collectionTimeout);
    }

    private function deleteStuckCollectionBatch(int $groupID, Carbon $cutoff): int
    {
        $attempt = 0;
        $affected = 0;

        do {
            try {
                $query = DB::table('collections as c')
                    ->where('c.added', '<', $cutoff)
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                        ->from('releases as r')
                        ->whereColumn('r.id', 'c.releases_id')
                        ->where('r.nzbstatus', '=', NzbService::NZB_NONE))
                    ->orderBy('c.id')
                    ->limit(self::BATCH_SIZE);
                if ($groupID !== 0) {
                    $query->where('c.groups_id', '=', $groupID);
                }

                $ids = $query->pluck('c.id')->all();
                if ($ids === []) {
                    break;
                }

                $affected = $this->collectionCleanupService->deleteCollectionsAndDescendants(
                    $ids,
                    'Stuck collections cleanup',
                    $this->echoCLI
                );
                break;
            } catch (Throwable $e) {
                $attempt++;
                if ($attempt >= self::MAX_RETRIES) {
                    if ($this->echoCLI) {
                        cli()->error(
                            'Stuck collections delete failed after retries: '.$e->getMessage()
                        );
                    }
                    break;
                }
                usleep(self::RETRY_BASE_DELAY_US * $attempt);
            }
        } while (true);

        return $affected;
    }

    // ========================================================================
    // Release Deletion Methods
    // ========================================================================

    /**
     * @param  array<string, mixed>  $stats
     */
    private function deleteReleasesUnderMinSize(int|string $groupId, array &$stats): void
    {
        $effectiveMinSize = $this->effectiveGroupThresholdForGroup(
            $groupId,
            'minsizetoformrelease',
            $this->settings->minSizeToFormRelease
        );

        if ($effectiveMinSize <= 0) {
            return;
        }

        $releases = Release::query()
            ->where('groups_id', $groupId)
            ->where('size', '<', $effectiveMinSize)
            ->select(['id', 'guid'])
            ->get();

        foreach ($releases as $release) {
            $this->deleteSingleRelease($release);
            $stats['minSize']++;
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function deleteReleasesOverMaxSize(int|string $groupId, array &$stats): void
    {
        if ($this->settings->maxSizeToFormRelease <= 0) {
            return;
        }

        $releases = Release::query()
            ->where('groups_id', $groupId)
            ->where('size', '>', $this->settings->maxSizeToFormRelease)
            ->select(['id', 'guid'])
            ->get();

        foreach ($releases as $release) {
            $this->deleteSingleRelease($release);
            $stats['maxSize']++;
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function deleteReleasesUnderMinFiles(int|string $groupId, array &$stats): void
    {
        $effectiveMinFiles = $this->effectiveGroupThresholdForGroup(
            $groupId,
            'minfilestoformrelease',
            $this->settings->minFilesToFormRelease
        );

        if ($effectiveMinFiles <= 0) {
            return;
        }

        $releases = Release::query()
            ->where('groups_id', $groupId)
            ->where('totalpart', '<', $effectiveMinFiles)
            ->select(['id', 'guid'])
            ->get();

        foreach ($releases as $release) {
            $this->deleteSingleRelease($release);
            $stats['minFiles']++;
        }
    }

    private function effectiveGroupThresholdForGroup(int|string $groupId, string $column, int $siteThreshold): int
    {
        return $this->effectiveGroupThreshold(
            UsenetGroup::query()->where('id', $groupId)->value($column),
            $siteThreshold
        );
    }

    private function effectiveGroupThreshold(mixed $groupThreshold, int $siteThreshold): int
    {
        if ($groupThreshold === null || $groupThreshold === '') {
            return $siteThreshold;
        }

        return (int) $groupThreshold;
    }

    private function deleteReleasesOverRetention(ReleaseDeleteStats $stats): ReleaseDeleteStats
    {
        if (! $this->settings->hasRetentionCleanup()) {
            return $stats;
        }

        $cutoff = now()->subDays($this->settings->releaseRetentionDays);

        Release::query()
            ->where('postdate', '<', $cutoff)
            ->select(['id', 'guid'])
            ->chunkById(self::BATCH_SIZE, function ($releases) use (&$stats): bool {
                foreach ($releases as $release) {
                    $this->deleteSingleRelease($release);
                    $stats = $stats->increment('retention');
                }

                return true;
            });

        return $stats;
    }

    private function deletePasswordedReleases(ReleaseDeleteStats $stats): ReleaseDeleteStats
    {
        if (! $this->settings->deletePasswordedRelease) {
            return $stats;
        }

        Release::query()
            ->select(['id', 'guid'])
            ->where('passwordstatus', '=', ReleaseBrowseService::PASSWD_RAR)
            ->orWhereIn('id', function ($query): void {
                $query->select('releases_id')
                    ->from('release_files')
                    ->where('passworded', '=', ReleaseBrowseService::PASSWD_RAR);
            })
            ->chunkById(self::BATCH_SIZE, function ($releases) use (&$stats): bool {
                foreach ($releases as $release) {
                    $this->deleteSingleRelease($release);
                    $stats = $stats->increment('password');
                }

                return true;
            });

        return $stats;
    }

    private function deleteCrossPostedReleases(ReleaseDeleteStats $stats): ReleaseDeleteStats
    {
        if (! $this->settings->hasCrossPostDetection()) {
            return $stats;
        }

        $cutoff = now()->subHours($this->settings->crossPostTime);
        $duplicateClusters = Release::query()
            ->where('adddate', '>', $cutoff)
            ->groupBy(['name', 'fromname'])
            ->havingRaw('COUNT(name) > 1 AND COUNT(fromname) > 1')
            ->select(['name', 'fromname']);

        Release::query()
            ->joinSub($duplicateClusters, 'duplicate_releases', function ($join): void {
                $join->on('releases.name', '=', 'duplicate_releases.name')
                    ->on('releases.fromname', '=', 'duplicate_releases.fromname');
            })
            ->where('releases.adddate', '>', $cutoff)
            ->select(['releases.id', 'releases.guid'])
            ->chunkById(self::BATCH_SIZE, function ($releases) use (&$stats): bool {
                foreach ($releases as $release) {
                    $this->deleteSingleRelease($release);
                    $stats = $stats->increment('duplicate');
                }

                return true;
            }, 'releases.id', 'id');

        return $stats;
    }

    private function deleteIncompleteReleases(ReleaseDeleteStats $stats): ReleaseDeleteStats
    {
        if (! $this->settings->hasCompletionCleanup()) {
            return $stats;
        }

        Release::query()
            ->where('completion', '<', $this->settings->completion)
            ->where('completion', '>', 0)
            ->select(['id', 'guid'])
            ->chunkById(self::BATCH_SIZE, function ($releases) use (&$stats): bool {
                foreach ($releases as $release) {
                    $this->deleteSingleRelease($release);
                    $stats = $stats->increment('completion');
                }

                return true;
            });

        return $stats;
    }

    private function deleteDisabledCategoryReleases(ReleaseDeleteStats $stats): ReleaseDeleteStats
    {
        $disabledCategories = Category::getDisabledIDs();

        if ($disabledCategories->isEmpty()) {
            return $stats;
        }

        $categoryIds = $disabledCategories->pluck('id')->toArray();

        Release::query()
            ->whereIn('categories_id', $categoryIds)
            ->select(['id', 'guid'])
            ->chunkById(self::BATCH_SIZE, function ($releases) use (&$stats): bool {
                foreach ($releases as $release) {
                    $this->deleteSingleRelease($release);
                    $stats = $stats->increment('disabledCategory');
                }

                return true;
            });

        return $stats;
    }

    private function deleteCategoryMinSizeReleases(ReleaseDeleteStats $stats): ReleaseDeleteStats
    {
        $categories = Category::query()
            ->where('minsizetoformrelease', '>', 0)
            ->select(['id', 'minsizetoformrelease as minsize'])
            ->get();

        foreach ($categories as $category) {
            Release::query()
                ->where('categories_id', (int) $category->id)
                ->where('size', '<', (int) $category->minsize) // @phpstan-ignore property.notFound
                ->select(['id', 'guid'])
                ->limit(1000)
                ->chunkById(self::BATCH_SIZE, function ($releases) use (&$stats): bool {
                    foreach ($releases as $release) {
                        $this->deleteSingleRelease($release);
                        $stats = $stats->increment('categoryMinSize');
                    }

                    return true;
                });
        }

        return $stats;
    }

    private function deleteDisabledGenreReleases(ReleaseDeleteStats $stats): ReleaseDeleteStats
    {
        $genres = new GenreService;
        $genreList = $genres->getDisabledIDs();

        if ($genreList->isEmpty()) {
            return $stats;
        }

        foreach ($genreList as $genre) {
            $musicInfoQuery = MusicInfo::query()
                ->where('genre_id', (int) $genre->id) // @phpstan-ignore property.notFound
                ->select(['id']);

            Release::query()
                ->joinSub(
                    $musicInfoQuery,
                    'mi',
                    static fn ($join) => $join->on('releases.musicinfo_id', '=', 'mi.id')
                )
                ->select(['releases.id', 'releases.guid'])
                ->chunkById(self::BATCH_SIZE, function ($releases) use (&$stats): bool {
                    foreach ($releases as $release) {
                        $this->deleteSingleRelease($release);
                        $stats = $stats->increment('disabledGenre');
                    }

                    return true;
                }, 'releases.id');
        }

        return $stats;
    }

    private function deleteMiscReleases(ReleaseDeleteStats $stats): ReleaseDeleteStats
    {
        if ($this->settings->miscOtherRetentionHours > 0) {
            $cutoff = now()->subHours($this->settings->miscOtherRetentionHours);

            Release::query()
                ->where('categories_id', Category::OTHER_MISC)
                ->where('adddate', '<=', $cutoff)
                ->select(['id', 'guid'])
                ->chunkById(self::BATCH_SIZE, function ($releases) use (&$stats): bool {
                    foreach ($releases as $release) {
                        $this->deleteSingleRelease($release);
                        $stats = $stats->increment('miscOther');
                    }

                    return true;
                });
        }

        if ($this->settings->miscHashedRetentionHours > 0) {
            $cutoff = now()->subHours($this->settings->miscHashedRetentionHours);

            Release::query()
                ->where('categories_id', Category::OTHER_HASHED)
                ->where('adddate', '<=', $cutoff)
                ->select(['id', 'guid'])
                ->chunkById(self::BATCH_SIZE, function ($releases) use (&$stats): bool {
                    foreach ($releases as $release) {
                        $this->deleteSingleRelease($release);
                        $stats = $stats->increment('miscHashed');
                    }

                    return true;
                });
        }

        return $stats;
    }

    private function deleteSingleRelease(object $release): void
    {
        $this->releaseManagement->deleteSingle(
            ['g' => $release->guid, 'i' => $release->id],
            $this->nzb,
            $this->releaseImage
        );
    }

    // ========================================================================
    // Output Helper Methods
    // ========================================================================

    private function outputBanner(): void
    {
        if (! $this->echoCLI) {
            return;
        }

        echo PHP_EOL;
        cli()->header('NNTmux Release Processing');
        cli()->info('Started: '.now()->format('Y-m-d H:i:s'));
    }

    private function outputHeader(string $title): void
    {
        if (! $this->echoCLI) {
            return;
        }

        echo PHP_EOL;
        cli()->header(strtoupper($title));
        cli()->header(str_repeat('-', strlen($title)));
    }

    private function outputSubHeader(string $title): void
    {
        if (! $this->echoCLI) {
            return;
        }

        cli()->notice("  {$title}");
    }

    /** @phpstan-ignore method.unused */
    private function outputSuccess(string $message): void
    {
        if (! $this->echoCLI) {
            return;
        }

        cli()->primary("    {$message}");
    }

    private function outputInfo(string $message): void
    {
        if (! $this->echoCLI) {
            return;
        }

        cli()->info("    {$message}");
    }

    private function outputStat(string $label, string|int $value, string $suffix = ''): void
    {
        if (! $this->echoCLI) {
            return;
        }

        $formattedValue = is_int($value) ? number_format($value) : $value;
        cli()->primary("      {$label}: {$formattedValue}{$suffix}");
    }

    private function outputElapsedTime(DateTimeInterface $startTime, string $prefix = 'Time'): void
    {
        if (! $this->echoCLI) {
            return;
        }

        $elapsed = now()->diffInSeconds($startTime, true);
        $timeStr = $this->formatElapsedTime($elapsed);
        cli()->info("      {$prefix}: {$timeStr}");
    }

    private function formatElapsedTime(int|float $seconds): string
    {
        if ($seconds < 1) {
            return sprintf('%dms', (int) ($seconds * 1000));
        }

        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return sprintf('%dm %ds', $minutes, (int) $remainingSeconds);
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh %dm', $hours, $remainingMinutes);
    }

    private function outputProgress(int $current, int $total, string $action): void
    {
        if (! $this->echoCLI || $total === 0) {
            return;
        }

        $percent = min(100, (int) (($current / $total) * 100));
        echo "\r      {$action}: ".number_format($current).'/'.number_format($total)." ({$percent}%)   ";

        if ($current >= $total) {
            echo PHP_EOL;
        }
    }

    private function outputFinalSummary(
        int $releasesAdded,
        int $nzbsCreated,
        int $dupes,
        int $iterations,
        DateTimeInterface $startTime
    ): void {
        if (! $this->echoCLI) {
            return;
        }

        $elapsed = now()->diffInSeconds($startTime, true);

        echo PHP_EOL;
        cli()->header('SUMMARY');
        cli()->header('-------');
        cli()->primary('  Releases added: '.number_format($releasesAdded));
        cli()->primary('  NZBs created: '.number_format($nzbsCreated));
        if ($dupes > 0) {
            cli()->warning('  Duplicates skipped: '.number_format($dupes));
        }
        cli()->info('  Processing cycles: '.number_format($iterations));
        cli()->info('  Total time: '.$this->formatElapsedTime($elapsed));
        echo PHP_EOL;
    }

    /**
     * @param  array{minSize: int, maxSize: int, minFiles: int}  $stats
     */
    private function outputCollectionDeleteStats(array $stats, DateTimeInterface $startTime): void
    {
        $totalDeleted = $stats['minSize'] + $stats['maxSize'] + $stats['minFiles'] + ($stats['par2Only'] ?? 0);

        if ($totalDeleted > 0) {
            $this->outputStat('Too small', $stats['minSize']);
            $this->outputStat('Too large', $stats['maxSize']);
            $this->outputStat('Too few files', $stats['minFiles']);
            if (($stats['par2Only'] ?? 0) > 0) {
                $this->outputStat('Par2 only', $stats['par2Only']);
            }
            $this->outputStat('Total removed', $totalDeleted);
        } else {
            $this->outputInfo('No collections filtered');
        }
        $this->outputElapsedTime($startTime);
    }

    /**
     * @param  array{minSize: int, maxSize: int, minFiles: int}  $stats
     */
    private function outputReleaseDeleteByGroupStats(array $stats, DateTimeInterface $startTime): void
    {
        $total = $stats['minSize'] + $stats['maxSize'] + $stats['minFiles'];

        if ($total > 0) {
            $this->outputStat('Too small', $stats['minSize']);
            $this->outputStat('Too large', $stats['maxSize']);
            $this->outputStat('Too few files', $stats['minFiles']);
        }
        $this->outputElapsedTime($startTime);
    }

    private function outputReleaseDeleteStats(ReleaseDeleteStats $stats, DateTimeInterface $startTime): void
    {
        if (! $this->echoCLI) {
            return;
        }

        $total = $stats->total();

        if ($total > 0) {
            if ($stats->retention > 0) {
                $this->outputStat('Past retention', $stats->retention);
            }
            if ($stats->password > 0) {
                $this->outputStat('Passworded', $stats->password);
            }
            if ($stats->duplicate > 0) {
                $this->outputStat('Cross-posted', $stats->duplicate);
            }
            if ($stats->completion > 0) {
                $this->outputStat("Under {$this->settings->completion}% complete", $stats->completion);
            }
            if ($stats->disabledCategory > 0) {
                $this->outputStat('Disabled categories', $stats->disabledCategory);
            }
            if ($stats->categoryMinSize > 0) {
                $this->outputStat('Under category min size', $stats->categoryMinSize);
            }
            if ($stats->disabledGenre > 0) {
                $this->outputStat('Disabled genres', $stats->disabledGenre);
            }
            if ($stats->miscOther > 0) {
                $this->outputStat('Misc->Other expired', $stats->miscOther);
            }
            if ($stats->miscHashed > 0) {
                $this->outputStat('Misc->Hashed expired', $stats->miscHashed);
            }

            $this->outputStat('Total releases removed', $total);
        } else {
            $this->outputInfo('No releases removed');
        }

        $this->outputElapsedTime($startTime);
    }
}
