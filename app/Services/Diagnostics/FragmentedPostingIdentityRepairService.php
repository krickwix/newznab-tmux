<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reunite one posting whose files each landed in their own collection.
 *
 * The third fragmentation shape, and the one no existing pass can reach.
 * Subjects look like this -- a real file counter, and a random name per file
 * that shares no stem with its siblings:
 *
 *   [005/243] - "1X1bCO5fFm82B1XnNgR" yEnc
 *   [006/243] - "2P9bCrQhUHNbZfsJpL9" yEnc
 *   [001/208] - "0302PsZJyGT2d8Q9x"   yEnc
 *
 * CollectionHandler::collectionIdentity() keys on `cleanedName . $totalFiles`.
 * The cleaner cannot strip a name that is pure entropy, so every file mints its
 * own key and one posting becomes up to N collections of one binary each. Stage
 * 1 needs COUNT(DISTINCT filenumber) >= totalfiles, which a one-binary fragment
 * of a 243-file posting can never reach, so the rows sit at filecheck=0 until
 * retention purges articles that were fully downloaded. Measured live: 21,846
 * stalled collections, 19,815 of them holding one binary.
 *
 * WHY THE OTHER TWO PASSES CANNOT DO THIS, rather than a fourth way of doing
 * the same thing:
 *
 *  - SplitPostingIdentityRepairService groups by cleaned posting STEM. These
 *    names have no stem, so each collection becomes its own one-file cohort and
 *    is refused as short. Its FILE_COUNTER_PATTERN guard refuses them a second
 *    time, correctly -- verified across 590 refusals in movies/hdtv/
 *    cinemageddon, 579 really were short (one held 3 of 113 files).
 *  - BraceTokenIdentityRepairService handles the `{file} {token}` shape. On
 *    a.b.movies it correctly collapses 903 collections into one Lioness.S03
 *    cohort of 8 real files and refuses it as par2_only.
 *  - SplitCollectionReconciler needs a payload ANCHOR with par2 companions.
 *    Every file here is a random name, so there is no anchor to find.
 *
 * THE COHORT KEY IS (groups_id, fromname, totalfiles) AND THAT IS NOT ENOUGH ON
 * ITS OWN. Two postings by one poster, same file count, same window would merge
 * into a chimera. So the key only proposes; what accepts is a bijection:
 *
 *   binaries == totalfiles
 *   AND COUNT(DISTINCT filenumber) == totalfiles
 *   AND MIN(filenumber) == 1 AND MAX(filenumber) == totalfiles
 *
 * Every file slot filled exactly once, no gaps, no duplicates. That is
 * self-validating in a way the stem heuristics are not: a partial archive
 * cannot pass it, because a missing file leaves a hole and a chimera leaves a
 * duplicate. It is also why this pass never writes `totalfiles` -- the declared
 * count is already correct and already proven, which is exactly the premise
 * SplitPostingIdentityRepairService cannot establish for its own targets and
 * why that one needs `unsurvivableShape()` before it dares write a count.
 *
 * Verified against a live cohort before this class existed: 102 collections,
 * 243 binaries, 243 distinct filenumbers spanning 1..243, declared 243.
 *
 * Left alone on purpose:
 *
 *  - `collectionhash` is UNIQUE and the survivor keeps its own. There is no
 *    cohort key worth minting: siblings hash off random names, so a future
 *    article of this posting would never land on it anyway, and not touching it
 *    removes a whole class of collision. Re-runs converge because a merged
 *    cohort is one collection and `cols > 1` stops selecting it.
 *  - `filenumber` is untouched. The bijection means the union is ALREADY dense
 *    1..N, so unlike the other two passes there is nothing to renumber and no
 *    survivor to park above MAX(filenumber) first -- moving the binaries cannot
 *    collide on UNIQUE (collections_id, filenumber).
 *  - `partcheck` is untouched. Parts follow their binary; rehoming a binary
 *    between collections does not change which parts it holds.
 *
 * Defaults are dry-run. Applying deletes collections.
 */
