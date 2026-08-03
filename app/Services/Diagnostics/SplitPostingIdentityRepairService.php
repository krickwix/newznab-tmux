<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reunite the sibling collections of one posting that ingest split by part count.
 *
 * These posts are NOT obfuscated. They carry an ordinary subject naming a real
 * file, and one posting ends up spread across several collections:
 *
 *   ... Helljahve " yEnc "The Paper Boy (1994) Dvdrip by Helljahve.avi.001" yEnc
 *   ... Helljahve " yEnc "The Paper Boy (1994) Dvdrip by Helljahve.avi.017" yEnc
 *   ... Helljahve " yEnc "The Paper Boy (1994) Dvdrip by Helljahve.avi.vol000+045.par2" yEnc
 *
 * They stall for a reason distinct from every other repair in this namespace.
 * CollectionHandler::collectionIdentity() keys a collection on
 * `cleanedName . $totalFiles`, and on these posts the subject's only counter is
 * a PART counter, so $totalFiles differs between files of one posting: .avi.001
 * declares 242, .avi.017 declares 226, the par2 volumes declare 124/63/238. The
 * suffix that exists to separate genuinely different postings instead splits ONE
 * posting apart. Verified in-pod against the live cleaner: the Paper Boy posting
 * minted 7 distinct ingest keys across 7 collections.
 *
 * The consequence is a permanent stall rather than a wrong release. Stage 1
 * requires COUNT(DISTINCT filenumber) >= CEIL(totalfiles * completionpercent
 * / 100), and totalfiles holds a part count, so the biggest Paper Boy fragment
 * reads `16 >= CEIL(242 * 0.94)` = `16 >= 228`. Never true at any completeness,
 * so the rows sit at filecheck=0 until retention purges articles that were
 * fully downloaded.
 *
 * WHY THIS IS NOT "recompute totalfiles". That was the obvious fix and it
 * destroys data. Setting totalfiles = COUNT(binaries) per collection makes
 * every fragment advance past stage 1, and then the two delete predicates take
 * them: the five single-binary fragments are under minfilestoformrelease, and a
 * par2 volume's lone binary is 100% par2 for the $par2Only filter. Measured on
 * the Paper Boy cohort: 7 collections advance, 6 are deleted, 656 parts cascade
 * away. That is the same failure that cost 512 production collections and
 * ~541 MB on 2026-08-03.
 *
 * So the count is written ONLY behind unsurvivableShape(), which refuses any
 * cohort whose merged shape either delete predicate would take. That guard, not
 * the act of merging, is what makes writing a file count safe -- a cohort that
 * is already one collection still gets its count and its dense filenumbers,
 * because a fragment like Paper Boy's 16-file collection needs exactly that to
 * clear stage 1.
 *
 * AND ONLY WHEN totalfiles IS ACTUALLY UNTRUSTWORTHY. A subject carrying a real
 * `[n/m]` file counter is not this bug: its totalfiles is correct, so a
 * shortfall means articles were never downloaded and the stall is right.
 * FILE_COUNTER_PATTERN refuses those outright. Skipping that check does not
 * strand data, it publishes a partial archive as complete -- the one failure
 * mode here that waiting cannot undo. It was caught by a production dry-run on
 * a named target, after every fixture had missed the shape; over the live
 * residue it disqualifies 3,608 of 7,822 candidate collections.
 *
 * Target state matches BraceTokenIdentityRepairService: ONE collection per
 * posting, one binary per real file, dense filenumbers 1..N.
 *
 * Defaults are dry-run. Applying deletes collections.
 */
final class SplitPostingIdentityRepairService
{
    /**
     * Production `settings.minfilestoformrelease`. Only a default: the caller
     * passes the live value, because a group override changes it.
     */
    public const int DEFAULT_MIN_FILES = 2;

    /**
     * The brace-token style, BraceTokenIdentityRepairService's territory.
     * Verbatim from that class's SUBJECT_PREFILTER.
     */
    private const string BRACE_TOKEN_PREFILTER = '{%}%{%}%';

