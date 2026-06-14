<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecategorizeReleases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:recategorize-releases
    {--misc : Re-categorize all releases in misc categories}
    {--all : Re-categorize all releases}
    {--test : Test only, no updates}
    {--group= : Re-categorize all releases in a group}
    {--groups= : Re-categorize all releases in a list of groups}
    {--id= : Re-categorize a single release by id}
    {--ids= : Re-categorize all releases in a list of release ids}
    {--limit= : Re-categorize at most this many selected releases}
    {--category= : Re-categorize all releases in a category}
    {--categories= : Re-categorize all releases in a list of categories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-categorize releases based on their name and group.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $countQuery = Release::query();
        $hasSelector = false;
        $limit = $this->parsePositiveIntegerOption('limit');
        if ($limit === false) {
            return self::FAILURE;
        }

        if ($this->option('all') && ($this->hasScopedSelector() || $limit !== null)) {
            $this->error('Cannot combine --all with scoped selectors or --limit.');

            return self::FAILURE;
        }

        if ($this->option('misc')) {
            $countQuery->whereIn('categories_id', Category::OTHERS_GROUP);
            $hasSelector = true;
        }

        if ($this->option('all')) {
            if ($this->confirm('This will reset categorization on all releases and re-categorize them all from scratch. Are you sure? (y/n)', false)) {
                if (! $this->option('test')) {
                    Release::query()->where('iscategorized', 1)->update([
                        'iscategorized' => 0,
                    ]);
                }
                $countQuery->where('iscategorized', 0);
            } else {
                $this->info('Reset script stopped.');

                return self::FAILURE;
            }

            $hasSelector = true;
        }

        if ($this->option('group')) {
            $countQuery->where('groups_id', $this->option('group'));
            $hasSelector = true;
        }

        if ($this->option('groups')) {
            $countQuery->whereIn('groups_id', $this->parseIntegerList((string) $this->option('groups')));
            $hasSelector = true;
        }

        if ($this->option('id')) {
            $countQuery->where('id', (int) $this->option('id'));
            $hasSelector = true;
        }

        if ($this->option('ids')) {
            $countQuery->whereIn('id', $this->parseIntegerList((string) $this->option('ids')));
            $hasSelector = true;
        }

        if ($this->option('category')) {
            $countQuery->where('categories_id', $this->option('category'));
            $hasSelector = true;
        }

        if ($this->option('categories')) {
            $countQuery->whereIn('categories_id', $this->parseIntegerList((string) $this->option('categories')));
            $hasSelector = true;
        }

        if (! $hasSelector && $this->option('test')) {
            $countQuery->where('iscategorized', 0);
            $hasSelector = true;
        }

        if (! $hasSelector) {
            $this->error('You must specify at least one option. See: --help');

            return self::FAILURE;
        }

        $count = $countQuery->count();
        if ($limit !== null) {
            $count = min($count, $limit);
        }

        $cat = new CategorizationService;
        $resultsQuery = $countQuery
            ->select(['id', 'name', 'searchname', 'fromname', 'groups_id', 'categories_id'])
            ->orderBy('id');
        if ($limit !== null) {
            $resultsQuery->limit($limit);
        }

        $results = $resultsQuery->get();
        $bar = $this->output->createProgressBar($count);
        $bar->start();
        foreach ($results as $result) {
            $bar->advance();
            $catId = $this->determineBestCategory($cat, $result);
            if ((int) $result->categories_id !== (int) $catId['categories_id']) {
                if ($this->option('test')) {
                    $this->info('Would have changed '.$result->searchname.' from '.$result->categories_id.' to '.$catId['categories_id']);
                } else {
                    Release::query()->where('id', $result->id)->update([
                        'iscategorized' => 1,
                        'videos_id' => 0,
                        'tv_episodes_id' => 0,
                        'imdbid' => null,
                        'musicinfo_id' => null,
                        'consoleinfo_id' => null,
                        'gamesinfo_id' => 0,
                        'bookinfo_id' => 0,
                        'anidbid' => null,
                        'categories_id' => $catId['categories_id'],
                    ]);

                    Search::updateRelease((int) $result->id);

                    /** @var Category|null $newCatName */
                    $newCatName = Category::query()->where('id', $catId['categories_id'])->first();

                    $this->line('');
                    $this->output->writeln('<fg=yellow>ID       :</> '.$result->id);
                    $this->output->writeln('<fg=green>Release  :</> '.$result->searchname);
                    $this->output->writeln('<fg=cyan>Group    :</> '.$result->group->name);
                    $oldCategoryTitle = $result->category?->parent ? ($result->category->parent->title.' -> '.$result->category->title) : ($result->category?->title ?? 'N/A'); // @phpstan-ignore nullsafe.neverNull

                    $newCategoryTitle = $newCatName?->parent ? ($newCatName->parent->title.' -> '.$newCatName->title) : ($newCatName?->title ?? 'N/A'); // @phpstan-ignore nullsafe.neverNull
                    $this->output->writeln('<fg=white>Category :</> '.$oldCategoryTitle.' <fg=yellow>→</> <fg=magenta>'.$newCategoryTitle.'</>');
                    $this->line('');
                }
            }
        }
        $bar->finish();

        return self::SUCCESS;
    }

    /**
     * @param  object{id:int,name?:string,searchname:string,fromname?:string|null,groups_id:int|string,categories_id:int|string}  $release
     * @return array<string, mixed>
     */
    private function determineBestCategory(CategorizationService $categorization, object $release): array
    {
        $poster = ! empty($release->fromname) ? (string) $release->fromname : '';
        $searchResult = $categorization->determineCategory($release->groups_id, (string) $release->searchname, $poster, true);

        if (! in_array((int) $release->categories_id, Category::OTHERS_GROUP, true)) {
            return $searchResult;
        }

        if ((int) $release->categories_id === Category::OTHER_HASHED) {
            if ($this->isWeakGroupFallback($searchResult)) {
                $searchResult = $this->hashedResult();
            }

            if ((int) ($searchResult['categories_id'] ?? Category::OTHER_MISC) === Category::OTHER_HASHED) {
                $fileEvidenceResult = $this->determineCategoryFromExplicitFileEvidence((int) $release->id);
                if ($fileEvidenceResult !== null) {
                    return $fileEvidenceResult;
                }
            }
        }

        $subject = trim((string) ($release->name ?? ''));
        if ($subject === '' || strcasecmp($subject, (string) $release->searchname) === 0) {
            return $searchResult;
        }

        $subjectResult = $categorization->determineCategory($release->groups_id, $subject, $poster, true);
        if (! $this->isContentCategory($subjectResult)) {
            if ((int) $release->categories_id === Category::OTHER_HASHED
                && (int) ($searchResult['categories_id'] ?? Category::OTHER_MISC) === Category::OTHER_HASHED
                && $this->isWeakGroupFallback($subjectResult)
                && $this->hasReadableSubjectEvidence($subject)) {
                return $subjectResult;
            }

            return $searchResult;
        }

        if (! $this->isContentCategory($searchResult) || $this->isWeakGroupFallback($searchResult)) {
            return $subjectResult;
        }

        return $searchResult;
    }

    /**
     * @param  array<string, mixed>  $categoryResult
     */
    private function isContentCategory(array $categoryResult): bool
    {
        return ! in_array((int) ($categoryResult['categories_id'] ?? Category::OTHER_MISC), Category::OTHERS_GROUP, true);
    }

    /**
     * @param  array<string, mixed>  $categoryResult
     */
    private function isWeakGroupFallback(array $categoryResult): bool
    {
        $matchedBy = (string) ($categoryResult['debug']['matched_by'] ?? '');

        return str_starts_with($matchedBy, 'group_name_')
            || $matchedBy === 'classic_movie_title';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function determineCategoryFromExplicitFileEvidence(int $releaseId): ?array
    {
        $evidence = DB::table('release_files')
            ->join('releases', 'releases.id', '=', 'release_files.releases_id')
            ->join('usenet_groups', 'usenet_groups.id', '=', 'releases.groups_id')
            ->where('release_files.releases_id', $releaseId)
            ->select(['release_files.name as file_name', 'usenet_groups.name as group_name'])
            ->orderBy('release_files.name')
            ->limit(50)
            ->get();

        foreach ($evidence as $row) {
            $fileName = (string) $row->file_name;
            $groupName = (string) $row->group_name;

            if ($this->isTrustedLosslessGroup($groupName) && $this->hasExplicitLosslessFileEvidence($fileName)) {
                return $this->categoryResult(Category::MUSIC_LOSSLESS, 'release_file_lossless_evidence');
            }

            if ($this->isTrustedMovieGroup($groupName) && $this->hasExplicitMovieFileEvidence($fileName)) {
                return $this->categoryResult(Category::MOVIE_OTHER, 'release_file_movie_evidence');
            }
        }

        return null;
    }

    private function isTrustedLosslessGroup(string $groupName): bool
    {
        return preg_match('/(?:alt\.binaries|a\.b)\..*lossless/i', $groupName) === 1;
    }

    private function isTrustedMovieGroup(string $groupName): bool
    {
        return preg_match('/(?:alt\.binaries|a\.b)\..*?(movies?|movie[.-]?classic|dvd[.-]classic|dvd[.-]movies?|bluray|blu[.-]?ray|uhd|vintage[.-]?film)/i', $groupName) === 1;
    }

    private function hasExplicitLosslessFileEvidence(string $fileName): bool
    {
        return preg_match('/\.(?:flac|ape|wav|aiff|dsf|dff|m4a|tak)(?:$|["\s])/i', $fileName) === 1;
    }

    private function hasExplicitMovieFileEvidence(string $fileName): bool
    {
        return preg_match('/(?:^|[\/\\\\])VIDEO_TS(?:[\/\\\\]|$)/i', $fileName) === 1
            || preg_match('/\.(?:mkv|mp4|avi|mpg|mpeg|vob|iso)(?:$|["\s])/i', $fileName) === 1;
    }

    private function hasReadableSubjectEvidence(string $subject): bool
    {
        return preg_match('/[A-Za-z][a-z]{3,}/', $subject) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function hashedResult(): array
    {
        return $this->categoryResult(Category::OTHER_HASHED, 'hashed_locked');
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryResult(int $categoryId, string $matchedBy): array
    {
        return [
            'categories_id' => $categoryId,
            'debug' => [
                'matched_by' => $matchedBy,
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function parseIntegerList(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): int => (int) trim($item),
            explode(',', $value)
        )));
    }

    private function parsePositiveIntegerOption(string $option): int|false|null
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! preg_match('/^[1-9]\d*$/', $value)) {
            $this->error('--'.$option.' must be a positive integer.');

            return false;
        }

        return (int) $value;
    }

    private function hasScopedSelector(): bool
    {
        foreach (['misc', 'group', 'groups', 'id', 'ids', 'category', 'categories'] as $option) {
            if ($this->option($option)) {
                return true;
            }
        }

        return false;
    }
}