final class FragmentedPostingIdentityRepairService
{
    /**
     * Production `settings.minfilestoformrelease`. Only a default: the caller
     * passes the live value, because a group override changes it.
     */
    private const int DEFAULT_MIN_FILES = 1;

    /** Cohorts considered per run when the caller does not say. */
    private const int DEFAULT_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function repair(
        string $group,
        int $limit = self::DEFAULT_LIMIT,
        ?string $before = null,
        ?int $minFiles = null,
        bool $update = false,
    ): array {
        $groupRow = $this->resolveGroup($group);
        $groupId = (int) $groupRow->id;
        $effectiveMinFiles = $minFiles ?? $this->effectiveMinFiles($groupId);
        $limit = max(1, $limit);

        $cohorts = $this->findCohorts($groupId, $limit, $before);

        $plans = [];
        foreach ($cohorts as $cohort) {
            $plans[] = $this->plan($groupId, $cohort, $effectiveMinFiles);
        }

        $applied = ['collections' => 0, 'binaries_moved' => 0, 'cohorts' => 0, 'files' => 0];
        if ($update) {
            $applied = $this->applyRegrouping($plans, $groupId);
        }

        $merged = array_values(array_filter($plans, static fn (array $p): bool => $p['refusal'] === null));
        $skipped = array_values(array_filter($plans, static fn (array $p): bool => $p['refusal'] !== null));

        return [
            'group' => ['id' => $groupId, 'name' => $groupRow->name],
            'updated' => $update,
            'min_files' => $effectiveMinFiles,
            'cohorts_found' => \count($plans),
            'cohorts_mergeable' => \count($merged),
            'cohorts_skipped' => \count($skipped),
            'cohort_limit_reached' => \count($plans) >= $limit,
            'collections_in_cohorts' => array_sum(array_map(
                static fn (array $p): int => \count($p['collections']),
                $plans
            )),
            'files_in_cohorts' => array_sum(array_map(
                static fn (array $p): int => $p['declared_files'],
                $plans
            )),
            'collections_removed' => $applied['collections'],
            'binaries_moved' => $applied['binaries_moved'],
            'cohorts_merged' => $applied['cohorts'],
            'files_retained' => $applied['files'],
            'cohorts' => array_map($this->publicPlan(...), $merged),
            'skipped' => array_map($this->publicPlan(...), $skipped),
        ];
    }