    /**
     * Any long hex run, which is what the remaining obfuscated styles use as a
     * filename -- broader than Par2SetIdentityRepairService's own
     * PAR2_MEMBER_PATTERN on purpose, since a subject only has to look
     * obfuscated for this service to decline it.
     *
     * Applied in PHP rather than SQL: `REGEXP` is MariaDB-only and pushing it
     * into the query makes the whole service unrunnable against the sqlite
     * fixtures, which is where the merge is actually proven safe.
     */
    private const string HASH_STYLE_PATTERN = '/[0-9a-f]{32,}/i';

    /**
     * A GENUINE file counter, e.g. `(Nativ) [58/93] - "Nativ.part57.rar" yEnc`.
     *
     * Its presence is disqualifying, and this guard is the difference between a
     * repair and a corruption. When a subject carries `[n/m]`, `totalfiles`
     * holds a real file count, so a collection with fewer binaries than that is
     * GENUINELY INCOMPLETE -- articles were never downloaded -- and the stall is
     * correct behaviour. Rewriting totalfiles to COUNT(binaries) there would
     * publish a 36-of-93 archive as complete: unextractable, and worse than the
     * stall because a release cannot be undone by waiting.
     *
     * Caught by a production dry-run, not by a test: every fixture seeded a
     * subject WITHOUT a file counter, so the shape was unrepresented. Measured
     * over the live residue, 3,608 of 7,822 candidate collections carry one and
     * ALL of them have `totalfiles > COUNT(binaries)`.
     */
    private const string FILE_COUNTER_PATTERN = '/\[\d+\s*\/\s*\d+\]/';

    /**
     * @param  string|null  $posting  Repair only the posting with this exact
     *                                filename stem. A staged production drain
     *                                needs to name its target: $limit admits
     *                                cohorts in binary-id order, which says
     *                                nothing about which posting is safe next.
     * @param  int|null  $minFiles  Override for the effective
     *                              `minfilestoformrelease`; read from the live
     *                              settings and group override when null.
     * @return array<string,mixed>
     */
    public function repair(
        int|string $group,
        int $limit,
        ?string $before,
        bool $update,
        ?int $minFiles = null,
        ?string $posting = null
    ): array {
        if ($limit < 1) {
            throw new InvalidArgumentException('--limit must be at least 1.');
        }

        if ($minFiles !== null && $minFiles < 1) {
            throw new InvalidArgumentException('--min-files must be at least 1.');
        }

        $startedAt = microtime(true);
        $groupRow = $this->resolveGroup($group);
        $groupId = (int) $groupRow->id;
        $groupName = (string) $groupRow->name;
        $minFiles ??= $this->effectiveMinFiles($groupId);

        [$cohorts, $scanned, $truncated] = $this->candidateCohorts($groupId, $limit, $before, $posting);

        $plans = [];
        foreach ($cohorts as $cohort) {
            $plans[] = $this->planCohort($cohort, $minFiles);
        }

        $skipped = array_values(array_filter(
            array_map(static function (array $plan): ?array {
                if ($plan['refusal'] === null) {
                    return null;
                }

                return [
                    'name' => $plan['posting'],
                    'reason' => $plan['refusal'],
                    'file' => $plan['refusal_file'],
                    'values' => $plan['refusal_values'],
                    'files' => $plan['files'],
                    'collections' => $plan['collections'],
                ];
            }, $plans)
        ));

        $applied = ['collections' => 0, 'binaries' => 0, 'parts' => 0, 'cohorts' => 0, 'files' => 0];
        if ($update) {
            $applied = $this->applyRegrouping($plans, $groupId);
        }

        return [
            'group' => ['id' => $groupId, 'name' => $groupName],
            'updated' => $update,
            'min_files' => $minFiles,
            'collections_scanned' => $scanned,
            'cohorts_found' => \count($plans),
            'collections_in_cohorts' => array_sum(array_map(
                static fn (array $plan): int => \count($plan['collections']),
                $plans
            )),
            'files_in_cohorts' => array_sum(array_map(
                static fn (array $plan): int => \count($plan['files']),
                $plans
            )),
            'cohort_limit_reached' => $truncated,
            'cohorts_merged' => $applied['cohorts'],
            'cohorts_skipped' => \count($skipped),
            'collections_removed' => $applied['collections'],
            'binaries_removed' => $applied['binaries'],
            'binaries_retained' => $applied['files'],
            'parts_moved' => $applied['parts'],
            // Not a refusal: pre-existing damage this repair neither causes nor
            // fixes. Reported so it is visible rather than silently carried into
            // an NZB. See planCohort().
            'binaries_with_duplicate_partnumbers' => array_sum(array_map(
                static fn (array $plan): int => $plan['duplicate_partnumber_binaries'],
                $plans
            )),
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'cohorts' => array_map(static fn (array $plan): array => [
                'name' => $plan['posting'],
                'posting' => $plan['posting'],
                'fromname' => $plan['fromname'],
                'collections' => $plan['collections'],
                'collection_count' => \count($plan['collections']),
                'files' => $plan['files'],
                'file_count' => \count($plan['files']),
                'refusal' => $plan['refusal'],
            ], $plans),
            'skipped' => $skipped,
        ];
    }

