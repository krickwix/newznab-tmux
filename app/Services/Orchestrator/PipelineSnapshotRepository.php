<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Services\Nzb\NzbBacklogCreationService;
use Illuminate\Support\Facades\DB;

class PipelineSnapshotRepository
{
    private const int BACKFILL_CANDIDATE_LIMIT = 16;

    private readonly BackfillTargetSelector $targets;

    private readonly WorkerControlStateStore $state;

    public function __construct(
        private readonly PrometheusSafetySignalProvider $safety,
        private readonly NzbBacklogCreationService $nzbBacklog,
        ?BackfillTargetSelector $targets = null,
        ?WorkerControlStateStore $state = null,
    ) {
        $this->targets = $targets ?? new BackfillTargetSelector;
        $this->state = $state ?? new WorkerControlStateStore;
    }

    /** @param array<string, int|float>|null $previous */
    public function capture(?array $previous = null): PipelineSnapshot
    {
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
        $backfillTarget = $this->targets->select(
            $this->backfillCandidates(),
            $this->state->backfillYieldHistory(),
            time(),
            $controlState->ineffectiveBackfillPermitsByTarget,
        );
        $backfillGroup = (string) ($backfillTarget['name'] ?? '');

        $backlogs = [
            'parts' => (int) ($tables->parts_count ?? 0),
            'binaries' => (int) ($pipeline->binaries_backlog ?? 0),
            'collections' => (int) ($pipeline->collections_backlog ?? 0),
            'releases' => (int) ($pipeline->releases_backlog ?? 0),
            'nzbs' => (int) ($pipeline->nzbs_backlog ?? 0),
        ];
        $ages = [
            'binaries' => (int) ($pipeline->oldest_binary_age ?? 0),
            'collections' => (int) ($pipeline->oldest_collection_age ?? 0),
            'releases' => (int) ($pipeline->oldest_release_age ?? 0),
            'nzbs' => (int) ($pipeline->oldest_nzb_age ?? 0),
        ];
        if ($eligibleNzbs === 0) {
            $ages['nzbs'] = 0;
        }
        [$rates, $ewma] = $this->rates($backlogs, $previous);
        $high = $this->isHigh($backlogs, $ages, $ewma);
        $low = $this->isLow($backlogs, $ewma);
        $deadlocks = $status['Innodb_deadlocks'] ?? 0;
        $waits = $status['Innodb_row_lock_current_waits'] ?? 0;
        $deadlockDelta = $previous !== null && isset($previous['database_deadlocks']) && $deadlocks > $previous['database_deadlocks'];

        return new PipelineSnapshot(
            partsBacklog: $backlogs['parts'],
            binariesBacklog: $backlogs['binaries'],
            collectionsBacklog: $backlogs['collections'],
            releasesBacklog: $backlogs['releases'],
            nzbsBacklog: $backlogs['nzbs'],
            telemetryFresh: $signals['fresh'],
            telemetryComplete: $tables !== null && $pipeline !== null,
            databaseMemorySafe: $signals['memory_safe'],
            databaseCpuSafe: $signals['cpu_safe'],
            databaseWaitsSafe: $waits === 0 && ! $deadlockDelta,
            storageSafe: $signals['storage_safe'],
            highPressure: $high,
            lowPressure: $low,
            providerAvailable: $backfillGroup !== '',
            cursorAvailable: $backfillGroup !== '',
            currentGroupsAvailable: (bool) ($pipeline->current_groups ?? false),
            eligibleBackfillSupply: $eligibleNzbs === 0 && $backfillGroup !== '',
            databaseDeadlocks: $deadlocks,
            databaseCurrentWaits: $waits,
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
        );
    }

    /** @return array{cursor: int, cursor_postdate: string, ready_collections: int, releases: int, release_high_watermark: int} */
    public function backfillOutcomeForGroup(string $group): array
    {
        $row = DB::selectOne('SELECT
            CAST(g.first_record AS SIGNED) AS backfill_cursor,
            CAST(g.first_record_postdate AS CHAR) AS cursor_postdate,
            (SELECT COUNT(*) FROM collections c WHERE c.groups_id = g.id AND c.filecheck = 3) AS ready_collections,
            (SELECT COUNT(*) FROM releases r WHERE r.groups_id = g.id) AS releases,
            (SELECT COALESCE(MAX(r.id), 0) FROM releases r WHERE r.groups_id = g.id) AS release_high_watermark
            FROM usenet_groups g WHERE g.name = ? LIMIT 1', [$group]);

        return [
            'cursor' => (int) ($row->backfill_cursor ?? 0),
            'cursor_postdate' => (string) ($row->cursor_postdate ?? ''),
            'ready_collections' => (int) ($row->ready_collections ?? 0),
            'releases' => (int) ($row->releases ?? 0),
            'release_high_watermark' => (int) ($row->release_high_watermark ?? 0),
        ];
    }

    public function backfillCreatedNzbsForCohort(
        string $group,
        int $releaseHighWatermark,
        string $startPostdate,
        string $endPostdate,
    ): int {
        return (int) DB::scalar('SELECT COUNT(*)
            FROM releases r
            INNER JOIN usenet_groups g ON g.id = r.groups_id
            WHERE g.name = ?
            AND r.id > ?
            AND r.nzbstatus = 1
            AND r.postdate BETWEEN LEAST(?, ?) AND GREATEST(?, ?)', [
            $group,
            max(0, $releaseHighWatermark),
            $startPostdate,
            $endPostdate,
            $startPostdate,
            $endPostdate,
        ]);
    }

