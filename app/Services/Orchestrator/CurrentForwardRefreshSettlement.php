<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CurrentForwardRefreshSettlement
{
    private readonly CurrentForwardWindowLineage $lineage;

    public function __construct(
        private readonly PipelineSnapshotRepository $snapshots,
        ?CurrentForwardWindowLineage $lineage = null,
    ) {
        $this->lineage = $lineage ?? new CurrentForwardWindowLineage;
    }

    /**
     * @return array{
     *   release_count:int,release_high:int,pending_collections:int,
     *   counts:array{target:int,non_target:int,uncategorized:int},
     *   bytes:array{target:int,non_target:int,uncategorized:int},hash:string
     * }
     */
    private function cohortObservation(
        object $window,
        string $group,
        string $startPostdate,
        string $endPostdate,
    ): array {
        $rootId = $this->chainRootId($window);
        if ($this->lineage->enabled() && $this->lineage->schemaReady() && $rootId > 0) {
            $exact = $this->lineage->observe($rootId);
            $quality = $this->snapshots->currentForwardReleaseQualityForIds(
                $exact['release_ids'],
                $group,
            );

            return [
                'release_count' => $exact['releases'],
                'release_high' => $exact['release_high'],
                // A chain cannot be productive while any original exact
                // collection remains unresolved. At the pinned drain deadline
                // this condition is routed into the bounded continuation
                // decision rather than silently credited as productive.
                'pending_collections' => $exact['unresolved_collections'],
                'counts' => $quality['counts'],
                'bytes' => $quality['bytes'],
                'hash' => hash('sha256', json_encode([
                    'lineage' => $exact['hash'],
                    'quality' => $quality,
                ], JSON_THROW_ON_ERROR)),
            ];
        }

        return $this->snapshots->currentForwardCohortObservation(
            $group,
            (int) $window->release_baseline,
            $startPostdate,
            $endPostdate,
        );
    }

    /** @return array{status:string,reason:string,generation:int,ready_nzbs:int}|null */
    private function continueExactPartialCohort(
        object $window,
        ?object $source,
        PipelineSnapshot $snapshot,
        int $now,
    ): ?array {
        if (! $this->lineage->enabled() || ! $this->lineage->schemaReady() || $source === null) {
            return null;
        }

        $rootId = $this->chainRootId($window);
        $ordinal = max(1, (int) ($window->chain_ordinal ?? 1));
        $observation = $this->lineage->observe($rootId);
        if ($observation['parts'] <= 0
            || $observation['binaries'] <= 0
            || $observation['collections'] <= 0
            || $observation['unresolved_collections'] <= 0
        ) {
            return null;
        }

        $root = DB::table('current_forward_windows')->where('id', $rootId)->first();
        if ($root === null) {
            return null;
        }
        $deadline = strtotime((string) ($root->continuation_deadline_at ?? ''));
        if ($deadline === false) {
            $rootIngested = strtotime((string) ($root->ingested_at ?? ''));
            $deadline = ($rootIngested === false ? $now : $rootIngested) + $this->lineage->deadlineSeconds();
        }
        if ($now >= $deadline) {
            return $this->quarantine($window, $source, 'current_forward_continuation_deadline', strike: false);
        }
        if ($ordinal >= $this->lineage->maxWindows()) {
            return $this->quarantine($window, $source, 'current_forward_continuation_chain_exhausted');
        }

        $baselinePresent = $ordinal === 1
            ? $observation['original_present_parts']
            : $this->lineage->priorPresentParts($rootId);
        $progress = $ordinal === 1
            ? $observation['parts']
            : max(0, $observation['original_present_parts'] - $baselinePresent);
        $windowInsertedParts = (int) DB::table('current_forward_window_objects')
            ->where('window_id', $window->id)
            ->where('object_type', CurrentForwardWindowLineage::BINARY)
            ->sum('inserted_parts');
        $minimumProgress = max(
            $this->lineage->minimumProgressParts(),
            (int) ceil(max(1, $windowInsertedParts) * 0.01),
        );
        if ($ordinal > 1 && $progress < $minimumProgress) {
            return $this->quarantine($window, $source, 'current_forward_continuation_no_progress');
        }

        $pipelineHash = hash('sha256', json_encode([
            $snapshot->partsBacklog,
            $snapshot->binariesBacklog,
            $snapshot->physicalCollectionsBacklog(),
            $snapshot->releasesBacklog,
            $snapshot->eligibleNzbs,
            $snapshot->databaseRowLockWaits,
            $snapshot->observedAt,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $window,
            $source,
            $rootId,
            $ordinal,
            $deadline,
            $observation,
            $baselinePresent,
            $progress,
            $pipelineHash,
        ): array {
            $lockedSource = DB::table('current_forward_sources')
                ->where('id', $source->id)
                ->lockForUpdate()
                ->first();
            $lockedRoot = DB::table('current_forward_windows')
                ->where('id', $rootId)
                ->lockForUpdate()
                ->first();
            $lockedWindow = (int) $window->id === $rootId
                ? $lockedRoot
                : DB::table('current_forward_windows')
                    ->where('id', $window->id)
                    ->lockForUpdate()
                    ->first();
            if ($lockedSource === null
                || (string) $lockedSource->state !== 'READY'
                || $lockedRoot === null
                || $lockedWindow === null
                || (string) $lockedWindow->state !== 'ATTRIBUTING'
                || ((int) $window->id !== $rootId && (string) $lockedRoot->state !== 'CONTINUATION_PENDING')
            ) {
                return $this->result(
                    'pending',
                    'current_forward_settlement_race_lost',
                    (int) $window->generation,
                );
            }

            if ((int) $window->id !== $rootId) {
                DB::table('current_forward_windows')->where('id', $window->id)->update([
                    'state' => 'CHAINED',
                    'failure_reason' => null,
                    'settled_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('current_forward_windows')->where('id', $rootId)->update([
                'state' => 'CONTINUATION_PENDING',
                'continuation_deadline_at' => date('Y-m-d H:i:s', $deadline),
                'failure_reason' => null,
                'settled_at' => null,
                'updated_at' => now(),
            ]);
            $this->lineage->recordObservation(
                $rootId,
                (int) $window->id,
                $ordinal,
                $observation,
                'CONTINUE',
                'current_forward_exact_partial_progress',
                $baselinePresent,
                $progress,
                $pipelineHash,
            );

            return $this->result(
                'continuation',
                'current_forward_continuation_pending',
                (int) $window->generation,
            );
        }, 3);
    }

    /**
     * @return array{status:string,reason:string,generation:int,ready_nzbs:int}
     */
    public function settle(PipelineSnapshot $snapshot, int $now): array
    {
        if (! Schema::hasTable('current_forward_sources')
            || ! Schema::hasTable('current_forward_windows')
        ) {
            return $this->result('none', 'current_forward_refresh_schema_absent');
        }

        $expiredContinuation = $this->expireOrDisablePendingContinuation($now);
        if ($expiredContinuation !== null) {
            return $expiredContinuation;
        }

        $candidate = DB::table('current_forward_windows')
            ->whereIn('state', ['INGESTED', 'ATTRIBUTING'])
            ->orderBy('id')
            ->first();
        $window = DB::transaction(function () use ($candidate, $now): ?object {
            if ($candidate === null) {
                return null;
            }
            DB::table('current_forward_sources')
                ->where('id', $candidate->source_id)
                ->lockForUpdate()
                ->first();
            $window = DB::table('current_forward_windows')
                ->where('id', $candidate->id)
                ->lockForUpdate()
                ->first();
            if ($window === null) {
                return null;
            }
            if (! in_array((string) $window->state, ['INGESTED', 'ATTRIBUTING'], true)) {
                return null;
            }
            if ((string) $window->state === 'INGESTED') {
                $startedAt = date('Y-m-d H:i:s', $now);
                $ingestedAt = strtotime((string) $window->ingested_at);
                $deadlineBase = $ingestedAt === false ? $now : $ingestedAt;
                $updates = [
                    'state' => 'ATTRIBUTING',
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('current_forward_windows', 'attribution_started_at')) {
                    $updates['attribution_started_at'] = $startedAt;
                    $updates['zero_output_deadline_at'] = date(
                        'Y-m-d H:i:s',
                        $deadlineBase + $this->zeroOutputGraceSeconds(),
                    );
                    $updates['drain_deadline_at'] = date(
                        'Y-m-d H:i:s',
                        $deadlineBase + $this->incompleteGraceSeconds(),
                    );
                }
                DB::table('current_forward_windows')
                    ->where('id', $window->id)
                    ->where('state', 'INGESTED')
                    ->update($updates);
                $window->state = 'ATTRIBUTING';
                $window->attribution_started = true;
            }

            return $window;
        }, 3);
        if ($window === null) {
            return $this->result('none', 'current_forward_no_unsettled_ingest');
        }
        $generation = (int) $window->generation;
        if (($window->attribution_started ?? false) === true) {
            return $this->result(
                'pending',
                'current_forward_attribution_started',
                $generation,
            );
        }
        $rootId = $this->chainRootId($window);
        if ($this->lineage->enabled() && $this->lineage->schemaReady() && $rootId > 0) {
            $integrity = $this->lineage->integrity($rootId);
            if (! $integrity['integrity_ok']) {
                $source = DB::table('current_forward_sources')->where('id', $window->source_id)->first();

                return $this->quarantine(
                    $window,
                    $source,
                    'current_forward_lineage_integrity_failure',
                    strike: false,
                );
            }
        }
        if (! $this->pipelineAllowsSettlement($snapshot)) {
            if ($this->drainDeadlineReached($window, $now)) {
                $source = DB::table('current_forward_sources')->where('id', $window->source_id)->first();

                return $this->quarantine(
                    $window,
                    $source,
                    'current_forward_pipeline_settlement_timeout',
                    strike: false,
                );
            }

            return $this->result(
                'pending',
                'current_forward_pipeline_not_drained',
                $generation,
            );
        }

        $source = DB::table('current_forward_sources')->where('id', $window->source_id)->first();
        $group = trim((string) ($source->group_name ?? ''));
        $startPostdate = (string) ($window->cursor_postdate ?? '');
        $endPostdate = (string) ($window->cursor_end_postdate ?? '');
        if ($source === null
            || $group === ''
            || $generation <= 0
            || $window->release_baseline === null
            || (int) $window->release_baseline < 0
            || strtotime((string) $window->ingested_at) === false
            || strtotime($startPostdate) === false
            || strtotime($endPostdate) === false
        ) {
            return $this->quarantine($window, $source, 'current_forward_cohort_bounds_missing');
        }

        $observation = $this->cohortObservation($window, $group, $startPostdate, $endPostdate);
        $createdReleases = $observation['release_count'];
        $pendingCollections = $observation['pending_collections'];
        $counts = $observation['counts'];
        $bytes = $observation['bytes'];
        $classifiedNzbs = array_sum($counts);
        $targetNzbs = max(0, (int) $counts['target']);
        $targetBytes = max(0, (int) $bytes['target']);
        $nonTargetBytes = max(0, (int) $bytes['non_target']) + max(0, (int) $bytes['uncategorized']);
        if ($pendingCollections > 0) {
            if ($this->drainDeadlineReached($window, $now)) {
                $continuation = $this->continueExactPartialCohort($window, $source, $snapshot, $now);
                if ($continuation !== null) {
                    return $continuation;
                }

                return $this->quarantine($window, $source, 'current_forward_cohort_drain_timeout');
            }

            return $this->result(
                'pending',
                'current_forward_cohort_draining',
                $generation,
            );
        }

        $incomplete = $createdReleases > $classifiedNzbs || (int) $counts['uncategorized'] > 0;
        if ($incomplete) {
            if ($this->drainDeadlineReached($window, $now)) {
                return $this->quarantine($window, $source, 'current_forward_incomplete_after_grace');
            }

            return $this->result(
                'pending',
                'current_forward_nzbs_incomplete',
                $generation,
            );
        }
        if ($targetNzbs === 0 && $createdReleases === 0) {
            if ($this->zeroOutputDeadlineReached($window, $now)) {
                $continuation = $this->continueExactPartialCohort($window, $source, $snapshot, $now);
                if ($continuation !== null) {
                    return $continuation;
                }

                return $this->quarantine($window, $source, 'current_forward_zero_output');
            }

            return $this->result(
                'pending',
                'current_forward_zero_output_grace',
                $generation,
            );
        }
        if (! $this->meetsTargetQuality($counts, $bytes)) {
            if ($this->drainDeadlineReached($window, $now)) {
                return $this->quarantine($window, $source, 'current_forward_wrong_category');
            }

            return $this->result(
                'pending',
                'current_forward_quality_grace',
                $generation,
            );
        }
        if ($createdReleases !== $classifiedNzbs) {
            if ($this->drainDeadlineReached($window, $now)) {
                return $this->quarantine($window, $source, 'current_forward_release_accounting_timeout');
            }

            return $this->result(
                'pending',
                'current_forward_release_accounting_unstable',
                $generation,
            );
        }

        $outcomes = [
            'outcome_releases' => $createdReleases,
            'outcome_ready_nzbs' => $targetNzbs,
            'outcome_target_bytes' => $targetBytes,
            'outcome_non_target_bytes' => $nonTargetBytes,
            'outcome_release_high' => $observation['release_high'],
            'outcome_pending_collections' => $pendingCollections,
            'observation_hash' => $observation['hash'],
            'last_observed_at' => date('Y-m-d H:i:s', $now),
        ];
        $stableSince = strtotime((string) ($window->observation_stable_since_at ?? ''));
        $unchanged = $stableSince !== false
            && (string) ($window->observation_hash ?? '') === $observation['hash'];
        if (! $unchanged) {
            DB::table('current_forward_windows')
                ->where('id', $window->id)
                ->where('state', 'ATTRIBUTING')
                ->update([
                    ...$outcomes,
                    'observation_stable_since_at' => date('Y-m-d H:i:s', $now),
                    'updated_at' => now(),
                ]);

            return $this->result(
                'pending',
                'current_forward_productive_stabilizing',
                $generation,
                $targetNzbs,
            );
        }
        if ($now - $stableSince < $this->settlementGraceSeconds()) {
            return $this->result(
                'pending',
                'current_forward_productive_stabilizing',
                $generation,
                $targetNzbs,
            );
        }

        $confirmed = $this->cohortObservation($window, $group, $startPostdate, $endPostdate);
        $rootId = $this->chainRootId($window);

        return DB::transaction(function () use (
            $window,
            $source,
            $outcomes,
            $generation,
            $targetNzbs,
            $confirmed,
            $now,
            $rootId,
            $group,
            $startPostdate,
            $endPostdate,
        ): array {
            $lockedSource = DB::table('current_forward_sources')
                ->where('id', $source->id)
                ->lockForUpdate()
                ->first();
            $lockedRoot = DB::table('current_forward_windows')
                ->where('id', $rootId)
                ->lockForUpdate()
                ->first();
            $locked = $rootId === (int) $window->id
                ? $lockedRoot
                : DB::table('current_forward_windows')
                    ->where('id', $window->id)
                    ->lockForUpdate()
                    ->first();
            if ($locked === null || (string) $locked->state !== 'ATTRIBUTING') {
                return $this->result('pending', 'current_forward_settlement_race_lost', $generation);
            }
            if ($lockedRoot === null
                || ($rootId !== (int) $locked->id && (string) $lockedRoot->state !== 'CONTINUATION_PENDING')
            ) {
                return $this->result('pending', 'current_forward_settlement_race_lost', $generation);
            }
            $terminalObservation = $confirmed;
            if ($this->lineage->enabled() && $this->lineage->schemaReady() && $rootId > 0) {
                // Release attribution participates in this same root lock.
                // Re-observe exact lineage here so a concurrent release either
                // commits before this read or sees the terminal root and rolls
                // its whole release transaction back.
                $terminalObservation = $this->cohortObservation(
                    $locked,
                    $group,
                    $startPostdate,
                    $endPostdate,
                );
            }
            if (! hash_equals((string) $locked->observation_hash, $terminalObservation['hash'])) {
                DB::table('current_forward_windows')->where('id', $window->id)->update([
                    'observation_hash' => $terminalObservation['hash'],
                    'observation_stable_since_at' => date('Y-m-d H:i:s', $now),
                    'last_observed_at' => date('Y-m-d H:i:s', $now),
                    'outcome_release_high' => $terminalObservation['release_high'],
                    'outcome_pending_collections' => $terminalObservation['pending_collections'],
                    'updated_at' => now(),
                ]);

                return $this->result(
                    'pending',
                    'current_forward_productive_stabilizing',
                    $generation,
                    (int) $terminalObservation['counts']['target'],
                );
            }
            if ($lockedSource === null || (string) $lockedSource->state !== 'READY') {
                DB::table('current_forward_windows')->where('id', $window->id)->update([
                    'state' => 'QUARANTINED',
                    'failure_reason' => 'current_forward_source_locked_at_settlement',
                    'settled_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($lockedSource !== null) {
                    DB::table('current_forward_sources')->where('id', $source->id)->update([
                        'last_reason' => 'current_forward_source_locked_at_settlement',
                        'updated_at' => now(),
                    ]);
                }

                return $this->result(
                    'quarantined',
                    'current_forward_source_locked_at_settlement',
                    $generation,
                );
            }
            if ($rootId !== (int) $locked->id) {
                DB::table('current_forward_windows')->where('id', $locked->id)->update([
                    'state' => 'CHAINED',
                    'failure_reason' => null,
                    'settled_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('current_forward_windows')->where('id', $rootId)->update([
                ...$outcomes,
                'state' => 'PRODUCTIVE',
                'failure_reason' => null,
                'settled_at' => now(),
                'updated_at' => now(),
            ]);
            $sourceUpdates = [
                'state' => 'READY',
                'strikes' => 0,
                'last_productive_generation' => $generation,
                'last_productive_at' => now(),
                'last_reason' => null,
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('current_forward_sources', 'last_productive_release_id')) {
                $sourceUpdates['last_productive_release_id'] = $terminalObservation['release_high'];
            }
            DB::table('current_forward_sources')->where('id', $source->id)->update($sourceUpdates);

            return $this->result(
                'productive',
                'current_forward_productive',
                $generation,
                $targetNzbs,
            );
        }, 3);
    }

    /** @return array{status:string,reason:string,generation:int,ready_nzbs:int}|null */
    private function expireOrDisablePendingContinuation(int $now): ?array
    {
        if (! $this->lineage->schemaReady()
            || ! Schema::hasColumn('current_forward_windows', 'continuation_deadline_at')
        ) {
            return null;
        }

        $query = DB::table('current_forward_windows')
            ->where('state', 'CONTINUATION_PENDING')
            ->orderBy('id');
        if ($this->lineage->enabled()) {
            $query->whereNotNull('continuation_deadline_at')
                ->where('continuation_deadline_at', '<=', date('Y-m-d H:i:s', $now));
        }
        $candidate = $query->first();
        if ($candidate === null) {
            return null;
        }

        return DB::transaction(function () use ($candidate, $now): array {
            $settings = Schema::hasTable('settings')
                ? DB::table('settings')
                    ->whereIn('name', [
                        'orchestrator_cf_permit',
                        'orchestrator_cf_claimed',
                        'orchestrator_cf_failed',
                        'orchestrator_cf_failure',
                    ])
                    ->orderBy('name')
                    ->lockForUpdate()
                    ->pluck('value', 'name')
                : collect();
            $source = DB::table('current_forward_sources')
                ->where('id', $candidate->source_id)
                ->lockForUpdate()
                ->first();
            $root = DB::table('current_forward_windows')
                ->where('id', $candidate->id)
                ->lockForUpdate()
                ->first();
            $deadline = strtotime((string) ($root->continuation_deadline_at ?? ''));
            $disabled = ! $this->lineage->enabled();
            if ($root === null
                || (string) $root->state !== 'CONTINUATION_PENDING'
                || (! $disabled && ($deadline === false || $now < $deadline))
            ) {
                return $this->result(
                    'pending',
                    'current_forward_settlement_race_lost',
                    (int) ($candidate->generation ?? 0),
                );
            }

            $reason = $disabled
                ? 'current_forward_continuation_disabled'
                : 'current_forward_continuation_admission_timeout';
            $members = DB::table('current_forward_windows')
                ->where('chain_root_id', $root->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $memberGenerations = $members->pluck('generation')
                ->map(static fn (mixed $generation): int => (int) $generation)
                ->filter(static fn (int $generation): bool => $generation > 0)
                ->values()
                ->all();
            DB::table('current_forward_windows')
                ->whereIn('id', $members->pluck('id')->all())
                ->whereIn('state', [
                    'OFFERED',
                    'CLAIMED',
                    'INGESTED',
                    'ATTRIBUTING',
                    'CONTINUATION_PENDING',
                    'CHAINED',
                ])
                ->update([
                    'state' => 'QUARANTINED',
                    'failure_reason' => $reason,
                    'settled_at' => now(),
                    'updated_at' => now(),
                ]);
            $permit = (int) ($settings['orchestrator_cf_permit'] ?? 0);
            $claimed = (int) ($settings['orchestrator_cf_claimed'] ?? 0);
            if ($permit > 0 && in_array($permit, $memberGenerations, true)) {
                DB::table('settings')->where('name', 'orchestrator_cf_permit')->update(['value' => '0']);
            }
            if ($claimed > 0 && in_array($claimed, $memberGenerations, true)) {
                DB::table('settings')->updateOrInsert(
                    ['name' => 'orchestrator_cf_failed'],
                    ['value' => (string) $claimed],
                );
                DB::table('settings')->updateOrInsert(
                    ['name' => 'orchestrator_cf_failure'],
                    ['value' => $reason],
                );
            }
            if ($source !== null) {
                DB::table('current_forward_sources')->where('id', $source->id)->update([
                    'last_reason' => $reason,
                    'updated_at' => now(),
                ]);
            }

            return $this->result('quarantined', $reason, (int) ($root->generation ?? 0));
        }, 3);
    }

    /** @param array{target:int,non_target:int,uncategorized:int} $counts
     * @param  array{target:int,non_target:int,uncategorized:int}  $bytes
     */
    private function meetsTargetQuality(array $counts, array $bytes): bool
    {
        $targetBytes = max(0, (int) $bytes['target']);
        $nonTargetBytes = max(0, (int) $bytes['non_target']);
        $uncategorizedBytes = max(0, (int) $bytes['uncategorized']);
        $totalBytes = $targetBytes + $nonTargetBytes + $uncategorizedBytes;
        $targetShare = $totalBytes > 0 ? $targetBytes / $totalBytes : 0.0;

        return (int) $counts['target'] > 0
            && (int) $counts['uncategorized'] === 0
            && (int) $counts['non_target'] <= (int) config('nntmux.orchestrator.backfill_max_non_target_releases', 0)
            && $nonTargetBytes <= (int) config('nntmux.orchestrator.backfill_max_non_target_bytes', 0)
            && $targetShare >= (float) config('nntmux.orchestrator.backfill_min_target_byte_share', 1.0);
    }

    private function pipelineAllowsSettlement(PipelineSnapshot $snapshot): bool
    {
        return $snapshot->telemetryIsValid()
            && $snapshot->hardSafetyPassed()
            && ! $snapshot->highPressure
            && $snapshot->databaseCurrentWaits === 0
            && $snapshot->databaseAdmissionSafe
            && $snapshot->releasesBacklog === 0
            && $snapshot->eligibleNzbs === 0;
    }

    /** @return array{status:string,reason:string,generation:int,ready_nzbs:int} */
    private function quarantine(object $window, ?object $source, string $reason, bool $strike = true): array
    {
        $rootId = $this->chainRootId($window);

        return DB::transaction(function () use ($window, $source, $reason, $strike, $rootId): array {
            $lockedSource = $source === null
                ? null
                : DB::table('current_forward_sources')
                    ->where('id', $source->id)
                    ->lockForUpdate()
                    ->first();
            $lockedRoot = DB::table('current_forward_windows')
                ->where('id', $rootId)
                ->lockForUpdate()
                ->first();
            $locked = $rootId === (int) $window->id
                ? $lockedRoot
                : DB::table('current_forward_windows')
                    ->where('id', $window->id)
                    ->lockForUpdate()
                    ->first();
            if ($locked === null || ! in_array((string) $locked->state, ['INGESTED', 'ATTRIBUTING'], true)) {
                return $this->result(
                    'pending',
                    'current_forward_settlement_race_lost',
                    (int) $window->generation,
                );
            }
            if ($lockedRoot === null) {
                return $this->result(
                    'pending',
                    'current_forward_settlement_race_lost',
                    (int) $window->generation,
                );
            }
            DB::table('current_forward_windows')
                ->where(static fn ($query) => $query
                    ->where('id', $rootId)
                    ->orWhere('chain_root_id', $rootId))
                ->whereIn('state', [
                    'OFFERED',
                    'CLAIMED',
                    'INGESTED',
                    'ATTRIBUTING',
                    'CONTINUATION_PENDING',
                    'CHAINED',
                ])
                ->update([
                    'state' => 'QUARANTINED',
                    'failure_reason' => $reason,
                    'settled_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($strike && $source !== null && $lockedSource !== null) {
                if (in_array((string) $lockedSource->state, ['HALTED', 'QUALITY_LOCKED'], true)) {
                    DB::table('current_forward_sources')->where('id', $source->id)->update([
                        'last_reason' => $reason,
                        'updated_at' => now(),
                    ]);
                } else {
                    $strikes = min(2, (int) $lockedSource->strikes + 1);
                    DB::table('current_forward_sources')->where('id', $source->id)->update([
                        'state' => $strikes >= 2 ? 'QUALITY_LOCKED' : 'READY',
                        'strikes' => $strikes,
                        'last_reason' => $reason,
                        'updated_at' => now(),
                    ]);
                }
            }

            return $this->result(
                'quarantined',
                $reason,
                (int) $window->generation,
            );
        }, 3);
    }

    private function chainRootId(object $window): int
    {
        $root = property_exists($window, 'chain_root_id')
            ? (int) ($window->chain_root_id ?? 0)
            : 0;

        return $root > 0 ? $root : (int) ($window->id ?? 0);
    }

    private function drainDeadlineReached(object $window, int $now): bool
    {
        $deadline = strtotime((string) ($window->drain_deadline_at ?? ''));

        return $deadline !== false
            ? $now >= $deadline
            : $now - strtotime((string) $window->ingested_at) >= $this->incompleteGraceSeconds();
    }

    private function zeroOutputDeadlineReached(object $window, int $now): bool
    {
        $deadline = strtotime((string) ($window->zero_output_deadline_at ?? ''));

        return $deadline !== false
            ? $now >= $deadline
            : $now - strtotime((string) $window->ingested_at) >= $this->zeroOutputGraceSeconds();
    }

    private function settlementGraceSeconds(): int
    {
        return min(600, max(30, (int) config(
            'nntmux.orchestrator.current_forward_settlement_grace_seconds',
            120,
        )));
    }

    private function zeroOutputGraceSeconds(): int
    {
        return min(1800, max(300, (int) config(
            'nntmux.orchestrator.current_forward_zero_output_grace_seconds',
            600,
        )));
    }

    private function incompleteGraceSeconds(): int
    {
        return min(3600, max(600, (int) config(
            'nntmux.orchestrator.current_forward_incomplete_grace_seconds',
            900,
        )));
    }

    /** @return array{status:string,reason:string,generation:int,ready_nzbs:int} */
    private function result(
        string $status,
        string $reason,
        int $generation = 0,
        int $readyNzbs = 0,
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'generation' => $generation,
            'ready_nzbs' => $readyNzbs,
        ];
    }
}