    /**
     * Group stalled binaries into postings.
     *
     * The cohort key is `fromname` + the real filename with its volume/part
     * suffix stripped -- NOT the cleaned collection name ingest uses. That
     * matters: CollectionsCleaningService's generic path strips digit runs, so
     * it reduces `Lonely.Movie.2026` to `Lonely.Movie.` and would fuse two
     * different postings into one collection. That fusion is precisely the bug
     * the brace-token work traced (43 files collapsing onto one key), and a
     * union keyed on it would produce a wrong release instead of a stalled one.
     * The filename stem cannot fuse distinct postings, and it still unions the
     * payload with its par2 volumes, because `X.avi.017` and
     * `X.avi.vol000+045.par2` both reduce to `X.avi`.
     *
     * Verified against the live Paper Boy cohort: this key yields 20 files over
     * 5 collections for the payload, leaving the `.jpg` and `.nzb` sidecars in
     * their own single-file cohorts, which unsurvivableShape() then refuses.
     *
     * Cohorts are built from BINARIES, not subjects. A stalled collection is not
     * one file: the live Paper Boy fragment 477646 holds 16 binaries with
     * filenumbers scattered over 1..172. Counting subjects would report one file
     * per collection and make the dry-run disagree with the apply.
     *
     * $limit bounds COHORTS, not rows: a cohort must be complete to merge
     * safely, and a row limit would slice a posting across runs.
     *
     * @return array{0: list<array{posting: string, fromname: string, collections: list<int>, binaries: array<string, list<int>>, declared_parts: array<string, int>, declared_file_counts: array<int, int>, oldest_dateadded: string|null, oldest_date: string|null}>, 1: int, 2: bool}
     */
    private function candidateCohorts(int $groupId, int $limit, ?string $before, ?string $posting): array
    {
        $query = DB::table('binaries as b')
            ->join('collections as c', 'c.id', '=', 'b.collections_id')
            ->where('c.groups_id', $groupId)
            ->where('c.filecheck', 0)
            // Leave the brace-token style to its own repair. The hash styles are
            // excluded per row below, where a regex is portable.
            ->where('c.subject', 'not like', self::BRACE_TOKEN_PREFILTER)
            ->select([
                'b.id as binary_id',
                'b.name as binary_name',
                'b.totalparts',
                'c.id as collection_id',
                'c.subject',
                'c.totalfiles',
                'c.fromname',
                'c.date',
                'c.dateadded',
            ]);

        if ($before !== null) {
            $query->where('c.dateadded', '<', $before);
        }

        $cohorts = [];
        $scannedCollections = [];
        $truncated = false;

        foreach ($query->orderBy('b.id')->lazyById(1000, 'b.id', 'binary_id') as $row) {
            if (preg_match(self::HASH_STYLE_PATTERN, (string) $row->subject) === 1) {
                continue;
            }

            $scannedCollections[(int) $row->collection_id] = true;

            $file = $this->fileNameOf((string) $row->binary_name);
            if ($file === null) {
                continue;
            }

            $stem = $this->postingStem($file);
            if ($stem === null) {
                continue;
            }

            if ($posting !== null && $stem !== $posting) {
                continue;
            }

            $fromName = (string) ($row->fromname ?? '');
            $key = $fromName."\0".$stem;

            if (! isset($cohorts[$key])) {
                if (\count($cohorts) >= $limit) {
                    // Stop admitting NEW cohorts but keep scanning, so the ones
                    // already admitted stay complete.
                    $truncated = true;

                    continue;
                }

                $cohorts[$key] = [
                    'posting' => $stem,
                    'fromname' => $fromName,
                    'collections' => [],
                    'binaries' => [],
                    'declared_parts' => [],
                    'declared_file_counts' => [],
                    'oldest_dateadded' => null,
                    'oldest_date' => null,
                ];
            }

            // A member whose subject carries a real file counter makes the whole
            // cohort untouchable: its totalfiles is trustworthy, so the shortfall
            // is missing articles rather than a mis-key. Recorded rather than
            // skipped here, so planCohort() can REPORT the refusal instead of the
            // cohort silently shrinking and the dry-run disagreeing with reality.
            if (preg_match(self::FILE_COUNTER_PATTERN, (string) $row->subject) === 1) {
                $cohorts[$key]['declared_file_counts'][(int) $row->collection_id] = (int) $row->totalfiles;
            }

            $cohorts[$key]['collections'][(int) $row->collection_id] = true;
            $cohorts[$key]['binaries'][$file][] = (int) $row->binary_id;
            $cohorts[$key]['declared_parts'][$file] = max(
                $cohorts[$key]['declared_parts'][$file] ?? 0,
                (int) $row->totalparts
            );
            $cohorts[$key]['oldest_dateadded'] = $this->earliest(
                $cohorts[$key]['oldest_dateadded'],
                $row->dateadded === null ? null : (string) $row->dateadded
            );
            $cohorts[$key]['oldest_date'] = $this->earliest(
                $cohorts[$key]['oldest_date'],
                $row->date === null ? null : (string) $row->date
            );
        }

        $resolved = [];
        foreach ($cohorts as $cohort) {
            $collectionIds = array_map('intval', array_keys($cohort['collections']));
            sort($collectionIds);
            $cohort['collections'] = $collectionIds;
            $resolved[] = $cohort;
        }

        return [$resolved, \count($scannedCollections), $truncated];
    }

