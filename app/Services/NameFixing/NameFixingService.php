<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\Extractors\NfoNameExtractor;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Main service for name fixing operations.
 *
 * Orchestrates the various name fixing sources (NFO, Files, CRC, SRR, etc.)
 * and handles the overall processing flow.
 */
class NameFixingService
{
    private const FRESH_HASHED_MARKER_MUTATION_LEASE_SECONDS = 30;

    // Constants for name fixing status
    public const PROC_NFO_NONE = 0;

    public const PROC_NFO_DONE = 1;

    public const PROC_FILES_NONE = 0;

    public const PROC_FILES_DONE = 1;

    public const PROC_FILES_RETRY_DONE = 2;

    public const PROC_PAR2_NONE = 0;

    public const PROC_PAR2_DONE = 1;

    public const PROC_UID_NONE = 0;

    public const PROC_UID_DONE = 1;

    public const PROC_HASH16K_NONE = 0;

    public const PROC_HASH16K_DONE = 1;

    public const PROC_SRR_NONE = 0;

    public const PROC_SRR_DONE = 1;

    public const PROC_CRC_NONE = 0;

    public const PROC_CRC_DONE = 1;

    // Constants for overall rename status
    public const IS_RENAMED_NONE = 0;

    public const IS_RENAMED_DONE = 1;

    protected ReleaseUpdateService $updateService;

    protected NameCheckerService $checkerService;

    protected NfoNameExtractor $nfoExtractor;

    protected FileNameExtractor $fileExtractor;

    protected FileNameCleaner $fileNameCleaner;

    protected FilePrioritizer $filePrioritizer;

    protected PredbMatchSelector $predbMatchSelector;

    protected bool $echoOutput;

    protected string $othercats;

    protected string $timeother;

    protected string $timeall;

    protected string $fullother;

    protected string $fullall;

    protected string $timehashed;

    protected string $fullhashed;

    protected string $moviecats;

    /** @var list<int> */
    protected array $movieCategoryIds;

    protected string $timemovies;

    protected string $fullmovies;

    protected int $_totalReleases = 0;

    public function __construct(
        ?ReleaseUpdateService $updateService = null,
        ?NameCheckerService $checkerService = null,
        ?NfoNameExtractor $nfoExtractor = null,
        ?FileNameExtractor $fileExtractor = null,
        ?FileNameCleaner $fileNameCleaner = null,
        ?FilePrioritizer $filePrioritizer = null,
        ?PredbMatchSelector $predbMatchSelector = null
    ) {
        $this->updateService = $updateService ?? new ReleaseUpdateService;
        $this->checkerService = $checkerService ?? new NameCheckerService;
        $this->nfoExtractor = $nfoExtractor ?? new NfoNameExtractor;
        $this->fileExtractor = $fileExtractor ?? new FileNameExtractor;
        $this->fileNameCleaner = $fileNameCleaner ?? new FileNameCleaner;
        $this->filePrioritizer = $filePrioritizer ?? new FilePrioritizer;
        $this->predbMatchSelector = $predbMatchSelector ?? new PredbMatchSelector($this->fileNameCleaner);
        $this->echoOutput = (bool) config('nntmux.echocli');

        $this->othercats = implode(',', array_diff(Category::OTHERS_GROUP, [Category::OTHER_HASHED]));
        $this->timeother = sprintf(' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) AND rel.categories_id IN (%s) GROUP BY rel.id ORDER BY postdate DESC', $this->othercats);
        $this->timeall = ' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) GROUP BY rel.id ORDER BY postdate DESC';
        $this->fullother = sprintf(' AND rel.categories_id IN (%s) GROUP BY rel.id ORDER BY rel.adddate DESC', $this->othercats);
        $this->fullall = '';
        $this->timehashed = sprintf(' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) AND rel.categories_id = %d GROUP BY rel.id ORDER BY postdate DESC', Category::OTHER_HASHED);
        $this->fullhashed = sprintf(' AND rel.categories_id = %d GROUP BY rel.id ORDER BY rel.adddate DESC', Category::OTHER_HASHED);
        $this->movieCategoryIds = array_values(array_diff(Category::MOVIES_GROUP, [Category::MOVIE_ROOT]));
        $this->moviecats = implode(',', $this->movieCategoryIds);
        $this->timemovies = sprintf(' AND rel.adddate > (NOW() - INTERVAL 6 HOUR) AND rel.categories_id IN (%s) GROUP BY rel.id ORDER BY postdate DESC', $this->moviecats);
        $this->fullmovies = sprintf(' AND rel.categories_id IN (%s) GROUP BY rel.id', $this->moviecats);
    }

