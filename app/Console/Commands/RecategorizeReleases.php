<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use Illuminate\Console\Command;

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
    public function handle(): void
    {
        $countQuery = Release::query();
        $hasSelector = false;
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
                exit();
            }

            $hasSelector = true;
        }

        if ($this->option('group')) {
            $countQuery->where('groups_id', $this->option('group'));
            $hasSelector = true;
        }

        if ($this->option('groups')) {
            $countQuery->whereIn('groups_id', explode(',', $this->option('groups')));
            $hasSelector = true;
        }

        if ($this->option('category')) {
            $countQuery->where('categories_id', $this->option('category'));
            $hasSelector = true;
        }

        if ($this->option('categories')) {
            $countQuery->whereIn('categories_id', explode(',', $this->option('categories')));
            $hasSelector = true;
        }

        if (! $hasSelector && $this->option('test')) {
            $countQuery->where('iscategorized', 0);
            $hasSelector = true;
        }

        if (! $hasSelector) {
            $this->error('You must specify at least one option. See: --help');
            exit();
        }

        $count = $countQuery->count();

        $cat = new CategorizationService;
        $results = $countQuery->select(['id', 'name', 'searchname', 'fromname', 'groups_id', 'categories_id'])->get();
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

        $subject = trim((string) ($release->name ?? ''));
        if ($subject === '' || strcasecmp($subject, (string) $release->searchname) === 0) {
            return $searchResult;
        }

        $subjectResult = $categorization->determineCategory($release->groups_id, $subject, $poster, true);
        if (! $this->isContentCategory($subjectResult)) {
            if ((int) $release->categories_id === Category::OTHER_HASHED
                && (int) ($searchResult['categories_id'] ?? Category::OTHER_MISC) === Category::OTHER_HASHED
                && $this->isWeakGroupFallback($subjectResult)) {
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
}