    /**
     * Decide, once, what would happen to a cohort -- so the dry-run reports
     * exactly what the apply would do instead of looking like a clean pass.
     *
     * @param  array{posting: string, fromname: string, collections: list<int>, binaries: array<string, list<int>>, declared_parts: array<string, int>, declared_file_counts: array<int, int>, oldest_dateadded: string|null, oldest_date: string|null}  $cohort
     * @return array<string,mixed>
     */
    private function planCohort(array $cohort, int $minFiles): array
    {
        $files = $this->orderFiles(array_keys($cohort['binaries']));

        $plan = [
            'posting' => $cohort['posting'],
            'fromname' => $cohort['fromname'],
            'collections' => $cohort['collections'],
            'binaries' => $cohort['binaries'],
            'declared_parts' => $cohort['declared_parts'],
            'files' => $files,
            'oldest_dateadded' => $cohort['oldest_dateadded'],
            'oldest_date' => $cohort['oldest_date'],
            'refusal' => $this->unsurvivableShape($files, $minFiles),
            'refusal_file' => null,
            'refusal_values' => [],
            'duplicate_partnumber_binaries' => 0,
        ];

        // Checked BEFORE the shape refusals, because it is the only refusal whose
        // absence would corrupt rather than merely strand: this cohort is not a
        // mis-key at all, and no amount of merging makes its missing articles
        // appear. See FILE_COUNTER_PATTERN.
        $declared = $cohort['declared_file_counts'] ?? [];
        if ($declared !== []) {
            $plan['refusal'] = 'declares_a_real_file_count';
            $plan['refusal_values'] = [
                'declared_files' => max($declared),
                'files_present' => \count($files),
            ];

            return $plan;
        }

        if ($plan['refusal'] !== null) {
            return $plan;
        }

        foreach ($files as $file) {
            $binaryIds = $cohort['binaries'][$file];

            // `parts` is PRIMARY KEY (binaries_id, number), so only an article
            // number appearing under two binaries of the SAME file can break the
            // rehome, and only when there is more than one binary to fold.
            if (\count($binaryIds) > 1) {
                $clash = $this->clashingArticleNumbers($binaryIds);
                if ($clash !== []) {
                    $plan['refusal'] = 'duplicate_article_number';
                    $plan['refusal_file'] = $file;
                    $plan['refusal_values'] = $clash;

                    return $plan;
                }
            }

            // Duplicate partnumbers WITHIN one binary are pre-existing: the live
            // Paper Boy fragment has a binary holding 859 parts across 242
            // distinct partnumbers, from articles ingest mis-filed before v217.
            // Refusing on that would strand the residue permanently over damage
            // the merge neither causes nor worsens, so it is counted, not
            // refused. `partnumber` carries no unique constraint.
            $plan['duplicate_partnumber_binaries'] += $this->binariesWithDuplicatePartnumbers($binaryIds);
        }

        return $plan;
    }

