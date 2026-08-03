<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use App\Services\Binaries\ObfuscatedSubjectNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reclaim "brace token" collections stranded before the ingest fix.
 *
 * Affected posts append a per-article random token after the real filename:
 *
 *   {Supergirl.2026.vol127+72.par2} {5Zn03jrjsly2} yEnc (410/710)
 *   {Supergirl.2026.vol127+72.par2} {K7x0l9F182pk} yEnc (168/710)
 *
 * Because the token was part of the parsed subject, it leaked into both the
 * collection key and the binary hash, so every single article minted its own
 * collection AND its own binary, each stuck at currentparts=1/totalparts=710.
 * The parts-complete gate can never be satisfied, so these rows sit at
 * filecheck=0 until retention purges them. ObfuscatedSubjectNormalizer now
 * strips the token at ingest, but the historical rows need regrouping.
 *
 * Unlike Par2SetIdentityRepairService this needs no NNTP access at all: the real
 * filename is still intact inside collections.subject, so the cohort is
 * recoverable locally and there is no probe budget, no ambiguity class and no
 * article fetch. It is also structurally out of that service's reach --
 * PAR2_MEMBER_PATTERN only matches subjects whose filename is a bare SHA-1.
 *
 * Target state is ONE collection per POSTING, holding one binary per real file
 * with dense filenumbers 1..N -- which is exactly what an unobfuscated post
 * looks like. An earlier version of this service targeted one collection per
 * FILE, and that shape is destroyed downstream: see the "survivable shape"
 * notes on the two delete predicates below. It cost 512 production collections
 * and ~541 MB of articles, so the predicates are now enforced here, before any
 * write, rather than discovered afterwards.
 *
 * Defaults are dry-run. Applying deletes collections, so nothing about this is
 * safe to run blind.
 */
final class BraceTokenIdentityRepairService
{
    /**
     * Coarse, index-agnostic SQL prefilter for '{name} {token} trailer'.
     *
     * Deliberately loose: the authoritative test is
     * ObfuscatedSubjectNormalizer::normalize() in PHP, which is the same code
     * ingest uses. Doing the precise match in SQL would need REGEXP and would
     * make this untestable outside MariaDB.
     */
    private const string SUBJECT_PREFILTER = '{%}%{%}%';

    /**
     * Production `settings.minfilestoformrelease`. Only a default: the caller
     * passes the live value, because a group override changes it.
     */
    public const int DEFAULT_MIN_FILES = 2;

    private ObfuscatedSubjectNormalizer $normalizer;

    public function __construct(?ObfuscatedSubjectNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new ObfuscatedSubjectNormalizer;
    }

