<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Predb;
use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\Categorization\CategorizationService;
use App\Services\ReleaseCleaningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Service for updating releases with new names.
 *
 * Handles the actual database updates and category re-determination
 * when a release is renamed.
 */
class ReleaseUpdateService
{
    /**
     * PreDB regex pattern for scene release names.
     */
    public const PREDB_REGEX = '/([\w.\'()\[\]-]+(?:[\s._-]+[\w.\'()\[\]-]+)+[-.][\w]+)/ui';

    // Constants for name fixing status
    public const PROC_NFO_NONE = 0;

    public const PROC_NFO_DONE = 1;

    public const PROC_FILES_NONE = 0;

    public const PROC_FILES_DONE = 1;

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

    protected CategorizationService $category;

    protected FileNameCleaner $fileNameCleaner;

    protected bool $echoOutput;

    /**
     * The release ID we are trying to rename.
     */
    protected int $relid = 0;

    /**
     * Has the current release found a new name?
     */
    public bool $matched = false;

    /**
     * Was the check completed?
     */
    public bool $done = false;

    /**
     * How many releases have got a new name?
     */
    public int $fixed = 0;

    /**
     * How many releases were checked.
     */
    public int $checked = 0;

    public function __construct(
        ?CategorizationService $category = null,
        ?FileNameCleaner $fileNameCleaner = null
    ) {
        $this->category = $category ?? new CategorizationService;
        $this->fileNameCleaner = $fileNameCleaner ?? new FileNameCleaner;
        $this->echoOutput = (bool) config('nntmux.echocli');
    }

    /**
     * Update the release with the new information.
     *
     * @param  object|array<string, mixed>  $release  The release to update
     * @param  string  $name  The new name
     * @param  string  $method  The method that found the name
     * @param  bool  $echo  Whether to actually update the database
     * @param  string  $type  The type string for logging
     * @param  bool  $nameStatus  Whether to update status columns
     * @param  bool  $show  Whether to show output
     * @param  int|null  $preId  PreDB ID if matched
     *
     * @throws \Exception
     */
    public function updateRelease(
        object|array $release,
        string $name,
        string $method,
        bool $echo,
        string $type,
        bool $nameStatus,
        bool $show,
        ?int $preId = 0
    ): void {
        $preId = $preId ?? 0;
        if (is_array($release)) {
            $release = (object) $release;
        }

        // If $release does not have a releases_id, we should add it.
        if (! isset($release->releases_id)) {
            $release->releases_id = $release->id;
        }

        if ($this->relid !== $release->releases_id) {
            $cleanedName = (new ReleaseCleaningService)->fixerCleaner($name);
            // Normalize and sanity-check candidate for non-trusted sources
            $normalizedName = $this->fileNameCleaner->normalizeCandidateTitle($cleanedName);
            $newName = $this->fileNameCleaner->formatSearchName($cleanedName, $normalizedName);

            // Determine if the source is trusted enough to bypass plausibility checks
            $trustedSource = $this->isTrustedSource($type, $method, $preId);
            $preferredSubjectTitle = false;

            if (! $trustedSource) {
                $subjectTitle = $this->preferClassicMovieSubjectTitle($release, $newName);
                if ($subjectTitle !== $newName) {
                    $newName = $subjectTitle;
                    $normalizedName = $this->fileNameCleaner->normalizeCandidateTitle($newName);
                    $preferredSubjectTitle = true;
                }
            }

            if (! $trustedSource && ! $preferredSubjectTitle && ! $this->fileNameCleaner->isPlausibleReleaseTitle($normalizedName)) {
                // Skip low-quality rename candidates for untrusted sources
                $this->done = true;

                return;
            }

            if ($type === 'PAR2, ') {
                $candidateBeforeSubjectPreference = $newName;
                $newName = $this->preferPar2SubjectTitle($release, $name, $newName);
                if (
                    stripos($method, 'Folder name') !== false
                    && $this->sameNormalizedCandidate($newName, $candidateBeforeSubjectPreference)
                ) {
                    $this->done = true;

                    return;
                }
            }

            if ($type === 'PAR2, ') {
                $newName = ucwords($newName);
                if (preg_match('/(.+?)\.[a-z0-9]{2,3}(PAR2)?$/i', $name, $hit)) {
                    $newName = $hit[1];
                }
            }

            // Split on path separator backslash to strip any path
            $newName = explode('\\', $newName);
            $newName = preg_replace(['/^[=_.:\s-]+/', '/[=_.:\s-]+$/'], '', $newName[0]);

            $newTitle = substr($newName, 0, 299);

            $allowedCategories = (array) ($release->allowed_categories ?? []);
            $determinedCategory = $this->category->determineCategory(
                $release->groups_id,
                $newTitle,
                ! empty($release->fromname) ? $release->fromname : ''
            );

            $categoryChanged = (int) $release->categories_id === Category::OTHER_HASHED
                && (int) $release->categories_id !== (int) $determinedCategory['categories_id'];

            if (strtolower($newTitle) !== strtolower($release->searchname) || $categoryChanged) {
                $this->matched = true;
                $this->relid = (int) $release->releases_id;

                if ($allowedCategories !== [] && ! in_array((int) $determinedCategory['categories_id'], $allowedCategories, true)) {
                    $this->matched = false;
                    $this->relid = 0;
                    $this->done = true;

                    return;
                }

                $this->fixed++;

                if ($this->echoOutput && $show && $determinedCategory !== null) {
                    $this->echoReleaseInfo($release, $newTitle, $determinedCategory, $type, $method);
                }

                if ($echo === true) {
                    $updated = $this->performDatabaseUpdate($release, $newTitle, $type, $nameStatus, $preId, (int) $determinedCategory['categories_id']);
                    if (! $updated && $type === 'Fresh hashed files, ') {
                        $this->matched = false;
                        $this->relid = 0;
                        $this->fixed--;
                    }
                }
            }
        }
        $this->done = true;
    }