    /**
     * Candidate cohorts, already filtered to the bijection in SQL so a run
     * cannot even see a shape it would have to refuse for incompleteness.
     *
     * `$before` exists because a posting still arriving will satisfy the
     * bijection only by accident; point this at postings that have stopped.
     *
     * @return list<object>
     */
    private function findCohorts(int $groupId, int $limit, ?string $before): array
    {
        $bindings = [$groupId];
        $beforeSql = '';
        if ($before !== null && $before !== '') {
            $beforeSql = ' AND c.dateadded < ?';
            $bindings[] = $before;
        }
        $bindings[] = $limit;

        return DB::select(
            'SELECT c.fromname,
                    c.totalfiles AS declared_files,
                    COUNT(DISTINCT c.id) AS collections,
                    COUNT(b.id) AS binaries,
                    COUNT(DISTINCT b.filenumber) AS distinct_filenumbers,
                    MIN(b.filenumber) AS min_filenumber,
                    MAX(b.filenumber) AS max_filenumber,
                    MIN(c.dateadded) AS oldest_dateadded,
                    MIN(c.date) AS oldest_date
             FROM collections c
             JOIN binaries b ON b.collections_id = c.id
             WHERE c.filecheck = 0
               AND c.totalfiles > 0
               AND c.groups_id = ?'.$beforeSql.'
             GROUP BY c.fromname, c.totalfiles
             HAVING collections > 1
                AND binaries = declared_files
                AND distinct_filenumbers = declared_files
                AND min_filenumber = 1
                AND max_filenumber = declared_files
             ORDER BY collections DESC
             LIMIT ?',
            $bindings
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(int $groupId, object $cohort, int $minFiles): array
    {
        $declaredFiles = (int) $cohort->declared_files;

        $collections = DB::table('collections')
            ->where('groups_id', $groupId)
            ->where('fromname', $cohort->fromname)
            ->where('totalfiles', $declaredFiles)
            ->where('filecheck', 0)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $binaries = DB::table('binaries')
            ->whereIn('collections_id', $collections)
            ->orderBy('filenumber')
            ->get(['id', 'name', 'filenumber']);

        $files = $binaries->pluck('name')->map(static fn ($n): string => (string) $n)->all();

        $plan = [
            'fromname' => (string) $cohort->fromname,
            'declared_files' => $declaredFiles,
            'collections' => $collections,
            'binary_ids' => $binaries->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            'files' => $files,
            'oldest_dateadded' => $cohort->oldest_dateadded,
            'oldest_date' => $cohort->oldest_date,
            'refusal' => null,
            'refusal_values' => [],
        ];

        // Re-derive the bijection from the rows actually loaded rather than
        // trusting the aggregate: the SELECT and this read are not one
        // statement, and a cohort that changed underneath us must be refused,
        // not merged on a stale premise.
        $filenumbers = $binaries->pluck('filenumber')->map(static fn ($n): int => (int) $n)->all();
        $distinct = array_values(array_unique($filenumbers));
        if (\count($binaries) !== $declaredFiles
            || \count($distinct) !== $declaredFiles
            || $filenumbers === []
            || min($filenumbers) !== 1
            || max($filenumbers) !== $declaredFiles
        ) {
            $plan['refusal'] = 'bijection_broken';
            $plan['refusal_values'] = [
                'declared_files' => $declaredFiles,
                'binaries' => \count($binaries),
                'distinct_filenumbers' => \count($distinct),
                'min_filenumber' => $filenumbers === [] ? null : min($filenumbers),
                'max_filenumber' => $filenumbers === [] ? null : max($filenumbers),
            ];

            return $plan;
        }

        if (\count($collections) < 2) {
            $plan['refusal'] = 'not_fragmented';

            return $plan;
        }

        // Both refusals below mirror the delete predicates the release pipeline
        // will apply the moment this collection advances. Merging into a shape
        // stage 6 then deletes would cascade the parts away -- the failure that
        // cost 512 collections on 2026-08-03.
        if ($declaredFiles < $minFiles) {
            $plan['refusal'] = 'below_min_files';
            $plan['refusal_values'] = ['declared_files' => $declaredFiles, 'min_files' => $minFiles];

            return $plan;
        }

        if ($this->allPar2($files)) {
            $plan['refusal'] = 'par2_only';

            return $plan;
        }

        return $plan;
    }

    /**
     * Move every cohort member's binaries onto one survivor and drop the husks.
     *
     * No renumbering and no parking: the bijection guarantees the union already
     * holds each filenumber exactly once, so UNIQUE (collections_id,
     * filenumber) cannot be violated by the move. The survivor is the lowest
     * id, which is also the row whose timestamps the cohort keeps.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return array{collections: int, binaries_moved: int, cohorts: int, files: int}
     */
    private function applyRegrouping(array $plans, int $groupId): array
    {
        $totals = ['collections' => 0, 'binaries_moved' => 0, 'cohorts' => 0, 'files' => 0];

        foreach ($plans as $plan) {
            if ($plan['refusal'] !== null) {
                continue;
            }

            /** @var list<int> $collectionIds */
            $collectionIds = $plan['collections'];
            $survivor = min($collectionIds);
            $absorbed = array_values(array_filter(
                $collectionIds,
                static fn (int $id): bool => $id !== $survivor
            ));

            DB::transaction(function () use ($plan, $groupId, $survivor, $absorbed, &$totals): void {
                if ($absorbed !== []) {
                    $totals['binaries_moved'] += DB::table('binaries')
                        ->whereIn('collections_id', $absorbed)
                        ->update(['collections_id' => $survivor]);
                }

                DB::table('collections')->where('id', $survivor)->update([
                    'groups_id' => $groupId,
                    // totalfiles is deliberately NOT written: the bijection has
                    // already proven the declared count correct.
                    'filecheck' => 0,
                    // Keep the oldest member's timestamps -- the collection
                    // really is that old, and back-dating dateadded clears the
                    // delaytime gate instead of restarting the wait.
                    'date' => $plan['oldest_date'],
                    'dateadded' => $plan['oldest_dateadded'],
                ]);

                if ($absorbed !== []) {
                    // Safe only because the binaries were rehomed first: the
                    // collections FK cascades to binaries and on to parts.
                    DB::table('collections')->whereIn('id', $absorbed)->delete();
                    $totals['collections'] += \count($absorbed);
                }

                $totals['files'] += $plan['declared_files'];
                $totals['cohorts']++;
            });
        }

        return $totals;
    }

    /**
     * @param  list<string>  $files
     */
    private function allPar2(array $files): bool
    {
        if ($files === []) {
            return true;
        }

        foreach ($files as $file) {
            if (! $this->isPar2($file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deliberately UNANCHORED, and transcribed from the predicate it has to
     * mirror -- ReleaseProcessingService's $par2Only filter, which is
     * `b.name REGEXP '\.(vol[0-9]+\+[0-9]+\.par2|par2)'` with no `$`.
     *
     * An end-anchored version is the natural thing to write and it is strictly
     * weaker than the gate it is protecting against, which is the worst
     * possible direction for a guard to be wrong in. Caught in production on
     * the first apply against alt.binaries.movies: brace-token binaries are
     * named `{Lioness.S03.vol063+64.par2} {sraBl51wo8je} yEnc`, so the `.par2`
     * sits mid-string and `$` missed all 616 of them. Two par2-only cohorts
     * merged that BraceTokenIdentityRepairService had already refused by name.
     */
    private function isPar2(string $file): bool
    {
        return preg_match('/\.(?:vol\d+\+\d+\.par2|par2)/i', trim($file)) === 1;
    }

    /**
     * Mirror ReleaseProcessingService's live resolution: a group override wins
     * over the site setting, and applying the wrong floor is what turns a
     * merge into a delete.
     */
    private function effectiveMinFiles(int $groupId): int
    {
        $override = DB::table('usenet_groups')->where('id', $groupId)->value('minfilestoformrelease');
        if ($override !== null && (int) $override > 0) {
            return (int) $override;
        }

        $setting = DB::table('settings')->where('name', 'minfilestoformrelease')->value('value');
        if ($setting !== null && (int) $setting > 0) {
            return (int) $setting;
        }

        return self::DEFAULT_MIN_FILES;
    }

    private function resolveGroup(string $group): object
    {
        $query = DB::table('usenet_groups')->select(['id', 'name']);
        $row = ctype_digit($group)
            ? $query->where('id', (int) $group)->first()
            : $query->where('name', $group)->first();

        if ($row === null) {
            throw new InvalidArgumentException(sprintf('Unknown group "%s".', $group));
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function publicPlan(array $plan): array
    {
        return [
            'fromname' => $plan['fromname'],
            'declared_files' => $plan['declared_files'],
            'collections' => \count($plan['collections']),
            'collection_ids' => \array_slice($plan['collections'], 0, 10),
            'sample_files' => \array_slice($plan['files'], 0, 5),
            'oldest_dateadded' => $plan['oldest_dateadded'],
            'refusal' => $plan['refusal'],
            'refusal_values' => $plan['refusal_values'],
        ];
    }
}