    /**
     * @param  string|null  $posting  Repair only the posting with this exact
     *                                stem. A staged production drain needs to
     *                                name its target: $limit admits cohorts in
     *                                collection-id order, which is arbitrary
     *                                with respect to which posting is safe to
     *                                merge next.
     * @param  int|null  $minFiles  Override for the effective
     *                              `minfilestoformrelease`; read from the live
     *                              settings and group override when null. A
     *                              posting resolving to fewer real files than
     *                              this is reported and left alone: merging it
     *                              would produce a collection that
     *                              deleteCollectionsUnderThreshold() removes,
     *                              cascading its parts away.
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
        $minFiles ??= $this->effectiveMinFiles($groupRow);
        $normalizationEnabled = $this->normalizer->appliesTo((string) $groupRow->name);

        // Repairing a group ingest does not normalize would write keys ingest
        // never computes: the next article for the file would miss the repaired
        // row and mint a fresh stalled collection. Refuse rather than trade one
        // kind of stranded row for another.
        if ($update && ! $normalizationEnabled) {
            throw new InvalidArgumentException(sprintf(
                'Brace-token normalization is not enabled for %s; add it to nntmux.obfuscated_brace_token_groups before repairing.',
                (string) $groupRow->name
            ));
        }

        [$cohorts, $scanned, $truncated] = $this->candidateCohorts($groupId, $limit, $before, $posting);

        $resolved = [];
        foreach ($cohorts as $posting => $cohort) {
            $files = $this->orderFiles(array_keys($cohort['files']));

            $resolved[] = [
                'name' => $posting,
                'posting' => $posting,
                'collection_key' => ObfuscatedSubjectNormalizer::postingKey($posting, $groupId),
                'collections' => $cohort['collections'],
                'collection_count' => \count($cohort['collections']),
                'files' => $files,
                'file_count' => \count($files),
                'par2_only' => $this->isPar2Only($files),
                'oldest_dateadded' => $cohort['oldest_dateadded'],
                'oldest_date' => $cohort['oldest_date'],
            ];
        }

        $skipped = [];
        $applied = ['collections' => 0, 'binaries' => 0, 'parts' => 0, 'cohorts' => 0, 'files' => 0];
        if ($update) {
            $applied = $this->applyRegrouping($resolved, $groupId, $minFiles, $skipped);
        } else {
            // The same two predicates the apply path enforces, so a dry-run
            // reports what it would refuse instead of looking like a clean pass.
            foreach ($resolved as $entry) {
                $refusal = $this->unsurvivableShape($entry['files'], $minFiles);
                if ($refusal !== null) {
                    $skipped[] = [
                        'name' => $entry['posting'],
                        'reason' => $refusal,
                        'files' => $entry['files'],
                        'collections' => $entry['collections'],
                    ];
                }
            }
        }

        return [
            'group' => ['id' => $groupId, 'name' => (string) $groupRow->name],
            'group_normalization_enabled' => $normalizationEnabled,
            'updated' => $update,
            'min_files' => $minFiles,
            'collections_scanned' => $scanned,
            'cohorts_found' => \count($resolved),
            'collections_in_cohorts' => array_sum(array_column($resolved, 'collection_count')),
            'files_in_cohorts' => array_sum(array_column($resolved, 'file_count')),
            'cohort_limit_reached' => $truncated,
            'cohorts_merged' => $applied['cohorts'],
            'cohorts_skipped' => \count($skipped),
            'collections_removed' => $applied['collections'],
            'binaries_removed' => $applied['binaries'],
            'binaries_retained' => $applied['files'],
            'parts_moved' => $applied['parts'],
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'cohorts' => $resolved,
            'skipped' => $skipped,
        ];
    }

    /**
     * Group stalled collections by POSTING, tracking the real files within each.
     *
     * Grouping happens in PHP rather than SQL so the cohort key is produced by
     * the very code ingest runs. Rows are streamed by id so a group holding tens
     * of thousands of stranded collections does not have to be materialised at
     * once; only the (id, name) pairs are retained.
     *
     * $limit bounds COHORTS, not rows: a cohort must be complete to be merged
     * safely, and a row limit would slice a posting's articles across runs and
     * leave half of them behind under a subject that no longer exists.
     *
     * @return array{0: array<string, array{collections: list<int>, files: array<string,true>, oldest_dateadded: string|null, oldest_date: string|null}>, 1: int, 2: bool}
     */
    private function candidateCohorts(int $groupId, int $limit, ?string $before, ?string $posting = null): array
    {
        $query = DB::table('collections')
            ->where('groups_id', $groupId)
            ->where('filecheck', 0)
            ->where('subject', 'like', self::SUBJECT_PREFILTER)
            ->select(['id', 'subject', 'date', 'dateadded']);

        if ($before !== null) {
            $query->where('dateadded', '<', $before);
        }

        $cohorts = [];
        $scanned = 0;
        $truncated = false;

        foreach ($query->orderBy('id')->lazyById(1000, 'id') as $row) {
            $scanned++;
            $normalized = $this->normalizer->normalize((string) $row->subject);
            if ($normalized === null) {
                continue;
            }

            $file = $normalized['name'];
            $cohortName = ObfuscatedSubjectNormalizer::postingIdentity($file)['posting'];

            if ($posting !== null && $cohortName !== $posting) {
                continue;
            }

            if (! isset($cohorts[$cohortName])) {
                if (\count($cohorts) >= $limit) {
                    // Stop admitting NEW cohorts, but keep scanning so the
                    // cohorts already admitted stay complete.
                    $truncated = true;

                    continue;
                }

                $cohorts[$cohortName] = [
                    'collections' => [],
                    'files' => [],
                    'oldest_dateadded' => null,
                    'oldest_date' => null,
                ];
            }

            $cohorts[$cohortName]['collections'][] = (int) $row->id;
            $cohorts[$cohortName]['files'][$file] = true;
            $cohorts[$cohortName]['oldest_dateadded'] = $this->earliest(
                $cohorts[$cohortName]['oldest_dateadded'],
                $row->dateadded === null ? null : (string) $row->dateadded
            );
            $cohorts[$cohortName]['oldest_date'] = $this->earliest(
                $cohorts[$cohortName]['oldest_date'],
                $row->date === null ? null : (string) $row->date
            );
        }

        return [$cohorts, $scanned, $truncated];
    }

