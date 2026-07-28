<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use App\Services\Binaries\ObfuscatedHashSetNormalizer;
use App\Services\Binaries\Par2Packet;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Regroup obfuscated hash-set collections using the PAR2 RecoverySetID.
 *
 * Background: posts in the affected groups name every file by its own SHA-1, so
 * the subject carries no token shared across the files of one post. The legacy
 * collection key embedded that per-file name, so each file minted its own
 * collection while declaring the post-wide file count, and every one of them
 * waited forever for files that were filed elsewhere.
 *
 * ObfuscatedHashSetNormalizer recovers the grouping heuristically from
 * (group, article date, declared total). This service recovers it
 * authoritatively instead: a PAR2 packet header carries a RecoverySetID that is
 * derived from the set contents and is identical across every par2 volume of the
 * post, so it survives the filename obfuscation entirely.
 *
 * This is a REPAIR pass, deliberately not an ingest-time step. It fetches whole
 * articles (~700 KB each) rather than the few preamble lines the BODY-preamble
 * path needs, so it is far too expensive to run against every incoming header.
 * It is intended for collections still stranded after the cheap heuristic has
 * had its chance.
 *
 * Defaults are dry-run and every budget is bounded, so an accidental invocation
 * costs a handful of article fetches and changes nothing.
 */
final class Par2SetIdentityRepairService
{
    /**
     * Matches subjects whose filename is a bare SHA-1, optionally with a .par2
     * extension. Only par2 members can carry a RecoverySetID, so the caller
     * restricts probing to those.
     */
    private const string PAR2_MEMBER_PATTERN = '^\\[[0-9]+/[0-9]+\\][ -]*"[0-9a-f]{40}\\.par2"';

    public function __construct(private readonly NNTPService $nntp) {}