    protected function preferClassicMovieSubjectTitle(object $release, string $candidate): string
    {
        $groupName = UsenetGroup::getNameByID($release->groups_id);
        if (! $this->isClassicMovieGroup($groupName)) {
            return $candidate;
        }

        if (! $this->looksLikeCompactFilenameStem($candidate)) {
            return $candidate;
        }

        $subject = (string) ($release->name ?? $release->searchname ?? '');
        $title = $this->extractReadableSubjectMovieTitle($subject);

        return $title === null ? $candidate : $title;
    }

    protected function isClassicMovieGroup(string $groupName): bool
    {
        return preg_match('/(?:alt\.binaries|a\.b)\..*?(?:vintage[.-]?film|classic[.-]?film|dvd[.-]?classic|movies?[.-]?classic|movie[.-]?classic)/i', $groupName) === 1;
    }

    protected function looksLikeCompactFilenameStem(string $candidate): bool
    {
        $normalized = preg_replace('/[^\pL\pN]+/u', '', $candidate) ?? $candidate;

        return strlen($normalized) >= 8
            && preg_match('/\s/u', $candidate) !== 1
            && preg_match('/[a-z]/u', $normalized) !== 1
            && preg_match('/[A-Z]{4,}/u', $normalized) === 1;
    }

    protected function extractReadableSubjectMovieTitle(string $subject): ?string
    {
        if ($subject === '' || preg_match('/\b(?:19|20)\d{2}\b/', $subject) !== 1) {
            return null;
        }

        $withoutQuotedFiles = preg_replace('/["\'][^"\']+["\']/', ' ', $subject) ?? $subject;
        $withoutQuotedFiles = preg_replace('/\[[0-9]+\/[0-9]+\]/', ' ', $withoutQuotedFiles) ?? $withoutQuotedFiles;
        $withoutQuotedFiles = preg_replace('/\byEnc\b/i', ' ', $withoutQuotedFiles) ?? $withoutQuotedFiles;
        $withoutQuotedFiles = preg_replace('/\b1\s*:\s*1\b/i', ' ', $withoutQuotedFiles) ?? $withoutQuotedFiles;
        $withoutQuotedFiles = preg_replace('/\s+-\s*$/', '', trim($withoutQuotedFiles)) ?? $withoutQuotedFiles;
        $withoutQuotedFiles = preg_replace('/\s{2,}/', ' ', $withoutQuotedFiles) ?? $withoutQuotedFiles;
        $title = trim($withoutQuotedFiles, " \t\n\r\0\x0B-_.");

        if (preg_match('/^(.+?\b(?:19|20)\d{2}\b\)?)/u', $title, $hit) === 1) {
            $title = $hit[1];
        }

        $title = preg_replace('/\(((?:19|20)\d{2})\)/', ' $1', $title) ?? $title;
        $title = preg_replace('/\s{2,}/', ' ', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B-_.");

        if (preg_match_all('/[\pL]{3,}/u', $title) < 1 || preg_match('/\b(?:19|20)\d{2}\b/', $title) !== 1) {
            return null;
        }

        return $title;
    }

    /**
     * PAR2 file lists often expose archive members like Title.part01.rar while
     * the original subject carries the cleaner PAR2 title and sometimes a year.
     */
    protected function preferPar2SubjectTitle(object $release, string $rawName, string $candidate): string
    {
        if (! preg_match('/(?:^|[._ -])(?:part\d+|r\d{2,3})$/i', $candidate)
            && ! preg_match('/(?:^|[._ -])(?:part\d+|r\d{2,3})(?:\.rar)?$/i', $rawName)) {
            return $candidate;
        }

        $subject = (string) ($release->searchname ?? $release->name ?? '');
        if ($subject === '') {
            return $candidate;
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
            if ($title !== '') {
                return $title;
            }
        }

        return $candidate;
    }

    protected function sameNormalizedCandidate(string $first, string $second): bool
    {
        $normalize = static fn (string $value): string => strtolower((string) preg_replace('/[^\pL\pN]+/u', '', $value));

        return $normalize($first) === $normalize($second);
    }

    /**
     * Check if the source is trusted enough to bypass plausibility checks.
     */
    protected function isTrustedSource(string $type, string $method, int $preId): bool
    {
        return
            (! empty($preId) && $preId > 0) ||
            str_starts_with($type, 'PreDB') ||
            str_starts_with($type, 'PreDb') ||
            $type === 'UID, ' ||
            $type === 'PAR2 hash, ' ||
            $type === 'CRC32, ' ||
            $type === 'SRR, ' ||
            stripos($method, 'Title Match') !== false ||
            stripos($method, 'App:') !== false ||
            stripos($method, 'Classic movie support filename') !== false ||
            stripos($method, 'file matched source') !== false ||
            stripos($method, 'PreDb') !== false ||
            stripos($method, 'preDB') !== false;
    }

    /**
     * Echo release information to CLI.
     *
     * @param  array<string, mixed>  $determinedCategory
     */
    public function echoReleaseInfo(
        object $release,
        string $newName,
        array $determinedCategory,
        string $type,
        string $method
    ): void {
        $groupName = UsenetGroup::getNameByID($release->groups_id);
        $oldCatName = Category::getNameByID($release->categories_id);
        $newCatName = Category::getNameByID($determinedCategory['categories_id']);

        if ($type === 'PAR2, ') {
            echo PHP_EOL;
        }

        echo PHP_EOL;

        cli()->primary('Release Information:');

        echo '  '.cli()->headerOver('New name:   ').cli()->primary(substr($newName, 0, 100)).PHP_EOL;
        echo '  '.cli()->headerOver('Old name:   ').cli()->primary(substr((string) $release->searchname, 0, 100)).PHP_EOL;
        echo '  '.cli()->headerOver('Use name:   ').cli()->primary(substr((string) $release->name, 0, 100)).PHP_EOL;
        echo PHP_EOL;

        echo '  '.cli()->headerOver('New cat:    ').cli()->primary($newCatName).PHP_EOL;
        echo '  '.cli()->headerOver('Old cat:    ').cli()->primary($oldCatName).PHP_EOL;
        echo '  '.cli()->headerOver('Group:      ').cli()->primary($groupName).PHP_EOL;
        echo PHP_EOL;

        echo '  '.cli()->headerOver('Method:     ').cli()->primary($type.$method).PHP_EOL;
        echo '  '.cli()->headerOver('Release ID: ').cli()->primary((string) $release->releases_id).PHP_EOL;

        if (! empty($release->filename)) {
            echo '  '.cli()->headerOver('Filename:   ').cli()->primary(substr((string) $release->filename, 0, 100)).PHP_EOL;
        }

        if ($type !== 'PAR2, ') {
            echo PHP_EOL;
        }
    }

    /**
     * Perform the actual database update.
     */
    protected function performDatabaseUpdate(
        object $release,
        string $newTitle,
        string $type,
        bool $nameStatus,
        int $preId,
        int $categoryId
    ): bool {
        $updated = DB::transaction(function () use ($release, $newTitle, $type, $nameStatus, $preId, $categoryId): bool {
            $freshHashedRetry = $type === 'Fresh hashed files, ';
            $guardedRetry = $freshHashedRetry && isset($release->fresh_hashed_retry_guard);
            if ($freshHashedRetry && ! $guardedRetry) {
                return false;
            }
            if ($nameStatus === true) {
                $status = $this->getStatusColumnsForType($type);

                $updateColumns = [
                    'videos_id' => 0,
                    'tv_episodes_id' => 0,
                    'imdbid' => null,
                    'musicinfo_id' => '',
                    'consoleinfo_id' => '',
                    'bookinfo_id' => '',
                    'anidbid' => '',
                    'predb_id' => $preId,
                    'searchname' => $newTitle,
                    'categories_id' => $categoryId,
                ];

                if (! empty($status)) {
                    foreach ($status as $key => $stat) {
                        $updateColumns = Arr::add($updateColumns, $key, $stat);
                    }
                }

                $query = Release::query()->where('id', $release->releases_id);
                if ($guardedRetry) {
                    $this->applyFreshHashedRetryGuard($query, (array) $release->fresh_hashed_retry_guard);
                }
                $affected = $query->update($updateColumns);
            } else {
                $query = Release::query()->where('id', $release->releases_id);
                if ($guardedRetry) {
                    $this->applyFreshHashedRetryGuard($query, (array) $release->fresh_hashed_retry_guard);
                }
                $affected = $query->update([
                    'videos_id' => 0,
                    'tv_episodes_id' => 0,
                    'imdbid' => null,
                    'musicinfo_id' => null,
                    'consoleinfo_id' => null,
                    'bookinfo_id' => null,
                    'anidbid' => null,
                    'predb_id' => $preId,
                    'searchname' => $newTitle,
                    'categories_id' => $categoryId,
                    'iscategorized' => 1,
                ]);
            }

            if ($guardedRetry && $affected !== 1) {
                return false;
            }

            event(new ReleaseNameFixed(
                (int) $release->releases_id,
                (string) $release->searchname,
                $newTitle,
                (int) $release->categories_id,
                $release->groups_id,
                (string) ($release->fromname ?? '')
            ));

            return true;
        });

        if ($updated) {
            Search::updateRelease($release->releases_id);
        }

        return $updated;
    }

    /** @param Builder<Release> $query @param array<string, int|string|null> $guard */
    private function applyFreshHashedRetryGuard(Builder $query, array $guard): void
    {
        $query
            ->where('proc_files', NameFixingService::PROC_FILES_DONE)
            ->where('categories_id', Category::OTHER_HASHED)
            ->where('isrenamed', NameFixingService::IS_RENAMED_NONE)
            ->where('predb_id', 0)
            ->where('adddate', '>=', now()->subSeconds(600));

        foreach (['name', 'searchname', 'groups_id', 'fromname', 'adddate'] as $column) {
            if (array_key_exists($column, $guard)) {
                $query->where($column, $guard[$column]);
            }
        }
    }

    /**
     * Get the status columns to update for a given type.
     *
     * @return array<string, mixed>
     */
    protected function getStatusColumnsForType(string $type): array
    {
        return match ($type) {
            'NFO, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_nfo' => 1],
            'PAR2, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_par2' => 1],
            'Filenames, ', 'file matched source: ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_files' => 1],
            'Fresh hashed files, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_files' => 2],
            'PreDB FT Exact, ' => ['isrenamed' => 1, 'iscategorized' => 1],
            'sorter, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_sorter' => 1],
            'UID, ', 'Mediainfo, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_uid' => 1],
            'PAR2 hash, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_hash16k' => 1],
            'SRR, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_srr' => 1],
            'CRC32, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_crc32' => 1],
            default => [],
        };
    }

    /**
     * Update a single column in releases.
     */
    public function updateSingleColumn(string $column, int $status, int $id): void
    {
        if ($column !== '' && $id !== 0) {
            Release::query()->where('id', $id)->update([$column => $status]);
            Search::updateRelease($id);
        }
    }

    /**
     * Check if a release matches a PreDB entry.
     *
     * @return array<string, mixed>
     */
    public function checkPreDbMatch(object $release, string $textstring): ?array
    {
        if (preg_match_all(self::PREDB_REGEX, $textstring, $hits) && ! preg_match('/Source\s:/i', $textstring)) {
            foreach ($hits as $hit) {
                foreach ($hit as $val) {
                    $title = Predb::query()->where('title', trim($val))->select(['title', 'id'])->first();
                    if ($title !== null) {
                        return ['title' => $title['title'], 'id' => $title['id']];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Reset status variables for new processing.
     */
    public function reset(): void
    {
        $this->done = $this->matched = false;
    }

    /**
     * Increment the checked counter.
     */
    public function incrementChecked(): void
    {
        $this->checked++;
    }

    /**
     * Get the current statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'fixed' => $this->fixed,
            'checked' => $this->checked,
            'matched' => $this->matched,
            'done' => $this->done,
        ];
    }
}
