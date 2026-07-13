<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;
use App\Services\Nzb\NzbBacklogCreationService;
use Illuminate\Support\Facades\DB;

class PipelineSnapshotRepository
{
    private const int BACKFILL_CANDIDATE_LIMIT = 16;

    private readonly BackfillTargetSelector $targets;

    private readonly WorkerControlStateStore $state;

    private readonly PipelinePressureClassifier $pressure;

    public function __construct(
        private readonly PrometheusSafetySignalProvider $safety,
        private readonly NzbBacklogCreationService $nzbBacklog,
        ?BackfillTargetSelector $targets = null,
        ?WorkerControlStateStore $state = null,
        ?PipelinePressureClassifier $pressure = null,
    ) {
        $this->targets = $targets ?? new BackfillTargetSelector;
        $this->state = $state ?? new WorkerControlStateStore;
        $this->pressure = $pressure ?? new PipelinePressureClassifier;
    }

    /** @param array<string, int|float>|null $previous */
    public function capture(?array $previous = null): PipelineSnapshot
    {
        $recoveryCriteria = $this->bodyRecoveryCriteria();
        $recoverySources = $this->bodyRecoverySourceSnapshot($recoveryCriteria);
        $tables = DB::selectOne("SELECT
            COALESCE(MAX(CASE WHEN TABLE_NAME = 'parts' THEN TABLE_ROWS END), 0) AS parts_count,
            COALESCE(MAX(CASE WHEN TABLE_NAME = 'binaries' THEN TABLE_ROWS END), 0) AS binaries_count
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('parts', 'binaries')");
        $pipeline = DB::selectOne('SELECT
            (SELECT COUNT(*) FROM collections WHERE filecheck IN (0, 1, 2, 15, 16)) AS collections_backlog,
            (SELECT COUNT(*) FROM binaries WHERE partcheck = 0) AS binaries_backlog,
            (SELECT COUNT(*) FROM collections WHERE filecheck = 3) AS ready_collections,
            (SELECT COUNT(*) FROM collections WHERE filecheck = 3) AS releases_backlog,
            (SELECT COUNT(*) FROM releases) AS release_total,
            (SELECT COUNT(*) FROM releases WHERE nzbstatus = 0) AS nzbs_backlog,
            (SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(c.dateadded), NOW()), 0) FROM binaries b INNER JOIN collections c ON c.id = b.collections_id WHERE b.partcheck = 0) AS oldest_binary_age,
            (SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(dateadded), NOW()), 0) FROM collections WHERE filecheck IN (0, 1, 2, 15, 16)) AS oldest_collection_age,
            (SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(dateadded), NOW()), 0) FROM collections WHERE filecheck = 3) AS oldest_release_age,
            (SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(adddate), NOW()), 0) FROM releases WHERE nzbstatus = 0) AS oldest_nzb_age,
            NOT EXISTS(SELECT 1 FROM usenet_groups g LEFT JOIN short_groups s ON s.name = g.name
                WHERE g.active = 1 AND (s.name IS NULL OR CAST(s.last_record AS SIGNED) - CAST(g.last_record AS SIGNED) > 10000)) AS current_groups');
        $statusRows = DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_deadlocks', 'Innodb_row_lock_current_waits')");
        $status = [];
        foreach ($statusRows as $row) {
            $status[(string) $row->Variable_name] = (int) $row->Value;
        }
        $signals = $this->safety->signals();
        $eligibleNzbs = $this->nzbBacklog->eligibleCandidateCount((int) config('nntmux.distributed_nzb_scan_cap', 10000));
        $controlState = $this->state->loadState();
        $yieldHistory = $this->state->backfillYieldHistory();

        $totalCollections = (int) ($pipeline->collections_backlog ?? 0);
        $recoverySourceCollections = (int) $recoverySources['backlog'];
        $ordinaryCollections = max(0, $totalCollections - $recoverySourceCollections);
        $splitConsistent = $recoverySourceCollections <= $totalCollections;
        $backlogs = [
            'parts' => (int) ($tables->parts_count ?? 0),
            'binaries' => (int) ($pipeline->binaries_backlog ?? 0),
            'collections' => $ordinaryCollections,
            'collections_total' => $totalCollections,
            'recovery_sources' => $recoverySourceCollections,
            'releases' => (int) ($pipeline->releases_backlog ?? 0),
            'nzbs' => (int) ($pipeline->nzbs_backlog ?? 0),
        ];
        $backfillTarget = $this->selectBackfillTarget(
            $this->safeBackfillCandidates($this->backfillCandidates(), $backlogs),
            $yieldHistory,
            $controlState,
            time(),
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
        [$rates, $ewma] = $this->rates($backlogs, $previous);
        $high = $this->pressure->isHigh($backlogs, $ages, $ewma);
        $low = $this->pressure->isLow($backlogs, $ewma);
        $deadlocks = isset($status['Innodb_deadlocks']) ? (int) $status['Innodb_deadlocks'] : null;
        $waits = isset($status['Innodb_row_lock_current_waits']) ? (int) $status['Innodb_row_lock_current_waits'] : null;

        return new PipelineSnapshot(
            partsBacklog: $backlogs['parts'],
            binariesBacklog: $backlogs['binaries'],
            collectionsBacklog: $backlogs['collections'],
            releasesBacklog: $backlogs['releases'],
            nzbsBacklog: $backlogs['nzbs'],
            telemetryFresh: $signals['fresh'],
            telemetryComplete: $tables !== null && $pipeline !== null,
            telemetryConsistent: $splitConsistent,
            databaseMemorySafe: $signals['memory_safe'],
            databaseCpuSafe: $signals['cpu_safe'],
            databaseWaitsSafe: $this->databaseWaitsSafe($deadlocks, $waits, $previous),
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
            observedAt: time(),
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
            bodyRecoverySourceBacklog: $recoverySourceCollections,
            oldestBodyRecoverySourceAgeSeconds: (int) $recoverySources['oldest_age'],
        );
    }

    /** @param array<string, int|float>|null $previous */
    private function databaseWaitsSafe(?int $deadlocks, ?int $currentWaits, ?array $previous): bool
    {
        if ($deadlocks === null || $currentWaits === null) {
            return false;
        }

        $deadlockDelta = $previous !== null
            && isset($previous['database_deadlocks'])
            && $deadlocks > (int) $previous['database_deadlocks'];
        $elapsed = $previous === null ? 0 : time() - (int) ($previous['observed_at'] ?? 0);
        $previousIsConsecutive = $elapsed >= 30 && $elapsed <= 180;
        $persistentWaits = $currentWaits > 0
            && $previousIsConsecutive
            && (int) ($previous['database_current_waits'] ?? 0) > 0;

        return ! $deadlockDelta && ! $persistentWaits;
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
                FROM collections c WHERE c.filecheck IN (0, 1, 2, 15, 16)');
        }
        $predicate = $criteria->identityPredicate();

        return (int) DB::scalar("SELECT COALESCE(TIMESTAMPDIFF(SECOND, MIN(c.dateadded), NOW()), 0)
            FROM collections c
            WHERE c.filecheck IN (0, 1, 2, 15, 16)
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
    private function safeBackfillQuantity(array $backlogs, string $backfillGroup = ''): int
    {
        $fraction = (float) config('nntmux.orchestrator.backfill_headroom_fraction', 0.10);
        $high = (array) config('nntmux.orchestrator.high_watermarks', []);
        $growth = $this->state->backfillGrowthFor($backfillGroup);
        $quantities = [];
        foreach (['parts', 'binaries', 'collections', 'collections_total', 'releases', 'nzbs'] as $stage) {
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
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $candidates
     * @param  array{parts: int, binaries: int, collections: int, collections_total: int, releases: int, nzbs: int}  $backlogs
     * @return list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int, safe_quantity: int}>
     */
    private function safeBackfillCandidates(array $candidates, array $backlogs): array
    {
        $safe = [];
        foreach ($candidates as $candidate) {
            $quantity = $this->safeBackfillQuantity($backlogs, $candidate['name']);
            if ($quantity >= 10_000) {
                $safe[] = [...$candidate, 'safe_quantity' => $quantity];
            }
        }

        return $safe;
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int, safe_quantity: int}>  $candidates
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int, safe_quantity: int}|null
     */
    private function selectBackfillTarget(array $candidates, array $history, ControlState $state, int $now): ?array
    {
        $pendingGroups = $this->state->pendingBackfillDelayedAttributionGroups();
        $contextRepeat = $this->state->backfillContextRepeat($now);
        $continuationGroup = trim((string) ($contextRepeat['group'] ?? ''));
        if (! $this->state->backfillDelayedAttributionCanContinue($continuationGroup, $now)) {
            $continuationGroup = '';
            $contextRepeat = null;
        }
        $candidates = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => ! in_array($candidate['name'], $pendingGroups, true)
                || $candidate['name'] === $continuationGroup,
        ));

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
        return $this->backfillNzbCategoryCountsForCohort(
            $group,
            max(0, $releaseHighWatermark),
            null,
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

        return $this->backfillNzbCategoryCountsForCohort(
            $group,
            max(0, $releaseIdLowExclusive),
            max(0, $releaseIdHighInclusive),
            $startPostdate,
            $endPostdate,
        );
    }

    /** @return array{target: int, non_target: int, uncategorized: int} */
    private function backfillNzbCategoryCountsForCohort(
        string $group,
        int $releaseIdLowExclusive,
        ?int $releaseIdHighInclusive,
        string $startPostdate,
        string $endPostdate,
    ): array {
        $postdateToleranceSeconds = (int) config('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);
        $upperBound = $releaseIdHighInclusive === null ? '' : 'AND r.id <= ?';
        $bindings = [$group, $releaseIdLowExclusive];
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
            COALESCE(SUM(CASE WHEN c.root_categories_id IN (2000, 5000)
                AND c.id NOT IN (2999, 5999) THEN 1 ELSE 0 END), 0) AS target,
            COALESCE(SUM(CASE WHEN c.root_categories_id IS NOT NULL
                AND c.root_categories_id NOT IN (1, 2000, 5000)
                AND c.id NOT IN (2999, 5999) THEN 1 ELSE 0 END), 0) AS non_target,
            COALESCE(SUM(CASE WHEN c.id IS NULL
                OR c.root_categories_id IS NULL
                OR c.root_categories_id = 1
                OR c.id IN (2999, 5999) THEN 1 ELSE 0 END), 0) AS uncategorized
            FROM releases r
            INNER JOIN usenet_groups g ON g.id = r.groups_id
            LEFT JOIN categories c ON c.id = r.categories_id
            WHERE g.name = ?
            AND r.id > ?
            '.$upperBound.'
            AND r.nzbstatus = 1
            AND r.postdate BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)
                AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)', $bindings);

        return [
            'target' => max(0, (int) ($row->target ?? 0)),
            'non_target' => max(0, (int) ($row->non_target ?? 0)),
            'uncategorized' => max(0, (int) ($row->uncategorized ?? 0)),
        ];
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
        if (count($allowedGroups) > self::BACKFILL_CANDIDATE_LIMIT) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($allowedGroups), '?'));

        $rows = DB::select('SELECT
            g.name,
            CAST(g.first_record AS SIGNED) AS backfill_cursor,
            CAST(g.first_record_postdate AS CHAR) AS cursor_postdate,
            CAST(g.first_record AS SIGNED) - CAST(s.first_record AS SIGNED) AS remaining_articles
            FROM usenet_groups g
            INNER JOIN short_groups s ON s.name = g.name
            WHERE g.backfill = 1
            AND BINARY g.name IN ('.$placeholders.')
            AND g.first_record IS NOT NULL
            AND g.first_record_postdate >= \'2000-01-01\'
            AND s.updated >= NOW() - INTERVAL 10 MINUTE
            AND CAST(s.first_record AS SIGNED) > 0
            AND CAST(s.last_record AS SIGNED) >= CAST(s.first_record AS SIGNED)
            AND CAST(g.last_record AS SIGNED) BETWEEN CAST(s.first_record AS SIGNED) AND CAST(s.last_record AS SIGNED)
            AND (
                (g.active = 1 AND CAST(s.last_record AS SIGNED) - CAST(g.last_record AS SIGNED) <= 10000)
                OR (
                    g.active = 0
                    AND g.last_record_postdate >= \'2000-01-01\'
                    AND CAST(g.last_record AS SIGNED) < 9223372036854775807
                    AND CAST(g.first_record AS SIGNED) >= CAST(s.first_record AS SIGNED)
                    AND CAST(g.first_record AS SIGNED) <= CAST(g.last_record AS SIGNED) + 1
                )
            )
            AND CAST(g.first_record AS SIGNED) - CAST(s.first_record AS SIGNED) > 10000
            ORDER BY g.first_record_postdate DESC, g.name ASC
            LIMIT '.self::BACKFILL_CANDIDATE_LIMIT, $allowedGroups);

        return array_map(static fn (object $row): array => [
            'name' => (string) $row->name,
            'cursor' => (int) $row->backfill_cursor,
            'cursor_postdate' => (string) $row->cursor_postdate,
            'remaining_articles' => (int) $row->remaining_articles,
        ], $rows);
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
            $splitStage = in_array($stage, ['collections', 'collections_total', 'recovery_sources'], true);
            $previousCompatible = $previousFresh && (! $splitStage || (int) ($previous['schema_version'] ?? 0) === 2);
            $rate = ! $previousCompatible ? 0.0 : ($value - (int) ($previous[$stage] ?? $value)) * 60 / $elapsed;
            $rates[$stage] = $rate;
            $ewma[$stage] = ! $previousCompatible ? 0.0 : 0.3 * $rate + 0.7 * (float) ($previous['ewma_'.$stage] ?? 0.0);
        }

        return [$rates, $ewma];
    }
}