    /**
     * Collapse each posting onto one collection holding one binary per real file.
     *
     * The rewritten collectionhash cannot be one ingest reproduces: ingest hashes
     * `cleanedName . $totalFiles`, and $totalFiles for a further article is still
     * that article's own part count -- the value that was never stable. So the
     * survivor is keyed on this service's own namespaced cohort key, and the
     * consequence is accepted explicitly: a LATER article for an already-merged
     * posting mints a new collection rather than joining this one.
     *
     * That is the correct trade here and not a latent version of the bug the
     * brace-token service warns about. These postings are complete -- every
     * article is already downloaded, which is why the rows are stalled rather
     * than growing -- so there is no further article to strand. The ingest-side
     * fix (keying on the filename stem, not the part count) is a separate
     * change; until it lands this repair is for the historical residue only and
     * should be pointed at postings whose articles have stopped arriving, which
     * --before enforces.
     *
     * Constraints handled rather than assumed away:
     *
     *  - collections.collectionhash is UNIQUE, so an existing owner of the
     *    target hash is adopted as the survivor and re-runs converge.
     *  - binaries is UNIQUE (collections_id, filenumber) and the final ordinals
     *    are numbers members already hold -- the live fragment 477646 carries
     *    filenumbers up to 172 and the absorbed fragments all carry 1 -- so
     *    survivors are parked above MAX(filenumber) before the dense ordinals
     *    are written. `filenumber` is `int(10) unsigned`; a negative park clamps
     *    to 0 and collides.
     *
     * @param  list<array<string,mixed>>  $plans
     * @return array{collections: int, binaries: int, parts: int, cohorts: int, files: int}
     */
    private function applyRegrouping(array $plans, int $groupId): array
    {
        $totals = ['collections' => 0, 'binaries' => 0, 'parts' => 0, 'cohorts' => 0, 'files' => 0];

        foreach ($plans as $plan) {
            if ($plan['refusal'] !== null) {
                continue;
            }

            /** @var list<int> $collectionIds */
            $collectionIds = $plan['collections'];
            /** @var list<string> $files */
            $files = $plan['files'];
            /** @var array<string, list<int>> $byFile */
            $byFile = $plan['binaries'];
            /** @var array<string, int> $declaredParts */
            $declaredParts = $plan['declared_parts'];

            $hash = sha1($this->cohortKey($groupId, (string) $plan['fromname'], (string) $plan['posting']));

            $existing = DB::table('collections')->where('collectionhash', $hash)->value('id');
            $survivor = $existing !== null ? (int) $existing : min($collectionIds);

            $memberIds = array_values(array_unique(array_merge([$survivor], $collectionIds)));
            sort($memberIds);

            $absorbed = array_values(array_filter(
                $memberIds,
                static fn (int $id): bool => $id !== $survivor
            ));

            $parkBase = $this->parkBase($memberIds, \count($files));

            DB::transaction(function () use (
                $plan,
                $groupId,
                $hash,
                $survivor,
                $absorbed,
                $files,
                $byFile,
                $declaredParts,
                $parkBase,
                &$totals
            ): void {
                $survivorBinaries = [];
                $park = 0;

                foreach ($files as $file) {
                    $binaryIds = $byFile[$file];
                    $survivorBinary = min($binaryIds);
                    $survivorBinaries[$file] = $survivorBinary;

                    $drained = array_values(array_filter(
                        $binaryIds,
                        static fn (int $id): bool => $id !== $survivorBinary
                    ));

                    if ($drained !== []) {
                        $totals['parts'] += DB::table('parts')
                            ->whereIn('binaries_id', $drained)
                            ->update(['binaries_id' => $survivorBinary]);

                        // Delete before the collections so the FK cascade cannot
                        // take the parts we just rehomed with it.
                        DB::table('binaries')->whereIn('id', $drained)->delete();
                        $totals['binaries'] += \count($drained);
                    }

                    // Park on a filenumber no not-yet-renumbered sibling holds.
                    $park++;
                    DB::table('binaries')->where('id', $survivorBinary)->update([
                        'collections_id' => $survivor,
                        'filenumber' => $parkBase + $park,
                    ]);
                }

                $ordinal = 0;
                foreach ($files as $file) {
                    $ordinal++;
                    $survivorBinary = $survivorBinaries[$file];

                    $partStats = DB::table('parts')
                        ->where('binaries_id', $survivorBinary)
                        ->selectRaw('count(*) as parts, coalesce(sum(size), 0) as bytes')
                        ->first();

                    DB::table('binaries')->where('id', $survivorBinary)->update([
                        'name' => substr($file, 0, 1000),
                        // Dense 1..N: runCollectionFileCheckStage0() and
                        // stage6CompleteCollectionIds() both read
                        // MAX(filenumber) as a file count, so a gap or a
                        // high-band offset leaves the collection permanently
                        // short of its completion bar.
                        'filenumber' => $ordinal,
                        'totalparts' => $declaredParts[$file],
                        'currentparts' => (int) ($partStats->parts ?? 0),
                        'partsize' => (int) ($partStats->bytes ?? 0),
                        // Let stage 3 re-evaluate now that every part of this
                        // file finally sits under one binary.
                        'partcheck' => 0,
                    ]);

                    $totals['files']++;
                }

                DB::table('collections')->where('id', $survivor)->update([
                    'collectionhash' => $hash,
                    'groups_id' => $groupId,
                    // Safe only because unsurvivableShape() has already refused
                    // every shape the delete predicates would take.
                    'totalfiles' => \count($files),
                    'filecheck' => 0,
                    // Keep the oldest member's timestamps: the collection really
                    // is that old, and back-dating dateadded clears the
                    // delaytime gate immediately instead of restarting the wait.
                    'date' => $plan['oldest_date'],
                    'dateadded' => $plan['oldest_dateadded'],
                ]);

                if ($absorbed !== []) {
                    DB::table('collections')->whereIn('id', $absorbed)->delete();
                    $totals['collections'] += \count($absorbed);
                }

                $totals['cohorts']++;
            });
        }

        return $totals;
    }