    /**
     * @return list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>
     */
    public function backfillCandidates(): array
    {
        $rows = DB::select('SELECT
            g.name,
            CAST(g.first_record AS SIGNED) AS backfill_cursor,
            CAST(g.first_record_postdate AS CHAR) AS cursor_postdate,
            CAST(g.first_record AS SIGNED) - CAST(s.first_record AS SIGNED) AS remaining_articles
            FROM usenet_groups g
            INNER JOIN short_groups s ON s.name = g.name
            WHERE g.active = 1
            AND g.backfill = 1
            AND g.first_record IS NOT NULL
            AND g.first_record_postdate >= \'2000-01-01\'
            AND s.updated >= NOW() - INTERVAL 10 MINUTE
            AND CAST(s.first_record AS SIGNED) > 0
            AND CAST(s.last_record AS SIGNED) >= CAST(s.first_record AS SIGNED)
            AND CAST(s.last_record AS SIGNED) - CAST(g.last_record AS SIGNED) <= 10000
            AND CAST(g.first_record AS SIGNED) - CAST(s.first_record AS SIGNED) >= 20000
            ORDER BY g.first_record_postdate DESC, g.name ASC
            LIMIT '.self::BACKFILL_CANDIDATE_LIMIT, []);

        return array_map(static fn (object $row): array => [
            'name' => (string) $row->name,
            'cursor' => (int) $row->backfill_cursor,
            'cursor_postdate' => (string) $row->cursor_postdate,
            'remaining_articles' => (int) $row->remaining_articles,
        ], $rows);
    }

    /**
     * @param  array<string, int>  $now
     * @param  array<string, int>  $ages
     * @param  array<string, float>  $ewma
     */
    private function isHigh(array $now, array $ages, array $ewma): bool
    {
        foreach ($now as $stage => $value) {
            $limit = (int) config('nntmux.orchestrator.high_watermarks.'.$stage, PHP_INT_MAX);
            $growthLimit = max(1.0, $limit * 0.001);
            if ($value >= $limit || ($ewma[$stage] ?? 0.0) > $growthLimit) {
                return true;
            }
        }

        foreach ($ages as $stage => $age) {
            $low = (int) floor((int) config('nntmux.orchestrator.high_watermarks.'.$stage, 0) * 0.6);
            if (($now[$stage] ?? 0) > $low && $age >= (int) config('nntmux.orchestrator.age_slo_seconds.'.$stage, PHP_INT_MAX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $now
     * @param  array<string, float>  $ewma
     */
    private function isLow(array $now, array $ewma): bool
    {
        // Parts and binaries are capacity signals: they must remain below their
        // hard watermarks and must not grow, but they need not drain to 60%.
        foreach (['parts', 'binaries'] as $stage) {
            $value = $now[$stage];
            $high = (int) config('nntmux.orchestrator.high_watermarks.'.$stage, 0);
            if ($value >= $high || ($ewma[$stage] ?? 0.0) > max(1.0, $high * 0.0005)) {
                return false;
            }
        }

        foreach (['collections', 'releases', 'nzbs'] as $stage) {
            $value = $now[$stage];
            $low = (int) floor((int) config('nntmux.orchestrator.high_watermarks.'.$stage, 0) * 0.6);
            if ($value > $low || ($ewma[$stage] ?? 0.0) > max(1.0, $low * 0.0005)) {
                return false;
            }
        }

        return true;
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
        $elapsed = $previous === null ? 0 : max(1, time() - (int) ($previous['observed_at'] ?? time()));
        foreach ($now as $stage => $value) {
            $rate = $previous === null ? 0.0 : ($value - (int) ($previous[$stage] ?? $value)) * 60 / $elapsed;
            $rates[$stage] = $rate;
            $ewma[$stage] = 0.3 * $rate + 0.7 * (float) ($previous['ewma_'.$stage] ?? 0.0);
        }

        return [$rates, $ewma];
    }
}