    /**
     * @return array<string,mixed>
     */
    public function repair(
        int|string $group,
        int $limit,
        int $maxProbesPerCohort,
        float $maxSeconds,
        ?string $before,
        bool $update
    ): array {
        if ($limit < 1) {
            throw new InvalidArgumentException('--limit must be at least 1.');
        }
        if ($maxProbesPerCohort < 1) {
            throw new InvalidArgumentException('--max-probes-per-cohort must be at least 1.');
        }

        $groupRow = $this->resolveGroup($group);
        $cohorts = $this->candidateCohorts((int) $groupRow->id, $limit, $before);

        $startedAt = microtime(true);
        $connected = false;

        $probed = 0;
        $noPacket = 0;
        $fetchErrors = 0;
        $resolved = [];
        $ambiguous = [];
        $unresolved = [];
        $budgetExhausted = false;

        try {
            foreach ($cohorts as $cohortKey => $members) {
                if ($maxSeconds > 0 && (microtime(true) - $startedAt) >= $maxSeconds) {
                    $budgetExhausted = true;
                    break;
                }

                if (! $connected) {
                    if (NNTPService::isError($this->nntp->doConnect())) {
                        throw new InvalidArgumentException('Unable to connect to the news server.');
                    }
                    $connected = true;
                }

                $setIds = [];
                $attempts = 0;

                foreach ($members as $member) {
                    if ($attempts >= $maxProbesPerCohort) {
                        break;
                    }
                    $attempts++;

                    $body = $this->nntp->getMessages($groupRow->name, [$member->messageid]);
                    if (NNTPService::isError($body) || ! \is_string($body)) {
                        $fetchErrors++;

                        continue;
                    }
                    $probed++;

                    // Articles that begin part-way through a large recovery slice
                    // carry no packet boundary. That is expected, not an error;
                    // move on to another member of the same cohort.
                    $packet = Par2Packet::firstFrom($body);
                    if ($packet === null) {
                        $noPacket++;

                        continue;
                    }

                    $setIds[$packet->recoverySetId][] = (int) $member->collection_id;
                }

                if ($setIds === []) {
                    $unresolved[] = ['cohort' => $cohortKey, 'probes' => $attempts];

                    continue;
                }

                // More than one recovery set inside a single cohort means the
                // heuristic grouping merged distinct posts. Never rewrite those
                // automatically; report them for inspection instead.
                if (\count($setIds) > 1) {
                    $ambiguous[] = ['cohort' => $cohortKey, 'set_ids' => array_keys($setIds)];

                    continue;
                }

                $setId = (string) array_key_first($setIds);

                // The probed members are par2 volumes only, but a post is mostly
                // rar volumes: one sampled cohort held 6 par2 against 76 rar
                // collections. Merging just the par2 rows would strand the rest,
                // so once the set id has confirmed the cohort is a single post,
                // membership is taken from the whole cohort.
                $membership = $this->cohortMembership($cohortKey);

                [$cohortGroupId, $cohortUnixtime, $cohortTotalFiles] = array_pad(
                    array_map('intval', explode('|', $cohortKey)),
                    3,
                    0
                );

                $resolved[] = [
                    'cohort' => $cohortKey,
                    'set_id' => $setId,
                    'collection_key' => ObfuscatedHashSetNormalizer::cohortKey(
                        $cohortGroupId,
                        $cohortTotalFiles,
                        $cohortUnixtime
                    ),
                    'probed_par2_collections' => array_values(array_unique(array_map(
                        static fn (object $m): int => (int) $m->collection_id,
                        $members
                    ))),
                    'collections' => $membership,
                    'probes' => $attempts,
                ];
            }
        } finally {
            if ($connected) {
                $this->nntp->doQuit();
            }
        }

        $skipped = [];
        $applied = $update ? $this->applyRegrouping($resolved, $skipped) : 0;

        return [
            'group' => ['id' => (int) $groupRow->id, 'name' => (string) $groupRow->name],
            'updated' => $update,
            'cohorts_scanned' => $cohorts->count(),
            'articles_probed' => $probed,
            'fetch_errors' => $fetchErrors,
            'articles_without_packet' => $noPacket,
            'cohorts_resolved' => \count($resolved),
            'cohorts_ambiguous' => \count($ambiguous),
            'cohorts_unresolved' => \count($unresolved),
            'cohorts_skipped' => \count($skipped),
            'collections_regrouped' => $applied,
            'budget_exhausted' => $budgetExhausted,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'resolved' => $resolved,
            'ambiguous' => $ambiguous,
            'unresolved' => $unresolved,
            'skipped' => $skipped,
        ];
    }

    /**
     * Group stalled par2 members by the heuristic cohort triple, so each cohort
     * can be confirmed or rejected against the authoritative set id.
     *
     * Only part 1 of each binary is considered: later parts routinely land
     * mid-slice with no packet header, which makes perfectly good data look
     * unparseable.
     *
     * @return Collection<string,list<object>>
     */
    private function candidateCohorts(int $groupId, int $limit, ?string $before): Collection
    {
        $query = DB::table('collections as c')
            ->join('binaries as b', 'b.collections_id', '=', 'c.id')
            ->join('parts as p', 'p.binaries_id', '=', 'b.id')
            ->where('c.groups_id', $groupId)
            ->where('c.filecheck', 0)
            ->where('p.partnumber', 1)
            ->whereRaw('c.subject regexp ?', [self::PAR2_MEMBER_PATTERN])
            ->select([
                'c.id as collection_id',
                'p.messageid as messageid',
                DB::raw('concat(c.groups_id, "|", unix_timestamp(c.date), "|", c.totalfiles) as cohort_key'),
            ]);

        if ($before !== null) {
            $query->where('c.dateadded', '<', $before);
        }

        return $query->orderBy('c.id')
            ->limit($limit)
            ->get()
            ->groupBy(static fn (object $row): string => (string) $row->cohort_key)
            ->map(static fn (Collection $rows): array => $rows->values()->all());
    }