    /**
     * The survivor's identity string, namespaced so it cannot collide with a
     * hash ingest or another repair computes for a different posting.
     */
    private function cohortKey(int $groupId, string $fromName, string $stem): string
    {
        return 'splitposting:g'.$groupId.':'.$fromName."\0".$stem;
    }

    /**
     * The real filename a subject or binary name quotes.
     *
     * The quote layout is why this cannot be "the first quoted run". These
     * subjects read `<description> " yEnc "<filename>" yEnc`, so the first
     * quoted run is the literal string ` yEnc ` -- taking it would give every
     * file of every posting the same name and union unrelated rows. The
     * filename is the second-to-last quote-delimited segment, which also holds
     * for the ordinary layouts (`[02/11] "X.part1.rar" yEnc`,
     * `[FULL]  - "X.part2.rar"  [RELEASE] yEnc`).
     *
     * Returns null when the name has no quoted filename, which is how
     * unrecognised layouts stay out of a merge rather than being guessed at.
     */
    private function fileNameOf(string $raw): ?string
    {
        $segments = explode('"', $raw);
        if (\count($segments) < 3) {
            return null;
        }

        $file = trim($segments[\count($segments) - 2]);

        // A filename has an extension. Without this a subject ending in a stray
        // quote would contribute its description as a "file".
        if ($file === '' || ! str_contains($file, '.')) {
            return null;
        }

        return $file;
    }

