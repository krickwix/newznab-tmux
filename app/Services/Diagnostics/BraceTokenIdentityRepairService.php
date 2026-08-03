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
 * Target state per real file is ONE collection holding ONE binary that owns
 * every part, which is what an unobfuscated multi-part binary looks like:
 * anything less (e.g. one binary per absorbed member) still fails the
 * parts-complete gate.
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

    private ObfuscatedSubjectNormalizer $normalizer;

    public function __construct(?ObfuscatedSubjectNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new ObfuscatedSubjectNormalizer;
    }

    /**
     * @return array<string,mixed>
     */
    public function repair(
        int|string $group,
        int $limit,
        ?string $before,
        bool $update
    ): array {
        if ($limit < 1) {
            throw new InvalidArgumentException('--limit must be at least 1.');
        }

        $startedAt = microtime(true);
        $groupRow = $this->resolveGroup($group);
        $groupId = (int) $groupRow->id;
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

        [$cohorts, $scanned, $truncated] = $this->candidateCohorts($groupId, $limit, $before);

        $resolved = [];
        foreach ($cohorts as $name => $cohort) {
            $resolved[] = [
                'name' => $name,
                'collection_key' => ObfuscatedSubjectNormalizer::collectionKey($name, $groupId),
                'collections' => $cohort['collections'],
                'collection_count' => \count($cohort['collections']),
                'oldest_dateadded' => $cohort['oldest_dateadded'],
                'oldest_date' => $cohort['oldest_date'],
            ];
        }

        $skipped = [];
        $applied = ['collections' => 0, 'binaries' => 0, 'parts' => 0, 'cohorts' => 0];
        if ($update) {
            $applied = $this->applyRegrouping($resolved, $groupId, $skipped);
        }

        return [
            'group' => ['id' => $groupId, 'name' => (string) $groupRow->name],
            'group_normalization_enabled' => $normalizationEnabled,
            'updated' => $update,
            'collections_scanned' => $scanned,
            'cohorts_found' => \count($resolved),
            'collections_in_cohorts' => array_sum(array_column($resolved, 'collection_count')),
            'cohort_limit_reached' => $truncated,
            'cohorts_merged' => $applied['cohorts'],
            'cohorts_skipped' => \count($skipped),
            'collections_removed' => $applied['collections'],
            'binaries_removed' => $applied['binaries'],
            'parts_moved' => $applied['parts'],
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'cohorts' => $resolved,
            'skipped' => $skipped,
        ];
    }

    /**
     * Group stalled collections by the de-tokenised filename.
     *
     * Grouping happens in PHP rather than SQL so the cohort key is produced by
     * the very code ingest runs. Rows are streamed by id so a group holding tens
     * of thousands of stranded collections does not have to be materialised at
     * once; only the (id, name) pairs are retained.
     *
     * $limit bounds COHORTS, not rows: a cohort must be complete to be merged
     * safely, and a row limit would slice one file's articles across runs and
     * leave half of them behind under a subject that no longer exists.
     *
     * @return array{0: array<string, array{collections: list<int>, oldest_dateadded: string|null, oldest_date: string|null}>, 1: int, 2: bool}
     */
    private function candidateCohorts(int $groupId, int $limit, ?string $before): array
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

            $name = $normalized['name'];
            if (! isset($cohorts[$name])) {
                if (\count($cohorts) >= $limit) {
                    // Stop admitting NEW cohorts, but keep scanning so the
                    // cohorts already admitted stay complete.
                    $truncated = true;

                    continue;
                }

                $cohorts[$name] = [
                    'collections' => [],
                    'oldest_dateadded' => null,
                    'oldest_date' => null,
                ];
            }

            $cohorts[$name]['collections'][] = (int) $row->id;
            $cohorts[$name]['oldest_dateadded'] = $this->earliest(
                $cohorts[$name]['oldest_dateadded'],
                $row->dateadded === null ? null : (string) $row->dateadded
            );
            $cohorts[$name]['oldest_date'] = $this->earliest(
                $cohorts[$name]['oldest_date'],
                $row->date === null ? null : (string) $row->date
            );
        }

        return [$cohorts, $scanned, $truncated];
    }

    /**
     * Collapse each cohort onto one collection holding one binary.
     *
     * The rewritten key MUST be the one the ingest path computes, which is why
     * it comes from ObfuscatedSubjectNormalizer::collectionKey(). If the two
     * ever disagree, a later article for this file would miss the repaired row
     * and mint a fresh stalled collection.
     *
     * Constraints governing the merge, all handled rather than assumed away:
     *
     *  - collections.collectionhash is UNIQUE, so a previous run (or ingest on
     *    the fixed code) may already own the target hash. That owner is adopted
     *    as the survivor, which makes re-runs converge instead of colliding.
     *  - parts is keyed PRIMARY (binaries_id, number), so two members holding
     *    the same article number cannot be folded onto one binary. Article
     *    numbers are unique per group so this should not occur; it is checked
     *    rather than trusted because a violation would abort mid-transaction.
     *  - two members claiming the same partnumber means the cohort is not one
     *    file. Those are reported for inspection, never merged: the merge is
     *    lossy and a wrong one destroys collections.
     *
     * @param  list<array<string,mixed>>  $resolved
     * @param  list<array<string,mixed>>  $skipped
     * @return array{collections: int, binaries: int, parts: int, cohorts: int}
     */
    private function applyRegrouping(array $resolved, int $groupId, array &$skipped): array
    {
        $totals = ['collections' => 0, 'binaries' => 0, 'parts' => 0, 'cohorts' => 0];

        foreach ($resolved as $entry) {
            /** @var list<int> $collectionIds */
            $collectionIds = $entry['collections'];
            $hash = sha1((string) $entry['collection_key']);

            $existing = DB::table('collections')->where('collectionhash', $hash)->value('id');
            $survivor = $existing !== null ? (int) $existing : min($collectionIds);

            $memberIds = array_values(array_unique(array_merge([$survivor], $collectionIds)));
            sort($memberIds);

            $binaryIds = $this->binaryIds($memberIds);
            if ($binaryIds === []) {
                $skipped[] = [
                    'name' => $entry['name'],
                    'reason' => 'no_binaries',
                    'collections' => $memberIds,
                ];

                continue;
            }

            $clash = $this->clashingParts($binaryIds);
            if ($clash !== null) {
                $skipped[] = [
                    'name' => $entry['name'],
                    'reason' => $clash['reason'],
                    'values' => $clash['values'],
                    'collections' => $memberIds,
                ];

                continue;
            }

            $survivorBinary = min($binaryIds);
            $drainedBinaries = array_values(array_filter(
                $binaryIds,
                static fn (int $id): bool => $id !== $survivorBinary
            ));
            $absorbed = array_values(array_filter(
                $memberIds,
                static fn (int $id): bool => $id !== $survivor
            ));
            $totalParts = (int) DB::table('binaries')->whereIn('id', $binaryIds)->max('totalparts');

            DB::transaction(function () use (
                $entry,
                $groupId,
                $hash,
                $survivor,
                $absorbed,
                $survivorBinary,
                $drainedBinaries,
                $totalParts,
                &$totals
            ): void {
                $moved = 0;
                if ($drainedBinaries !== []) {
                    $moved = DB::table('parts')
                        ->whereIn('binaries_id', $drainedBinaries)
                        ->update(['binaries_id' => $survivorBinary]);

                    // Delete before the collections so the FK cascade cannot
                    // take the parts we just rehomed with it.
                    DB::table('binaries')->whereIn('id', $drainedBinaries)->delete();
                }

                $partStats = DB::table('parts')
                    ->where('binaries_id', $survivorBinary)
                    ->selectRaw('count(*) as parts, coalesce(sum(size), 0) as bytes')
                    ->first();

                DB::table('binaries')->where('id', $survivorBinary)->update([
                    'collections_id' => $survivor,
                    'name' => substr((string) $entry['name'], 0, 1000),
                    'filenumber' => 1,
                    'totalparts' => $totalParts,
                    'currentparts' => (int) ($partStats->parts ?? 0),
                    'partsize' => (int) ($partStats->bytes ?? 0),
                    // Let stage 3 re-evaluate completeness now that every part
                    // finally sits under one binary.
                    'partcheck' => 0,
                ]);

                DB::table('collections')->where('id', $survivor)->update([
                    'collectionhash' => $hash,
                    'subject' => substr((string) $entry['name'], 0, 255),
                    'groups_id' => $groupId,
                    'totalfiles' => 1,
                    'filecheck' => 0,
                    // Keep the oldest member's timestamps: the collection really
                    // is that old, and back-dating dateadded puts it past the
                    // delaytime gate immediately instead of restarting its wait.
                    'date' => $entry['oldest_date'],
                    'dateadded' => $entry['oldest_dateadded'],
                ]);

                if ($absorbed !== []) {
                    DB::table('collections')->whereIn('id', $absorbed)->delete();
                }

                $totals['cohorts']++;
                $totals['collections'] += \count($absorbed);
                $totals['binaries'] += \count($drainedBinaries);
                $totals['parts'] += $moved;
            });
        }

        return $totals;
    }

    /**
     * @param  list<int>  $collectionIds
     * @return list<int>
     */
    private function binaryIds(array $collectionIds): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('binaries')
            ->whereIn('collections_id', $collectionIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
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