    /**
     * Fix names using NFO content.
     */
    public function fixNamesWithNfo(int $time, bool $echo, int $cats, bool $nameStatus, bool $show, int $limit = 0): void
    {
        $this->echoStartMessage($time, '.nfo files');
        $type = 'NFO, ';

        $preId = false;
        if ($cats === 3) {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.fromname
                FROM releases rel
                INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
                WHERE rel.predb_id = 0'
            );
            $cats = 2;
            $preId = true;
        } else {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.fromname
                FROM releases rel
                INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
                WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
                AND rel.predb_id = 0
                AND rel.proc_nfo = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_NFO_NONE
            );
        }

        $releases = $this->getReleases($time, $cats, $query, $limit);
        $total = $releases->count();

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' releases to process.');

            foreach ($releases as $rel) {
                /** @var Release $rel */
                $releaseRow = Release::fromQuery(
                    sprintf(
                        'SELECT nfo.releases_id AS nfoid, rel.groups_id, rel.fromname, rel.categories_id, rel.name, rel.searchname,
                            UNCOMPRESS(nfo) AS textstring, rel.id AS releases_id
                        FROM releases rel
                        INNER JOIN release_nfos nfo ON (nfo.releases_id = rel.id)
                        WHERE rel.id = %d LIMIT 1',
                        $rel->releases_id
                    )
                );

                $this->updateService->incrementChecked();

                // Ignore encrypted NFOs
                if (preg_match('/^=newz\[NZB\]=\w+/', $releaseRow[0]->textstring)) {
                    $this->updateService->updateSingleColumn('proc_nfo', self::PROC_NFO_DONE, $rel->releases_id);

                    continue;
                }

                $this->updateService->reset();

                // Try NFO extraction
                $nfoResult = $this->nfoExtractor->extractFromNfo($releaseRow[0]->textstring);
                if ($nfoResult !== null) {
                    $this->updateService->updateRelease(
                        $releaseRow[0],
                        $nfoResult->newName,
                        'nfoCheck: '.$nfoResult->method,
                        $echo,
                        $type,
                        $nameStatus,
                        $show
                    );
                }

                // If NFO extraction didn't work, try pattern checkers
                if (! $this->updateService->matched) {
                    $this->checkWithPatternMatchers($releaseRow[0], $echo, $type, $nameStatus, $show, $preId);
                }

                if ($nameStatus === true && ! $this->updateService->matched) {
                    $this->updateProcessingFlags($type, $rel->releases_id);
                }

                $this->echoRenamed($show);
            }
            $this->echoFoundCount($echo, ' NFO\'s');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    /**
     * Fix names using file names.
     */
    public function fixNamesWithFiles(int $time, bool $echo, int $cats, bool $nameStatus, bool $show, int $limit = 0): void
    {
        $this->echoStartMessage($time, 'file names');
        $type = 'Filenames, ';
        $allowedCategories = $cats === 4 ? $this->movieCategoryIds : [];

        $preId = false;
        if ($cats === 3) {
            $query = sprintf(
                'SELECT rf.name AS textstring, rf.size AS evidence_size, rf.crc32 AS evidence_crc32,
                    rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id, rel.adddate,
                    rf.releases_id AS fileid, rel.id AS releases_id
                FROM releases rel
                INNER JOIN release_files rf ON rf.releases_id = rel.id
                WHERE predb_id = 0'
            );
            $cats = 2;
            $preId = true;
        } else {
            $query = sprintf(
                'SELECT rf.name AS textstring, rf.size AS evidence_size, rf.crc32 AS evidence_crc32,
                    rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id, rel.adddate,
                    rf.releases_id AS fileid, rel.id AS releases_id
                FROM releases rel
                INNER JOIN release_files rf ON rf.releases_id = rel.id
                WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
                AND rel.predb_id = 0
                AND proc_files = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_FILES_NONE
            );
        }

        $releases = $this->getReleases($time, $cats, $query, $limit);
        $total = $releases->count();

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' file names to process.');

            // Group files by release
            $releaseFiles = [];
            foreach ($releases as $release) {
                /** @var Release $release */
                $releaseId = $release->releases_id;
                if (! isset($releaseFiles[$releaseId])) {
                    $releaseFiles[$releaseId] = [
                        'release' => $release,
                        'files' => [],
                        'evidence' => [],
                    ];
                }
                $releaseFiles[$releaseId]['files'][] = $release->textstring;
                $releaseFiles[$releaseId]['evidence'][] = $this->freshHashedFileSignature(
                    (string) $release->textstring,
                    (string) $release->evidence_size,
                    (string) $release->evidence_crc32,
                );
            }

            foreach ($releaseFiles as $releaseId => $data) {
                $this->processReleaseFiles($data['release'], $data['files'], $allowedCategories, $echo, $nameStatus, $show, $type);

                if ($nameStatus === true && ! $this->updateService->matched) {
                    if ($cats === 5 && $this->isFreshRelease($data['release'])) {
                        if ($echo) {
                            $this->finishFreshHashedFileFirstPass($data['release'], $data['evidence']);
                        }
                    } else {
                        $this->updateProcessingFlags($type, (int) $releaseId);
                    }
                }

                $this->echoRenamed($show);
            }

            $this->echoFoundCount($echo, ' files');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    public function retryFreshHashedFiles(bool $echo, bool $nameStatus, bool $show, int $limit = 100): void
    {
        if (! $echo || ! $nameStatus) {
            return;
        }
        $limit = min(100, $limit > 0 ? $limit : 100);
        $candidates = $this->freshHashedFileCandidates($limit);
        $allowedCategories = [
            ...array_values(array_diff(Category::MOVIES_GROUP, [Category::MOVIE_ROOT])),
            ...array_values(array_diff(Category::TV_GROUP, [Category::TV_ROOT])),
        ];
        $stats = ['scanned' => $candidates->count(), 'eligible' => 0, 'growth' => 0, 'claims' => 0, 'matched' => 0, 'fail_closed' => 0, 'deferred' => 0];

        foreach ($candidates as $candidate) {
            $releaseId = (int) $candidate->id;
            $marker = $this->freshHashedFileMarker($releaseId);
            if ($marker === null || ! $this->acquireFreshHashedFileLease($releaseId, $lease)) {
                continue;
            }
            $stats['claims']++;
            try {
                $markerAfterLease = $this->freshHashedFileMarker($releaseId);
                $release = $this->freshHashedEligibleRelease($releaseId);
                if ($markerAfterLease === null || $release === null || ! hash_equals($marker['token'], $markerAfterLease['token'])) {
                    continue;
                }
                $marker = $markerAfterLease;
                $stats['eligible']++;
                $evidence = $this->freshHashedFileEvidence($releaseId);
                if (! $evidence['overflow'] && $evidence['signatures'] === $marker['evidence']) {
                    continue;
                }
                $stats['growth']++;
                $release->releases_id = $release->id;
                $release->fresh_hashed_retry_guard = $this->freshHashedRetryGuard($release);

                if ($evidence['overflow']) {
                    if ($this->finishFreshHashedRetry($release)) {
                        $this->forgetFreshHashedFileMarker($releaseId, $marker['token']);
                    }
                    $stats['fail_closed']++;
                    Log::warning('Fresh hashed file retry failed closed on excessive files', [
                        'release_id' => $releaseId,
                    ]);

                    continue;
                }

                $this->processReleaseFiles(
                    $release,
                    $evidence['files'],
                    $allowedCategories,
                    $echo,
                    $nameStatus,
                    $show,
                    'Fresh hashed files, ',
                );
                if ($this->updateService->matched) {
                    $stats['matched']++;
                    $this->forgetFreshHashedFileMarker($releaseId, $marker['token']);

                    continue;
                }

                $after = $this->freshHashedFileEvidence($releaseId);
                if ($after['overflow']) {
                    if ($this->finishFreshHashedRetry($release)) {
                        $this->forgetFreshHashedFileMarker($releaseId, $marker['token']);
                    }
                    $stats['fail_closed']++;

                    continue;
                }
                if ($after['signatures'] !== $evidence['signatures']) {
                    $stats['deferred']++;

                    continue;
                }
                if ($this->finishFreshHashedRetry($release)) {
                    $this->forgetFreshHashedFileMarker($releaseId, $marker['token']);
                }
            } catch (Throwable $error) {
                $stats['deferred']++;
                Log::warning('Fresh hashed file retry processing deferred after transient failure', [
                    'release_id' => $releaseId,
                    'error' => $error->getMessage(),
                ]);
            } finally {
                $this->releaseFreshHashedFileLease($lease);
            }
        }
        cli()->info(sprintf(
            'Fresh hashed file retry: scanned=%d eligible=%d growth=%d claims=%d matched=%d fail_closed=%d deferred=%d',
            $stats['scanned'],
            $stats['eligible'],
            $stats['growth'],
            $stats['claims'],
            $stats['matched'],
            $stats['fail_closed'],
            $stats['deferred'],
        ));
    }

    protected function freshHashedFileCandidates(int $limit): \Illuminate\Support\Collection
    {
        $limit = min(100, max(1, $limit));
        $cursor = $this->freshHashedFileCursor();
        $query = $this->freshHashedFileCandidateQuery();
        if ($cursor !== null) {
            $query->where(function ($query) use ($cursor): void {
                $query->where('adddate', '>', $cursor['adddate'])
                    ->orWhere(function ($query) use ($cursor): void {
                        $query->where('adddate', $cursor['adddate'])->where('id', '>', $cursor['id']);
                    });
            });
        }
        $candidates = $query->limit($limit)->get();
        if ($cursor !== null && $candidates->count() < $limit) {
            $wrapped = $this->freshHashedFileCandidateQuery()
                ->where(function ($query) use ($cursor): void {
                    $query->where('adddate', '<', $cursor['adddate'])
                        ->orWhere(function ($query) use ($cursor): void {
                            $query->where('adddate', $cursor['adddate'])->where('id', '<=', $cursor['id']);
                        });
                })
                ->limit($limit - $candidates->count())
                ->get();
            $candidates = $candidates->concat($wrapped);
        }
        if ($candidates->isNotEmpty()) {
            $last = $candidates->last();
            $this->storeFreshHashedFileCursor((string) $last->adddate, (int) $last->id);
        }

        return $candidates;
    }

    private function freshHashedFileCandidateQuery(): Builder
    {
        return DB::table('releases')
            ->select(['id', 'adddate'])
            ->where('categories_id', Category::OTHER_HASHED)
            ->where('isrenamed', self::IS_RENAMED_NONE)
            ->where('predb_id', 0)
            ->where('proc_files', self::PROC_FILES_DONE)
            ->where('adddate', '>=', now()->subSeconds(600))
            ->orderBy('adddate')
            ->orderBy('id');
    }

    /** @param list<string> $files @param list<int> $allowedCategories */
    private function processReleaseFiles(
        Release $source,
        array $files,
        array $allowedCategories,
        bool $echo,
        bool $nameStatus,
        bool $show,
        string $type,
    ): void {
        $this->updateService->reset();
        $this->updateService->incrementChecked();
        foreach ($this->filePrioritizer->prioritizeForMatching($files) as $filename) {
            $release = clone $source;
            $release->textstring = $filename;
            if ($allowedCategories !== []) {
                $release->allowed_categories = $allowedCategories;
            }
            $fileResult = $this->fileExtractor->extractFromFile($filename);
            if ($fileResult !== null
                && ($allowedCategories === [] || $this->isSafeMovieFilenameResult($fileResult->method, $fileResult->newName))) {
                $this->updateService->updateRelease(
                    $release,
                    $fileResult->newName,
                    'fileCheck: '.$fileResult->method,
                    $echo,
                    $type,
                    $nameStatus,
                    $show,
                );
            }
            if (! $this->updateService->matched) {
                $this->preDbFileCheck($release, $echo, $type, $nameStatus, $show);
            }
            if (! $this->updateService->matched) {
                $this->preDbTitleCheck($release, $echo, $type, $nameStatus, $show);
            }
            if ($this->updateService->matched) {
                break;
            }
        }
    }

    private function isFreshRelease(Release $release): bool
    {
        return (int) $release->categories_id === Category::OTHER_HASHED
            && strtotime((string) $release->adddate) >= time() - 600;
    }

    /** @param list<string> $observedEvidence */
    protected function finishFreshHashedFileFirstPass(Release $release, array $observedEvidence): void
    {
        $releaseId = (int) $release->releases_id;
        sort($observedEvidence, SORT_STRING);
        $overflow = count($observedEvidence) > 32;
        $observedEvidence = array_slice(array_values(array_unique($observedEvidence)), 0, 32);
        $observedAt = time();
        $releaseExpiry = strtotime((string) $release->adddate) + 600;
        $expiresAt = min($observedAt + 600, $releaseExpiry);
        $token = bin2hex(random_bytes(16));
        $stored = false;
        if ($expiresAt > $observedAt) {
            $cache = null;
            $mutationLease = null;
            try {
                $cache = $this->freshHashedCacheRepository();
                $mutationLease = $this->freshHashedCacheLock(
                    $cache,
                    $this->freshHashedFileMarkerMutationKey($releaseId),
                    self::FRESH_HASHED_MARKER_MUTATION_LEASE_SECONDS,
                );
                if (! $mutationLease->get()) {
                    return;
                }
            } catch (Throwable $error) {
                Log::warning('Could not coordinate fresh hashed file retry baseline', [
                    'release_id' => $releaseId,
                    'error' => $error->getMessage(),
                ]);
                $cache = null;
                $mutationLease = null;
            }

            if ($cache !== null && $mutationLease !== null) {
                try {
                    try {
                        $stored = $cache->add($this->freshHashedFileKey($releaseId), [
                            'schema' => 2,
                            'release_id' => $releaseId,
                            'evidence' => $observedEvidence,
                            'overflow' => $overflow,
                            'observed_at' => $observedAt,
                            'expires_at' => $expiresAt,
                            'token' => $token,
                        ], $expiresAt - $observedAt);
                    } catch (Throwable $error) {
                        Log::warning('Could not persist fresh hashed file retry baseline', [
                            'release_id' => $releaseId,
                            'error' => $error->getMessage(),
                        ]);
                    }

                    $this->beforeFreshHashedFileFirstPassCas($releaseId);
                    $updated = $this->markFreshHashedFileFirstPassDone($releaseId);
                    if ($stored && $updated !== 1 && $mutationLease->isOwnedByCurrentProcess()) {
                        try {
                            $value = $cache->get($this->freshHashedFileKey($releaseId));
                            if (is_array($value) && hash_equals((string) ($value['token'] ?? ''), $token)) {
                                $cache->forget($this->freshHashedFileKey($releaseId));
                            }
                        } catch (Throwable $error) {
                            Log::warning('Could not clean losing fresh hashed file retry baseline', [
                                'release_id' => $releaseId,
                                'error' => $error->getMessage(),
                            ]);
                        }
                    }
                } finally {
                    try {
                        $mutationLease->release();
                    } catch (Throwable $error) {
                        Log::warning('Could not release fresh hashed file marker mutation lease', [
                            'release_id' => $releaseId,
                            'error' => $error->getMessage(),
                        ]);
                    }
                }

                return;
            }
        }

        $this->beforeFreshHashedFileFirstPassCas($releaseId);
        $this->markFreshHashedFileFirstPassDone($releaseId);
    }

    private function markFreshHashedFileFirstPassDone(int $releaseId): int
    {
        return DB::table('releases')
            ->where('id', $releaseId)
            ->where('proc_files', self::PROC_FILES_NONE)
            ->update(['proc_files' => self::PROC_FILES_DONE]);
    }

    protected function beforeFreshHashedFileFirstPassCas(int $releaseId): void {}

    /** @return array{evidence: list<string>, overflow: bool, token: string}|null */
    private function freshHashedFileMarker(int $releaseId): ?array
    {
        try {
            $value = Cache::store((string) config('cache.default', 'redis'))->get($this->freshHashedFileKey($releaseId));
        } catch (Throwable $error) {
            Log::warning('Could not read fresh hashed file retry baseline', [
                'release_id' => $releaseId,
                'error' => $error->getMessage(),
            ]);

            return null;
        }
        if (! is_array($value)) {
            return null;
        }
        $observedAt = (int) ($value['observed_at'] ?? 0);
        $expiresAt = (int) ($value['expires_at'] ?? 0);
        $evidence = $value['evidence'] ?? null;
        if ((int) ($value['schema'] ?? 0) !== 2
            || (int) ($value['release_id'] ?? 0) !== $releaseId
            || ! is_array($evidence)
            || count($evidence) > 32
            || array_filter($evidence, static fn (mixed $signature): bool => ! is_string($signature) || strlen($signature) !== 64) !== []
            || ! is_bool($value['overflow'] ?? null)
            || $observedAt <= 0
            || $observedAt > time()
            || $expiresAt <= time()
            || $expiresAt > $observedAt + 600
            || ! is_string($value['token'] ?? null)
            || $value['token'] === '') {
            return null;
        }

        return ['evidence' => array_values($evidence), 'overflow' => $value['overflow'], 'token' => $value['token']];
    }

    /** @return array{signatures: list<string>, files: list<string>, overflow: bool} */
    protected function freshHashedFileEvidence(int $releaseId): array
    {
        $rows = DB::table('release_files')
            ->select(['name', 'size', 'crc32'])
            ->where('releases_id', $releaseId)
            ->orderBy('name')
            ->limit(33)
            ->get();
        $bounded = $rows->take(32);
        $signatures = $bounded->map(fn (object $row): string => $this->freshHashedFileSignature(
            (string) $row->name,
            (string) $row->size,
            (string) $row->crc32,
        ))->values()->all();
        sort($signatures, SORT_STRING);

        return [
            'signatures' => array_values(array_unique($signatures)),
            'files' => $bounded->pluck('name')->map(static fn (mixed $name): string => (string) $name)->values()->all(),
            'overflow' => $rows->count() > 32,
        ];
    }

    private function freshHashedFileSignature(string $name, string $size, string $crc32): string
    {
        return hash('sha256', json_encode([$name, $size, $crc32], JSON_THROW_ON_ERROR));
    }

    protected function freshHashedEligibleRelease(int $releaseId): ?Release
    {
        return Release::query()
            ->where('id', $releaseId)
            ->where('categories_id', Category::OTHER_HASHED)
            ->where('isrenamed', self::IS_RENAMED_NONE)
            ->where('predb_id', 0)
            ->where('proc_files', self::PROC_FILES_DONE)
            ->where('adddate', '>=', now()->subSeconds(600))
            ->first();
    }

    /** @return array<string, int|string|null> */
    private function freshHashedRetryGuard(Release $release): array
    {
        return [
            'name' => (string) $release->name,
            'searchname' => (string) $release->searchname,
            'groups_id' => (int) $release->groups_id,
            'fromname' => $release->fromname === null ? null : (string) $release->fromname,
            'adddate' => (string) $release->adddate,
        ];
    }

    private function finishFreshHashedRetry(Release $release): bool
    {
        $guard = (array) $release->fresh_hashed_retry_guard;
        $query = DB::table('releases')
            ->where('id', $release->id)
            ->where('proc_files', self::PROC_FILES_DONE)
            ->where('categories_id', Category::OTHER_HASHED)
            ->where('isrenamed', self::IS_RENAMED_NONE)
            ->where('predb_id', 0)
            ->where('adddate', '>=', now()->subSeconds(600));
        foreach ($guard as $column => $value) {
            $query->where($column, $value);
        }

        return $query->update(['proc_files' => self::PROC_FILES_RETRY_DONE]) === 1;
    }

    private function acquireFreshHashedFileLease(int $releaseId, mixed &$lease): bool
    {
        try {
            $cache = $this->freshHashedCacheRepository();
            $lease = $this->freshHashedCacheLock($cache, $this->freshHashedFileLeaseKey($releaseId), 300);

            return $lease->get();
        } catch (Throwable $error) {
            Log::warning('Could not acquire fresh hashed file retry lease', [
                'release_id' => $releaseId,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    private function releaseFreshHashedFileLease(mixed $lease): void
    {
        try {
            $lease->release();
        } catch (Throwable $error) {
            Log::warning('Could not release fresh hashed file retry lease', ['error' => $error->getMessage()]);
        }
    }

    /** @return array{adddate: string, id: int}|null */
    private function freshHashedFileCursor(): ?array
    {
        try {
            $cursor = Cache::store((string) config('cache.default', 'redis'))->get($this->freshHashedFileCursorKey());
        } catch (Throwable) {
            return null;
        }
        if (! is_array($cursor) || ! is_string($cursor['adddate'] ?? null) || ! is_int($cursor['id'] ?? null)) {
            return null;
        }

        return ['adddate' => $cursor['adddate'], 'id' => $cursor['id']];
    }

    private function storeFreshHashedFileCursor(string $adddate, int $id): void
    {
        try {
            Cache::store((string) config('cache.default', 'redis'))->put($this->freshHashedFileCursorKey(), [
                'adddate' => $adddate,
                'id' => $id,
            ], 1200);
        } catch (Throwable) {
            // Losing the optional cursor may delay a retry, but must not affect release state.
        }
    }

    private function forgetFreshHashedFileMarker(int $releaseId, string $token): void
    {
        try {
            $cache = $this->freshHashedCacheRepository();
            $mutationLease = $this->freshHashedCacheLock($cache, $this->freshHashedFileMarkerMutationKey($releaseId), 10);
            if ($mutationLease->get()) {
                try {
                    $value = $cache->get($this->freshHashedFileKey($releaseId));
                    if (is_array($value) && hash_equals((string) ($value['token'] ?? ''), $token)) {
                        $cache->forget($this->freshHashedFileKey($releaseId));
                    }
                } finally {
                    $mutationLease->release();
                }
            }
        } catch (Throwable $error) {
            Log::warning('Could not clean fresh hashed file retry baseline', [
                'release_id' => $releaseId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function freshHashedCacheRepository(): CacheRepository
    {
        $cache = Cache::store((string) config('cache.default', 'redis'));
        if (! $cache instanceof CacheRepository) {
            throw new \RuntimeException('Fresh hashed retry requires an Illuminate cache repository.');
        }

        return $cache;
    }

    private function freshHashedCacheLock(CacheRepository $cache, string $key, int $seconds): Lock
    {
        $store = $cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new \RuntimeException('Fresh hashed retry requires a lock-capable cache store.');
        }
        $lock = $store->lock($key, $seconds);
        if (! $lock instanceof Lock) {
            throw new \RuntimeException('Fresh hashed retry cache store returned an invalid lock.');
        }

        return $lock;
    }

    private function freshHashedFileKey(int $releaseId): string
    {
        return 'nntmux:namefix:fresh-hashed-files:'.$releaseId;
    }

    private function freshHashedFileLeaseKey(int $releaseId): string
    {
        return $this->freshHashedFileKey($releaseId).':lease';
    }

    private function freshHashedFileMarkerMutationKey(int $releaseId): string
    {
        return $this->freshHashedFileKey($releaseId).':mutation';
    }

    private function freshHashedFileCursorKey(): string
    {
        return 'nntmux:namefix:fresh-hashed-files:cursor';
    }

    /**
     * Fix names from release subjects when no release_files row exists yet.
     */
    public function fixNamesWithSubjects(int $time, bool $echo, int $cats, bool $nameStatus, bool $show, int $limit = 0): void
    {
        $this->echoStartMessage($time, 'release subjects');
        $type = 'Filenames, ';
        $allowedCategories = $cats === 4 ? $this->movieCategoryIds : [];

        $query = sprintf(
            'SELECT rel.id AS releases_id, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
                COALESCE(NULLIF(rel.name, \'\'), rel.searchname) AS textstring
            FROM releases rel
            WHERE (
                rel.isrenamed = %d
                OR (rel.categories_id = %d AND rel.name REGEXP %s)
            )
            AND rel.predb_id = 0
            AND (
                rel.proc_files = %d
                OR COALESCE(NULLIF(rel.name, \'\'), rel.searchname) REGEXP %s
                OR COALESCE(NULLIF(rel.name, \'\'), rel.searchname) REGEXP %s
            )',
            self::IS_RENAMED_NONE,
            Category::OTHER_HASHED,
            escapeString($this->readableSoftwareSubjectRegex()),
            self::PROC_FILES_NONE,
            escapeString('(^|[^[:alnum:]])(19|20)[0-9]{2}([^[:alnum:]]|$)'),
            escapeString($this->readableSoftwareSubjectRegex())
        );

        $releases = $this->getReleases($time, $cats, $query, $limit);
        $total = $releases ? $releases->count() : 0;

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' release subjects to process.');

            foreach ($releases as $release) {
                /** @var Release $release */
                $this->updateService->reset();
                $this->updateService->incrementChecked();

                if ($allowedCategories !== []) {
                    $release->allowed_categories = $allowedCategories;
                }

                foreach ($this->subjectNameCandidates((string) $release->textstring) as $candidate) {
                    $candidateRelease = clone $release;
                    $candidateRelease->textstring = $candidate;

                    $fileResult = $this->fileExtractor->extractFromFile($candidate);
                    if ($fileResult !== null
                        && $this->isSafeSubjectFilenameResult($fileResult->method, $fileResult->newName)
                        && ($allowedCategories === [] || $this->isSafeMovieFilenameResult($fileResult->method, $fileResult->newName))) {
                        $this->updateService->updateRelease(
                            $candidateRelease,
                            $fileResult->newName,
                            'subjectCheck: '.$fileResult->method,
                            $echo,
                            $type,
                            $nameStatus,
                            $show
                        );
                    }

                    if (! $this->updateService->matched) {
                        $this->checkWithPatternMatchers($candidateRelease, $echo, $type, $nameStatus, $show, false);
                    }

                    if ($this->updateService->matched) {
                        break;
                    }
                }

                if ($nameStatus === true && ! $this->updateService->matched) {
                    $this->updateProcessingFlags($type, $release->releases_id);
                }

                $this->echoRenamed($show);
            }

            $this->echoFoundCount($echo, ' subjects');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    /**
     * @return list<string>
     */
    protected function subjectNameCandidates(string $subject): array
    {
        $candidates = [];

        if (preg_match('/\byEnc\b/i', $subject) && preg_match('/\b(?:19|20)\d{2}\b/', $subject)) {
            $candidates[] = $subject;
        }

        if (preg_match('/^\(([^()"]{4,180})\)\s*\[\d+(?:\/|\s+of\s+)\d+\]/u', $subject, $match)
            && preg_match('/'.$this->readableSoftwareSubjectRegex().'/iu', $match[1])) {
            $candidates[] = $match[1];
        }

        if (preg_match_all('/"([^"]+)"/', $subject, $matches)) {
            foreach ($matches[1] as $quoted) {
                $candidates[] = $quoted;
            }
        }

        if (preg_match('/(?:^|[-:>])\s*([^"<>]+?\b(?:19|20)\d{2}\b[^"<>]+?)(?:\s+yEnc|\s*$)/i', $subject, $match)) {
            $candidates[] = trim($match[1]);
        }

        $candidates[] = $subject;

        return array_values(array_unique(array_filter(array_map(
            static fn (string $candidate): string => trim($candidate),
            $candidates
        ))));
    }

    protected function readableSoftwareSubjectRegex(): string
    {
        return '(^|[^[:alnum:]])(Adobe|AcroRdr|AutoCAD|Autodesk|Corel|CorelDRAW|CyberLink|DVDFab|Foxit|Microsoft|Navicat|Office|Photoshop|PotPlayer|PowerDVD|SQLiteExpert|Topaz|Visual[._ -]?Studio|VMware|Windows|Setup|Installer|KeyGen|Crack|Activator|Patch|Portable|Multilingual|x64|x86)([^[:alnum:]]|$)';
    }

    protected function isSafeMovieFilenameResult(string $method, string $name): bool
    {
        return $method !== 'Folder name';
    }

    protected function isSafeSubjectFilenameResult(string $method, string $name): bool
    {
        if (preg_match('/\.(?:asb|cneg\d*|ene)$/i', $name)) {
            return false;
        }

        if ($method !== 'Folder name') {
            return true;
        }

        return (bool) preg_match('/\b(?:S\d{1,2}E\d{1,2}|(?:19|20)\d{2})\b/i', $name);
    }

    /**
     * Fix names using SRR files.
     */
    public function fixNamesWithSrr(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $this->echoStartMessage($time, 'SRR file names');
        $type = 'SRR, ';

        if ($cats === 3) {
            $query = sprintf(
                'SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
                    rf.releases_id AS fileid, rel.id AS releases_id
                FROM releases rel
                INNER JOIN release_files rf ON (rf.releases_id = rel.id)
                WHERE predb_id = 0
                AND (rf.name LIKE %s OR rf.name LIKE %s)',
                escapeString('%.srr'),
                escapeString('%.srs')
            );
            $cats = 2;
        } else {
            $query = sprintf(
                'SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
                    rf.releases_id AS fileid, rel.id AS releases_id
                FROM releases rel
                INNER JOIN release_files rf ON (rf.releases_id = rel.id)
                WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
                AND rel.predb_id = 0
                AND (rf.name LIKE %s OR rf.name LIKE %s)
                AND rel.proc_srr = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                escapeString('%.srr'),
                escapeString('%.srs'),
                self::PROC_SRR_NONE
            );
        }

        $releases = $this->getReleases($time, $cats, $query);
        $total = $releases->count();

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' srr file extensions to process.');

            foreach ($releases as $release) {
                $this->updateService->reset();
                $this->updateService->incrementChecked();

                $this->srrNameCheck($release, $echo, $type, $nameStatus, $show);
                $this->echoRenamed($show);
            }

            $this->echoFoundCount($echo, ' files');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    /**
     * Fix names using CRC32 hashes.
     */
    public function fixNamesWithCrc(int $time, bool $echo, int $cats, bool $nameStatus, bool $show, int $limit = 0): void
    {
        $this->echoStartMessage($time, 'CRC32');
        $type = 'CRC32, ';

        $preId = false;
        if ($cats === 3) {
            $query = sprintf(
                'SELECT rf.crc32 AS textstring, rf.name AS filename, rf.size AS filesize, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id, rel.size as relsize,
                    rf.releases_id AS fileid, rel.id AS releases_id
                FROM releases rel
                INNER JOIN release_files rf ON rf.releases_id = rel.id
                WHERE predb_id = 0
                AND rf.crc32 != \'\'
                AND rf.crc32 IS NOT NULL'
            );
            $cats = 2;
            $preId = true;
        } else {
            $query = sprintf(
                'SELECT rf.crc32 AS textstring, rf.name AS filename, rf.size AS filesize, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id, rel.size as relsize,
                    rf.releases_id AS fileid, rel.id AS releases_id
                FROM releases rel
                INNER JOIN release_files rf ON rf.releases_id = rel.id
                WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
                AND rel.predb_id = 0
                AND rel.proc_crc32 = %d
                AND rf.crc32 != \'\'
                AND rf.crc32 IS NOT NULL',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_CRC_NONE
            );
        }

        $releases = $this->getReleases($time, $cats, $query, $limit);
        $total = $releases->count();

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' CRC32\'s to process.');

            // Group by release
            $releasesCrc = [];
            foreach ($releases as $release) {
                /** @var Release $release */
                $releaseId = $release->releases_id;
                if (! isset($releasesCrc[$releaseId])) {
                    $releasesCrc[$releaseId] = [
                        'release' => $release,
                        'crcs' => [],
                    ];
                }
                if (! empty($release->textstring)) {
                    $priority = $this->filePrioritizer->getCrcPriority($release->filename ?? '');
                    $releasesCrc[$releaseId]['crcs'][$priority][] = $release->textstring;
                }
            }

            foreach ($releasesCrc as $releaseId => $data) {
                $this->updateService->reset();
                $this->updateService->incrementChecked();

                ksort($data['crcs']);
                foreach ($data['crcs'] as $crcs) {
                    foreach ($crcs as $crc) {
                        /** @var Release $release */
                        $release = clone $data['release'];
                        $release->textstring = $crc;

                        $this->crcCheck($release, $echo, $type, $nameStatus, $show, $preId);

                        if ($this->updateService->matched) {
                            break 2;
                        }
                    }
                }

                $this->echoRenamed($show);
            }

            $this->echoFoundCount($echo, ' crc32\'s');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    /**
     * Fix names using Media info unique IDs.
     */
    public function fixNamesWithMedia(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $type = 'UID, ';
        $this->echoStartMessage($time, 'mediainfo Unique_IDs');

        if ($cats === 3) {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
                    rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
                    ru.unique_id AS uid
                FROM releases rel
                LEFT JOIN media_infos ru ON ru.releases_id = rel.id
                WHERE ru.releases_id IS NOT NULL
                AND rel.predb_id = 0'
            );
            $cats = 2;
        } else {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
                    rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
                    ru.unique_id AS uid
                FROM releases rel
                LEFT JOIN media_infos ru ON ru.releases_id = rel.id
                WHERE ru.releases_id IS NOT NULL
                AND (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
                AND rel.predb_id = 0
                AND rel.proc_uid = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_UID_NONE
            );
        }

        $releases = $this->getReleases($time, $cats, $query);
        $total = $releases->count();

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' unique ids to process.');

            foreach ($releases as $rel) {
                $this->updateService->reset();
                $this->updateService->incrementChecked();
                $this->uidCheck($rel, $echo, $type, $nameStatus, $show);
                $this->echoRenamed($show);
            }

            $this->echoFoundCount($echo, ' UID\'s');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    /**
     * Fix names using PAR2 hash_16K.
     */
    public function fixNamesWithParHash(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $type = 'PAR2 hash, ';
        $this->echoStartMessage($time, 'PAR2 hash_16K');

        if ($cats === 3) {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
                    rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
                    IFNULL(ph.hash, \'\') AS hash
                FROM releases rel
                LEFT JOIN par_hashes ph ON ph.releases_id = rel.id
                WHERE ph.hash != \'\'
                AND rel.predb_id = 0'
            );
            $cats = 2;
        } else {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.size AS relsize, rel.groups_id, rel.fromname, rel.categories_id,
                    rel.name, rel.name AS textstring, rel.predb_id, rel.searchname,
                    IFNULL(ph.hash, \'\') AS hash
                FROM releases rel
                LEFT JOIN par_hashes ph ON ph.releases_id = rel.id
                WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
                AND rel.predb_id = 0
                AND ph.hash != \'\'
                AND rel.proc_hash16k = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_HASH16K_NONE
            );
        }

        $releases = $this->getReleases($time, $cats, $query);
        $total = $releases->count();

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' hash_16K to process.');

            foreach ($releases as $rel) {
                $this->updateService->reset();
                $this->updateService->incrementChecked();
                $this->hashCheck($rel, $echo, $type, $nameStatus, $show);
                $this->echoRenamed($show);
            }

            $this->echoFoundCount($echo, ' hashes');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    /**
     * Check with pattern matchers (TV, Movie, Game, App).
     */
    protected function checkWithPatternMatchers(object $release, bool $echo, string $type, bool $nameStatus, bool $show, bool $preId): void
    {
        // Check for PreDB match first
        $preDbMatch = $this->updateService->checkPreDbMatch($release, $release->textstring);
        if ($preDbMatch !== null) {
            $this->updateService->updateRelease(
                $release,
                $preDbMatch['title'],
                'preDB: Match',
                $echo,
                $type,
                $nameStatus,
                $show,
                $preDbMatch['id']
            );

            return;
        }

        if ($preId) {
            return;
        }

        // Try pattern checkers
        $result = $this->checkerService->check($release, $release->textstring);
        if ($result !== null) {
            $this->updateService->updateRelease(
                $release,
                $result->newName,
                $result->getFormattedMethod(),
                $echo,
                $type,
                $nameStatus,
                $show
            );
        }
    }

    /**
     * Check SRR file for release name.
     */
    protected function srrNameCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        $extractedName = null;

        if (preg_match('/^(.+)\.srr$/i', $release->textstring, $hit)) {
            $extractedName = $hit[1];
        } elseif (preg_match('/^(.+)\.srs$/i', $release->textstring, $hit)) {
            $extractedName = $hit[1];
        }

        if ($extractedName !== null) {
            if (preg_match('/[\\\\\/]([^\\\\\/]+)$/', $extractedName, $pathMatch)) {
                $extractedName = $pathMatch[1];
            }

            if (preg_match(ReleaseUpdateService::PREDB_REGEX, $extractedName)) {
                $this->updateService->updateRelease(
                    $release,
                    $extractedName,
                    'fileCheck: SRR extension',
                    $echo,
                    $type,
                    $nameStatus,
                    $show
                );

                return true;
            }
        }

        $this->updateService->updateSingleColumn('proc_srr', self::PROC_SRR_DONE, $release->releases_id);

        return false;
    }

    /**
     * Check CRC32 for matches.
     */
    protected function crcCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show, bool $preId): bool
    {
        if ($release->textstring === '') {
            $this->updateService->updateSingleColumn('proc_crc32', self::PROC_CRC_DONE, $release->releases_id);

            return false;
        }

        $predbMatch = DB::selectOne(
            'SELECT p.id AS predb_id, p.title
            FROM predb_crcs pc
            INNER JOIN predb p ON p.id = pc.predb_id
            WHERE pc.crchash = ?
            AND (pc.filesize = 0 OR pc.filesize = ?)
            ORDER BY pc.filesize DESC, p.predate DESC
            LIMIT 1',
            [strtoupper((string) $release->textstring), (int) ($release->filesize ?? $release->relsize ?? 0)]
        );

        if ($predbMatch !== null) {
            $this->updateService->updateRelease(
                $release,
                $predbMatch->title,
                'crcCheck: PreDB CRC',
                $echo,
                $type,
                $nameStatus,
                $show,
                (int) $predbMatch->predb_id
            );
            $this->updateService->updateSingleColumn('proc_crc32', self::PROC_CRC_DONE, $release->releases_id);

            return true;
        }

        $result = Release::fromQuery(
            sprintf(
                'SELECT rf.crc32, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id, rel.size as relsize, rel.predb_id as predb_id,
                    rf.releases_id AS fileid, rel.id AS releases_id
                FROM releases rel
                LEFT JOIN release_files rf ON rf.releases_id = rel.id
                WHERE rel.predb_id > 0
                AND rf.crc32 = %s',
                escapeString($release->textstring)
            )
        );

        foreach ($result as $res) {
            /** @var Release $res */
            $floor = round(($res->relsize - $release->relsize) / $res->relsize * 100, 1);
            if ($floor >= -5 && $floor <= 5) {
                $this->updateService->updateRelease(
                    $release,
                    $res->searchname,
                    'crcCheck: CRC32',
                    $echo,
                    $type,
                    $nameStatus,
                    $show,
                    $res->predb_id
                );

                return true;
            }
        }

        $this->updateService->updateSingleColumn('proc_crc32', self::PROC_CRC_DONE, $release->releases_id);

        return false;
    }

    /**
     * Check UID for matches.
     */
    protected function uidCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        if (empty($release->uid)) {
            $this->updateService->updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release->releases_id);

            return false;
        }

        $result = Release::fromQuery(sprintf(
            'SELECT r.id AS releases_id, r.size AS relsize, r.name AS textstring, r.searchname, r.fromname, r.predb_id
            FROM releases r
            LEFT JOIN media_infos ru ON ru.releases_id = r.id
            WHERE ru.releases_id IS NOT NULL
            AND ru.unique_id = %s
            AND ru.releases_id != %d
            AND (r.predb_id > 0 OR r.anidbid > 0 OR r.fromname = %s)',
            escapeString($release->uid),
            $release->releases_id,
            escapeString('nonscene@Ef.net (EF)')
        ));

        foreach ($result as $res) {
            /** @var Release $res */
            $floor = round(($res->relsize - $release->relsize) / $res->relsize * 100, 1);
            if ($floor >= -10 && $floor <= 10) {
                $this->updateService->updateRelease(
                    $release,
                    $res->searchname,
                    'uidCheck: Unique_ID',
                    $echo,
                    $type,
                    $nameStatus,
                    $show,
                    $res->predb_id
                );

                return true;
            }
        }

        $this->updateService->updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release->releases_id);

        return false;
    }

    /**
     * Check PAR2 hash for matches.
     */
    protected function hashCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        $result = Release::fromQuery(sprintf(
            'SELECT r.id AS releases_id, r.size AS relsize, r.name AS textstring, r.searchname, r.fromname, r.predb_id
            FROM releases r
            LEFT JOIN par_hashes ph ON ph.releases_id = r.id
            WHERE ph.hash = %s
            AND ph.releases_id != %d
            AND (r.predb_id > 0 OR r.anidbid > 0)',
            escapeString($release->hash),
            $release->releases_id
        ));

        foreach ($result as $res) {
            /** @var Release $res */
            $floor = round(($res->relsize - $release->relsize) / $res->relsize * 100, 1);
            if ($floor >= -5 && $floor <= 5) {
                $this->updateService->updateRelease(
                    $release,
                    $res->searchname,
                    'hashCheck: PAR2 hash_16K',
                    $echo,
                    $type,
                    $nameStatus,
                    $show,
                    $res->predb_id
                );

                return true;
            }
        }

        $this->updateService->updateSingleColumn('proc_hash16k', self::PROC_HASH16K_DONE, $release->releases_id);

        return false;
    }

    /**
     * Check PreDB for filename matches.
     */
    protected function preDbFileCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        $fileName = $this->fileNameCleaner->cleanForMatching($release->textstring);

        if (empty($fileName)) {
            return false;
        }

        $bestMatch = $this->findBestPredbMatch($fileName);
        if ($bestMatch !== null) {
            $this->updateService->updateRelease(
                $release,
                $bestMatch['title'] ?? '',
                'PreDb: Filename match',
                $echo,
                $type,
                $nameStatus,
                $show,
                $bestMatch['id'] ?? null
            );

            return true;
        }

        return false;
    }

    /**
     * Check PreDB for title matches.
     */
    protected function preDbTitleCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        $fileName = $this->fileNameCleaner->cleanForMatching($release->textstring);

        if (empty($fileName)) {
            return false;
        }

        $bestMatch = $this->findBestPredbMatch($fileName);
        if ($bestMatch !== null) {
            $this->updateService->updateRelease(
                $release,
                $bestMatch['title'] ?? '',
                'PreDb: Title match',
                $echo,
                $type,
                $nameStatus,
                $show,
                $bestMatch['id'] ?? null
            );

            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findBestPredbMatch(string $fileName): ?array
    {
        $results = Search::searchPredb($fileName);

        return $this->predbMatchSelector->selectBestMatch($fileName, $results);
    }

    /**
     * Get releases based on time and category parameters.
     *
     * @return Collection<int, mixed>
     */
    protected function getReleases(int $time, int $cats, string $query, int $limit = 0): \Illuminate\Database\Eloquent\Collection|bool // @phpstan-ignore class.notFound, return.phpDocType
    {
        $releases = false;
        $queryLimit = ($limit === 0) ? '' : ' LIMIT '.$limit;

        if ($time === 1 && $cats === 1) {
            $releases = Release::fromQuery($query.$this->timeother.$queryLimit);
        }
        if ($time === 1 && $cats === 2) {
            $releases = Release::fromQuery($query.$this->timeall.$queryLimit);
        }
        if ($time === 2 && $cats === 1) {
            $releases = Release::fromQuery($query.$this->fullother.$queryLimit);
        }
        if ($time === 2 && $cats === 2) {
            $releases = Release::fromQuery($query.$this->fullall.$queryLimit);
        }
        if ($time === 1 && $cats === 4) {
            $releases = Release::fromQuery($query.$this->timemovies.$queryLimit);
        }
        if ($time === 2 && $cats === 4) {
            $releases = Release::fromQuery($query.$this->fullmovies.$queryLimit);
        }
        if ($time === 1 && $cats === 5) {
            $releases = Release::fromQuery($query.$this->timehashed.$queryLimit);
        }
        if ($time === 2 && $cats === 5) {
            $releases = Release::fromQuery($query.$this->fullhashed.$queryLimit);
        }

        return $releases;
    }

    /**
     * Echo start message.
     */
    protected function echoStartMessage(int $time, string $type): void
    {
        cli()->info(
            sprintf(
                'Fixing search names %s using %s.',
                ($time === 1 ? 'in the past 6 hours' : 'since the beginning'),
                $type
            )
        );
    }

    /**
     * Echo found count.
     */
    protected function echoFoundCount(bool $echo, string $type): void
    {
        $stats = $this->updateService->getStats();
        if ($echo === true) {
            cli()->info(
                PHP_EOL.
                number_format($stats['fixed']).
                ' releases have had their names changed out of: '.
                number_format($stats['checked']).
                $type.'.'
            );
        } else {
            cli()->info(
                PHP_EOL.
                number_format($stats['fixed']).
                ' releases could have their names changed. '.
                number_format($stats['checked']).
                $type.' were checked.'
            );
        }
    }

    /**
     * Echo renamed progress.
     */
    protected function echoRenamed(bool $show): void
    {
        $stats = $this->updateService->getStats();

        // Show milestone message every 500 releases
        if ($stats['checked'] % 500 === 0 && $stats['checked'] > 0) {
            cli()->alternate(PHP_EOL.number_format($stats['checked']).' files processed.'.PHP_EOL);
        }

        // Show progress at meaningful intervals to reduce tmux pane clutter
        if ($show === true) {
            $percent = $this->_totalReleases > 0
                ? round(($stats['checked'] / $this->_totalReleases) * 100, 1)
                : 0;

            // Calculate progress interval - show update every 10% or at completion
            $progressInterval = max(1, (int) ($this->_totalReleases / 10));
            $isLastItem = $stats['checked'] === $this->_totalReleases;
            $isIntervalPoint = $stats['checked'] % $progressInterval === 0;

            // Only output at intervals or completion to keep tmux pane clean
            if ($isIntervalPoint || $isLastItem) {
                cli()->info(
                    'Renamed: '.number_format($stats['fixed']).
                    ' | Processed: '.number_format($stats['checked']).
                    '/'.number_format($this->_totalReleases).
                    ' ('.$percent.'%)'
                );
            }
        }
    }

    /**
     * Get the update service.
     */
    public function getUpdateService(): ReleaseUpdateService
    {
        return $this->updateService;
    }

    /**
     * Get the checker service.
     */
    public function getCheckerService(): NameCheckerService
    {
        return $this->checkerService;
    }

    /**
     * Fix names using PAR2 files (requires NNTP connection).
     */
    public function fixNamesWithPar2(int $time, bool $echo, int $cats, bool $nameStatus, bool $show, NNTPService $nntp, int $limit = 0): void
    {
        $this->echoStartMessage($time, 'par2 files');

        if ($cats === 3) {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.guid, rel.groups_id, rel.fromname,
                    rel.name, rel.searchname, rel.categories_id
                FROM releases rel
                WHERE rel.predb_id = 0'
            );
            $cats = 2;
        } else {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.guid, rel.groups_id, rel.fromname,
                    rel.name, rel.searchname, rel.categories_id
                FROM releases rel
                WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
                AND rel.predb_id = 0
                AND rel.proc_par2 = %d',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                self::PROC_PAR2_NONE
            );
        }

        $releases = $this->getReleases($time, $cats, $query, $limit);
        $total = $releases ? $releases->count() : 0;

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' releases to process.');
            $nzbContentsService = app(NzbContentsService::class);

            foreach ($releases as $release) {
                /** @var Release $release */
                $subjectTitle = $this->extractTitleFromPar2Subject((string) ($release->name ?? ''));
                if ($subjectTitle !== null) {
                    $this->updateService->reset();
                    $this->updateService->updateRelease($release, $subjectTitle, 'PAR2 subject title', $echo, 'PAR2, ', $nameStatus, $show);
                    if ($this->updateService->matched) {
                        $this->updateService->fixed++;
                    }
                    $this->updateService->incrementChecked();
                    $this->echoRenamed($show);

                    continue;
                }

                if ($nzbContentsService->checkPar2($release->guid, $release->releases_id, $release->groups_id, (int) $echo, (int) $nameStatus, (int) $show)) {
                    $this->updateService->fixed++;
                }

                $this->updateService->incrementChecked();
                $this->echoRenamed($show);
            }
            $this->echoFoundCount($echo, ' files');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    protected function extractTitleFromPar2Subject(string $subject): ?string
    {
        if ($subject === '' || stripos($subject, '.par2') === false) {
            return null;
        }

        if (preg_match('/^(.+?)\s+-\s*((?:19|20)\d{2})\s+-/u', $subject, $hit)) {
            $title = preg_replace('/[^\pL\pN]+/u', '.', trim($hit[1]));
            $title = trim((string) $title, '.');
            if ($title !== '') {
                return $title.'.'.$hit[2];
            }
        }

        if (preg_match('/"([^"]+?)\.(?:vol\d+\+\d+\.)?par2"/iu', $subject, $hit)) {
            $title = trim($hit[1]);
            if ($title !== '' && ! preg_match('/^[a-f0-9]{32,}$/i', $title)) {
                return $title;
            }
        }

        return null;
    }

    /**
     * Fix XXX release names using specific file names.
     */
    public function fixXXXNamesWithFiles(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $this->echoStartMessage($time, 'file names');
        $type = 'Filenames, ';

        if ($cats === 3) {
            $query = sprintf(
                'SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
                    rf.releases_id AS fileid, rel.id AS releases_id
                FROM releases rel
                INNER JOIN release_files rf ON rf.releases_id = rel.id
                WHERE predb_id = 0'
            );
            $cats = 2;
        } else {
            $query = sprintf(
                'SELECT rf.name AS textstring, rel.categories_id, rel.name, rel.searchname, rel.fromname, rel.groups_id,
                    rf.releases_id AS fileid, rel.id AS releases_id
                FROM releases rel
                INNER JOIN release_files rf ON rf.releases_id = rel.id
                WHERE (rel.isrenamed = %d OR rel.categories_id IN (%d, %d))
                AND rel.predb_id = 0
                AND rf.name LIKE %s',
                self::IS_RENAMED_NONE,
                Category::OTHER_MISC,
                Category::OTHER_HASHED,
                escapeString('%SDPORN%')
            );
        }

        $releases = $this->getReleases($time, $cats, $query);
        $total = $releases ? $releases->count() : 0;

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' xxx file names to process.');

            foreach ($releases as $release) {
                $this->updateService->reset();
                $this->xxxNameCheck($release, $echo, $type, $nameStatus, $show);
                $this->updateService->incrementChecked();
                $this->echoRenamed($show);
            }
            $this->echoFoundCount($echo, ' files');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    /**
     * Fix release names using mediainfo movie_name.
     */
    public function fixNamesWithMediaMovieName(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $type = 'Mediainfo, ';
        $this->echoStartMessage($time, 'Mediainfo movie_name');

        if ($cats === 3) {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.name, rel.name AS textstring, rel.predb_id, rel.searchname, rel.fromname, rel.groups_id, rel.categories_id, rel.id AS releases_id, rf.movie_name as movie_name
                FROM releases rel
                INNER JOIN media_infos rf ON rf.releases_id = rel.id
                WHERE rel.predb_id = 0'
            );
            $cats = 2;
        } else {
            $query = sprintf(
                'SELECT rel.id AS releases_id, rel.name, rel.name AS textstring, rel.predb_id, rel.searchname, rel.fromname, rel.groups_id, rel.categories_id, rel.id AS releases_id, rf.movie_name as movie_name, rf.file_name as file_name
                FROM releases rel
                INNER JOIN media_infos rf ON rf.releases_id = rel.id
                WHERE rel.isrenamed = %d
                AND rel.predb_id = 0',
                self::IS_RENAMED_NONE
            );
            if ($cats === 2) {
                $query .= PHP_EOL.'AND rel.categories_id IN ('.Category::OTHER_MISC.','.Category::OTHER_HASHED.')';
            }
        }

        $releases = $this->getReleases($time, $cats, $query);
        $total = $releases ? $releases->count() : 0;

        if ($total > 0) {
            $this->_totalReleases = $total;
            cli()->info(number_format($total).' mediainfo movie names to process.');

            foreach ($releases as $rel) {
                $this->updateService->incrementChecked();
                $this->updateService->reset();
                $this->mediaMovieNameCheck($rel, $echo, $type, $nameStatus, $show);
                $this->echoRenamed($show);
            }
            $this->echoFoundCount($echo, ' MediaInfo\'s');
        } else {
            cli()->info('Nothing to fix.');
        }
    }

    /**
     * Check for XXX release name.
     */
    protected function xxxNameCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        if (preg_match('/^.+?SDPORN/i', $release->textstring, $hit)) {
            $this->updateService->updateRelease($release, $hit[0], 'fileCheck: XXX SDPORN', $echo, $type, $nameStatus, $show);

            return true;
        }

        $this->updateService->updateSingleColumn('proc_files', self::PROC_FILES_DONE, $release->releases_id);

        return false;
    }

    /**
     * Check mediainfo movie_name for release name.
     */
    protected function mediaMovieNameCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        $newName = '';

        if (! empty($release->movie_name)) {
            if (preg_match(ReleaseUpdateService::PREDB_REGEX, $release->movie_name, $hit)) {
                $newName = $hit[1];
            } elseif (preg_match('/(.+),(\sRMZ\.cr)?$/i', $release->movie_name, $hit)) {
                $newName = $hit[1];
            } else {
                $newName = $release->movie_name;
            }
        }

        if ($newName !== '') {
            $this->updateService->updateRelease($release, $newName, 'MediaInfo: Movie Name', $echo, $type, $nameStatus, $show, $release->predb_id ?? 0);

            return true;
        }

        $this->updateService->updateSingleColumn('proc_uid', self::PROC_UID_DONE, $release->releases_id);

        return false;
    }

    /**
     * Check the array using regex for a clean name.
     *
     * @throws \Exception
     */
    public function checkName(object $release, bool $echo, string $type, bool $nameStatus, bool $show, bool $preId = false): bool
    {
        // Check PreDB first
        $preDbMatch = $this->updateService->checkPreDbMatch($release, $release->textstring);
        if ($preDbMatch !== null) {
            $this->updateService->updateRelease($release, $preDbMatch['title'], 'preDB: Match', $echo, $type, $nameStatus, $show, $preDbMatch['id']);

            return true;
        }

        if ($preId) {
            return $this->updateService->matched;
        }

        // Route to appropriate checker based on type
        switch ($type) {
            case 'PAR2, ':
                $result = $this->fileExtractor->extractFromFile($release->textstring);
                if ($result !== null) {
                    $this->updateService->updateRelease($release, $result->newName, 'fileCheck: '.$result->method, $echo, $type, $nameStatus, $show);
                }
                break;

            case 'NFO, ':
                $result = $this->nfoExtractor->extractFromNfo($release->textstring);
                if ($result !== null) {
                    $this->updateService->updateRelease($release, $result->newName, 'nfoCheck: '.$result->method, $echo, $type, $nameStatus, $show);
                }
                break;

            case 'Filenames, ':
                // Try direct file name extraction first (handles NZBSPLIT wrappers)
                if (! $this->updateService->matched) {
                    $result = $this->fileExtractor->extractFromFile($release->textstring);
                    if ($result !== null) {
                        $this->updateService->updateRelease($release, $result->newName, 'fileCheck: '.$result->method, $echo, $type, $nameStatus, $show);
                    }
                }
                // Try PreDB file check
                if (! $this->updateService->matched) {
                    $this->preDbFileCheck($release, $echo, $type, $nameStatus, $show);
                }
                // Try PreDB title check
                if (! $this->updateService->matched) {
                    $this->preDbTitleCheck($release, $echo, $type, $nameStatus, $show);
                }
                // Try file name extraction
                if (! $this->updateService->matched) {
                    $result = $this->fileExtractor->extractFromFile($release->textstring);
                    if ($result !== null) {
                        $this->updateService->updateRelease($release, $result->newName, 'fileCheck: '.$result->method, $echo, $type, $nameStatus, $show);
                    }
                }
                break;

            default:
                // Use pattern checker service
                $result = $this->checkerService->check($release, $release->textstring);
                if ($result !== null) {
                    $this->updateService->updateRelease($release, $result->newName, $result->getFormattedMethod(), $echo, $type, $nameStatus, $show);
                }
        }

        // Update processing flags if not matched
        if ($nameStatus === true && ! $this->updateService->matched) {
            $this->updateProcessingFlags($type, $release->releases_id);
        }

        return $this->updateService->matched;
    }

    /**
     * Update processing flags based on type.
     */
    protected function updateProcessingFlags(string $type, int $releaseId): void
    {
        switch ($type) {
            case 'NFO, ':
                $this->updateService->updateSingleColumn('proc_nfo', self::PROC_NFO_DONE, $releaseId);
                break;
            case 'Filenames, ':
                $this->updateService->updateSingleColumn('proc_files', self::PROC_FILES_DONE, $releaseId);
                break;
            case 'PAR2, ':
                $this->updateService->updateSingleColumn('proc_par2', self::PROC_PAR2_DONE, $releaseId);
                break;
            case 'PAR2 hash, ':
                $this->updateService->updateSingleColumn('proc_hash16k', self::PROC_HASH16K_DONE, $releaseId);
                break;
            case 'SRR, ':
                $this->updateService->updateSingleColumn('proc_srr', self::PROC_SRR_DONE, $releaseId);
                break;
            case 'UID, ':
            case 'Mediainfo, ':
                $this->updateService->updateSingleColumn('proc_uid', self::PROC_UID_DONE, $releaseId);
                break;
            case 'CRC32, ':
                $this->updateService->updateSingleColumn('proc_crc32', self::PROC_CRC_DONE, $releaseId);
                break;
        }
    }

    /**
     * Match a release filename to a PreDB filename or title.
     *
     * @throws \Exception
     */
    public function matchPreDbFiles(object $release, bool $echo, bool $nameStatus, bool $show): int
    {
        $matching = 0;

        $files = explode('||', $release->filename ?? '');
        $prioritizedFiles = $this->filePrioritizer->prioritizeForPreDb($files);

        foreach ($prioritizedFiles as $fileName) {
            $cleanedFileName = $this->fileNameCleaner->cleanForMatching($fileName);

            if (empty($cleanedFileName) || strlen($cleanedFileName) < 8) {
                continue;
            }

            $results = Search::searchPredb($cleanedFileName);

            if (! empty($results)) {
                $bestMatch = $this->predbMatchSelector->selectBestMatch($cleanedFileName, $results);

                if ($bestMatch !== null) {
                    if ($bestMatch['title'] !== $release->searchname) {
                        $this->updateService->updateRelease($release, $bestMatch['title'], 'file matched source: '.($bestMatch['source'] ?? ''), $echo, 'PreDB file match, ', $nameStatus, $show);
                    } else {
                        $this->updateService->updateSingleColumn('predb_id', $bestMatch['id'] ?? 0, $release->releases_id);
                    }
                    $matching++;

                    return $matching;
                }
            }
        }

        return $matching;
    }

    /**
     * Check if a release name looks like a season pack.
     * Season packs have S01/S02 etc. without an episode (E01) suffix.
     * Uses atomic group so "S02E07" matches "S02" then fails the (?!E\d+) lookahead
     * instead of backtracking to "S0" and incorrectly matching.
     */
    public function isSeasonPack(string $name): bool
    {
        return (bool) preg_match('/S(?>\d{1,2})(?!E\d+)/i', $name);
    }

    /**
     * Reset the update service state.
     */
    public function reset(): void
    {
        $this->updateService->reset();
    }

    /**
     * Retrieves releases and their file names to attempt PreDB matches.
     *
     * @param  array<string, mixed>  $args
     *
     * @throws \Exception
     */
    public function getPreFileNames(array $args = []): void
    {
        $show = isset($args[2]) && $args[2] === 'show';

        if (isset($args[1]) && is_numeric($args[1])) {
            $limit = 'LIMIT '.$args[1];
            $orderBy = 'ORDER BY r.id DESC';
        } else {
            $orderBy = 'ORDER BY r.id ASC';
            $limit = 'LIMIT 1000000';
        }

        cli()->info(PHP_EOL.'Match PreFiles '.($args[1] ?? 'all').' Started at '.now());
        cli()->info('Matching predb filename to cleaned release_files.name.');

        $counter = $counted = 0;
        $timeStart = now();

        $query = Release::fromQuery(
            sprintf(
                "SELECT r.id AS releases_id, r.name, r.searchname,
                    r.fromname, r.groups_id, r.categories_id,
                    GROUP_CONCAT(rf.name ORDER BY LENGTH(rf.name) DESC SEPARATOR '||') AS filename
                FROM releases r
                INNER JOIN release_files rf ON r.id = rf.releases_id
                WHERE rf.name IS NOT NULL
                AND r.predb_id = 0
                AND r.categories_id IN (%s)
                AND r.isrenamed = 0
                GROUP BY r.id
                %s %s",
                implode(',', Category::OTHERS_GROUP),
                $orderBy,
                $limit
            )
        );

        if ($query->isNotEmpty()) {
            $total = $query->count();

            if ($total > 0) {
                cli()->info(PHP_EOL.number_format($total).' releases to process.');

                foreach ($query as $row) {
                    $success = $this->matchPreDbFiles($row, true, true, $show);
                    if ($success === 1) {
                        $counted++;
                    }
                    if ($show === false) {
                        cli()->info('Renamed Releases: ['.number_format($counted).'] '.cli()->percentString(++$counter, $total));
                    }
                }
                cli()->info(PHP_EOL.'Renamed '.number_format($counted).' releases in '.now()->diffInSeconds($timeStart, true).' seconds.');
            } else {
                cli()->info('Nothing to do.');
            }
        }
    }
}
