<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Orchestrator\CurrentForwardWindowLineage;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RecoverCurrentForwardContinuation extends Command
{
    protected $signature = 'nntmux:current-forward-recover-continuation
                            {generation : Exact quarantined generation}
                            {--group-id= : Expected usenet_groups id}
                            {--expected-first= : Expected first article}
                            {--expected-last= : Expected last article}
                            {--expected-cursor= : Expected committed group cursor}
                            {--collections= : Comma-separated exact collection ids}
                            {--expected-parts= : Exact stored part count}
                            {--expected-binaries= : Exact binary count}
                            {--evidence-hash= : SHA-256 emitted by a dry run}
                            {--apply : Apply after every pinned value and safety gate matches}';

    protected $description = 'Dry-run and explicitly recover one zero-output ledger window into bounded continuation';

    public function handle(
        PipelineSnapshotRepository $snapshots,
        CurrentForwardWindowLineage $lineage,
        WorkerControlStateStore $state,
    ): int {
        $generation = (int) $this->argument('generation');
        $groupId = $this->requiredPositiveOption('group-id');
        $first = $this->requiredPositiveOption('expected-first');
        $last = $this->requiredPositiveOption('expected-last');
        $cursor = $this->requiredPositiveOption('expected-cursor');
        $expectedParts = $this->requiredPositiveOption('expected-parts');
        $expectedBinaries = $this->requiredPositiveOption('expected-binaries');
        $collectionIds = $this->collectionIds();
        if ($generation <= 0 || $last - $first + 1 !== 10_000 || $cursor !== $last || $collectionIds === []) {
            $this->error('Pinned generation, exact 10k range, committed cursor, and collections are required.');

            return self::FAILURE;
        }
        if (! $lineage->schemaReady()) {
            $this->error('Continuation schema is unavailable.');

            return self::FAILURE;
        }

        try {
            $evidence = $this->evidence($generation, $groupId, $first, $last, $cursor, $collectionIds);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (! $this->option('apply')) {
            $this->warn('Dry run only. Re-run with every expected value, --evidence-hash, and --apply.');

            return self::SUCCESS;
        }
        if (! $lineage->enabled()) {
            $this->error('Continuation feature flag is disabled.');

            return self::FAILURE;
        }
        $pinnedHash = strtolower(trim((string) $this->option('evidence-hash')));
        if (preg_match('/^[a-f0-9]{64}$/D', $pinnedHash) !== 1
            || ! hash_equals($pinnedHash, $evidence['evidence_hash'])
            || $evidence['parts'] !== $expectedParts
            || $evidence['binaries'] !== $expectedBinaries
            || $evidence['collections'] !== count($collectionIds)
        ) {
            $this->error('Pinned evidence hash or exact object counts do not match the dry run.');

            return self::FAILURE;
        }

        $previousSnapshot = $state->previousSnapshot();
        if ($previousSnapshot === null) {
            $this->error('No fresh orchestrator safety baseline is available; no state was changed.');

            return self::FAILURE;
        }
        $snapshot = $snapshots->capture($previousSnapshot);
        if (! $snapshot->telemetryIsValid()
            || ! $snapshot->hardSafetyPassed()
            || ! $snapshot->lowPressure
            || $snapshot->highPressure
            || $snapshot->databaseCurrentWaits !== 0
            || ! $snapshot->databaseAdmissionSafe
            || $snapshot->releasesBacklog !== 0
            || $snapshot->eligibleNzbs !== 0
        ) {
            $this->error('Current pipeline or database admission is unsafe; no state was changed.');

            return self::FAILURE;
        }

        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            $this->error('Recovery requires a top-level transaction.');

            return self::FAILURE;
        }
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        try {
            $result = $connection->transaction(function () use (
                $generation,
                $groupId,
                $first,
                $last,
                $cursor,
                $collectionIds,
                $expectedParts,
                $expectedBinaries,
                $pinnedHash,
                $lineage,
                $snapshot,
            ): array {
                $candidate = DB::table('current_forward_windows')->where('generation', $generation)->first();
                if ($candidate === null) {
                    throw new RuntimeException('Pinned generation disappeared.');
                }
                $settings = DB::table('settings')
                    ->whereIn('name', [
                        'orchestrator_bf_permit',
                        'orchestrator_bf_claimed',
                        'orchestrator_bf_completed',
                        'orchestrator_cf_permit',
                        'orchestrator_cf_claimed',
                        'orchestrator_cf_completed',
                        'orchestrator_cf_failed',
                    ])
                    ->orderBy('name')
                    ->lockForUpdate()
                    ->pluck('value', 'name');
                $bfClaimed = (int) ($settings['orchestrator_bf_claimed'] ?? 0);
                $cfClaimed = (int) ($settings['orchestrator_cf_claimed'] ?? 0);
                if ((int) ($settings['orchestrator_bf_permit'] ?? 0) !== 0
                    || (int) ($settings['orchestrator_cf_permit'] ?? 0) !== 0
                    || ($bfClaimed > 0 && $bfClaimed !== (int) ($settings['orchestrator_bf_completed'] ?? 0))
                    || ($cfClaimed > 0
                        && $cfClaimed !== (int) ($settings['orchestrator_cf_completed'] ?? 0)
                        && $cfClaimed !== (int) ($settings['orchestrator_cf_failed'] ?? 0))
                ) {
                    throw new RuntimeException('An input permit or claim became active during recovery.');
                }
                $source = DB::table('current_forward_sources')
                    ->where('id', $candidate->source_id)
                    ->lockForUpdate()
                    ->first();
                $window = DB::table('current_forward_windows')
                    ->where('id', $candidate->id)
                    ->lockForUpdate()
                    ->first();
                $group = DB::table('usenet_groups')->where('id', $groupId)->lockForUpdate()->first();
                if ($source === null
                    || $window === null
                    || $group === null
                    || (int) $window->generation !== $generation
                    || (string) $window->state !== 'QUARANTINED'
                    || (string) $window->failure_reason !== 'current_forward_zero_output'
                    || (int) $window->first_article !== $first
                    || (int) $window->last_article !== $last
                    || (int) $source->groups_id !== $groupId
                    || (int) $group->last_record !== $cursor
                    || (string) $source->state !== 'READY'
                    || (int) $source->strikes !== 1
                    || (string) $source->last_reason !== 'current_forward_zero_output'
                ) {
                    throw new RuntimeException('Ledger, source, or group state changed after the dry run.');
                }

                $fresh = $this->evidence(
                    $generation,
                    $groupId,
                    $first,
                    $last,
                    $cursor,
                    $collectionIds,
                    lockObjects: true,
                );
                if (! hash_equals($pinnedHash, $fresh['evidence_hash'])
                    || $fresh['parts'] !== $expectedParts
                    || $fresh['binaries'] !== $expectedBinaries
                ) {
                    throw new RuntimeException('Exact membership changed while acquiring recovery locks.');
                }
                // A legacy v174 quarantine had no continuation chain or
                // deadline. The explicit operator recovery creates that
                // bounded chain now; descendants may never extend this time.
                $deadline = time() + $lineage->deadlineSeconds();

                DB::table('current_forward_windows')->where('id', $window->id)->update([
                    'chain_root_id' => $window->id,
                    'parent_window_id' => null,
                    'chain_ordinal' => 1,
                    'continuation_deadline_at' => date('Y-m-d H:i:s', $deadline),
                    'state' => 'CONTINUATION_PENDING',
                    'failure_reason' => 'operator_recovered_exact_zero_output',
                    'settled_at' => null,
                    'updated_at' => now(),
                ]);
                $lineage->seedRecoveredWindow(
                    (int) $window->id,
                    $collectionIds,
                    $first,
                    $last,
                );
                $observation = $lineage->observe((int) $window->id);
                if ($observation['parts'] !== $expectedParts
                    || $observation['binaries'] !== $expectedBinaries
                    || $observation['collections'] !== count($collectionIds)
                    || $observation['releases'] !== 0
                    || $observation['unresolved_collections'] <= 0
                ) {
                    throw new RuntimeException('Recovered exact lineage does not prove a bounded partial cohort.');
                }
                $lineage->recordObservation(
                    (int) $window->id,
                    (int) $window->id,
                    1,
                    $observation,
                    'RECOVER',
                    'operator_recovered_exact_zero_output',
                    $observation['original_present_parts'],
                    $observation['parts'],
                    hash('sha256', json_encode([
                        $snapshot->partsBacklog,
                        $snapshot->binariesBacklog,
                        $snapshot->physicalCollectionsBacklog(),
                        $snapshot->databaseRowLockWaits,
                        $snapshot->observedAt,
                    ], JSON_THROW_ON_ERROR)),
                );
                DB::table('current_forward_sources')->where('id', $source->id)->update([
                    'strikes' => 0,
                    'last_reason' => 'operator_recovered_exact_zero_output',
                    'updated_at' => now(),
                ]);

                return [
                    'window_id' => (int) $window->id,
                    'generation' => $generation,
                    'state' => 'CONTINUATION_PENDING',
                    'deadline' => date(DATE_ATOM, $deadline),
                    'cohort_hash' => $observation['hash'],
                ];
            }, 1);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /** @param list<int> $collectionIds
     * @return array{generation:int,group_id:int,first:int,last:int,cursor:int,collections:int,binaries:int,parts:int,releases:int,evidence_hash:string}
     */
    private function evidence(
        int $generation,
        int $groupId,
        int $first,
        int $last,
        int $cursor,
        array $collectionIds,
        bool $lockObjects = false,
    ): array {
        $window = DB::table('current_forward_windows')->where('generation', $generation)->first();
        $source = $window === null ? null : DB::table('current_forward_sources')->where('id', $window->source_id)->first();
        $group = DB::table('usenet_groups')->where('id', $groupId)->first();
        if ($window === null
            || $source === null
            || $group === null
            || (string) $window->state !== 'QUARANTINED'
            || (string) $window->failure_reason !== 'current_forward_zero_output'
            || (int) $window->first_article !== $first
            || (int) $window->last_article !== $last
            || (int) $source->groups_id !== $groupId
            || (int) $group->last_record !== $cursor
        ) {
            throw new RuntimeException('Pinned quarantined window or group cursor does not match.');
        }
        $collectionQuery = DB::table('collections')
            ->whereIn('id', $collectionIds)
            ->where('groups_id', $groupId)
            ->orderBy('id');
        if ($lockObjects) {
            $collectionQuery->lockForUpdate();
        }
        $collections = $collectionQuery->get(['id', 'totalfiles', 'filecheck', 'releases_id']);
        if ($collections->count() !== count($collectionIds)
            || $collections->contains(static fn (object $row): bool => $row->releases_id !== null)
        ) {
            throw new RuntimeException('Pinned collections are missing, released, or outside the expected group.');
        }
        $partQuery = static fn () => DB::table('parts as p')
            ->join('binaries as b', 'b.id', '=', 'p.binaries_id')
            ->join('collections as c', 'c.id', '=', 'b.collections_id')
            ->where('c.groups_id', $groupId)
            ->whereBetween('p.number', [$first, $last])
            ->orderBy('p.binaries_id')
            ->orderBy('p.number');
        $partColumns = [
            'p.binaries_id',
            'b.collections_id',
            'p.number',
            'p.partnumber',
            'p.size',
            'p.messageid',
        ];
        $candidateParts = $partQuery()->get($partColumns);
        $derivedCollectionIds = $candidateParts->pluck('collections_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($derivedCollectionIds !== $collectionIds) {
            throw new RuntimeException('Pinned collections do not exactly match the article-range lineage.');
        }
        $binaryIds = $candidateParts->pluck('binaries_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $binaryQuery = DB::table('binaries')->whereIn('id', $binaryIds)->orderBy('id');
        if ($lockObjects) {
            $binaryQuery->lockForUpdate();
        }
        $binaries = $binaryQuery->get([
            'id',
            'collections_id',
            'filenumber',
            'totalparts',
            'currentparts',
            'partsize',
        ]);
        // The canonical recovery lock order is collection -> binary -> parts.
        // Under the explicitly pinned repeatable-read transaction, the final
        // locking read must reproduce the candidate membership exactly.
        $parts = $lockObjects
            ? $partQuery()->lockForUpdate()->get($partColumns)
            : $candidateParts;
        $lockedCollectionIds = $parts->pluck('collections_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $lockedBinaryIds = $parts->pluck('binaries_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($lockedCollectionIds !== $collectionIds || $lockedBinaryIds !== $binaryIds) {
            throw new RuntimeException('Exact membership changed while acquiring recovery locks.');
        }
        $context = hash_init('sha256');
        foreach ([
            ['window', $generation, $groupId, $first, $last, $cursor],
            ...$collections->map(static fn (object $row): array => ['collection', ...array_values((array) $row)])->all(),
            ...$binaries->map(static fn (object $row): array => ['binary', ...array_values((array) $row)])->all(),
            ...$parts->map(static fn (object $row): array => ['part', ...array_values((array) $row)])->all(),
        ] as $row) {
            hash_update($context, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }

        return [
            'generation' => $generation,
            'group_id' => $groupId,
            'first' => $first,
            'last' => $last,
            'cursor' => $cursor,
            'collections' => $collections->count(),
            'binaries' => $binaries->count(),
            'parts' => $parts->count(),
            'releases' => 0,
            'evidence_hash' => hash_final($context),
        ];
    }

    private function requiredPositiveOption(string $name): int
    {
        $raw = $this->option($name);

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : 0;
    }

    /** @return list<int> */
    private function collectionIds(): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string) $this->option('collections')),
        ), static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