    /**
     * The posting a filename belongs to: the name with its volume/part suffix
     * removed, so every file of one posting -- payload and par2 alike -- reduces
     * to the same stem.
     *
     * Deliberately NOT CollectionsCleaningService: see candidateCohorts().
     */
    private function postingStem(string $file): ?string
    {
        $stem = preg_replace(
            [
                '/\.(?:vol\d+\+\d+\.par2|par2)$/i',
                '/\.(?:part\d+(?:\.rar)?|r\d+|z\d+|rar|7z|\d{3})$/i',
            ],
            '',
            $file
        );

        $stem = trim((string) $stem);

        return $stem === '' ? null : $stem;
    }

    /**
     * Start of a free filenumber band to park survivors on before renumbering.
     *
     * `binaries.filenumber` is `int(10) unsigned`, so the obvious scratch value
     * -- the negated binary id -- is not available: MariaDB clamps it to 0 and
     * the second park collides on '<survivor>-0'. Taken above MAX(filenumber)
     * across the cohort instead, which is free by construction.
     *
     * @param  list<int>  $memberIds
     */
    private function parkBase(array $memberIds, int $fileCount): int
    {
        $max = (int) (DB::table('binaries')
            ->whereIn('collections_id', $memberIds)
            ->max('filenumber') ?? 0);

        // Also clear the ordinals about to be written, so a park cannot land on
        // a number pass 2 is going to claim.
        return max($max, $fileCount);
    }

    /**
     * Why merging this posting would produce a collection the pipeline deletes.
     *
     * Both predicates live in App\Services\ReleaseProcessingService and both
     * cascade through FK_Collections, taking every part with them:
     *
     *  - deleteCollectionsUnderThreshold() drops a collection whose totalfiles
     *    is below `minfilestoformrelease`, and stage 4 has by then rewritten
     *    totalfiles to COUNT(binaries), so the real file count is what the floor
     *    measures;
     *  - createReleases()' $par2Only filter drops a collection whose binaries
     *    are all par2 volumes.
     *
     * A posting tripping either is left stranded on purpose: stranded rows are
     * recoverable until retention, cascaded ones are not. This is also what
     * keeps the `.jpg` and `.nzb` sidecar cohorts of a posting untouched.
     *
     * @param  list<string>  $files
     */
    private function unsurvivableShape(array $files, int $minFiles): ?string
    {
        if (\count($files) < $minFiles) {
            return 'below_min_files';
        }

        if ($this->isPar2Only($files)) {
            return 'par2_only';
        }

        return null;
    }