    /**
     * Collapse each posting onto one collection holding one binary per real file.
     *
     * The rewritten key MUST be the one the ingest path computes, which is why
     * it comes from ObfuscatedSubjectNormalizer::postingKey(). If the two ever
     * disagree, a later article for this posting would miss the repaired row and
     * mint a fresh stalled collection.
     *
     * Constraints governing the merge, all handled rather than assumed away:
     *
     *  - collections.collectionhash is UNIQUE, so a previous run (or ingest on
     *    the fixed code) may already own the target hash. That owner is adopted
     *    as the survivor, which makes re-runs converge instead of colliding.
     *    Its binaries join the file grouping, so an adopted owner cannot end up
     *    with two binaries for one file.
     *  - binaries is UNIQUE (collections_id, filenumber) and the final ordinals
     *    are a permutation of what the member binaries already carry, so
     *    assigning them directly can collide with a row not yet renumbered.
     *    Survivors are therefore parked on filenumber = -id (unique by
     *    construction) before the dense ordinals are written.
     *  - parts is keyed PRIMARY (binaries_id, number), so two members of the
     *    same file holding the same article number cannot be folded onto one
     *    binary. Checked per file rather than trusted, because a violation
     *    would abort mid-transaction.
     *  - two members of one file claiming the same partnumber means the cohort
     *    is not what it looks like. Those are reported for inspection, never
     *    merged: the merge is lossy and a wrong one destroys collections.
     *
     * @param  list<array<string,mixed>>  $resolved
     * @param  list<array<string,mixed>>  $skipped
     * @return array{collections: int, binaries: int, parts: int, cohorts: int, files: int}
     */
    private function applyRegrouping(array $resolved, int $groupId, int $minFiles, array &$skipped): array
    {
        $totals = ['collections' => 0, 'binaries' => 0, 'parts' => 0, 'cohorts' => 0, 'files' => 0];

        foreach ($resolved as $entry) {
            /** @var list<int> $collectionIds */
            $collectionIds = $entry['collections'];
            $hash = sha1((string) $entry['collection_key']);

            $existing = DB::table('collections')->where('collectionhash', $hash)->value('id');
            $survivor = $existing !== null ? (int) $existing : min($collectionIds);

            $memberIds = array_values(array_unique(array_merge([$survivor], $collectionIds)));
            sort($memberIds);

            // Authoritative file grouping comes from the binaries, not from the
            // scanned subjects: an adopted survivor may hold already-repaired
            // binaries that no longer carry a token and so were never scanned.
            $rows = DB::table('binaries')
                ->whereIn('collections_id', $memberIds)
                ->orderBy('id')
                ->get(['id', 'name', 'totalparts']);

            if ($rows->isEmpty()) {
                $skipped[] = [
                    'name' => $entry['posting'],
                    'reason' => 'no_binaries',
                    'collections' => $memberIds,
                ];

                continue;
            }

            /** @var array<string, list<int>> $byFile */
            $byFile = [];
            /** @var array<string, int> $declaredParts */
            $declaredParts = [];
            foreach ($rows as $row) {
                $file = $this->fileNameOf((string) $row->name);
                $byFile[$file][] = (int) $row->id;
                $declaredParts[$file] = max($declaredParts[$file] ?? 0, (int) $row->totalparts);
            }

            $files = $this->orderFiles(array_keys($byFile));

            $refusal = $this->unsurvivableShape($files, $minFiles);
            if ($refusal !== null) {
                $skipped[] = [
                    'name' => $entry['posting'],
                    'reason' => $refusal,
                    'files' => $files,
                    'collections' => $memberIds,
                ];

                continue;
            }

            $clashed = null;
            foreach ($files as $file) {
                $clash = $this->clashingParts($byFile[$file]);
                if ($clash !== null) {
                    $clashed = ['file' => $file] + $clash;
                    break;
                }
            }

            if ($clashed !== null) {
                $skipped[] = [
                    'name' => $entry['posting'],
                    'reason' => $clashed['reason'],
                    'file' => $clashed['file'],
                    'values' => $clashed['values'],
                    'collections' => $memberIds,
                ];

                continue;
            }

            $absorbed = array_values(array_filter(
                $memberIds,
                static fn (int $id): bool => $id !== $survivor
            ));

            DB::transaction(function () use (
                $entry,
                $groupId,
                $hash,
                $survivor,
                $absorbed,
                $files,
                $byFile,
                $declaredParts,
                &$totals
            ): void {
                $survivorBinaries = [];

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

                    // Park on a filenumber that cannot collide with any ordinal
                    // still held by a not-yet-renumbered sibling.
                    DB::table('binaries')->where('id', $survivorBinary)->update([
                        'collections_id' => $survivor,
                        'filenumber' => -$survivorBinary,
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
                        // MAX(filenumber) as a file count, so any gap or
                        // high-band offset leaves the collection permanently
                        // short of its completion bar.
                        'filenumber' => $ordinal,
                        'totalparts' => $declaredParts[$file],
                        'currentparts' => (int) ($partStats->parts ?? 0),
                        'partsize' => (int) ($partStats->bytes ?? 0),
                        // Let stage 3 re-evaluate completeness now that every
                        // part of this file finally sits under one binary.
                        'partcheck' => 0,
                    ]);

                    $totals['files']++;
                }

                DB::table('collections')->where('id', $survivor)->update([
                    'collectionhash' => $hash,
                    'subject' => substr('{'.$entry['posting'].'}', 0, 255),
                    'groups_id' => $groupId,
                    // Stage 6 overwrites this with COUNT(binaries) anyway; it is
                    // written so the row is coherent before stage 6 arrives, not
                    // because the value survives.
                    'totalfiles' => \count($files),
                    'filecheck' => 0,
                    // Keep the oldest member's timestamps: the collection really
                    // is that old, and back-dating dateadded puts it past the
                    // delaytime gate immediately instead of restarting its wait.
                    'date' => $entry['oldest_date'],
                    'dateadded' => $entry['oldest_dateadded'],
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
     * Why merging this posting would produce a collection the pipeline deletes.
     *
     * Both predicates live in App\Services\ReleaseProcessingService and both
     * cascade through FK_Collections, taking every part with them:
     *
     *  - deleteCollectionsUnderThreshold() drops a Sized collection whose
     *    totalfiles is below `minfilestoformrelease`, and stage 6 has by then
     *    rewritten totalfiles to COUNT(binaries) -- so the real file count is
     *    what the floor measures;
     *  - createReleases()' $par2Only filter drops a collection whose binaries
     *    are all par2 volumes.
     *
     * A posting that trips either one is left stranded on purpose. Stranded rows
     * are recoverable until retention; merged-then-deleted rows are not.
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
            if (ObfuscatedSubjectNormalizer::postingIdentity($file)['kind'] !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The de-tokenised filename a binary belongs to.
     *
     * Binaries written by an earlier repair run carry the cleaned name already,
     * so normalize() declines them; that name is the file name as-is.
     */
    private function fileNameOf(string $rawName): string
    {
        $normalized = $this->normalizer->normalize($rawName);

        return $normalized === null ? trim($rawName) : $normalized['name'];
    }

    /**
     * Payload volumes first, then the par2 recovery set, each in its own natural
     * order -- the layout an unobfuscated post already has, so the NZB reads the
     * way a client expects.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function orderFiles(array $files): array
    {
        usort($files, static function (string $a, string $b): int {
            $left = ObfuscatedSubjectNormalizer::postingIdentity($a);
            $right = ObfuscatedSubjectNormalizer::postingIdentity($b);

            return [$left['kind'], $left['hint'], $a] <=> [$right['kind'], $right['hint'], $b];
        });

        return array_values($files);
    }

    /**
     * Values that appear twice across a proposed binary merge, if any.
     *
     * @param  list<int>  $binaryIds
     * @return array{reason: string, values: list<int>}|null
     */
    private function clashingParts(array $binaryIds): ?array
    {
        foreach (['number' => 'duplicate_article_number', 'partnumber' => 'duplicate_partnumber'] as $column => $reason) {
            /** @var list<int> $dupes */
            $dupes = DB::table('parts')
                ->whereIn('binaries_id', $binaryIds)
                ->groupBy($column)
                ->havingRaw('count(*) > 1')
                ->limit(10)
                ->pluck($column)
                ->map(static fn (mixed $value): int => (int) $value)
                ->all();

            if ($dupes !== []) {
                return ['reason' => $reason, 'values' => $dupes];
            }
        }

        return null;
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
     * Mirrors ReleaseProcessingService::effectiveGroupThreshold(): a group
     * override of NULL or '' falls back to the site setting. Read live rather
     * than assumed, because merging against the wrong floor is what deletes
     * collections.
     */
    private function effectiveMinFiles(object $groupRow): int
    {
        $override = DB::table('usenet_groups')
            ->where('id', (int) $groupRow->id)
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
