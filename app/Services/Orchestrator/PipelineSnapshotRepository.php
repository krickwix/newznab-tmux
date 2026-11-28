<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Nzb\NzbBacklogCreationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class PipelineSnapshotRepository
{
    private const int BACKFILL_CANDIDATE_LIMIT = 16;

    private const string BACKFILL_TV_EPISODE_PATTERN = '(^|[^[:alnum:]])s[0-9]{1,2}[ ._-]*e[0-9]{1,3}([^[:alnum:]]|$)';

    private const string BACKFILL_TV_DATE_RANGE_PATTERN = '(^|[^[:alnum:]])(0?[1-9]|[12][0-9]|3[01])\.-(0?[1-9]|[12][0-9]|3[01])\.(0?[1-9]|1[0-2])\.[0-9]{2}([^[:alnum:]]|$)';

    private const string BACKFILL_TV_COMPLETE_SERIES_PATTERN = '(^|[^[:alnum:]])komplett[ ._-]+abenteuerserie[ ._-]+(19|20)[0-9]{2}([^[:alnum:]]|$).*(avi|mkv|mp4|mpeg|xvid|divx|h\\.?26[45])([^[:alnum:]]|$)';

    /**
     * The `sb` derived table of capture()'s pipeline statement, hoisted so the
     * equivalence test can run it beside the join form it replaced.
     */
    private const string SCHEDULABLE_BINARIES_SQL = '(SELECT GREATEST(0, CAST(ab.pending AS SIGNED) - CAST(xb.pending AS SIGNED)) AS schedulable_binaries_backlog,
                    GREATEST(0, CAST(ab.parts AS SIGNED) - CAST(xb.parts AS SIGNED)) AS schedulable_parts_backlog,
                    ab.pending AS binaries_backlog,
                    oba.oldest_binary_age
                FROM (SELECT COUNT(*) AS pending, COALESCE(SUM(currentparts), 0) AS parts
                        FROM binaries WHERE partcheck = 0) AS ab
                CROSS JOIN (SELECT COALESCE(SUM(x.pending), 0) AS pending, COALESCE(SUM(x.parts), 0) AS parts FROM (
                        SELECT COUNT(*) AS pending, COALESCE(SUM(b.currentparts), 0) AS parts
                            FROM binaries b INNER JOIN collections c ON c.id = b.collections_id
                            WHERE b.partcheck = 0 AND c.filecheck NOT IN (0, 1, 2, 15, 16)
                        UNION ALL
                        SELECT STRAIGHT_JOIN COUNT(*) AS pending, COALESCE(SUM(b.currentparts), 0) AS parts
                            FROM usenet_groups g
                            INNER JOIN collections c ON c.groups_id = g.id
                            INNER JOIN binaries b ON b.collections_id = c.id
                            WHERE g.active <> 1 AND g.backfill <> 1
                              AND c.filecheck IN (0, 1, 2, 15, 16) AND b.partcheck = 0
                        UNION ALL
                        SELECT STRAIGHT_JOIN COUNT(*) AS pending, COALESCE(SUM(b.currentparts), 0) AS parts
                            FROM (SELECT dg.groups_id FROM (SELECT DISTINCT groups_id FROM collections) dg
                                    LEFT JOIN usenet_groups g ON g.id = dg.groups_id
                                    WHERE g.id IS NULL) og
                            INNER JOIN collections c ON c.groups_id = og.groups_id
                            INNER JOIN binaries b ON b.collections_id = c.id
                            WHERE c.filecheck IN (0, 1, 2, 15, 16) AND b.partcheck = 0) AS x) AS xb
                CROSS JOIN (SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(p.dateadded), NOW()), 0) AS oldest_binary_age FROM (
                        SELECT (SELECT c.dateadded FROM collections c
                                WHERE c.groups_id = g.id AND c.filecheck = f.stage AND c.dateadded IS NOT NULL
                                  AND (SELECT 1 FROM binaries b
                                        WHERE b.collections_id = c.id AND b.partcheck = 0 LIMIT 1) IS NOT NULL
                                ORDER BY c.dateadded LIMIT 1) AS dateadded
                            FROM usenet_groups g
                            CROSS JOIN (SELECT 0 AS stage UNION ALL SELECT 1 UNION ALL SELECT 2
                                UNION ALL SELECT 15 UNION ALL SELECT 16) AS f
                            WHERE g.active = 1 OR g.backfill = 1) AS p) AS oba) AS sb';

    private readonly BackfillTargetSelector $targets;

    private readonly WorkerControlStateStore $state;

    private readonly PipelinePressureClassifier $pressure;

    private readonly ?BackfillDateEligibilityPolicy $eligibility;

    private readonly BackfillWindow $window;

    public function __construct(
        private readonly PrometheusSafetySignalProvider $safety,
        private readonly NzbBacklogCreationService $nzbBacklog,
        ?BackfillTargetSelector $targets = null,
        ?WorkerControlStateStore $state = null,
        ?PipelinePressureClassifier $pressure = null,
        ?BackfillDateEligibilityPolicy $eligibility = null,
        ?BackfillWindow $window = null,
    ) {
        $this->window = $window ?? new BackfillWindow;
        $this->targets = $targets ?? new BackfillTargetSelector;
        $this->state = $state ?? new WorkerControlStateStore;
        $this->pressure = $pressure ?? new PipelinePressureClassifier;
        $this->eligibility = $eligibility;
    }

    /** @param array<string, int|float>|null $previous */
    public function capture(?array $previous = null): PipelineSnapshot
    {
        return $this->withStatementTimeout(fn (): PipelineSnapshot => $this->captureSnapshot($previous));
    }

    /**
     * Bound every statement this snapshot issues, so a bad plan fails the
     * cycle instead of wedging it.
     *
     * On 2026-11-29 the pipeline statement ran 3,801 s on live from ~10:26
     * UTC. Nothing threw, so nothing failed closed: the orchestrator sat
     * inside capture() holding the leader lock while its own lease expired,
     * and every lane fell back to its fail-safe timer. The orchestrator had to
     * be rolled back by hand and the query killed.
     *
     * A capture that *errors* was already safe -- WorkerOrchestrator::decide()
     * catches Throwable, calls failClosed(), and releases the leader lock in
     * its finally. The failure mode that was not covered is a statement that
     * neither returns nor fails. max_statement_time converts that into an
     * exception, which turns an unbounded wedge into one skipped cycle on a
     * path that already exists.
     *
     * Session-scoped rather than per-statement text: capture() reaches a dozen
     * helpers that issue their own queries, and a session bound covers every
     * one without touching a single statement -- which also keeps the sqlite
     * tests of those statements working. The previous value is restored in a
     * finally, because the orchestrator is a long-running process and this
     * connection outlives the cycle.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function withStatementTimeout(callable $work): mixed
    {
        $seconds = max(0, (int) config('nntmux.orchestrator.snapshot_statement_timeout_seconds', 20));

        if ($seconds <= 0) {
            return $work();
        }

        $connection = null;
        $previous = null;

        // Arming the bound must never be able to break the capture it is
        // protecting -- a guard that can throw is worse than no guard. Real
        // MySQL has max_execution_time in milliseconds rather than
        // max_statement_time, and a connection can be anything in a test, so
        // every step here is allowed to fail into running unbounded, which is
        // exactly the behaviour that existed before this guard. $work() is
        // deliberately outside this try so a failing capture cannot be
        // mistaken for a failing probe and run twice.
        try {
            $candidate = DB::connection();

            if (in_array($candidate->getDriverName(), ['mysql', 'mariadb'], true)) {
                $previous = $candidate->scalar('SELECT @@SESSION.max_statement_time');
                $candidate->statement('SET SESSION max_statement_time = '.$seconds);
                $connection = $candidate;
            }
        } catch (Throwable $e) {
            Log::warning('Snapshot statement timeout could not be applied', [
                'error' => $e->getMessage(),
            ]);
            $connection = null;
        }

        if ($connection === null) {
            return $work();
        }

        try {
            return $work();
        } finally {
            try {
                $connection->statement('SET SESSION max_statement_time = '.(float) $previous);
            } catch (Throwable $e) {
                // A bound left behind caps every later query on this
                // connection, so this is louder than the arming failure.
                Log::error('Snapshot statement timeout could not be restored', [
                    'error' => $e->getMessage(),
                    'left_at_seconds' => $seconds,
                ]);
            }
        }
    }

    /** @param array<string, int|float>|null $previous */
    private function captureSnapshot(?array $previous = null): PipelineSnapshot
    {
        $recoveryCriteria = $this->bodyRecoveryCriteria();
        $recoverySources = $this->bodyRecoverySourceSnapshot($recoveryCriteria);
        // parts keeps InnoDB's sampled row estimate: an exact COUNT(*) over
        // ~50M rows is not affordable on a loop this hot, and no cheap exact
        // analogue exists (schedulable_parts_backlog is a subset, not a
        // substitute). This is a known, bounded-by-luck risk rather than a
        // solved one -- parts currently runs ~50M against a 300M watermark, so
        // a few percent of sampling error is nowhere near the cliff. If that
        // margin ever closes, this reintroduces exactly the failure documented
        // on $binariesBacklog below, and the answer then is a cheaper exact
        // counter, not a wider tolerance.
        // Groups paused by an open backfill window are not forward-fetched, so
        // their growing gap must not count as unfetched current content.
        // Configured forward-only groups are left out at every hour: they keep
        // their forward fetch but must not hold current_groups false or become
        // the backfill target.
        $pausedGroups = self::backfillExcludedGroups(
            $this->window->pausedGroups(),
            (array) config('nntmux.orchestrator.backfill_ignore_forward_groups', []),
        );
        $pausedClause = $pausedGroups === []
            ? ''
            : ' AND LOWER(g.name) NOT IN ('.implode(', ', array_fill(0, count($pausedGroups), '?')).')';
        $tables = DB::selectOne("SELECT
            COALESCE(MAX(CASE WHEN TABLE_NAME = 'parts' THEN TABLE_ROWS END), 0) AS parts_count
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parts'");
        // Each scan below runs once per orchestrator cycle (~24 s). Every
        // processable-collection figure shares one pass in `sc`.
        // Aggregates without GROUP BY always yield one row, so the cross
        // joins never drop the snapshot.
        //
        // `sb` is deliberately not the obvious join. Walking
        // binaries -> collections -> usenet_groups for every pending binary
        // cost 6.0-7.1 s measured on live data (1.19M rows with
        // partcheck = 0, each paying a collections primary-key lookup and a
        // usenet_groups lookup) and was 4.5 s of a 4.9 s snapshot. The same
        // three numbers now cost 1.3-1.8 s:
        //
        // - count and part sum are inverted. 99%+ of pending binaries belong
        //   to a schedulable collection, so the cheap set is the complement:
        //   total pending binaries minus the ones excluded by filecheck
        //   (34k rows) minus the ones in a group that is neither active nor
        //   on backfill. binaries.collections_id is a foreign key with
        //   ON DELETE CASCADE, so no pending binary can be missing its
        //   collection and escape the complement. collections.filecheck is
        //   NOT NULL, so NOT IN is total and the two sides really do
        //   partition the set.
        // - the group term is kept rather than dropped. It is currently empty
        //   (every group holding collections is active), but that is a fact
        //   about today's data, not an invariant, and these numbers gate
        //   backfill admission. STRAIGHT_JOIN makes it drive from
        //   usenet_groups, which probes collections.groups_id and misses
        //   immediately for the ~109k groups that hold nothing: 0.23 s
        //   instead of 0.55 s when the optimizer drives from collections.
        // - there are two group terms, not one, because there is no foreign
        //   key on collections.groups_id. A collection whose group row is
        //   gone was excluded by the original's INNER JOIN usenet_groups, but
        //   matches neither the filecheck branch nor the inactive-group branch
        //   of the complement, so it would be counted as schedulable and
        //   inflate the numbers the backfill gate reads. The orphan branch
        //   drives from the distinct group ids that actually hold collections
        //   -- a loose index scan over ~15 values, 0.9 ms -- so it costs 3 ms
        //   while the orphan set is empty, which it is today. Folding it into
        //   the inactive-group branch as a LEFT JOIN instead made that branch
        //   drive from collections and cost 0.59 s against 0.22 s.
        // - the pending-binary test inside the probe is a scalar subquery, not
        //   EXISTS, and that is the whole of the 2026-11-29 incident.
        //   MariaDB may implement an EXISTS semi-join either as FirstMatch --
        //   a per-row ref probe on ix_binaries_collection, 1.8 ms -- or by
        //   MATERIALIZING the entire partcheck = 0 set, which database
        //   measured at 2311.5 ms for a single probe. The choice is cost-based
        //   on that set's cardinality, so it flipped silently as the pending
        //   count grew, and the probe sits in a DEPENDENT SUBQUERY evaluated
        //   once per (group, stage) row: 180 rebuilds of a ~1M-row set is the
        //   3,801 s statement. A scalar subquery is not eligible for semi-join
        //   materialization at all, so the plan cannot flip this way. Same
        //   trick as the group-readiness query in ReleasesRunner, for a
        //   different reason.
        //
        //   Verified equivalent inside one START TRANSACTION WITH CONSISTENT
        //   SNAPSHOT: both forms returned MIN(dateadded) = 2026-11-24
        //   12:51:07. Measured after: probe 1.8 ms against 2311.5 ms, whole
        //   oba term 36.4 ms, and EXPLAIN carries no MATERIALIZED line.
        // - oldest_binary_age is not invertible, so it is answered by ordered
        //   probes instead: one index walk of ix_collections_release_stage6
        //   per (group, filecheck) pair, stopping at the first collection
        //   that still has a pending binary. 180 probes, 0.04 s. The
        //   IS NOT NULL guard reproduces MIN()'s NULL handling -- without it
        //   a NULL dateadded would sort first and blank out that pair.
        // - the exact same pending-binary count also feeds binaries_backlog,
        //   which used to issue its own COUNT(*) (0.24 s) over the same rows.
        //
        // Verified equivalent against the previous statement inside one
        // START TRANSACTION WITH CONSISTENT SNAPSHOT: identical
        // schedulable_binaries_backlog (1159485), schedulable_parts_backlog
        // (56557889) and MIN(dateadded), and binaries_backlog matched the
        // standalone COUNT(*) exactly (1193754).
        //
        // The GREATEST(0, ...) clamps are for the race, not the algebra: the
        // complement cannot exceed the total, but the two aggregates are
        // separate passes over a table being written to, so a few rows can
        // land between them. Without the clamp an unsigned underflow would
        // report a nonsensical backlog to the gates.
        $pipeline = DB::selectOne('SELECT
            (SELECT COUNT(*) FROM collections WHERE filecheck IN (0, 1, 2, 15, 16)) AS collections_backlog,
            sb.binaries_backlog,
            sb.schedulable_parts_backlog,
            sb.schedulable_binaries_backlog,
            sc.schedulable_collections_backlog,
            sc.ready_collections,
            sc.ready_collections AS releases_backlog,
            (SELECT COUNT(*) FROM releases) AS release_total,
            nz.nzbs_backlog,
            sb.oldest_binary_age,
            sc.oldest_collection_age,
            sc.oldest_release_age,
            nz.oldest_nzb_age,
            NOT EXISTS(SELECT 1 FROM usenet_groups g LEFT JOIN short_groups s ON s.name = g.name
                WHERE g.active = 1'.$pausedClause.' AND (s.name IS NULL OR CAST(s.last_record AS SIGNED) - CAST(g.last_record AS SIGNED) > '.$this->currentGroupMaxLag().')) AS current_groups
            FROM '.self::SCHEDULABLE_BINARIES_SQL.'
            CROSS JOIN (SELECT COALESCE(SUM(c.filecheck IN (0, 1, 2, 15, 16)), 0) AS schedulable_collections_backlog,
                    COALESCE(SUM(c.filecheck = 3), 0) AS ready_collections,
                    COALESCE(TIMESTAMPDIFF(SECOND, MIN(CASE WHEN c.filecheck IN (0, 1, 2, 15, 16) THEN c.dateadded END), NOW()), 0) AS oldest_collection_age,
                    COALESCE(TIMESTAMPDIFF(SECOND, MIN(CASE WHEN c.filecheck = 3 THEN c.dateadded END), NOW()), 0) AS oldest_release_age
                FROM collections c INNER JOIN usenet_groups g ON g.id = c.groups_id
                WHERE c.filecheck IN (0, 1, 2, 3, 15, 16) AND (g.active = 1 OR g.backfill = 1)) AS sc
            CROSS JOIN (SELECT COUNT(*) AS nzbs_backlog,
                    COALESCE(TIMESTAMPDIFF(SECOND, MIN(adddate), NOW()), 0) AS oldest_nzb_age
                FROM releases WHERE nzbstatus = 0) AS nz', $pausedGroups);
        $statusRows = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_deadlocks', 'Innodb_row_lock_current_waits', 'Innodb_row_lock_waits')");
        $status = [];
        foreach ($statusRows as $row) {
            $status[(string) $row->Variable_name] = (int) $row->Value;
        }
        $signals = $this->safety->signals();
        $eligibleNzbs = $this->nzbBacklog->eligibleCandidateCount((int) config('nntmux.distributed_nzb_scan_cap', 10000));
        $controlState = $this->state->loadState();
        $profileOverride = (new ControlProfileOverride)->effective();
        $yieldHistory = $this->state->backfillYieldHistory();

        $rawTotalCollections = (int) ($pipeline->collections_backlog ?? 0);
        $recoverySourceCollections = (int) $recoverySources['backlog'];
        // Checked on the raw counts: the split is a property of the table,
        // not of what the gates are later told to ignore.
        $splitConsistent = $recoverySourceCollections <= $rawTotalCollections;
        $inFlightCollections = $this->inFlightCollections();
        [
            'total' => $totalCollections,
            'ordinary' => $ordinaryCollections,
            'schedulable' => $schedulableCollectionsGated,
            'in_flight' => $inFlightCollections,
        ] = self::withoutInFlightCollections(
            $rawTotalCollections,
            max(0, $rawTotalCollections - $recoverySourceCollections),
            (int) ($pipeline->schedulable_collections_backlog ?? 0),
            $inFlightCollections,
        );
        $physicalParts = (int) ($tables->parts_count ?? 0);
        // Exact, not information_schema.TABLES.TABLE_ROWS. That column is an
        // InnoDB sample, and on 2026-08-07 it read 1,006,566 against an exact
        // 959,687 -- a 4.9% overshoot that put a table 40k rows *under* its
        // 1,000,000 watermark 6,566 rows over it. Everything downstream is a
        // cliff: safeBackfillQuantity() saw zero headroom, so every candidate
        // group fell below the 10,000 floor, so selectBackfillTarget() returned
        // null and backfill was dead fleet-wide while still reporting
        // backfill_permitted=true; physicalCapacityHigh() latched high pressure
        // on the same reading, which also holds the current-forward gate shut.
        // The error is unbounded in both directions and re-accumulates as the
        // table churns -- ANALYZE TABLE moved it to 925,542, 3.6% low -- so a
        // tolerance would only move the cliff rather than remove it.
        //
        // This costs nothing: binaries_backlog is already selected in the same
        // capture(), and it was already the declared fallback below. Preferring
        // the estimate meant the primary and its fallback measured different
        // quantities -- every row versus partcheck = 0 -- so the fallback path
        // was never the same guard, just a quieter one.
        $binariesBacklog = (int) ($pipeline->binaries_backlog ?? 0);
        $schedulableParts = (int) ($pipeline->schedulable_parts_backlog ?? 0);
        $schedulableBinaries = (int) ($pipeline->schedulable_binaries_backlog ?? 0);
        $schedulableCollections = $schedulableCollectionsGated;
        $backlogs = [
            'parts' => $schedulableParts,
            'binaries' => $schedulableBinaries,
            'collections' => $schedulableCollections,
            'releases' => (int) ($pipeline->releases_backlog ?? 0),
            'nzbs' => (int) ($pipeline->nzbs_backlog ?? 0),
        ];
        $capacityBacklogs = [
            'parts' => $physicalParts,
            'binaries' => $binariesBacklog,
            'collections' => $ordinaryCollections,
            'collections_total' => $totalCollections,
            'recovery_sources' => $recoverySourceCollections,
            'releases' => $backlogs['releases'],
            'nzbs' => $backlogs['nzbs'],
        ];
        $freeRun = $profileOverride?->isFreeRunFamily() === true;
        $safeBackfillCandidates = $this->safeBackfillCandidates(
            self::withoutGroups($this->backfillCandidates(), $pausedGroups),
            $capacityBacklogs,
            $freeRun,
        );
        $backfillTarget = $this->selectBackfillTarget(
            $safeBackfillCandidates,
            $yieldHistory,
            $controlState,
            time(),
        );
        $backfillPermitHandoffSafe = $this->permitHandoffTargetSafe(
            $safeBackfillCandidates,
            $this->state->permitObservation(),
        );
        $backfillGroup = (string) ($backfillTarget['name'] ?? '');
        $targetHistory = $yieldHistory[$backfillGroup] ?? null;
        $historyIsRecent = is_array($targetHistory)
            && time() - (int) ($targetHistory['last_attempt_at'] ?? 0) < (int) config('nntmux.orchestrator.backfill_yield_ttl_seconds', 86_400);
        $historyIsProven = $historyIsRecent
            && (int) ($targetHistory['attempts'] ?? 0) >= (int) config('nntmux.orchestrator.backfill_scale_min_attempts', 2);
        $ages = [
            'binaries' => (int) ($pipeline->oldest_binary_age ?? 0),
            'collections' => $this->oldestOrdinaryCollectionAge($recoveryCriteria),
            'releases' => (int) ($pipeline->oldest_release_age ?? 0),
            'nzbs' => (int) ($pipeline->oldest_nzb_age ?? 0),
        ];
        if ($eligibleNzbs === 0) {
            $ages['nzbs'] = 0;
        }
        $now = time();
        [$rates, $ewma] = $this->rates($backlogs, $previous);
        $physicalCapacityHigh = $this->physicalCapacityHigh(
            $physicalParts,
            $binariesBacklog,
            $ordinaryCollections,
            $totalCollections,
            $recoverySourceCollections,
        );
        $high = $physicalCapacityHigh || $this->pressure->isHigh($backlogs, $ages, $ewma);
        $windowLow = $this->window->collectionsLow();
        $lowClassifier = $windowLow === null
            ? $this->pressure
            : $this->pressure->withLowWatermarks(['collections' => $windowLow, 'collections_total' => $windowLow]);
        $low = ! $physicalCapacityHigh && $lowClassifier->isLow($backlogs, $ewma);
        $workerTelemetry = (new DistributedWorkerTelemetry)->snapshot(['releases'], (float) $now);
        // Unavailable telemetry is not a zero counter. Reading it as one reports
        // a false zero yield, and persisting that zero would make the next
        // successful read look like a huge jump from nothing. Carry the last
        // known total forward and report the yield as unmeasured instead.
        $releaseCreatedTotal = $workerTelemetry['available']
            ? (int) ($workerTelemetry['workers']['releases']['items']['release']['created'] ?? 0)
            : (int) ($previous['release_created_total'] ?? 0);
        $releaseYield = $workerTelemetry['available']
            ? $this->releaseYieldPerMinute($releaseCreatedTotal, $previous, $now)
            : null;
        $deadlocks = isset($status['Innodb_deadlocks']) ? (int) $status['Innodb_deadlocks'] : null;
        $waits = isset($status['Innodb_row_lock_current_waits']) ? (int) $status['Innodb_row_lock_current_waits'] : null;
        $rowLockWaits = isset($status['Innodb_row_lock_waits']) ? (int) $status['Innodb_row_lock_waits'] : null;
        $database = $this->databaseContentionTelemetry($deadlocks, $waits, $rowLockWaits, $previous, $now);
        $databaseAdmissionSafe = $database['database_admission_safe']
            && $this->databaseProfileStable($controlState, $now);

        return new PipelineSnapshot(
            partsBacklog: $physicalParts,
            binariesBacklog: $binariesBacklog,
            collectionsBacklog: $ordinaryCollections,
            releasesBacklog: $backlogs['releases'],
            nzbsBacklog: $backlogs['nzbs'],
            telemetryFresh: $signals['fresh'],
            telemetryComplete: $tables !== null && $pipeline !== null,
            telemetryConsistent: $splitConsistent,
            databaseMemorySafe: $signals['memory_safe'],
            databaseCpuSafe: $signals['cpu_safe'],
            databaseWaitsSafe: $database['database_waits_safe'],
            storageSafe: $signals['storage_safe'],
            highPressure: $high,
            lowPressure: $low,
            providerAvailable: $backfillGroup !== '',
            cursorAvailable: $backfillGroup !== '',
            currentGroupsAvailable: (bool) ($pipeline->current_groups ?? false),
            eligibleBackfillSupply: $eligibleNzbs === 0 && $backfillGroup !== '',
            databaseDeadlocks: $deadlocks ?? 0,
            databaseCurrentWaits: $waits ?? 0,
            storageAvailableBytes: $signals['storage_available_bytes'],
            observedAt: $now,
            readyCollections: (int) ($pipeline->ready_collections ?? 0),
            releaseTotal: (int) ($pipeline->release_total ?? 0),
            eligibleNzbs: $eligibleNzbs,
            oldestBinaryAgeSeconds: $ages['binaries'],
            oldestCollectionAgeSeconds: $ages['collections'],
            oldestReleaseAgeSeconds: $ages['releases'],
            oldestNzbAgeSeconds: $ages['nzbs'],
            backlogRatesPerMinute: $rates,
            backlogEwmaPerMinute: $ewma,
            backfillGroup: $backfillGroup,
            backfillCursor: (int) ($backfillTarget['cursor'] ?? 0),
            backfillYieldNzbsPer10k: $historyIsProven ? (float) ($targetHistory['ewma_nzbs_per_10k'] ?? 0.0) : 0.0,
            backfillYieldAttempts: is_array($targetHistory) ? (int) ($targetHistory['attempts'] ?? 0) : 0,
            backfillLastCursorDelta: is_array($targetHistory) ? (int) ($targetHistory['last_cursor_delta'] ?? 0) : 0,
            backfillLastEffectiveAt: is_array($targetHistory) ? (int) ($targetHistory['last_effective_at'] ?? 0) : 0,
            backfillHistoryRecent: $historyIsRecent,
            backfillTargetIneffectivePermits: (int) ($controlState->ineffectiveBackfillPermitsByTarget[$backfillGroup] ?? 0),
            backfillTargetLockRetryDue: (bool) ($backfillTarget['lock_retry_due'] ?? false),
            backfillRemainingArticles: (int) ($backfillTarget['remaining_articles'] ?? 0),
            backfillSafeQuantity: (int) ($backfillTarget['safe_quantity'] ?? 0),
            bodyRecoveryQueueBacklog: $this->bodyRecoveryQueueBacklog(),
            collectionsTotalBacklog: $totalCollections,
            collectionsInFlight: $inFlightCollections,
            bodyRecoverySourceBacklog: $recoverySourceCollections,
            oldestBodyRecoverySourceAgeSeconds: (int) $recoverySources['oldest_age'],
            backfillPermitHandoffSafe: $backfillPermitHandoffSafe,
            databaseRowLockWaits: $rowLockWaits ?? 0,
            databaseRowLockDelta: $database['database_row_lock_delta'],
            databaseRowLockInstantRate: $database['database_row_lock_instant_rate'],
            databaseRowLockWindowStartedAt: $database['database_row_lock_window_started_at'],
            databaseRowLockWindowStartCount: $database['database_row_lock_window_start_count'],
            databaseRowLockWindowRate: $database['database_row_lock_window_rate'],
            databaseRowLockAdmissionBlocked: $database['database_row_lock_admission_blocked'],
            databaseRowLockHardBreachAt: $database['database_row_lock_hard_breach_at'],
            databaseCurrentWaitStartedAt: $database['database_current_wait_started_at'],
            databaseAdmissionSafe: $databaseAdmissionSafe,
            schedulablePartsBacklog: $schedulableParts,
            schedulableBinariesBacklog: $schedulableBinaries,
            schedulableCollectionsBacklog: $schedulableCollections,
            releaseYieldPerMinute: $releaseYield,
            releaseCreatedTotal: $releaseCreatedTotal,
            databaseMemoryKnown: $signals['memory_known'],
            databaseCpuKnown: $signals['cpu_known'],
            storageKnown: $signals['storage_known'],
            profileOverride: $profileOverride,
            safetySampleSource: $signals['sample_source'] ?? 'live',
            safetySampleAgeSeconds: $signals['sample_age_seconds'] ?? 0,
        );
    }

    /**
     * @param  array<string, int|float|bool>|null  $previous
     * @return array{
     *     database_waits_safe: bool,
     *     database_row_lock_delta: int,
     *     database_row_lock_instant_rate: float,
     *     database_row_lock_window_started_at: int,
     *     database_row_lock_window_start_count: int,
     *     database_row_lock_window_rate: float,
     *     database_row_lock_admission_blocked: bool,
     *     database_row_lock_hard_breach_at: int,
     *     database_current_wait_started_at: int,
     *     database_admission_safe: bool
     * }
     */
    private function databaseContentionTelemetry(
        ?int $deadlocks,
        ?int $currentWaits,
        ?int $rowLockWaits,
        ?array $previous,
        int $now,
    ): array {
        $windowSeconds = $this->boundedIntConfig('database_row_lock_window_seconds', 120, 60, 600);
        $blockRate = $this->boundedFloatConfig('database_row_lock_admission_block_rate', 4.0, 0.1, 1_000.0);
        $reopenRate = $this->boundedFloatConfig('database_row_lock_admission_reopen_rate', 3.0, 0.0, $blockRate);
        $hardRate = $this->boundedFloatConfig('database_row_lock_hard_rate', 6.0, $blockRate, 2_000.0);
        $burstWaits = $this->boundedIntConfig('database_row_lock_burst_waits', 12, 1, 10_000);
        $burstSeconds = $this->boundedIntConfig('database_row_lock_burst_seconds', 60, 1, $windowSeconds);
        $instantHardRate = $this->boundedFloatConfig('database_row_lock_instant_hard_rate', 30.0, $hardRate, 10_000.0);
        $hardCooldownSeconds = $this->boundedIntConfig('database_row_lock_hard_cooldown_seconds', 600, 60, 86_400);
        $deadlockHardCount = $this->boundedIntConfig('database_deadlock_hard_count', 1, 1, 1_000);
        $currentWaitHardSeconds = $this->boundedIntConfig('database_current_wait_hard_seconds', 30, 5, 300);
        $profileStableSeconds = $this->boundedIntConfig('database_profile_stable_seconds', 120, 60, $windowSeconds);
        $snapshotMaxAgeSeconds = $this->boundedIntConfig('snapshot_max_age_seconds', 180, 60, 600);

        if ($deadlocks === null || $currentWaits === null || $rowLockWaits === null) {
            return [
                'database_waits_safe' => false,
                'database_row_lock_delta' => 0,
                'database_row_lock_instant_rate' => 0.0,
                'database_row_lock_window_started_at' => $now,
                'database_row_lock_window_start_count' => max(0, $rowLockWaits ?? 0),
                'database_row_lock_window_rate' => 0.0,
                'database_row_lock_admission_blocked' => true,
                'database_row_lock_hard_breach_at' => $now,
                'database_current_wait_started_at' => $currentWaits !== null && $currentWaits > 0 ? $now : 0,
                'database_admission_safe' => false,
            ];
        }

        $previousObservedAt = (int) ($previous['observed_at'] ?? 0);
        $hasV3Baseline = (int) ($previous['schema_version'] ?? 0) >= 3
            && array_key_exists('database_row_lock_waits', $previous ?? [])
            && $previousObservedAt > 0
            && $previousObservedAt < $now
            && $now - $previousObservedAt <= $snapshotMaxAgeSeconds
            && $rowLockWaits >= (int) ($previous['database_row_lock_waits'] ?? 0);
        if (! $hasV3Baseline) {
            return [
                'database_waits_safe' => true,
                'database_row_lock_delta' => 0,
                'database_row_lock_instant_rate' => 0.0,
                'database_row_lock_window_started_at' => $now,
                'database_row_lock_window_start_count' => $rowLockWaits,
                'database_row_lock_window_rate' => 0.0,
                'database_row_lock_admission_blocked' => true,
                'database_row_lock_hard_breach_at' => 0,
                'database_current_wait_started_at' => $currentWaits > 0 ? $now : 0,
                'database_admission_safe' => false,
            ];
        }

        $previousRowLockWaits = (int) $previous['database_row_lock_waits'];
        $elapsed = $now - $previousObservedAt;
        $delta = max(0, $rowLockWaits - $previousRowLockWaits);
        $instantRate = (float) ($delta * 60 / $elapsed);
        $windowStartedAt = (int) ($previous['database_row_lock_window_started_at'] ?? 0);
        $windowStartCount = (int) ($previous['database_row_lock_window_start_count'] ?? $previousRowLockWaits);
        if ($windowStartedAt <= 0 || $windowStartedAt > $previousObservedAt || $windowStartCount > $rowLockWaits) {
            $windowStartedAt = $previousObservedAt;
            $windowStartCount = $previousRowLockWaits;
        }
        $windowElapsed = max(1, $now - $windowStartedAt);
        $windowDelta = max(0, $rowLockWaits - $windowStartCount);
        $windowRate = (float) ($windowDelta * 60 / $windowElapsed);
        $windowComplete = $windowElapsed >= $windowSeconds;

        $currentWaitStartedAt = 0;
        if ($currentWaits > 0) {
            $previousWaitStartedAt = (int) ($previous['database_current_wait_started_at'] ?? 0);
            $currentWaitStartedAt = (int) ($previous['database_current_waits'] ?? 0) > 0
                && $previousWaitStartedAt > 0
                && $previousWaitStartedAt <= $previousObservedAt
                    ? $previousWaitStartedAt
                    : $now;
        }

        $deadlockDelta = $deadlocks - (int) ($previous['database_deadlocks'] ?? $deadlocks) >= $deadlockHardCount;
        $persistentCurrentWait = $currentWaitStartedAt > 0
            && $now - $currentWaitStartedAt >= $currentWaitHardSeconds;
        $burst = $delta >= $burstWaits && $elapsed <= $burstSeconds;
        $instantHard = $instantRate >= $instantHardRate;
        $windowHard = $windowComplete && $windowRate >= $hardRate;
        $hardBreach = $deadlockDelta || $persistentCurrentWait || $burst || $instantHard || $windowHard;
        $hardBreachAt = $hardBreach ? $now : max(0, (int) ($previous['database_row_lock_hard_breach_at'] ?? 0));
        $cooldownActive = $hardBreachAt > 0 && $now - $hardBreachAt < $hardCooldownSeconds;

        $admissionBlocked = (bool) ($previous['database_row_lock_admission_blocked'] ?? true);
        if ($instantRate >= $blockRate || $currentWaits > 0 || $hardBreach || $cooldownActive) {
            $admissionBlocked = true;
        } elseif ($windowComplete) {
            if ($windowRate >= $blockRate) {
                $admissionBlocked = true;
            } elseif ($windowRate <= $reopenRate) {
                $admissionBlocked = false;
            }
        }

        $profileStable = (bool) ($previous['database_admission_safe'] ?? false)
            || ($windowComplete && $windowElapsed >= $profileStableSeconds && $windowRate <= $reopenRate);
        $admissionSafe = ! $admissionBlocked
            && ! $cooldownActive
            && $currentWaits === 0
            && $profileStable;

        if ($windowComplete) {
            $windowStartedAt = $now;
            $windowStartCount = $rowLockWaits;
        }

        return [
            'database_waits_safe' => ! $hardBreach,
            'database_row_lock_delta' => $delta,
            'database_row_lock_instant_rate' => $instantRate,
            'database_row_lock_window_started_at' => $windowStartedAt,
            'database_row_lock_window_start_count' => $windowStartCount,
            'database_row_lock_window_rate' => $windowRate,
            'database_row_lock_admission_blocked' => $admissionBlocked,
            'database_row_lock_hard_breach_at' => $hardBreachAt,
            'database_current_wait_started_at' => $currentWaitStartedAt,
            'database_admission_safe' => $admissionSafe,
        ];
    }

    /**
     * Articles an active group may trail the server's newest and still count
     * as current. Applies to current_groups and to backfill candidacy. Busy
     * groups fetch at most one task per header pass, so a 10k lag flickers.
     */
    public function currentGroupMaxLag(): int
    {
        return $this->boundedIntConfig('current_group_max_lag', 10_000, 1_000, 1_000_000);
    }

    private function boundedIntConfig(string $key, int $default, int $minimum, int $maximum): int
    {
        return min($maximum, max($minimum, (int) config('nntmux.orchestrator.'.$key, $default)));
    }

    private function boundedFloatConfig(string $key, float $default, float $minimum, float $maximum): float
    {
        return min($maximum, max($minimum, (float) config('nntmux.orchestrator.'.$key, $default)));
    }

    private function databaseProfileStable(ControlState $state, int $now): bool
    {
        if ($state->lastTransitionAt === 0) {
            return true;
        }

        $stableSeconds = $this->boundedIntConfig('database_profile_stable_seconds', 120, 120, 3_600);

        return $state->lastTransitionAt <= $now
            && $now - $state->lastTransitionAt >= $stableSeconds;
    }

    private function bodyRecoveryCriteria(): ?BodyRecoverySourceCriteria
    {
        $groups = array_values(array_filter(array_map(
            'trim',
            (array) config('nntmux.orchestrator.body_recovery_source_groups', []),
        ), static fn (string $group): bool => $group !== ''));
        $regexIds = array_values(array_unique(array_map(
            'intval',
            (array) config('nntmux.orchestrator.body_recovery_source_regex_ids', []),
        )));
        if ($groups === [] || $regexIds === []) {
            return null;
        }
        $groupIds = DB::table('usenet_groups')
            ->where('active', 1)
            ->whereIn('name', $groups)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($groupIds === []) {
            return null;
        }

        return new BodyRecoverySourceCriteria(
            groupIds: $groupIds,
            regexIds: $regexIds,
            maxCurrentParts: (int) config('nntmux.orchestrator.body_recovery_source_max_current_parts', 2),
            minTotalParts: (int) config('nntmux.orchestrator.body_recovery_source_min_total_parts', 10),
            before: now()->subHours((int) config('nntmux.orchestrator.body_recovery_source_cutoff_hours', 2))->toDateTimeString(),
        );
    }

    /** @return array{backlog:int,oldest_age:int} */
    private function bodyRecoverySourceSnapshot(?BodyRecoverySourceCriteria $criteria): array
    {
        if ($criteria === null) {
            return ['backlog' => 0, 'oldest_age' => 0];
        }
        $predicate = $criteria->identityPredicate();
        $row = DB::selectOne("SELECT COUNT(*) AS backlog,
            COALESCE(TIMESTAMPDIFF(SECOND, MIN(c.dateadded), NOW()), 0) AS oldest_age
            FROM collections c FORCE INDEX (ix_collections_release_stage6)
            STRAIGHT_JOIN binaries b FORCE INDEX (ix_binaries_collection_hash) ON b.collections_id = c.id
            WHERE {$predicate['sql']}", $predicate['bindings']);

        return [
            'backlog' => (int) ($row->backlog ?? 0),
            'oldest_age' => (int) ($row->oldest_age ?? 0),
        ];
    }

    private function oldestOrdinaryCollectionAge(?BodyRecoverySourceCriteria $criteria): int
    {
        if ($criteria === null) {
            return (int) DB::scalar('SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(c.dateadded), NOW()), 0)
                FROM collections c
                INNER JOIN usenet_groups g ON g.id = c.groups_id
                WHERE c.filecheck IN (0, 1, 2, 15, 16)
                AND (g.active = 1 OR g.backfill = 1)');
        }
        $predicate = $criteria->identityPredicate();

        return (int) DB::scalar("SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(c.dateadded), NOW()), 0)
            FROM collections c
            INNER JOIN usenet_groups g ON g.id = c.groups_id
            WHERE c.filecheck IN (0, 1, 2, 15, 16)
            AND (g.active = 1 OR g.backfill = 1)
            AND NOT EXISTS (
                SELECT 1 FROM binaries b FORCE INDEX (ix_binaries_collection_hash)
                WHERE b.collections_id = c.id
                AND {$predicate['sql']}
            )", $predicate['bindings']);
    }

    private function bodyRecoveryQueueBacklog(): int
    {
        $groups = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('nntmux.body_preamble_deobfuscate_groups', '')),
        ), static fn (string $group): bool => $group !== ''));
        if ($groups === []) {
            return 0;
        }

        return (int) DB::table('missed_parts as mp')
            ->join('usenet_groups as g', 'g.id', '=', 'mp.groups_id')
            ->whereIn('g.name', $groups)
            ->where('mp.recovery_kind', 'body_preamble')
            ->where('mp.attempts', '<', 3)
            ->count('mp.id');
    }

    /** @param array{parts: int, binaries: int, collections: int, releases: int, nzbs: int} $backlogs */
    private function safeBackfillQuantity(array $backlogs, string $backfillGroup = '', bool $bypassCollectionCapacity = false): int
    {
        $fraction = (float) config('nntmux.orchestrator.backfill_headroom_fraction', 0.10);
        $high = (array) config('nntmux.orchestrator.high_watermarks', []);
        $growth = $this->state->backfillGrowthFor($backfillGroup);
        $quantities = [];
        foreach (['parts', 'binaries', 'collections', 'collections_total', 'releases', 'nzbs'] as $stage) {
            if ($bypassCollectionCapacity && in_array($stage, ['collections', 'collections_total'], true)) {
                continue;
            }
            $current = $backlogs[$stage] ?? ($stage === 'collections_total' ? $backlogs['collections'] : 0);
            $limit = $high[$stage] ?? ($stage === 'collections_total' ? $high['collections'] ?? 0 : 0);
            if ((int) $limit <= 0) {
                continue;
            }
            $headroom = max(0, (int) $limit - $current);
            $growthStage = $stage === 'collections_total' ? 'collections' : $stage;
            $configuredGrowth = (array) config('nntmux.orchestrator.backfill_growth_per_10k', []);
            $permits = (int) floor(($headroom * $fraction) / max(1, (int) (
                $growth[$growthStage] ?? $configuredGrowth[$growthStage] ?? 1
            )));
            $quantities[] = $permits * 10000;
        }

        return $quantities === [] ? 0 : min($quantities);
    }

    /**
     * Window-paused and forward-only groups, lower-cased and de-duplicated.
     *
     * @param  list<string>  $pausedGroups
     * @param  list<mixed>  $forwardOnlyGroups
     * @return list<string>
     */
    public static function backfillExcludedGroups(array $pausedGroups, array $forwardOnlyGroups): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $group): string => strtolower(trim((string) $group)),
            [...$pausedGroups, ...$forwardOnlyGroups],
        ), static fn (string $group): bool => $group !== '')));
    }

    /**
     * @template T of array{name: string}
     *
     * @param  list<T>  $candidates
     * @param  list<string>  $groups  lower-cased names
     * @return list<T>
     */
    public static function withoutGroups(array $candidates, array $groups): array
    {
        if ($groups === []) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => ! in_array(strtolower((string) $candidate['name']), $groups, true),
        ));
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $candidates
     * @param  array{parts: int, binaries: int, collections: int, collections_total: int, releases: int, nzbs: int}  $backlogs
     * @return list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int, safe_quantity: int}>
     */
    private function safeBackfillCandidates(array $candidates, array $backlogs, bool $bypassCollectionCapacity = false): array
    {
        $safe = [];
        foreach ($candidates as $candidate) {
            $quantity = $this->safeBackfillQuantity($backlogs, $candidate['name'], $bypassCollectionCapacity);
            if ($quantity >= 10_000) {
                $safe[] = [...$candidate, 'safe_quantity' => $quantity];
            }
        }

        return $safe;
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int, safe_quantity: int}>  $candidates
     * @param  array<string, mixed>|null  $observation
     */
    private function permitHandoffTargetSafe(array $candidates, ?array $observation): bool
    {
        $group = trim((string) ($observation['backfill_group'] ?? ''));
        $cursor = (int) ($observation['backfill_cursor'] ?? 0);
        $quantity = (int) ($observation['backfill_quantity'] ?? 0);
        if ($group === '' || $cursor <= 0 || $quantity < 10_000) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if ($candidate['name'] === $group
                && $candidate['cursor'] === $cursor
                && $candidate['safe_quantity'] >= $quantity
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int, safe_quantity: int}>  $candidates
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int, safe_quantity: int}|null
     */
    private function selectBackfillTarget(
        array $candidates,
        array $history,
        ControlState $state,
        int $now,
    ): ?array {
        $pendingGroups = $this->state->pendingBackfillDelayedAttributionGroups();
        $contextRepeat = $this->state->backfillContextRepeat($now);
        $continuationGroup = trim((string) ($contextRepeat['group'] ?? ''));
        $expectedCursorPostdate = $this->state
            ->backfillDelayedAttributionExpectedCursorPostdate($continuationGroup, $now);
        $continuationCandidate = collect($candidates)->firstWhere('name', $continuationGroup);
        if (! $this->state->backfillDelayedAttributionCanContinue($continuationGroup, $now)
            || $expectedCursorPostdate === null
            || ! is_array($continuationCandidate)
            || strtotime($expectedCursorPostdate) !== strtotime((string) $continuationCandidate['cursor_postdate'])
        ) {
            $continuationGroup = '';
            $contextRepeat = null;
        } elseif ($contextRepeat !== null) {
            $contextRepeat['expected_cursor_postdate'] = $expectedCursorPostdate;
        }
        if ($pendingGroups !== [] && $continuationGroup !== '') {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool => $candidate['name'] === $continuationGroup,
            ));
        } elseif ($pendingGroups !== []) {
            if (! config('nntmux.orchestrator.backfill_parallel_attribution', false)) {
                return null;
            }
            // A pending group waits for its own attribution; every other
            // group may take the next permit.
            $candidates = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool => ! in_array($candidate['name'], $pendingGroups, true),
            ));
        }

        return $this->targets->select(
            $candidates,
            $history,
            $now,
            $state->ineffectiveBackfillPermitsByTarget,
            $contextRepeat,
        );
    }

    /** @return array{cursor: int, cursor_postdate: string, ready_collections: int, releases: int, release_high_watermark: int, group_active: int, raw_collections: int, raw_binaries: int, partial_collections: int, complete_binaries: int} */
    public function backfillOutcomeForGroup(string $group): array
    {
        $row = DB::selectOne('SELECT
            CAST(g.first_record AS SIGNED) AS backfill_cursor,
            CAST(g.first_record_postdate AS CHAR) AS cursor_postdate,
            (SELECT COUNT(*) FROM collections c WHERE c.groups_id = g.id AND c.filecheck = 3) AS ready_collections,
            (SELECT COUNT(*) FROM releases r WHERE r.groups_id = g.id) AS releases,
            (SELECT COALESCE(MAX(r.id), 0) FROM releases r WHERE r.groups_id = g.id) AS release_high_watermark,
            g.active AS group_active,
            (SELECT COUNT(*) FROM collections c WHERE c.groups_id = g.id) AS raw_collections,
            (SELECT COUNT(*) FROM binaries b
                INNER JOIN collections c ON c.id = b.collections_id
                WHERE c.groups_id = g.id) AS raw_binaries,
            (SELECT COUNT(*) FROM collections c WHERE c.groups_id = g.id AND c.filecheck = 1) AS partial_collections,
            (SELECT COUNT(*)
                FROM collections c
                INNER JOIN binaries b ON b.collections_id = c.id
                WHERE c.groups_id = g.id
                AND c.filecheck IN (0, 1, 2, 15, 16)
                AND b.partcheck = 1) AS complete_binaries
            FROM usenet_groups g WHERE g.name = ? LIMIT 1', [$group]);

        return [
            'cursor' => (int) ($row->backfill_cursor ?? 0),
            'cursor_postdate' => (string) ($row->cursor_postdate ?? ''),
            'ready_collections' => (int) ($row->ready_collections ?? 0),
            'releases' => (int) ($row->releases ?? 0),
            'release_high_watermark' => (int) ($row->release_high_watermark ?? 0),
            'group_active' => (int) ($row->group_active ?? 1),
            'raw_collections' => (int) ($row->raw_collections ?? 0),
            'raw_binaries' => (int) ($row->raw_binaries ?? 0),
            'partial_collections' => (int) ($row->partial_collections ?? 0),
            'complete_binaries' => (int) ($row->complete_binaries ?? 0),
        ];
    }

    public function backfillCreatedNzbsForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): int {
        $postdateToleranceSeconds = (int) config('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);

        return (int) DB::scalar('SELECT COUNT(*)
            FROM releases r
            INNER JOIN usenet_groups g ON g.id = r.groups_id
            WHERE g.name = ?
            AND r.id > ?
            AND r.nzbstatus = 1
            AND r.postdate BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)
                AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)', [
            $group,
            max(0, $releaseHighWatermark),
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
        ]);
    }

    /** @return array{target: int, non_target: int, uncategorized: int} */
    public function backfillCreatedNzbCategoryCountsForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): array {
        return $this->backfillNzbCategoryQualityForCohort(
            $group,
            max(0, $releaseHighWatermark),
            null,
            $startPostdate,
            $endPostdate,
        )['counts'];
    }

    /** @return array{target: int, non_target: int, uncategorized: int} */
    public function backfillCreatedReleaseCategoryCountsForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): array {
        return $this->backfillNzbCategoryQualityForCohort(
            $group,
            max(0, $releaseHighWatermark),
            null,
            $startPostdate,
            $endPostdate,
            completedNzbsOnly: false,
        )['counts'];
    }

    /**
     * @return array{
     *     counts: array{target: int, non_target: int, uncategorized: int},
     *     bytes: array{target: int, non_target: int, uncategorized: int}
     * }
     */
    public function backfillCreatedNzbCategoryQualityForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): array {
        return $this->backfillNzbCategoryQualityForCohort(
            $group,
            max(0, $releaseHighWatermark),
            null,
            $startPostdate,
            $endPostdate,
        );
    }

    /**
     * @return array{
     *     counts: array{target: int, non_target: int, uncategorized: int},
     *     bytes: array{target: int, non_target: int, uncategorized: int}
     * }
     */
    public function backfillCreatedReleaseCategoryQualityForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): array {
        return $this->backfillNzbCategoryQualityForCohort(
            $group,
            max(0, $releaseHighWatermark),
            null,
            $startPostdate,
            $endPostdate,
            completedNzbsOnly: false,
        );
    }

    /** @return array{target: int, non_target: int, uncategorized: int} */
    public function backfillCreatedNzbCategoryBytesForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): array {
        return $this->backfillNzbCategoryQualityForCohort(
            $group,
            max(0, $releaseHighWatermark),
            null,
            $startPostdate,
            $endPostdate,
        )['bytes'];
    }

    /** @return array{target: int, non_target: int, uncategorized: int} */
    public function backfillCreatedReleaseCategoryBytesForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): array {
        return $this->backfillNzbCategoryQualityForCohort(
            $group,
            max(0, $releaseHighWatermark),
            null,
            $startPostdate,
            $endPostdate,
            completedNzbsOnly: false,
        )['bytes'];
    }

    /** @return array{target: int, non_target: int, uncategorized: int} */
    public function backfillCompletedNzbCategoryBytesForReleaseCohort(
        string $group,
        int $releaseIdLowExclusive,
        int $releaseIdHighInclusive,
        string $startPostdate,
        string $endPostdate,
    ): array {
        if ($releaseIdHighInclusive <= $releaseIdLowExclusive) {
            return ['target' => 0, 'non_target' => 0, 'uncategorized' => 0];
        }

        return $this->backfillNzbCategoryQualityForCohort(
            $group,
            max(0, $releaseIdLowExclusive),
            max(0, $releaseIdHighInclusive),
            $startPostdate,
            $endPostdate,
        )['bytes'];
    }

    /**
     * @return array{
     *     counts: array{target: int, non_target: int, uncategorized: int},
     *     bytes: array{target: int, non_target: int, uncategorized: int}
     * }
     */
    public function backfillCompletedNzbCategoryQualityForReleaseCohort(
        string $group,
        int $releaseIdLowExclusive,
        int $releaseIdHighInclusive,
        string $startPostdate,
        string $endPostdate,
    ): array {
        if ($releaseIdHighInclusive <= $releaseIdLowExclusive) {
            return $this->emptyBackfillCategoryQuality();
        }

        return $this->backfillNzbCategoryQualityForCohort(
            $group,
            max(0, $releaseIdLowExclusive),
            max(0, $releaseIdHighInclusive),
            $startPostdate,
            $endPostdate,
        );
    }

    public function backfillCompletedNzbsForReleaseCohort(
        string $group,
        int $releaseIdLowExclusive,
        int $releaseIdHighInclusive,
        string $startPostdate,
        string $endPostdate,
    ): int {
        if ($releaseIdHighInclusive <= $releaseIdLowExclusive) {
            return 0;
        }
        $postdateToleranceSeconds = (int) config('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);

        return (int) DB::scalar('SELECT COUNT(*)
            FROM releases r
            INNER JOIN usenet_groups g ON g.id = r.groups_id
            WHERE g.name = ?
            AND r.id > ?
            AND r.id <= ?
            AND r.nzbstatus = 1
            AND r.postdate BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)
                AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)', [
            $group,
            max(0, $releaseIdLowExclusive),
            max(0, $releaseIdHighInclusive),
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
        ]);
    }

    /** @return array{target: int, non_target: int, uncategorized: int} */
    public function backfillCompletedNzbCategoryCountsForReleaseCohort(
        string $group,
        int $releaseIdLowExclusive,
        int $releaseIdHighInclusive,
        string $startPostdate,
        string $endPostdate,
    ): array {
        if ($releaseIdHighInclusive <= $releaseIdLowExclusive) {
            return ['target' => 0, 'non_target' => 0, 'uncategorized' => 0];
        }

        return $this->backfillNzbCategoryQualityForCohort(
            $group,
            max(0, $releaseIdLowExclusive),
            max(0, $releaseIdHighInclusive),
            $startPostdate,
            $endPostdate,
        )['counts'];
    }

    /**
     * @return array{
     *     counts: array{target: int, non_target: int, uncategorized: int},
     *     bytes: array{target: int, non_target: int, uncategorized: int}
     * }
     */
    private function backfillNzbCategoryQualityForCohort(
        string $group,
        int $releaseIdLowExclusive,
        ?int $releaseIdHighInclusive,
        string $startPostdate,
        string $endPostdate,
        bool $completedNzbsOnly = true,
    ): array {
        $postdateToleranceSeconds = (int) config('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);
        $minPayloadBytes = max(1, (int) config('nntmux.orchestrator.backfill_min_payload_bytes', 104_857_600));
        $allowTvDateRange = in_array(
            $group,
            (array) config('nntmux.orchestrator.backfill_tv_date_range_groups', []),
            true,
        ) ? 1 : 0;
        $allowTvCompleteSeries = in_array(
            $group,
            (array) config('nntmux.orchestrator.backfill_tv_complete_series_groups', []),
            true,
        ) ? 1 : 0;
        $upperBound = $releaseIdHighInclusive === null ? '' : 'AND r.id <= ?';
        $completedNzbPredicate = $completedNzbsOnly ? 'AND r.nzbstatus = 1' : '';
        $bindings = [
            self::BACKFILL_TV_EPISODE_PATTERN,
            self::BACKFILL_TV_EPISODE_PATTERN,
            $allowTvDateRange,
            self::BACKFILL_TV_DATE_RANGE_PATTERN,
            self::BACKFILL_TV_DATE_RANGE_PATTERN,
            $allowTvCompleteSeries,
            self::BACKFILL_TV_COMPLETE_SERIES_PATTERN,
            self::BACKFILL_TV_COMPLETE_SERIES_PATTERN,
            $minPayloadBytes,
            self::BACKFILL_TV_EPISODE_PATTERN,
            self::BACKFILL_TV_EPISODE_PATTERN,
            $allowTvDateRange,
            self::BACKFILL_TV_DATE_RANGE_PATTERN,
            self::BACKFILL_TV_DATE_RANGE_PATTERN,
            $allowTvCompleteSeries,
            self::BACKFILL_TV_COMPLETE_SERIES_PATTERN,
            self::BACKFILL_TV_COMPLETE_SERIES_PATTERN,
            $minPayloadBytes,
            $group,
            $releaseIdLowExclusive,
        ];
        if ($releaseIdHighInclusive !== null) {
            $bindings[] = $releaseIdHighInclusive;
        }
        array_push(
            $bindings,
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
        );
        $row = DB::selectOne('SELECT
            COALESCE(SUM(classified.is_target), 0) AS target_count,
            COALESCE(SUM(classified.is_non_target), 0) AS non_target_count,
            COALESCE(SUM(classified.is_uncategorized), 0) AS uncategorized_count,
            COALESCE(SUM(CASE WHEN classified.is_target = 1 THEN classified.size ELSE 0 END), 0) AS target_bytes,
            COALESCE(SUM(CASE WHEN classified.is_non_target = 1 THEN classified.size ELSE 0 END), 0) AS non_target_bytes,
            COALESCE(SUM(CASE WHEN classified.is_uncategorized = 1 THEN classified.size ELSE 0 END), 0) AS uncategorized_bytes
            FROM (
                SELECT r.size,
                CASE WHEN c.root_categories_id IN (2000, 5000)
                AND (c.id NOT IN (2999, 5999)
                    OR (c.id IN (2999, 5999)
                        AND (LOWER(COALESCE(r.name, \'\')) REGEXP ?
                            OR LOWER(COALESCE(r.searchname, \'\')) REGEXP ?
                            OR (? = 1 AND (
                                LOWER(COALESCE(r.name, \'\')) REGEXP ?
                                OR LOWER(COALESCE(r.searchname, \'\')) REGEXP ?
                            ))
                            OR (? = 1 AND (
                                LOWER(COALESCE(r.name, \'\')) REGEXP ?
                                OR LOWER(COALESCE(r.searchname, \'\')) REGEXP ?
                            )))))
                AND r.size >= ? THEN 1 ELSE 0 END AS is_target,
                CASE WHEN c.root_categories_id IS NOT NULL
                AND c.root_categories_id NOT IN (1, 2000, 5000)
                AND c.id NOT IN (2999, 5999) THEN 1 ELSE 0 END AS is_non_target,
                CASE WHEN (c.id IS NULL
                OR c.root_categories_id IS NULL
                OR c.root_categories_id = 1
                OR (c.id IN (2999, 5999) AND NOT (
                    LOWER(COALESCE(r.name, \'\')) REGEXP ?
                    OR LOWER(COALESCE(r.searchname, \'\')) REGEXP ?
                    OR (? = 1 AND (
                        LOWER(COALESCE(r.name, \'\')) REGEXP ?
                        OR LOWER(COALESCE(r.searchname, \'\')) REGEXP ?
                    ))
                    OR (? = 1 AND (
                        LOWER(COALESCE(r.name, \'\')) REGEXP ?
                        OR LOWER(COALESCE(r.searchname, \'\')) REGEXP ?
                    ))
                )))
                AND r.size >= ? THEN 1 ELSE 0 END AS is_uncategorized
                FROM releases r
                INNER JOIN usenet_groups g ON g.id = r.groups_id
                LEFT JOIN categories c ON c.id = r.categories_id
                WHERE g.name = ?
                AND r.id > ?
                '.$upperBound.'
                '.$completedNzbPredicate.'
                AND r.postdate BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)
                    AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)
            ) classified', $bindings);

        return [
            'counts' => [
                'target' => max(0, (int) ($row->target_count ?? 0)),
                'non_target' => max(0, (int) ($row->non_target_count ?? 0)),
                'uncategorized' => max(0, (int) ($row->uncategorized_count ?? 0)),
            ],
            'bytes' => [
                'target' => max(0, (int) ($row->target_bytes ?? 0)),
                'non_target' => max(0, (int) ($row->non_target_bytes ?? 0)),
                'uncategorized' => max(0, (int) ($row->uncategorized_bytes ?? 0)),
            ],
        ];
    }

    /**
     * @return array{
     *     counts: array{target: int, non_target: int, uncategorized: int},
     *     bytes: array{target: int, non_target: int, uncategorized: int}
     * }
     */
    private function emptyBackfillCategoryQuality(): array
    {
        return [
            'counts' => ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
            'bytes' => ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
        ];
    }

    /**
     * Apply the production backfill quality policy to an exact set of release
     * IDs. This is shared by exact-lineage settlement so category 2999/5999,
     * TV name exceptions, and minimum payload semantics cannot drift.
     *
     * @param  list<int>  $releaseIds
     * @return array{
     *   counts:array{target:int,non_target:int,uncategorized:int},
     *   bytes:array{target:int,non_target:int,uncategorized:int}
     * }
     */
    public function currentForwardReleaseQualityForIds(array $releaseIds, string $group): array
    {
        $releaseIds = array_values(array_unique(array_filter(
            array_map('intval', $releaseIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($releaseIds === []) {
            return $this->emptyBackfillCategoryQuality();
        }

        $minimumBytes = max(1, (int) config(
            'nntmux.orchestrator.backfill_min_payload_bytes',
            104_857_600,
        ));
        $allowDateRange = in_array(
            $group,
            (array) config('nntmux.orchestrator.backfill_tv_date_range_groups', []),
            true,
        );
        $allowCompleteSeries = in_array(
            $group,
            (array) config('nntmux.orchestrator.backfill_tv_complete_series_groups', []),
            true,
        );
        $quality = $this->emptyBackfillCategoryQuality();
        $rows = DB::table('releases as r')
            ->leftJoin('categories as c', 'c.id', '=', 'r.categories_id')
            ->whereIn('r.id', $releaseIds)
            ->where('r.nzbstatus', 1)
            ->orderBy('r.id')
            ->get([
                'r.id',
                'r.name',
                'r.searchname',
                'r.size',
                'r.categories_id',
                'c.root_categories_id',
            ]);
        foreach ($rows as $row) {
            $category = (int) ($row->categories_id ?? 0);
            $root = $row->root_categories_id === null ? null : (int) $row->root_categories_id;
            $size = max(0, (int) $row->size);
            $miscQualified = ! in_array($category, [2999, 5999], true)
                || $this->releaseNameMatchesBackfillTarget(
                    (string) ($row->name ?? ''),
                    (string) ($row->searchname ?? ''),
                    $allowDateRange,
                    $allowCompleteSeries,
                );
            if (in_array($root, [2000, 5000], true)
                && $miscQualified
                && $size >= $minimumBytes
            ) {
                $quality['counts']['target']++;
                $quality['bytes']['target'] += $size;

                continue;
            }
            if ($root !== null
                && ! in_array($root, [1, 2000, 5000], true)
                && ! in_array($category, [2999, 5999], true)
            ) {
                $quality['counts']['non_target']++;
                $quality['bytes']['non_target'] += $size;

                continue;
            }
            if (($category === 0
                    || $root === null
                    || $root === 1
                    || (in_array($category, [2999, 5999], true) && ! $miscQualified))
                && $size >= $minimumBytes
            ) {
                $quality['counts']['uncategorized']++;
                $quality['bytes']['uncategorized'] += $size;
            }
        }

        return $quality;
    }

    private function releaseNameMatchesBackfillTarget(
        string $name,
        string $searchName,
        bool $allowDateRange,
        bool $allowCompleteSeries,
    ): bool {
        foreach ([$name, $searchName] as $candidate) {
            if ($this->matchesBackfillPattern(self::BACKFILL_TV_EPISODE_PATTERN, $candidate)
                || ($allowDateRange && $this->matchesBackfillPattern(self::BACKFILL_TV_DATE_RANGE_PATTERN, $candidate))
                || ($allowCompleteSeries && $this->matchesBackfillPattern(self::BACKFILL_TV_COMPLETE_SERIES_PATTERN, $candidate))
            ) {
                return true;
            }
        }

        return false;
    }

    private function matchesBackfillPattern(string $pattern, string $value): bool
    {
        return preg_match('~'.$pattern.'~i', strtolower($value)) === 1;
    }

    public function backfillPendingCollectionsForCohort(
        string $group,
        string $startPostdate,
        string $endPostdate,
    ): int {
        $postdateToleranceSeconds = (int) config('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);
        $completion = $this->backfillCompletionPercent();
        $collectionDelayHours = (int) (Settings::settingValue('delaytime') ?? 2);

        return (int) DB::scalar('SELECT COUNT(*) FROM (
            SELECT c.id
            FROM collections c
            INNER JOIN usenet_groups g ON g.id = c.groups_id
            INNER JOIN binaries b ON b.collections_id = c.id
            WHERE g.name = ?
            AND c.releases_id IS NULL
            AND c.filecheck IN (0, 1, 2, 3, 10, 15, 16)
            AND c.date BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)
                AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)
            GROUP BY c.id, c.totalfiles, c.filecheck, c.dateadded
            HAVING (
                COALESCE(NULLIF(c.totalfiles, 0), MAX(NULLIF(b.filenumber, 0)), 0) > 0
                AND COUNT(DISTINCT CASE WHEN b.filenumber > 0 THEN b.filenumber ELSE b.id END)
                    >= GREATEST(1, CEIL(COALESCE(NULLIF(c.totalfiles, 0), MAX(NULLIF(b.filenumber, 0)), 0) * ? / 100))
                AND SUM(CASE
                    WHEN b.totalparts > 0 AND b.currentparts >= CEIL(b.totalparts * ? / 100) THEN 1
                    ELSE 0
                END) >= GREATEST(1, CEIL(COALESCE(NULLIF(c.totalfiles, 0), MAX(NULLIF(b.filenumber, 0)), 0) * ? / 100))
            ) OR (
                c.filecheck IN (0, 1, 10)
                AND c.dateadded < DATE_SUB(NOW(), INTERVAL ? HOUR)
                AND COUNT(DISTINCT CASE WHEN b.filenumber > 0 THEN b.filenumber ELSE b.id END)
                    >= GREATEST(1, CEIL(COALESCE(NULLIF(c.totalfiles, 0), MAX(NULLIF(b.filenumber, 0)), 0) * ? / 100))
                AND SUM(CASE
                    WHEN b.totalparts > 0 AND b.currentparts >= CEIL(b.totalparts * ? / 100) THEN 1
                    ELSE 0
                END) = COUNT(b.id)
            ) OR (
                c.filecheck = 3
            )
        ) releasable_collections', [
            $group,
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
            $completion,
            $completion,
            $completion,
            $collectionDelayHours,
            $completion,
            $completion,
        ]);
    }

    /**
     * Return one internally consistent observation for a current-forward cohort.
     *
     * @return array{
     *   release_count:int,
     *   release_high:int,
     *   pending_collections:int,
     *   counts:array{target:int,non_target:int,uncategorized:int},
     *   bytes:array{target:int,non_target:int,uncategorized:int},
     *   hash:string
     * }
     */
    public function currentForwardCohortObservation(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): array {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Current-forward cohort observations require a top-level consistent transaction.');
        }
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($group, $releaseHighWatermark, $startPostdate, $endPostdate): array {
            $releaseCount = $this->backfillCreatedReleasesForCohort(
                $group,
                $releaseHighWatermark,
                $startPostdate,
                $endPostdate,
            );
            $quality = $this->backfillCreatedNzbCategoryQualityForCohort(
                $group,
                $releaseHighWatermark,
                $startPostdate,
                $endPostdate,
            );
            $pendingCollections = $this->backfillPendingCollectionsForCohort(
                $group,
                $startPostdate,
                $endPostdate,
            );
            $releaseHigh = $this->backfillReleaseHighForCohort(
                $group,
                $releaseHighWatermark,
                $startPostdate,
                $endPostdate,
            );
            $canonical = [
                'release_count' => $releaseCount,
                'release_high' => $releaseHigh,
                'pending_collections' => $pendingCollections,
                'counts' => $quality['counts'],
                'bytes' => $quality['bytes'],
            ];

            return [
                ...$canonical,
                'hash' => hash('sha256', (string) json_encode($canonical, JSON_THROW_ON_ERROR)),
            ];
        }, 3);
    }

    private function backfillReleaseHighForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): int {
        $postdateToleranceSeconds = (int) config('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);

        return (int) DB::scalar('SELECT COALESCE(MAX(r.id), ?)
            FROM releases r
            INNER JOIN usenet_groups g ON g.id = r.groups_id
            WHERE g.name = ?
            AND r.id > ?
            AND r.postdate BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)
                AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)', [
            max(0, $releaseHighWatermark),
            $group,
            max(0, $releaseHighWatermark),
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
        ]);
    }

    private function backfillCompletionPercent(): int
    {
        $completion = (int) (Settings::settingValue('completionpercent') ?? 94);

        return $completion <= 0 ? 100 : min(100, $completion);
    }

    public function backfillCreatedReleasesForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): int {
        $postdateToleranceSeconds = (int) config('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);

        return (int) DB::scalar('SELECT COUNT(*)
            FROM releases r
            INNER JOIN usenet_groups g ON g.id = r.groups_id
            WHERE g.name = ?
            AND r.id > ?
            AND r.postdate BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)
                AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)', [
            $group,
            max(0, $releaseHighWatermark),
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
            $startPostdate,
            $endPostdate,
            $postdateToleranceSeconds,
        ]);
    }

    /**
     * @return list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>
     */
    public function backfillCandidates(): array
    {
        $allowedGroups = array_values(array_unique(array_filter(array_map(
            static fn (mixed $group): string => trim((string) $group),
            (array) config('nntmux.orchestrator.backfill_probe_groups', []),
        ), static fn (string $group): bool => $group !== '')));
        if ($allowedGroups === []) {
            return [];
        }

        // Sentinel "all" (case-insensitive): schedule every group whose DB flags
        // opt it into backfill, instead of a hand-curated allowlist. The DB
        // (g.backfill = 1 plus the active/inactive range guards below) becomes
        // the single source of truth. The LIMIT still caps the per-cycle pool.
        $scheduleAll = false;
        foreach ($allowedGroups as $group) {
            if (strcasecmp($group, 'all') === 0) {
                $scheduleAll = true;
                break;
            }
        }

        if (! $scheduleAll) {
            if (count($allowedGroups) > self::BACKFILL_CANDIDATE_LIMIT) {
                return [];
            }
            $placeholders = implode(', ', array_fill(0, count($allowedGroups), '?'));
            $nameFilterSql = ' AND BINARY g.name IN ('.$placeholders.')';
            $bindings = $allowedGroups;
            // Curated allowlist: preserve historical ordering (newest cursor
            // postdate first) — the list is <= LIMIT so no group is truncated.
            $orderBySql = 'g.first_record_postdate DESC, g.name ASC';
        } else {
            $nameFilterSql = '';
            $bindings = [];
            // "all" mode can have more eligible groups than BACKFILL_CANDIDATE_LIMIT.
            // Rotate the candidate window by least-recently-processed first
            // (g.last_updated ASC) so no group is permanently starved out of the
            // pool; NULLs (never processed) sort first so fresh groups bootstrap.
            // The BackfillTargetSelector still applies yield/fairness within the
            // returned window.
            $orderBySql = 'g.last_updated IS NOT NULL, g.last_updated ASC, g.name ASC';
        }

        // The backfill flag is the operator's opt-in in every profile; free_run
        // must not widen it to active groups an operator disabled.
        $datePendingSql = ($this->eligibility ?? BackfillDateEligibilityPolicy::fromRuntime())->datePendingSql('g');

        $rows = DB::select('SELECT
            g.name,
            CAST(g.first_record AS SIGNED) AS backfill_cursor,
            CAST(g.first_record_postdate AS CHAR) AS cursor_postdate,
            CAST(g.first_record AS SIGNED) - CAST(s.first_record AS SIGNED) AS remaining_articles
            FROM usenet_groups g
            INNER JOIN short_groups s ON s.name = g.name
            WHERE g.backfill = 1'.$nameFilterSql.'
            AND g.first_record IS NOT NULL
            AND g.first_record_postdate >= \'2000-01-01\'
            AND '.$datePendingSql.'
            AND s.updated >= NOW() - INTERVAL 10 MINUTE
            AND CAST(s.first_record AS SIGNED) > 0
            AND CAST(s.last_record AS SIGNED) >= CAST(s.first_record AS SIGNED)
            AND CAST(g.last_record AS SIGNED) BETWEEN CAST(s.first_record AS SIGNED) AND CAST(s.last_record AS SIGNED)
            AND (
                (g.active = 1 AND CAST(s.last_record AS SIGNED) - CAST(g.last_record AS SIGNED) <= '.$this->currentGroupMaxLag().')
                OR (
                    g.active = 0
                    AND g.last_record_postdate >= \'2000-01-01\'
                    AND CAST(g.last_record AS SIGNED) < 9223372036854775807
                    AND CAST(g.first_record AS SIGNED) >= CAST(s.first_record AS SIGNED)
                    AND CAST(g.first_record AS SIGNED) <= CAST(g.last_record AS SIGNED) + 1
                )
            )
            AND CAST(g.first_record AS SIGNED) - CAST(s.first_record AS SIGNED) >= '.$this->minimumBackfillRangeArticles().'
            ORDER BY '.$orderBySql.'
            LIMIT '.self::BACKFILL_CANDIDATE_LIMIT, $bindings);

        return array_map(fn (object $row): array => [
            'name' => (string) $row->name,
            'cursor' => (int) $row->backfill_cursor,
            'cursor_postdate' => (string) $row->cursor_postdate,
            'remaining_articles' => $this->remainingArticlesWithinStopCursor(
                (string) $row->name,
                (int) $row->backfill_cursor,
                (int) $row->remaining_articles,
            ),
        ], $rows);
    }

    private function remainingArticlesWithinStopCursor(string $group, int $cursor, int $remainingArticles): int
    {
        return (new BackfillStopCursorPolicy)->remainingArticles($group, $cursor, $remainingArticles);
    }

    /**
     * Smallest remaining range worth a permit. It must never be below the
     * backfill lane's own floor: a permit the lane skips is never claimed, and
     * an unclaimed permit blocks every later grant.
     */
    private function minimumBackfillRangeArticles(): int
    {
        return max(1, (int) config('nntmux.safe_backfill_min_articles', 100));
    }

    /**
     * @param  array<string, int>  $now
     * @param  array<string, int|float>|null  $previous
     * @return array{array<string, float>, array<string, float>}
     */
    private function rates(array $now, ?array $previous): array
    {
        $rates = [];
        $ewma = [];
        $currentTime = time();
        $previousObservedAt = $previous === null ? 0 : (int) ($previous['observed_at'] ?? 0);
        $elapsed = $currentTime - $previousObservedAt;
        $previousFresh = $previous !== null
            && $elapsed >= 1
            && $elapsed <= (int) config('nntmux.orchestrator.snapshot_max_age_seconds', 180);
        foreach ($now as $stage => $value) {
            $schedulableStage = in_array($stage, ['parts', 'binaries', 'collections', 'collections_total', 'recovery_sources'], true);
            $previousCompatible = $previousFresh
                && (! $schedulableStage || (int) ($previous['schema_version'] ?? 0) >= 4);
            $rate = ! $previousCompatible ? 0.0 : ($value - (int) ($previous[$stage] ?? $value)) * 60 / $elapsed;
            $rates[$stage] = $rate;
            $ewma[$stage] = ! $previousCompatible ? 0.0 : 0.3 * $rate + 0.7 * (float) ($previous['ewma_'.$stage] ?? 0.0);
        }

        return [$rates, $ewma];
    }

    /**
     * Collections the hopeless purge has not been allowed to judge yet.
     *
     * Per-article obfuscated groups (alt.binaries.boneless) turn every article
     * into a collection of one binary that no later article can join. The
     * hopeless-purge lane deletes them once they are older than its age
     * cutoff, so at any moment the table holds roughly inflow x cutoff of
     * them: measured 2026-11-27, 330k of boneless's 401k collections were
     * under 20 minutes old, against a 600k collections_total watermark. That
     * pool is in transit, not backlog. Counting it held physical capacity
     * high, shut the current-forward gate and denied backfill for hours a
     * day, while the purge was keeping up.
     *
     * Only the configured groups, only pending filechecks in active or
     * backfill groups (the same population the schedulable and total counts
     * use), and only rows younger than the configured minutes are counted.
     * Keep the minutes equal to NNTMUX_HOPELESS_PURGE_AGE_MINUTES: shorter
     * still counts part of the pool, longer would also hide collections the
     * purge has already declined to delete. Real collections in these groups
     * are hidden from the gates for at most those minutes.
     *
     * Two statements on purpose. Joined to usenet_groups, MariaDB walks
     * ix_collections_release_stage6 end to end (type=index, ~400 ms on 2026-11-27
     * data); with the ids resolved first the count is a range scan on
     * (groups_id, filecheck, dateadded, id), ~180 ms for 374k matching rows,
     * the same cost as the existing total count. usenet_groups.name is
     * utf8mb4_unicode_ci, so the plain IN uses ix_groups_name (~1 ms) and is
     * as case-insensitive as the lowercased config; LOWER(name) would scan all
     * 111k groups.
     */
    private function inFlightCollections(): int
    {
        $groups = (array) config('nntmux.orchestrator.in_flight_collection_groups', []);
        $minutes = (int) config('nntmux.orchestrator.in_flight_collection_minutes', 0);
        if ($groups === [] || $minutes <= 0) {
            return 0;
        }

        $ids = array_map(
            static fn (object $row): int => (int) $row->id,
            DB::select('SELECT id FROM usenet_groups
                WHERE name IN ('.implode(', ', array_fill(0, count($groups), '?')).')
                    AND (active = 1 OR backfill = 1)', $groups),
        );
        if ($ids === []) {
            return 0;
        }

        return max(0, (int) DB::scalar('SELECT COUNT(*) FROM collections
            WHERE groups_id IN ('.implode(', ', array_fill(0, count($ids), '?')).')
                AND filecheck IN (0, 1, 2, 15, 16)
                AND dateadded > NOW() - INTERVAL ? MINUTE', [...$ids, $minutes]));
    }

    /**
     * Remove the in-flight pool from every collection figure the gates read.
     *
     * All three move together. The schedulable count also feeds the growth
     * rates, whose previous value is persisted from this same figure; mixing
     * adjusted and raw readings would report the pool's size as a drain or a
     * flood on every cycle. In-flight rows are a subset of each count, so the
     * clamp only matters if the counts were read at different instants.
     *
     * @return array{total: int, ordinary: int, schedulable: int, in_flight: int}
     */
    private static function withoutInFlightCollections(int $total, int $ordinary, int $schedulable, int $inFlight): array
    {
        $inFlight = max(0, min($inFlight, $total));

        return [
            'total' => $total - $inFlight,
            'ordinary' => max(0, $ordinary - $inFlight),
            'schedulable' => max(0, $schedulable - $inFlight),
            'in_flight' => $inFlight,
        ];
    }

    private function physicalCapacityHigh(
        int $parts,
        int $binaries,
        int $ordinaryCollections,
        int $totalCollections,
        int $recoverySources,
    ): bool {
        return $parts >= (int) config('nntmux.orchestrator.high_watermarks.parts', PHP_INT_MAX)
            || $binaries >= (int) config('nntmux.orchestrator.high_watermarks.binaries', PHP_INT_MAX)
            || $ordinaryCollections >= (int) config('nntmux.orchestrator.high_watermarks.collections', PHP_INT_MAX)
            || $totalCollections >= (int) config('nntmux.orchestrator.high_watermarks.collections_total', PHP_INT_MAX)
            || $recoverySources >= (int) config('nntmux.orchestrator.high_watermarks.recovery_sources', PHP_INT_MAX);
    }

    /**
     * Committed releases per minute, or null when it cannot be measured.
     *
     * Returning 0.0 for an unusable window made a stale reading
     * indistinguishable from a genuinely idle pipeline: the controller loops
     * every 15s against a 180s freshness bound, so one slow pass reported zero
     * yield while releases were still landing, and recovery treated that as an
     * unproductive pipeline. Null means "no information".
     *
     * @param  array<string, int|float>|null  $previous
     */
    private function releaseYieldPerMinute(int $releaseCreatedTotal, ?array $previous, int $now): ?float
    {
        $previousObservedAt = (int) ($previous['observed_at'] ?? 0);
        $previousTotal = (int) ($previous['release_created_total'] ?? $releaseCreatedTotal);
        $elapsed = $now - $previousObservedAt;
        if ((int) ($previous['schema_version'] ?? 0) < 5
            || $elapsed < 1
            || $elapsed > (int) config('nntmux.orchestrator.snapshot_max_age_seconds', 180)
            // A counter that moved backwards means the worker telemetry reset,
            // so the delta is meaningless rather than zero.
            || $releaseCreatedTotal < $previousTotal
        ) {
            return null;
        }

        return ($releaseCreatedTotal - $previousTotal) * 60 / $elapsed;
    }
}