    /**
     * @param  list<string>  $files
     */
    private function isPar2Only(array $files): bool
    {
        if ($files === []) {
            return false;
        }

        foreach ($files as $file) {
            if (! $this->isPar2($file)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mirrors the $par2Only filter's own regex, so this refuses exactly what the
     * pipeline would delete rather than an approximation of it.
     */
    private function isPar2(string $file): bool
    {
        return preg_match('/\.(?:vol\d+\+\d+\.par2|par2)$/i', trim($file)) === 1;
    }

    /**
     * Payload files first, then the par2 recovery set, each in natural order --
     * the layout an unobfuscated post already has, so the NZB reads the way a
     * client expects.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function orderFiles(array $files): array
    {
        usort($files, function (string $a, string $b): int {
            return [$this->isPar2($a) ? 1 : 0, $this->volumeHint($a), $a]
                <=> [$this->isPar2($b) ? 1 : 0, $this->volumeHint($b), $b];
        });

        return array_values($files);
    }

    /**
     * Sort hint within a kind: the numeric run that orders sibling files
     * (.001/.017, .vol000+045). Only the relative order is derivable from one
     * name, which is why the dense ordinals are allocated over the whole cohort
     * in applyRegrouping() rather than taken from here.
     */
    private function volumeHint(string $file): int
    {
        if (preg_match('/\.vol(\d+)\+\d+\.par2$/i', $file, $m) === 1) {
            return (int) $m[1];
        }

        if (preg_match('/\.(?:part|r|z)?(\d+)(?:\.rar)?$/i', $file, $m) === 1) {
            return (int) $m[1];
        }

        return -1;
    }

    /**
     * Article numbers appearing under more than one binary of the same file.
     *
     * @param  list<int>  $binaryIds
     * @return list<int>
     */
    private function clashingArticleNumbers(array $binaryIds): array
    {
        /** @var list<int> $dupes */
        $dupes = DB::table('parts')
            ->whereIn('binaries_id', $binaryIds)
            ->groupBy('number')
            ->havingRaw('count(*) > 1')
            ->limit(10)
            ->pluck('number')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $dupes;
    }

    /**
     * How many of these binaries already hold two parts with one partnumber.
     *
     * @param  list<int>  $binaryIds
     */
    private function binariesWithDuplicatePartnumbers(array $binaryIds): int
    {
        return DB::table('parts')
            ->whereIn('binaries_id', $binaryIds)
            ->groupBy('binaries_id')
            ->havingRaw('count(*) > count(distinct partnumber)')
            ->pluck('binaries_id')
            ->count();
    }

    private function earliest(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate < $current ? $candidate : $current;
    }

    /**
     * The floor deleteCollectionsUnderThreshold() will actually apply.
     *
     * A group override of NULL or '' falls back to the site setting. Read live
     * rather than assumed, because merging against the wrong floor deletes
     * collections.
     *
     * The max(1, ...) clamp is deliberately STRICTER than production. An explicit
     * 0 is a real override to effectiveGroupThreshold()
     * (ReleaseProcessingService.php:1761 -- only null/'' fall through), and both
     * min-files delete predicates are guarded by `> 0`, so 0 DISABLES the delete
     * for that group and nothing would be cascaded. Clamping to 1 anyway means we
     * refuse a handful of single-file cohorts we could technically have merged,
     * which errs toward stranding rather than deleting -- and 0 is a
     * misconfiguration to be corrected in settings, not a floor to trust here.
     */
    private function effectiveMinFiles(int $groupId): int
    {
        $override = DB::table('usenet_groups')
            ->where('id', $groupId)
            ->value('minfilestoformrelease');

        if ($override !== null && $override !== '') {
            return max(1, (int) $override);
        }

        $site = DB::table('settings')->where('name', 'minfilestoformrelease')->value('value');

        return $site === null || $site === '' ? self::DEFAULT_MIN_FILES : max(1, (int) $site);
    }

    private function resolveGroup(int|string $group): object
    {
        $query = DB::table('usenet_groups')->select(['id', 'name']);
        $row = is_numeric($group)
            ? $query->where('id', (int) $group)->first()
            : $query->where('name', (string) $group)->first();

        if ($row === null) {
            throw new InvalidArgumentException('Unknown Usenet group: '.(string) $group);
        }

        return $row;
    }
}