    /**
     * Point every collection of a confirmed set at one shared identity.
     *
     * The rewritten key MUST be the one the ingest path computes for this cohort,
     * which is why it comes from ObfuscatedHashSetNormalizer::cohortKey() rather
     * than being derived from the par2 set id. The set id proves the cohort is a
     * single post, but it is not a key ingest can reproduce from a header: a
     * later article for this post would hash 'hashset:g..:t..:d..', miss a row
     * keyed on the set id, and mint a fresh stalled collection.
     *
     * Two uniqueness constraints govern this merge and are both handled rather
     * than assumed away:
     *
     *  - binaries has a UNIQUE index on (collections_id, filenumber), so two
     *    collections in one cohort claiming the same filenumber cannot be merged.
     *    Measured as zero occurrences across all 1,079 live par2 cohorts, but
     *    this is a growing stream, so such a cohort is skipped and reported
     *    instead of being allowed to throw mid-transaction.
     *  - collections.collectionhash is UNIQUE. A previous run may already own the
     *    target hash, so an existing owner is adopted as the survivor, which
     *    makes re-runs idempotent.
     *
     * @param  list<array<string,mixed>>  $resolved
     * @param  list<array<string,mixed>>  $skipped
     */
    private function applyRegrouping(array $resolved, array &$skipped): int
    {
        $count = 0;

        foreach ($resolved as $entry) {
            /** @var list<int> $collectionIds */
            $collectionIds = $entry['collections'];
            $hash = sha1((string) $entry['collection_key']);

            // Adopt an existing owner of this hash so a re-run converges instead
            // of colliding on the unique index.
            $existing = DB::table('collections')->where('collectionhash', $hash)->value('id');
            $survivor = $existing !== null ? (int) $existing : min($collectionIds);

            $absorbed = array_values(array_filter(
                $collectionIds,
                static fn (int $id): bool => $id !== $survivor
            ));

            if ($absorbed === []) {
                continue;
            }

            $conflict = $this->conflictingFileNumbers($survivor, $absorbed);
            if ($conflict !== []) {
                $skipped[] = [
                    'cohort' => $entry['cohort'],
                    'set_id' => $entry['set_id'],
                    'reason' => 'duplicate_filenumber',
                    'filenumbers' => $conflict,
                ];

                continue;
            }

            DB::transaction(function () use ($survivor, $absorbed, $hash, &$count): void {
                DB::table('binaries')
                    ->whereIn('collections_id', $absorbed)
                    ->update(['collections_id' => $survivor]);

                DB::table('collections')->where('id', $survivor)->update([
                    'collectionhash' => $hash,
                    'filecheck' => 0,
                    'dateadded' => now(),
                ]);

                DB::table('collections')->whereIn('id', $absorbed)->delete();

                $count += \count($absorbed) + 1;
            });
        }

        return $count;
    }

    /**
     * Filenumbers claimed by more than one collection in a proposed merge.
     *
     * @param  list<int>  $absorbed
     * @return list<int>
     */
    private function conflictingFileNumbers(int $survivor, array $absorbed): array
    {
        $ids = array_merge([$survivor], $absorbed);

        /** @var list<int> $dupes */
        $dupes = DB::table('binaries')
            ->whereIn('collections_id', $ids)
            ->groupBy('filenumber')
            ->havingRaw('count(*) > 1')
            ->pluck('filenumber')
            ->map(static fn (mixed $n): int => (int) $n)
            ->all();

        return $dupes;
    }

    /**
     * Every stalled collection sharing a cohort triple, par2 and rar alike.
     *
     * The cohort key is (groups_id, article unixtime, totalfiles); the par2 probe
     * only establishes that the triple corresponds to exactly one recovery set.
     *
     * @return list<int>
     */
    private function cohortMembership(string $cohortKey): array
    {
        [$groupId, $unixtime, $totalFiles] = array_pad(explode('|', $cohortKey), 3, '0');

        /** @var list<int> $ids */
        $ids = DB::table('collections')
            ->where('groups_id', (int) $groupId)
            ->where('totalfiles', (int) $totalFiles)
            ->where('filecheck', 0)
            ->whereRaw('unix_timestamp(date) = ?', [(int) $unixtime])
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
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
