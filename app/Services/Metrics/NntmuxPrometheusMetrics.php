<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Category;
use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Nzb\NzbService;
use App\Services\Orchestrator\WorkerControlPolicy;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class NntmuxPrometheusMetrics
{
    private const TABLES = [
        'binaries',
        'collections',
        'missed_parts',
        'parts',
        'releases',
        'predb',
        'predb_crcs',
        'usenet_groups',
    ];

    private const TIMER_SETTINGS = [
        'bins_timer',
        'back_timer',
        'rel_timer',
        'fix_timer',
        'crap_timer',
        'post_timer',
        'post_timer_non',
        'post_timer_amazon',
        'metadata_refresh_timer',
        'seq_timer',
        'orchestrator_bins_timer',
        'orchestrator_back_timer',
        'orchestrator_rel_timer',
        'orchestrator_nzb_timer',
    ];

    public function __construct(
        private readonly DistributedWorkerTelemetry $workerTelemetry = new DistributedWorkerTelemetry,
        private readonly DistributedJobCatalog $jobCatalog = new DistributedJobCatalog,
        private readonly SplitCollectionTelemetry $splitCollectionTelemetry = new SplitCollectionTelemetry,
    ) {}

    public function render(): string
    {
        $lines = [
            '# HELP nntmux_metric_scrape_success Whether the NNTmux metrics scrape completed successfully.',
            '# TYPE nntmux_metric_scrape_success gauge',
        ];

        try {
            $lines = array_merge(
                $lines,
                $this->buildConfigMetrics(),
                $this->tableEstimateMetrics(),
                $this->bodyRecoveryClaimMetrics(),
                $this->groupMetrics(),
                $this->collectionFormationMetrics(),
                $this->pipelineLifecycleMetrics(),
                $this->nzbBacklogMetrics(),
                $this->releaseMetrics(),
                $this->externalMetadataMetrics(),
                $this->imdbLookupMetrics(),
                $this->timerMetrics(),
                $this->orchestratorMetrics(),
                $this->workerTelemetryMetrics(),
                $this->splitCollectionTelemetryMetrics(),
                $this->lockMetrics(),
            );
            $lines[] = 'nntmux_metric_scrape_success 1';
        } catch (Throwable $e) {
            $lines[] = 'nntmux_metric_scrape_success 0';
            $lines[] = $this->metric('nntmux_metric_scrape_error', 1, [
                'class' => $e::class,
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function imdbLookupMetrics(): array
    {
        $lines = [
            '# HELP nntmux_imdb_lookup_total IMDb lookup attempts by outcome and reason since cache start.',
            '# TYPE nntmux_imdb_lookup_total counter',
        ];

        try {
            $connectionName = (string) config('cache.stores.redis.connection', 'cache');
            foreach ($this->scanRedisKeys($connectionName, $this->redisPhysicalPattern('metrics:imdb_lookup*')) as $key) {
                $key = (string) $key;
                $labels = $this->parseImdbMetricLabels($key);
                if ($labels === null) {
                    continue;
                }

                $lines[] = $this->metric('nntmux_imdb_lookup_total', $this->redisValue($key, $connectionName), $labels);
            }
        } catch (Throwable) {
            $lines[] = $this->metric('nntmux_imdb_lookup_total', 0, [
                'outcome' => 'redis_unavailable',
                'reason' => 'redis_unavailable',
                'fallback_reason' => 'redis_unavailable',
                'source' => 'redis_unavailable',
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function buildConfigMetrics(): array
    {
        return [
            '# HELP nntmux_build_info Static NNTmux build and runtime environment information.',
            '# TYPE nntmux_build_info gauge',
            $this->metric('nntmux_build_info', 1, [
                'version' => (string) config('nntmux.build_version', 'unknown'),
                'environment' => (string) config('app.env', 'unknown'),
            ]),
            '# HELP nntmux_nzb_worker_config_info Static dedicated NZB worker mode information.',
            '# TYPE nntmux_nzb_worker_config_info gauge',
            $this->metric('nntmux_nzb_worker_config_info', 1, [
                'inline_creation' => config('nntmux.inline_nzb_creation', true) ? 'enabled' : 'disabled',
            ]),
            '# HELP nntmux_nzb_worker_batch_limit Configured maximum releases attempted per dedicated NZB worker cycle.',
            '# TYPE nntmux_nzb_worker_batch_limit gauge',
            $this->metric('nntmux_nzb_worker_batch_limit', max(1, (int) config('nntmux.distributed_nzb_limit', 1))),
            '# HELP nntmux_nzb_worker_sleep_seconds Configured sleep between dedicated NZB worker cycles.',
            '# TYPE nntmux_nzb_worker_sleep_seconds gauge',
            $this->metric('nntmux_nzb_worker_sleep_seconds', max(1, (int) config('nntmux.distributed_nzb_sleep', 60))),
            '# HELP nntmux_nzb_worker_scan_cap Maximum releases examined by one bounded NZB selector cycle.',
            '# TYPE nntmux_nzb_worker_scan_cap gauge',
            $this->metric('nntmux_nzb_worker_scan_cap', max(1, (int) config('nntmux.distributed_nzb_scan_cap', 5000))),
            '# HELP nntmux_nzb_worker_lock_seconds Dedicated NZB worker lock TTL.',
            '# TYPE nntmux_nzb_worker_lock_seconds gauge',
            $this->metric('nntmux_nzb_worker_lock_seconds', max(1, (int) config('nntmux.distributed_nzb_lock_seconds', 7200))),
        ];
    }

    /**
     * @return array{outcome: string, reason: string, fallback_reason: string, source: string}|null
     */
    private function parseImdbMetricLabels(string $key): ?array
    {
        if (! preg_match(
            '/metrics:imdb_lookup:outcome:(?<outcome>[^:]+):reason:(?<reason>[^:]+):fallback:(?<fallback>[^:]+):source:(?<source>[^:]+)/',
            $key,
            $match,
        )) {
            return null;
        }

        return [
            'outcome' => $match['outcome'],
            'reason' => $match['reason'],
            'fallback_reason' => $match['fallback'],
            'source' => $match['source'],
        ];
    }

    /**
     * @return list<string>
     */
    private function tableEstimateMetrics(): array
    {
        $database = DB::connection()->getDatabaseName();
        $rows = DB::table('information_schema.tables')
            ->select(['table_name', 'table_rows'])
            ->where('table_schema', $database)
            ->whereIn('table_name', self::TABLES)
            ->get();

        $lines = [
            '# HELP nntmux_table_rows_estimate Estimated table row count from information_schema.',
            '# TYPE nntmux_table_rows_estimate gauge',
        ];

        foreach ($rows as $row) {
            $lines[] = $this->metric('nntmux_table_rows_estimate', (float) ($row->table_rows ?? 0), [
                'table' => (string) $row->table_name,
            ]);
        }

        return $lines;
    }

    /** @return list<string> */
    private function bodyRecoveryClaimMetrics(): array
    {
        $lines = [
            '# HELP nntmux_body_recovery_rows BODY-preamble recovery rows by claim state and group.',
            '# TYPE nntmux_body_recovery_rows gauge',
        ];
        if (! Schema::hasColumns('missed_parts', ['recovery_kind', 'claim_token', 'claim_expires_at'])) {
            $lines[] = $this->metric('nntmux_body_recovery_rows', 0, ['group' => 'unavailable', 'state' => 'unavailable']);

            return $lines;
        }

        $rows = DB::table('missed_parts as mp')
            ->join('usenet_groups as ug', 'ug.id', '=', 'mp.groups_id')
            ->where('mp.recovery_kind', 'body_preamble')
            ->selectRaw('ug.name as group_name')
            ->selectRaw('SUM(CASE WHEN mp.attempts >= 3 THEN 1 ELSE 0 END) as exhausted')
            ->selectRaw('SUM(CASE WHEN mp.attempts < 3 AND mp.claim_token IS NULL AND (mp.claim_expires_at IS NULL OR mp.claim_expires_at <= ?) THEN 1 ELSE 0 END) as ready', [now()])
            ->selectRaw('SUM(CASE WHEN mp.attempts < 3 AND mp.claim_token IS NULL AND mp.claim_expires_at > ? THEN 1 ELSE 0 END) as cooling', [now()])
            ->selectRaw('SUM(CASE WHEN mp.attempts < 3 AND mp.claim_token IS NOT NULL AND mp.claim_expires_at > ? THEN 1 ELSE 0 END) as claimed', [now()])
            ->selectRaw('SUM(CASE WHEN mp.attempts < 3 AND mp.claim_token IS NOT NULL AND (mp.claim_expires_at IS NULL OR mp.claim_expires_at <= ?) THEN 1 ELSE 0 END) as expired', [now()])
            ->groupBy('ug.name')
            ->get();
        foreach ($rows as $row) {
            foreach (['ready', 'cooling', 'claimed', 'expired', 'exhausted'] as $state) {
                $lines[] = $this->metric('nntmux_body_recovery_rows', (float) ($row->{$state} ?? 0), [
                    'group' => (string) $row->group_name,
                    'state' => $state,
                ]);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function groupMetrics(): array
    {
        $active = (int) DB::table('usenet_groups')->where('active', 1)->count();
        $activeBackfill = (int) DB::table('usenet_groups')->where('active', 1)->where('backfill', 1)->count();
        $backfill = (int) DB::table('usenet_groups')->where('backfill', 1)->count();
        $oldest = DB::table('usenet_groups')
            ->where('backfill', 1)
            ->whereNotNull('first_record_postdate')
            ->min('first_record_postdate');

        $lines = [
            '# HELP nntmux_groups_total Usenet group count by state.',
            '# TYPE nntmux_groups_total gauge',
            $this->metric('nntmux_groups_total', $active, ['state' => 'active']),
            $this->metric('nntmux_groups_total', $activeBackfill, ['state' => 'active_backfill']),
            $this->metric('nntmux_groups_total', $backfill, ['state' => 'backfill']),
            '# HELP nntmux_backfill_oldest_cursor_age_seconds Historical coverage depth of the oldest backfill first-record cursor; this is not queue latency.',
            '# TYPE nntmux_backfill_oldest_cursor_age_seconds gauge',
            $this->metric('nntmux_backfill_oldest_cursor_age_seconds', $oldest === null ? 0 : max(0, time() - strtotime((string) $oldest))),
            '# HELP nntmux_group_first_record_postdate_timestamp First-record postdate timestamp for backfill groups.',
            '# TYPE nntmux_group_first_record_postdate_timestamp gauge',
            '# HELP nntmux_group_first_record Current first article number for backfill groups.',
            '# TYPE nntmux_group_first_record gauge',
        ];

        $groups = DB::table('usenet_groups')
            ->select(['name', 'first_record', 'first_record_postdate'])
            ->where('backfill', 1)
            ->orderByDesc('first_record_postdate')
            ->limit(50)
            ->get();

        foreach ($groups as $group) {
            $labels = ['group' => (string) $group->name];
            $lines[] = $this->metric('nntmux_group_first_record', (float) $group->first_record, $labels);
            $lines[] = $this->metric(
                'nntmux_group_first_record_postdate_timestamp',
                $group->first_record_postdate === null ? 0 : strtotime((string) $group->first_record_postdate),
                $labels,
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function collectionFormationMetrics(): array
    {
        $lines = [
            '# HELP nntmux_collections_filecheck_total Current collection count by group and filecheck state.',
            '# TYPE nntmux_collections_filecheck_total gauge',
            '# HELP nntmux_collections_pending_multifile_total Pending multi-file collections by group and collection regex.',
            '# TYPE nntmux_collections_pending_multifile_total gauge',
        ];

        if (! Schema::hasTable('collections') || ! Schema::hasTable('usenet_groups')) {
            return $lines;
        }

        $filecheckRows = DB::table('collections as c')
            ->join('usenet_groups as g', 'g.id', '=', 'c.groups_id')
            ->where(static function ($query): void {
                $query->where('g.active', 1)->orWhere('g.backfill', 1);
            })
            ->groupBy(['g.name', 'c.filecheck'])
            ->orderBy('g.name')
            ->orderBy('c.filecheck')
            ->selectRaw('g.name AS group_name, c.filecheck, COUNT(*) AS collections_count')
            ->get();

        foreach ($filecheckRows as $row) {
            $lines[] = $this->metric('nntmux_collections_filecheck_total', (int) $row->collections_count, [
                'group' => (string) $row->group_name,
                'filecheck' => (string) $row->filecheck,
            ]);
        }

        $pendingMultifileRows = DB::table('collections as c')
            ->join('usenet_groups as g', 'g.id', '=', 'c.groups_id')
            ->where(static function ($query): void {
                $query->where('g.active', 1)->orWhere('g.backfill', 1);
            })
            ->where('c.filecheck', 0)
            ->where('c.totalfiles', '>', 1)
            ->groupBy(['g.name', 'c.collection_regexes_id'])
            ->orderBy('group_name')
            ->orderBy('collection_regexes_id')
            ->selectRaw('g.name AS group_name, c.collection_regexes_id, COUNT(*) AS pending_multifile_count')
            ->get();

        foreach ($pendingMultifileRows as $row) {
            $lines[] = $this->metric('nntmux_collections_pending_multifile_total', (int) $row->pending_multifile_count, [
                'group' => (string) $row->group_name,
                'collection_regexes_id' => (string) $row->collection_regexes_id,
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function pipelineLifecycleMetrics(): array
    {
        $lines = [
            '# HELP nntmux_collections_filecheck_lifecycle_count Current aggregate collection count for every filecheck lifecycle state.',
            '# TYPE nntmux_collections_filecheck_lifecycle_count gauge',
        ];

        if (! Schema::hasTable('collections')) {
            return $lines;
        }

        $counts = DB::table('collections')
            ->selectRaw('filecheck, COUNT(*) AS collections_count')
            ->groupBy('filecheck')
            ->pluck('collections_count', 'filecheck');

        $states = [
            CollectionFileCheckStatus::Default->value => ['new', 'backlog'],
            CollectionFileCheckStatus::CompleteCollection->value => ['collection_complete', 'backlog'],
            CollectionFileCheckStatus::CompleteParts->value => ['parts_complete', 'backlog'],
            CollectionFileCheckStatus::Sized->value => ['ready_for_release', 'ready'],
            CollectionFileCheckStatus::Inserted->value => ['inserted', 'nzb_pending'],
            CollectionFileCheckStatus::Delete->value => ['delete', 'terminal'],
            CollectionFileCheckStatus::TempComplete->value => ['temporary_complete', 'backlog'],
            CollectionFileCheckStatus::ZeroPart->value => ['zero_part', 'backlog'],
        ];

        foreach ($states as $filecheck => [$state, $lifecycle]) {
            $lines[] = $this->metric(
                'nntmux_collections_filecheck_lifecycle_count',
                (int) ($counts[$filecheck] ?? 0),
                [
                    'filecheck' => (string) $filecheck,
                    'state' => $state,
                    'lifecycle' => $lifecycle,
                ],
            );
        }

        return $lines;
    }

    /**
     * Direct status counts intentionally avoid the expensive NZB eligibility
     * predicate. The worker outcome counters describe selector results.
     *
     * @return list<string>
     */
    private function nzbBacklogMetrics(): array
    {
        $lines = [
            '# HELP nntmux_nzb_releases_count Current release count by persisted NZB status.',
            '# TYPE nntmux_nzb_releases_count gauge',
            '# HELP nntmux_nzb_inserted_collections_count Inserted collections; a cheap upper-bound proxy for releases entering the NZB lane.',
            '# TYPE nntmux_nzb_inserted_collections_count gauge',
        ];

        if (! Schema::hasTable('releases') || ! Schema::hasColumn('releases', 'nzbstatus')) {
            return $lines;
        }

        $statuses = [
            NzbService::NZB_NONE => 'pending',
            NzbService::NZB_ADDED => 'added',
            NzbService::NZB_FAILED => 'failed',
        ];
        $counts = DB::table('releases')
            ->whereIn('nzbstatus', array_keys($statuses))
            ->selectRaw('nzbstatus, COUNT(*) AS releases_count')
            ->groupBy('nzbstatus')
            ->pluck('releases_count', 'nzbstatus');

        foreach ($statuses as $status => $state) {
            $lines[] = $this->metric('nntmux_nzb_releases_count', (int) ($counts[$status] ?? 0), [
                'state' => $state,
            ]);
        }

        $insertedCollections = Schema::hasTable('collections')
            ? (int) DB::table('collections')->where('filecheck', CollectionFileCheckStatus::Inserted->value)->count()
            : 0;
        $lines[] = $this->metric('nntmux_nzb_inserted_collections_count', $insertedCollections);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function releaseMetrics(): array
    {
        $hour = (int) DB::table('releases')->where('adddate', '>=', now()->subHour())->count();
        $day = (int) DB::table('releases')->where('adddate', '>=', now()->subDay())->count();
        $hasLegacyHashedColumn = Schema::hasColumn('releases', 'ishashed');
        $stateCounts = DB::table('releases')->selectRaw(sprintf(
            '%s AS hashed, '
            .'SUM(CASE WHEN categories_id = %d THEN 1 ELSE 0 END) AS hashed_category, '
            .'%s AS hashed_effective, '
            .'SUM(CASE WHEN isrenamed = 1 THEN 1 ELSE 0 END) AS renamed',
            $hasLegacyHashedColumn
                ? 'SUM(CASE WHEN ishashed = 1 THEN 1 ELSE 0 END)'
                : '0',
            Category::OTHER_HASHED,
            $hasLegacyHashedColumn
                ? sprintf('SUM(CASE WHEN ishashed = 1 OR categories_id = %d THEN 1 ELSE 0 END)', Category::OTHER_HASHED)
                : sprintf('SUM(CASE WHEN categories_id = %d THEN 1 ELSE 0 END)', Category::OTHER_HASHED),
        ))->first();
        $hashed = (int) ($stateCounts->hashed ?? 0);
        $hashedCategory = (int) ($stateCounts->hashed_category ?? 0);
        $hashedEffective = (int) ($stateCounts->hashed_effective ?? 0);
        $renamed = (int) ($stateCounts->renamed ?? 0);

        return [
            '# HELP nntmux_releases_recent_total Releases added in a recent time window.',
            '# TYPE nntmux_releases_recent_total gauge',
            $this->metric('nntmux_releases_recent_total', $hour, ['window' => '1h']),
            $this->metric('nntmux_releases_recent_total', $day, ['window' => '24h']),
            '# HELP nntmux_releases_state_total Release count by processing state.',
            '# TYPE nntmux_releases_state_total gauge',
            $this->metric('nntmux_releases_state_total', $hashed, ['state' => 'hashed']),
            $this->metric('nntmux_releases_state_total', $hashedCategory, ['state' => 'hashed_category']),
            $this->metric('nntmux_releases_state_total', $hashedEffective, ['state' => 'hashed_effective']),
            $this->metric('nntmux_releases_state_total', $renamed, ['state' => 'renamed']),
            ...$this->releaseCategoryMetrics(),
        ];
    }

    /**
     * @return list<string>
     */
    private function releaseCategoryMetrics(): array
    {
        $rows = DB::table('releases AS r')
            ->join('categories AS c', 'c.id', '=', 'r.categories_id')
            ->leftJoin('root_categories AS rc', 'rc.id', '=', 'c.root_categories_id')
            ->selectRaw('r.categories_id, c.title AS category, rc.title AS root_category, COUNT(*) AS releases_count')
            ->groupBy('r.categories_id', 'c.title', 'rc.title')
            ->orderBy('rc.title')
            ->orderBy('c.title')
            ->get();

        $lines = [
            '# HELP nntmux_releases_category_total Current release count by NNTmux category.',
            '# TYPE nntmux_releases_category_total gauge',
        ];

        foreach ($rows as $row) {
            $rootCategory = (string) ($row->root_category ?? 'Uncategorized');
            $category = (string) $row->category;

            $lines[] = $this->metric('nntmux_releases_category_total', (int) $row->releases_count, [
                'category_id' => (string) $row->categories_id,
                'root_category' => $rootCategory,
                'category' => $category,
                'category_path' => $rootCategory.' / '.$category,
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function externalMetadataMetrics(): array
    {
        $lines = [
            '# HELP nntmux_external_metadata_total External metadata evidence rows and backlog counts.',
            '# TYPE nntmux_external_metadata_total gauge',
        ];

        if (Schema::hasTable('predb_crcs')) {
            $lines[] = $this->metric('nntmux_external_metadata_total', (int) DB::table('predb_crcs')->count(), [
                'source' => 'srrdb',
                'state' => 'crc_rows',
            ]);
        }

        if (Schema::hasTable('predb') && Schema::hasTable('predb_crcs')) {
            // A LEFT JOIN ... IS NULL anti-join rather than the NOT EXISTS this used to be.
            // Same answer -- both return 19,889 today -- but MariaDB planned the subquery form
            // as MATERIALIZED, building a temp table from all 2.47M rows of
            // predb_crcs_predb_id_index on every scrape:
            //
            //   1 PRIMARY      predb      ref   ix_predb_source            rows=161,606
            //   2 MATERIALIZED predb_crcs index predb_crcs_predb_id_index  rows=2,470,209
            //
            // The join form uses that index directly with the optimiser's "Not exists" access,
            // touching ~26 rows per predb row instead: 1,015ms down to 373ms measured on the
            // live database. This is a query-shape problem, not a missing index -- the index it
            // needs already existed.
            $withoutCrc = (int) DB::table('predb as p')
                ->leftJoin('predb_crcs as c', 'c.predb_id', '=', 'p.id')
                ->where('p.source', 'srrdb')
                ->whereNull('c.predb_id')
                ->count();

            $lines[] = $this->metric('nntmux_external_metadata_total', $withoutCrc, [
                'source' => 'srrdb',
                'state' => 'predb_without_crc',
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function timerMetrics(): array
    {
        $settings = DB::table('settings')
            ->whereIn('name', self::TIMER_SETTINGS)
            ->pluck('value', 'name');

        $lines = [
            '# HELP nntmux_worker_sleep_seconds Configured distributed worker sleep setting.',
            '# TYPE nntmux_worker_sleep_seconds gauge',
        ];

        foreach (self::TIMER_SETTINGS as $setting) {
            if (! $settings->has($setting)) {
                continue;
            }

            $lines[] = $this->metric('nntmux_worker_sleep_seconds', (float) $settings[$setting], [
                'setting' => $setting,
            ]);
        }

        return $lines;
    }

    /** @return list<string> */
    private function orchestratorMetrics(): array
    {
        $settings = DB::table('settings')->whereIn('name', [
            'orchestrator_mode',
            'orchestrator_profile',
            'orchestrator_lease_until',
            'orchestrator_generation',
            'orchestrator_nzb_limit',
            'orchestrator_bf_paused',
            'orchestrator_bf_permit',
            'orchestrator_bf_qty',
            'orchestrator_cf_permit',
            'orchestrator_cf_claimed',
            'orchestrator_cf_completed',
            'orchestrator_cf_failed',
            'orchestrator_cf_issued_at',
            'orchestrator_cf_blocks',
            'orchestrator_cf_halt',
            'orchestrator_cf_group',
        ])->pluck('value', 'name');
        $mode = (string) ($settings['orchestrator_mode'] ?? 'legacy');
        $profile = (string) ($settings['orchestrator_profile'] ?? 'unknown');
        $leaseUntil = (int) ($settings['orchestrator_lease_until'] ?? 0);
        $currentForwardClaimed = (int) ($settings['orchestrator_cf_claimed'] ?? 0);
        $currentForwardClaimInProgress = $currentForwardClaimed > 0
            && $currentForwardClaimed !== (int) ($settings['orchestrator_cf_completed'] ?? 0)
            && $currentForwardClaimed !== (int) ($settings['orchestrator_cf_failed'] ?? 0);
        $currentForwardGroup = trim((string) ($settings['orchestrator_cf_group'] ?? ''));
        $currentForwardBlocks = array_values(array_filter(explode(',', (string) ($settings['orchestrator_cf_blocks'] ?? ''))));

        try {
            $decision = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
                ->get(WorkerControlStateStore::DECISION_KEY);
            $stateStore = new WorkerControlStateStore;
            $backfillYieldHistory = $stateStore->backfillYieldHistory();
            $targetIneffectivePermits = $stateStore->loadState()->ineffectiveBackfillPermitsByTarget;
        } catch (Throwable) {
            $decision = null;
            $backfillYieldHistory = [];
            $targetIneffectivePermits = [];
        }
        $decisionAge = is_array($decision)
            ? max(0, time() - (int) ($decision['observed_at'] ?? 0))
            : 0;
        $decisionFresh = is_array($decision)
            && (int) ($decision['observed_at'] ?? 0) > 0
            && $decisionAge <= max(1, (int) config('nntmux.orchestrator.snapshot_max_age_seconds', 180));
        if ($decisionFresh && $mode !== 'failsafe') {
            $mode = (string) ($decision['mode'] ?? $mode);
            $profile = (string) ($decision['profile'] ?? $profile);
        }

        $lines = [
            '# HELP nntmux_orchestrator_mode_info Current deterministic worker orchestrator mode.',
            '# TYPE nntmux_orchestrator_mode_info gauge',
            $this->metric('nntmux_orchestrator_mode_info', 1, ['mode' => $mode]),
            '# HELP nntmux_orchestrator_profile_info Current finite worker-control profile.',
            '# TYPE nntmux_orchestrator_profile_info gauge',
            $this->metric('nntmux_orchestrator_profile_info', 1, ['profile' => $profile]),
            '# HELP nntmux_orchestrator_generation Last atomically applied worker profile generation.',
            '# TYPE nntmux_orchestrator_generation gauge',
            $this->metric('nntmux_orchestrator_generation', (int) ($settings['orchestrator_generation'] ?? 0)),
            '# HELP nntmux_orchestrator_lease_remaining_seconds Remaining fail-safe lease lifetime.',
            '# TYPE nntmux_orchestrator_lease_remaining_seconds gauge',
            $this->metric('nntmux_orchestrator_lease_remaining_seconds', max(0, $leaseUntil - time())),
            '# HELP nntmux_orchestrator_backfill_permit Current one-shot backfill permit generation; zero means denied.',
            '# TYPE nntmux_orchestrator_backfill_permit gauge',
            $this->metric('nntmux_orchestrator_backfill_permit', (int) ($settings['orchestrator_bf_permit'] ?? 0)),
            '# HELP nntmux_orchestrator_backfill_paused Whether adaptive backfill is paused.',
            '# TYPE nntmux_orchestrator_backfill_paused gauge',
            $this->metric('nntmux_orchestrator_backfill_paused', (int) ($settings['orchestrator_bf_paused'] ?? 1)),
            '# HELP nntmux_orchestrator_backfill_permit_quantity Articles pinned atomically to the current one-shot permit.',
            '# TYPE nntmux_orchestrator_backfill_permit_quantity gauge',
            $this->metric('nntmux_orchestrator_backfill_permit_quantity', (int) ($settings['orchestrator_bf_qty'] ?? 0)),
            '# HELP nntmux_orchestrator_nzb_batch_size Desired bounded NZB batch size.',
            '# TYPE nntmux_orchestrator_nzb_batch_size gauge',
            $this->metric('nntmux_orchestrator_nzb_batch_size', (int) ($settings['orchestrator_nzb_limit'] ?? 0)),
            '# HELP nntmux_orchestrator_current_forward_permit Current exact current-forward permit generation; zero means none.',
            '# TYPE nntmux_orchestrator_current_forward_permit gauge',
            $this->metric('nntmux_orchestrator_current_forward_permit', (int) ($settings['orchestrator_cf_permit'] ?? 0)),
            '# HELP nntmux_orchestrator_current_forward_claim_in_progress Whether an exact current-forward generation is claimed and unresolved.',
            '# TYPE nntmux_orchestrator_current_forward_claim_in_progress gauge',
            $this->metric('nntmux_orchestrator_current_forward_claim_in_progress', $currentForwardClaimInProgress ? 1 : 0),
            '# HELP nntmux_orchestrator_current_forward_claim_age_seconds Age of the unresolved exact current-forward generation.',
            '# TYPE nntmux_orchestrator_current_forward_claim_age_seconds gauge',
            $this->metric(
                'nntmux_orchestrator_current_forward_claim_age_seconds',
                $currentForwardClaimInProgress ? max(0, time() - (int) ($settings['orchestrator_cf_issued_at'] ?? 0)) : 0,
            ),
            '# HELP nntmux_orchestrator_current_forward_quarantined_windows Exact current-forward windows quarantined after a claimed failure.',
            '# TYPE nntmux_orchestrator_current_forward_quarantined_windows gauge',
            $this->metric('nntmux_orchestrator_current_forward_quarantined_windows', count($currentForwardBlocks)),
            '# HELP nntmux_orchestrator_current_forward_halted Whether quarantine capacity forced global current-forward fail-safe.',
            '# TYPE nntmux_orchestrator_current_forward_halted gauge',
            $this->metric('nntmux_orchestrator_current_forward_halted', (int) ($settings['orchestrator_cf_halt'] ?? 0)),
        ];
        if ($currentForwardGroup !== '') {
            $lines[] = '# HELP nntmux_orchestrator_current_forward_target_info Current exact current-forward target group.';
            $lines[] = '# TYPE nntmux_orchestrator_current_forward_target_info gauge';
            $lines[] = $this->metric('nntmux_orchestrator_current_forward_target_info', 1, ['group' => $currentForwardGroup]);
        }
        $lines = array_merge($lines, $this->currentForwardRefreshMetrics());

        $lines[] = '# HELP nntmux_orchestrator_snapshot_fresh Whether the cached controller observation is within the configured maximum age.';
        $lines[] = '# TYPE nntmux_orchestrator_snapshot_fresh gauge';
        $lines[] = $this->metric('nntmux_orchestrator_snapshot_fresh', $decisionFresh ? 1 : 0);
        $lines[] = '# HELP nntmux_orchestrator_snapshot_age_seconds Age of the last controller observation.';
        $lines[] = '# TYPE nntmux_orchestrator_snapshot_age_seconds gauge';
        $lines[] = $this->metric('nntmux_orchestrator_snapshot_age_seconds', $decisionAge);
        if (! is_array($decision)) {
            return $lines;
        }
        if (! $decisionFresh) {
            return $lines;
        }
        $lines[] = '# HELP nntmux_orchestrator_backfill_policy_permitted Whether all deterministic backfill gates are green.';
        $lines[] = '# TYPE nntmux_orchestrator_backfill_policy_permitted gauge';
        $lines[] = $this->metric('nntmux_orchestrator_backfill_policy_permitted', ($decision['backfill_permitted'] ?? false) ? 1 : 0);
        $lines[] = '# HELP nntmux_orchestrator_eligible_nzbs Exact actionable NZBs in the bounded selector frontier.';
        $lines[] = '# TYPE nntmux_orchestrator_eligible_nzbs gauge';
        $lines[] = $this->metric('nntmux_orchestrator_eligible_nzbs', (int) ($decision['eligible_nzbs'] ?? 0));
        $qualifiedSupply = is_array($decision['qualified_supply'] ?? null) ? $decision['qualified_supply'] : [];
        $lines[] = '# HELP nntmux_orchestrator_qualified_supply_starved Whether schedulable input has grown through the dwell period without qualified output.';
        $lines[] = '# TYPE nntmux_orchestrator_qualified_supply_starved gauge';
        $lines[] = $this->metric('nntmux_orchestrator_qualified_supply_starved', ($qualifiedSupply['starved'] ?? false) ? 1 : 0);
        $lines[] = '# HELP nntmux_orchestrator_release_yield_per_minute Committed release creations per minute from monotonic worker telemetry between fresh controller observations.';
        $lines[] = '# TYPE nntmux_orchestrator_release_yield_per_minute gauge';
        // Reported as 0 when unmeasurable so the series never disappears (an
        // absent series would silently disable the alerts that read it). Pair it
        // with the companion gauge below to tell "no releases" from "no reading".
        $lines[] = $this->metric('nntmux_orchestrator_release_yield_per_minute', (float) ($qualifiedSupply['release_yield_per_minute'] ?? 0.0));
        $lines[] = '# HELP nntmux_orchestrator_release_yield_known Whether the release yield above was actually measurable (0 means the observation window was unusable, not that yield was zero).';
        $lines[] = '# TYPE nntmux_orchestrator_release_yield_known gauge';
        $lines[] = $this->metric(
            'nntmux_orchestrator_release_yield_known',
            array_key_exists('release_yield_per_minute', $qualifiedSupply)
                && $qualifiedSupply['release_yield_per_minute'] !== null ? 1 : 0,
        );
        $lines[] = '# HELP nntmux_orchestrator_schedulable_backlog Pending rows attached to enabled groups and eligible pipeline states.';
        $lines[] = '# TYPE nntmux_orchestrator_schedulable_backlog gauge';
        $schedulableBacklogs = is_array($decision['schedulable_backlogs'] ?? null) ? $decision['schedulable_backlogs'] : [];
        foreach (['parts', 'binaries', 'collections', 'releases', 'nzbs'] as $stage) {
            $lines[] = $this->metric('nntmux_orchestrator_schedulable_backlog', (int) ($schedulableBacklogs[$stage] ?? 0), ['stage' => $stage]);
        }
        $lines[] = '# HELP nntmux_orchestrator_body_recovery_queue Current actionable BODY recovery queue across configured groups.';
        $lines[] = '# TYPE nntmux_orchestrator_body_recovery_queue gauge';
        $lines[] = $this->metric('nntmux_orchestrator_body_recovery_queue', (int) ($decision['body_recovery_queue'] ?? 0));
        $contention = is_array($decision['database_contention'] ?? null)
            ? $decision['database_contention']
            : [];
        $lines[] = '# HELP nntmux_orchestrator_database_row_lock_waits Cumulative InnoDB row-lock waits observed by the controller.';
        $lines[] = '# TYPE nntmux_orchestrator_database_row_lock_waits gauge';
        $lines[] = $this->metric('nntmux_orchestrator_database_row_lock_waits', (int) ($contention['row_lock_waits'] ?? 0));
        $lines[] = '# HELP nntmux_orchestrator_database_row_lock_delta New row-lock waits since the prior controller sample.';
        $lines[] = '# TYPE nntmux_orchestrator_database_row_lock_delta gauge';
        $lines[] = $this->metric('nntmux_orchestrator_database_row_lock_delta', (int) ($contention['row_lock_delta'] ?? 0));
        $lines[] = '# HELP nntmux_orchestrator_database_row_lock_instant_rate_per_minute Instantaneous row-lock wait rate.';
        $lines[] = '# TYPE nntmux_orchestrator_database_row_lock_instant_rate_per_minute gauge';
        $lines[] = $this->metric('nntmux_orchestrator_database_row_lock_instant_rate_per_minute', (float) ($contention['instant_rate_per_minute'] ?? 0.0));
        $lines[] = '# HELP nntmux_orchestrator_database_row_lock_window_rate_per_minute Completed contention-window row-lock wait rate.';
        $lines[] = '# TYPE nntmux_orchestrator_database_row_lock_window_rate_per_minute gauge';
        $lines[] = $this->metric('nntmux_orchestrator_database_row_lock_window_rate_per_minute', (float) ($contention['window_rate_per_minute'] ?? 0.0));
        $lines[] = '# HELP nntmux_orchestrator_database_admission_blocked Whether contention hysteresis blocks new supply.';
        $lines[] = '# TYPE nntmux_orchestrator_database_admission_blocked gauge';
        $lines[] = $this->metric('nntmux_orchestrator_database_admission_blocked', ($contention['admission_blocked'] ?? true) ? 1 : 0);
        $lines[] = '# HELP nntmux_orchestrator_database_admission_safe Whether DB contention and profile-settlement gates allow new supply.';
        $lines[] = '# TYPE nntmux_orchestrator_database_admission_safe gauge';
        $lines[] = $this->metric('nntmux_orchestrator_database_admission_safe', ($contention['admission_safe'] ?? false) ? 1 : 0);
        $lines[] = '# HELP nntmux_orchestrator_database_hard_breach_timestamp_seconds Last hard contention breach timestamp; zero means none retained.';
        $lines[] = '# TYPE nntmux_orchestrator_database_hard_breach_timestamp_seconds gauge';
        $lines[] = $this->metric('nntmux_orchestrator_database_hard_breach_timestamp_seconds', (int) ($contention['hard_breach_at'] ?? 0));
        $lines[] = '# HELP nntmux_orchestrator_collection_backlog Collection backlog split into physical total, ordinary pressure, and proven BODY recovery sources.';
        $lines[] = '# TYPE nntmux_orchestrator_collection_backlog gauge';
        foreach (['total', 'ordinary', 'body_recovery_sources'] as $scope) {
            $lines[] = $this->metric(
                'nntmux_orchestrator_collection_backlog',
                (int) ($decision['collection_backlogs'][$scope] ?? 0),
                ['scope' => $scope],
            );
        }
        $targetGroup = (string) ($decision['backfill_target']['group'] ?? '');
        if ($targetGroup !== '') {
            $lines[] = '# HELP nntmux_orchestrator_backfill_target_info Selected adaptive backfill target group.';
            $lines[] = '# TYPE nntmux_orchestrator_backfill_target_info gauge';
            $lines[] = $this->metric('nntmux_orchestrator_backfill_target_info', 1, ['group' => $targetGroup]);
            $lines[] = '# HELP nntmux_orchestrator_backfill_target_cursor Selected adaptive backfill target cursor.';
            $lines[] = '# TYPE nntmux_orchestrator_backfill_target_cursor gauge';
            $lines[] = $this->metric('nntmux_orchestrator_backfill_target_cursor', (int) ($decision['backfill_target']['cursor'] ?? 0), ['group' => $targetGroup]);
            $lines[] = '# HELP nntmux_orchestrator_backfill_planned_quantity Articles selected for the newest permit decision; zero means no permit issued.';
            $lines[] = '# TYPE nntmux_orchestrator_backfill_planned_quantity gauge';
            $lines[] = $this->metric('nntmux_orchestrator_backfill_planned_quantity', (int) ($decision['backfill_target']['quantity'] ?? 0), ['group' => $targetGroup]);
        }
        $lines[] = '# HELP nntmux_orchestrator_backfill_yield_nzbs_per_10k Recent target NZB yield EWMA per 10,000 cursor articles.';
        $lines[] = '# TYPE nntmux_orchestrator_backfill_yield_nzbs_per_10k gauge';
        $lines[] = '# HELP nntmux_orchestrator_backfill_yield_attempts Recent attributed permits by target group.';
        $lines[] = '# TYPE nntmux_orchestrator_backfill_yield_attempts gauge';
        foreach ($backfillYieldHistory as $group => $entry) {
            $lines[] = $this->metric('nntmux_orchestrator_backfill_yield_nzbs_per_10k', $entry['ewma_nzbs_per_10k'], ['group' => $group]);
            $lines[] = $this->metric('nntmux_orchestrator_backfill_yield_attempts', $entry['attempts'], ['group' => $group]);
        }
        $lines[] = '# HELP nntmux_orchestrator_backfill_target_ineffective_permits Consecutive input-bearing permits without attributed output by target group.';
        $lines[] = '# TYPE nntmux_orchestrator_backfill_target_ineffective_permits gauge';
        $lines[] = '# HELP nntmux_orchestrator_backfill_target_locked Whether a target group reached its bounded ineffective-permit limit.';
        $lines[] = '# TYPE nntmux_orchestrator_backfill_target_locked gauge';
        ksort($targetIneffectivePermits);
        foreach ($targetIneffectivePermits as $group => $count) {
            $lines[] = $this->metric('nntmux_orchestrator_backfill_target_ineffective_permits', $count, ['group' => $group]);
            $lines[] = $this->metric(
                'nntmux_orchestrator_backfill_target_locked',
                $count >= WorkerControlPolicy::INEFFECTIVE_BACKFILL_LIMIT ? 1 : 0,
                ['group' => $group],
            );
        }
        $lines[] = '# HELP nntmux_orchestrator_stage_backlog Current bounded pipeline stage backlog or capacity level.';
        $lines[] = '# TYPE nntmux_orchestrator_stage_backlog gauge';
        $lines[] = '# HELP nntmux_orchestrator_stage_rate_per_minute Pipeline stage change rate by estimator.';
        $lines[] = '# TYPE nntmux_orchestrator_stage_rate_per_minute gauge';
        $lines[] = '# HELP nntmux_orchestrator_stage_oldest_age_seconds Oldest actionable item age by stage.';
        $lines[] = '# TYPE nntmux_orchestrator_stage_oldest_age_seconds gauge';
        $lines[] = '# HELP nntmux_orchestrator_stage_projected_runway_minutes Minutes until the stage high watermark at its current positive EWMA; -1 means stable, draining, or invalid rate telemetry.';
        $lines[] = '# TYPE nntmux_orchestrator_stage_projected_runway_minutes gauge';
        foreach (['parts', 'binaries', 'collections', 'collections_total', 'recovery_sources', 'releases', 'nzbs'] as $stage) {
            $lines[] = $this->metric('nntmux_orchestrator_stage_backlog', (float) ($decision['backlogs'][$stage] ?? 0), ['stage' => $stage]);
            $rate = (float) ($decision['ewma_per_minute'][$stage] ?? 0.0);
            $limit = (int) config('nntmux.orchestrator.high_watermarks.'.$stage, 0);
            $runway = is_finite($rate) && $rate > 0.0
                ? max(0.0, ($limit - (int) ($decision['backlogs'][$stage] ?? 0)) / $rate)
                : -1.0;
            $lines[] = $this->metric('nntmux_orchestrator_stage_projected_runway_minutes', $runway, ['stage' => $stage]);
            foreach (['rates_per_minute' => 'instant', 'ewma_per_minute' => 'ewma'] as $key => $estimator) {
                $lines[] = $this->metric('nntmux_orchestrator_stage_rate_per_minute', (float) ($decision[$key][$stage] ?? 0), [
                    'stage' => $stage,
                    'estimator' => $estimator,
                ]);
            }
            if (! in_array($stage, ['parts', 'collections_total'], true)) {
                $lines[] = $this->metric('nntmux_orchestrator_stage_oldest_age_seconds', (float) ($decision['oldest_age_seconds'][$stage] ?? 0), ['stage' => $stage]);
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function currentForwardRefreshMetrics(): array
    {
        $schemaReady = Schema::hasTable('current_forward_sources')
            && Schema::hasTable('current_forward_windows');
        $lines = [
            '# HELP nntmux_orchestrator_current_forward_refresh_enabled Whether audited current-forward refresh discovery is enabled.',
            '# TYPE nntmux_orchestrator_current_forward_refresh_enabled gauge',
            $this->metric(
                'nntmux_orchestrator_current_forward_refresh_enabled',
                config('nntmux.orchestrator.current_forward_refresh_enabled', false) ? 1 : 0,
            ),
            '# HELP nntmux_orchestrator_current_forward_ledger_issuance_enabled Whether fresh audited ledger windows may be offered to the exact current-forward worker.',
            '# TYPE nntmux_orchestrator_current_forward_ledger_issuance_enabled gauge',
            $this->metric(
                'nntmux_orchestrator_current_forward_ledger_issuance_enabled',
                config('nntmux.orchestrator.current_forward_ledger_issuance_enabled', false) ? 1 : 0,
            ),
            '# HELP nntmux_orchestrator_current_forward_continuation_enabled Whether exact-lineage adjacent-window continuation is enabled.',
            '# TYPE nntmux_orchestrator_current_forward_continuation_enabled gauge',
            $this->metric(
                'nntmux_orchestrator_current_forward_continuation_enabled',
                config('nntmux.orchestrator.current_forward_continuation_enabled', false) ? 1 : 0,
            ),
            '# HELP nntmux_orchestrator_current_forward_refresh_schema_ready Whether both additive refresh ledger tables exist.',
            '# TYPE nntmux_orchestrator_current_forward_refresh_schema_ready gauge',
            $this->metric('nntmux_orchestrator_current_forward_refresh_schema_ready', $schemaReady ? 1 : 0),
        ];
        if (! $schemaReady) {
            return $lines;
        }

        $lines[] = '# HELP nntmux_orchestrator_current_forward_refresh_sources Explicit trusted sources by lifecycle state.';
        $lines[] = '# TYPE nntmux_orchestrator_current_forward_refresh_sources gauge';
        $sourceCounts = DB::table('current_forward_sources')
            ->selectRaw('state, COUNT(*) AS aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');
        foreach (['PROBATION', 'READY', 'HALTED', 'QUALITY_LOCKED'] as $state) {
            $lines[] = $this->metric(
                'nntmux_orchestrator_current_forward_refresh_sources',
                (int) ($sourceCounts[$state] ?? 0),
                ['state' => strtolower($state)],
            );
        }

        $lines[] = '# HELP nntmux_orchestrator_current_forward_refresh_windows Immutable windows by lifecycle state.';
        $lines[] = '# TYPE nntmux_orchestrator_current_forward_refresh_windows gauge';
        $windowCounts = DB::table('current_forward_windows')
            ->selectRaw('state, COUNT(*) AS aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');
        foreach (['AUDITED', 'OFFERED', 'CLAIMED', 'INGESTED', 'ATTRIBUTING', 'CONTINUATION_PENDING', 'CHAINED', 'PRODUCTIVE', 'QUARANTINED'] as $state) {
            $lines[] = $this->metric(
                'nntmux_orchestrator_current_forward_refresh_windows',
                (int) ($windowCounts[$state] ?? 0),
                ['state' => strtolower($state)],
            );
        }

        $quarantineTimestampColumn = match (true) {
            Schema::hasColumn('current_forward_windows', 'settled_at') => 'settled_at',
            Schema::hasColumn('current_forward_windows', 'updated_at') => 'updated_at',
            default => 'created_at',
        };
        $lastQuarantinedAt = DB::table('current_forward_windows')
            ->where('state', 'QUARANTINED')
            ->max($quarantineTimestampColumn);
        $lastQuarantinedTimestamp = is_string($lastQuarantinedAt) ? strtotime($lastQuarantinedAt) : false;
        $lines[] = '# HELP nntmux_orchestrator_current_forward_last_quarantined_timestamp_seconds Unix timestamp of the most recent exact current-forward quarantine event.';
        $lines[] = '# TYPE nntmux_orchestrator_current_forward_last_quarantined_timestamp_seconds gauge';
        $lines[] = $this->metric(
            'nntmux_orchestrator_current_forward_last_quarantined_timestamp_seconds',
            $lastQuarantinedTimestamp === false ? 0 : $lastQuarantinedTimestamp,
        );

        $lastAuditedAt = DB::table('current_forward_sources')->max('last_audited_at');
        $lastAuditedTimestamp = is_string($lastAuditedAt) ? strtotime($lastAuditedAt) : false;
        $lines[] = '# HELP nntmux_orchestrator_current_forward_refresh_last_audit_age_seconds Age of the newest durable exact-XOVER audit.';
        $lines[] = '# TYPE nntmux_orchestrator_current_forward_refresh_last_audit_age_seconds gauge';
        $lines[] = $this->metric(
            'nntmux_orchestrator_current_forward_refresh_last_audit_age_seconds',
            $lastAuditedTimestamp === false ? 0 : max(0, time() - $lastAuditedTimestamp),
        );

        $oldestUnresolvedTimestamp = null;
        foreach (DB::table('current_forward_windows')
            ->whereIn('state', ['OFFERED', 'CLAIMED', 'INGESTED', 'ATTRIBUTING'])
            ->get() as $window
        ) {
            $candidate = match ((string) $window->state) {
                'OFFERED' => $window->offered_at ?? $window->updated_at,
                'CLAIMED' => $window->claimed_at ?? $window->updated_at,
                'INGESTED' => $window->ingested_at ?? $window->updated_at,
                'ATTRIBUTING' => $window->attribution_started_at ?? $window->ingested_at ?? $window->updated_at,
                default => $window->updated_at,
            };
            $timestamp = strtotime((string) $candidate);
            if ($timestamp !== false
                && ($oldestUnresolvedTimestamp === null || $timestamp < $oldestUnresolvedTimestamp)
            ) {
                $oldestUnresolvedTimestamp = $timestamp;
            }
        }
        $lines[] = '# HELP nntmux_orchestrator_current_forward_refresh_unresolved_age_seconds Age of the oldest non-terminal audited-ledger window.';
        $lines[] = '# TYPE nntmux_orchestrator_current_forward_refresh_unresolved_age_seconds gauge';
        $lines[] = $this->metric(
            'nntmux_orchestrator_current_forward_refresh_unresolved_age_seconds',
            $oldestUnresolvedTimestamp === null ? 0 : max(0, time() - $oldestUnresolvedTimestamp),
        );

        $lines[] = '# HELP nntmux_orchestrator_current_forward_refresh_verifications Append-only exact-XOVER verification records.';
        $lines[] = '# TYPE nntmux_orchestrator_current_forward_refresh_verifications gauge';
        $lines[] = $this->metric(
            'nntmux_orchestrator_current_forward_refresh_verifications',
            Schema::hasTable('current_forward_window_verifications')
                ? DB::table('current_forward_window_verifications')->count()
                : 0,
        );

        $openRoot = Schema::hasColumn('current_forward_windows', 'chain_root_id')
            ? DB::table('current_forward_windows')->where('state', 'CONTINUATION_PENDING')->orderBy('id')->first()
            : null;
        $deadline = strtotime((string) ($openRoot->continuation_deadline_at ?? ''));
        $lines[] = '# HELP nntmux_orchestrator_current_forward_continuation_deadline_remaining_seconds Remaining absolute time for the open exact-lineage chain.';
        $lines[] = '# TYPE nntmux_orchestrator_current_forward_continuation_deadline_remaining_seconds gauge';
        $lines[] = $this->metric(
            'nntmux_orchestrator_current_forward_continuation_deadline_remaining_seconds',
            $deadline === false ? 0 : max(0, $deadline - time()),
        );
        $lines[] = '# HELP nntmux_orchestrator_current_forward_continuation_objects Exact objects linked to the open continuation chain.';
        $lines[] = '# TYPE nntmux_orchestrator_current_forward_continuation_objects gauge';
        foreach (['COLLECTION' => 'collections', 'BINARY' => 'binaries', 'RELEASE' => 'releases'] as $type => $item) {
            $count = $openRoot !== null && Schema::hasTable('current_forward_window_objects')
                ? DB::table('current_forward_window_objects')
                    ->where('chain_root_id', $openRoot->id)
                    ->where('object_type', $type)
                    ->distinct()
                    ->count('object_id')
                : 0;
            $lines[] = $this->metric(
                'nntmux_orchestrator_current_forward_continuation_objects',
                $count,
                ['item' => $item],
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function workerTelemetryMetrics(?float $now = null): array
    {
        $workers = array_keys($this->jobCatalog->jobs());
        $snapshot = $this->workerTelemetry->snapshot($workers, $now);
        $lines = [
            '# HELP nntmux_worker_telemetry_available Whether the resettable Redis-backed worker telemetry store was readable; counter history resets when its cache storage is replaced.',
            '# TYPE nntmux_worker_telemetry_available gauge',
            $this->metric('nntmux_worker_telemetry_available', $snapshot['available'] ? 1 : 0),
            '# HELP nntmux_worker_runs_total Distributed worker cycles by worker and bounded outcome since the Redis telemetry cache was created.',
            '# TYPE nntmux_worker_runs_total counter',
            '# HELP nntmux_worker_items_total Worker item observations by worker, bounded item, and bounded result since the Redis telemetry cache was created.',
            '# TYPE nntmux_worker_items_total counter',
            '# HELP nntmux_worker_last_run_duration_seconds Duration of the most recently completed worker cycle.',
            '# TYPE nntmux_worker_last_run_duration_seconds gauge',
            '# HELP nntmux_worker_last_started_timestamp_seconds Unix timestamp when the most recent worker cycle started.',
            '# TYPE nntmux_worker_last_started_timestamp_seconds gauge',
            '# HELP nntmux_worker_last_completed_timestamp_seconds Unix timestamp when the most recent worker cycle completed with any outcome.',
            '# TYPE nntmux_worker_last_completed_timestamp_seconds gauge',
            '# HELP nntmux_worker_last_success_timestamp_seconds Unix timestamp of the most recent successful worker cycle.',
            '# TYPE nntmux_worker_last_success_timestamp_seconds gauge',
            '# HELP nntmux_worker_in_progress Best-effort Redis state indicating whether a worker cycle is currently executing; it can remain stale after abrupt process loss.',
            '# TYPE nntmux_worker_in_progress gauge',
            '# HELP nntmux_worker_in_progress_age_seconds Age of the currently executing worker cycle.',
            '# TYPE nntmux_worker_in_progress_age_seconds gauge',
            '# HELP nntmux_worker_in_progress_stale Whether an in-progress marker exceeded its maximum distributed-lock lifetime and was suppressed.',
            '# TYPE nntmux_worker_in_progress_stale gauge',
        ];

        // Do not turn a Redis outage into apparent counter resets. Prometheus
        // should retain the last real samples while availability reports the
        // telemetry failure independently.
        if (! $snapshot['available']) {
            return $lines;
        }

        $emptyWorker = [
            'runs' => array_fill_keys(DistributedWorkerTelemetry::RUN_OUTCOMES, 0),
            'items' => array_fill_keys(
                DistributedWorkerTelemetry::ITEMS_BY_WORKER['nzb-backlog'],
                array_fill_keys(DistributedWorkerTelemetry::ITEM_RESULTS, 0),
            ),
            'last_duration_seconds' => 0.0,
            'last_started_timestamp_seconds' => 0.0,
            'last_completed_timestamp_seconds' => 0.0,
            'last_success_timestamp_seconds' => 0.0,
            'in_progress' => false,
            'in_progress_age_seconds' => 0.0,
        ];

        foreach ($workers as $worker) {
            $workerSnapshot = $snapshot['workers'][$worker] ?? $emptyWorker;
            $staleAfterSeconds = $worker === 'nzb-backlog'
                ? max(1, (int) config('nntmux.distributed_nzb_lock_seconds', 7200))
                : max(
                    1,
                    (int) config('nntmux.distributed_lock_seconds', 900),
                    (int) config('nntmux.distributed_long_lock_seconds', 3600),
                );
            $staleInProgress = $workerSnapshot['in_progress']
                && $workerSnapshot['in_progress_age_seconds'] > $staleAfterSeconds;
            foreach (DistributedWorkerTelemetry::RUN_OUTCOMES as $outcome) {
                $lines[] = $this->metric('nntmux_worker_runs_total', $workerSnapshot['runs'][$outcome], [
                    'worker' => $worker,
                    'outcome' => $outcome,
                ]);
            }
            foreach (DistributedWorkerTelemetry::ITEMS_BY_WORKER[$worker] ?? [] as $item) {
                foreach (DistributedWorkerTelemetry::ITEM_RESULTS as $result) {
                    $lines[] = $this->metric('nntmux_worker_items_total', $workerSnapshot['items'][$item][$result], [
                        'worker' => $worker,
                        'item' => $item,
                        'result' => $result,
                    ]);
                }
            }
            $lines[] = $this->metric('nntmux_worker_last_run_duration_seconds', $workerSnapshot['last_duration_seconds'], [
                'worker' => $worker,
            ]);
            $lines[] = $this->metric('nntmux_worker_last_started_timestamp_seconds', $workerSnapshot['last_started_timestamp_seconds'], [
                'worker' => $worker,
            ]);
            $lines[] = $this->metric('nntmux_worker_last_completed_timestamp_seconds', $workerSnapshot['last_completed_timestamp_seconds'], [
                'worker' => $worker,
            ]);
            if ($workerSnapshot['last_success_timestamp_seconds'] > 0) {
                $lines[] = $this->metric('nntmux_worker_last_success_timestamp_seconds', $workerSnapshot['last_success_timestamp_seconds'], [
                    'worker' => $worker,
                ]);
            }
            $lines[] = $this->metric('nntmux_worker_in_progress', $workerSnapshot['in_progress'] && ! $staleInProgress ? 1 : 0, [
                'worker' => $worker,
            ]);
            $lines[] = $this->metric('nntmux_worker_in_progress_age_seconds', $staleInProgress ? 0 : $workerSnapshot['in_progress_age_seconds'], [
                'worker' => $worker,
            ]);
            $lines[] = $this->metric('nntmux_worker_in_progress_stale', $staleInProgress ? 1 : 0, [
                'worker' => $worker,
            ]);
        }

        $lines[] = '# HELP nntmux_nzb_selector_in_progress_age_seconds Age of the active dedicated NZB selector cycle.';
        $lines[] = '# TYPE nntmux_nzb_selector_in_progress_age_seconds gauge';
        $lines[] = $this->metric(
            'nntmux_nzb_selector_in_progress_age_seconds',
            (($snapshot['workers']['nzb-backlog']['in_progress_age_seconds'] ?? 0)
                > max(1, (int) config('nntmux.distributed_nzb_lock_seconds', 7200)))
                ? 0
                : ($snapshot['workers']['nzb-backlog']['in_progress_age_seconds'] ?? 0),
        );
        $lines[] = '# HELP nntmux_nzb_selector_last_duration_seconds Duration of the most recent dedicated NZB candidate selection, excluding NZB writes.';
        $lines[] = '# TYPE nntmux_nzb_selector_last_duration_seconds gauge';
        $lines[] = $this->metric(
            'nntmux_nzb_selector_last_duration_seconds',
            $snapshot['nzb_selector_last_duration_seconds'],
        );

        return $lines;
    }

    /** @return list<string> */
    private function splitCollectionTelemetryMetrics(): array
    {
        $groups = array_values(array_unique(array_filter(array_map(
            static fn (mixed $group): string => trim((string) $group),
            (array) config('nntmux.split_collection_reconcile_groups', []),
        ))));
        if (count($groups) > 16) {
            $groups = [];
        }
        $snapshot = $this->splitCollectionTelemetry->snapshot($groups);
        $lines = [
            '# HELP nntmux_split_collection_pair_xref_telemetry_available Whether the resettable Redis-backed split-pair decision counters were readable.',
            '# TYPE nntmux_split_collection_pair_xref_telemetry_available gauge',
            $this->metric('nntmux_split_collection_pair_xref_telemetry_available', $snapshot['available'] ? 1 : 0),
            '# HELP nntmux_split_collection_pair_xref_decisions_total Initial split-pair Xref decisions by bounded group and fixed result since the Redis telemetry cache was created.',
            '# TYPE nntmux_split_collection_pair_xref_decisions_total counter',
        ];
        if (! $snapshot['available']) {
            return $lines;
        }

        foreach ($groups as $group) {
            foreach (SplitCollectionTelemetry::DECISIONS as $decision) {
                $lines[] = $this->metric(
                    'nntmux_split_collection_pair_xref_decisions_total',
                    $snapshot['groups'][$group][$decision] ?? 0,
                    ['group' => $group, 'result' => $decision],
                );
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function lockMetrics(): array
    {
        $lines = [
            '# HELP nntmux_worker_lock_ttl_seconds Redis distributed worker lock TTL.',
            '# TYPE nntmux_worker_lock_ttl_seconds gauge',
        ];

        try {
            $prefix = (string) config('cache.prefix');
            $connectionName = (string) (config('cache.stores.redis.lock_connection')
                ?: config('cache.stores.redis.connection', 'default')
                ?: 'default');
            foreach ($this->scanRedisKeys($connectionName, $this->redisPhysicalPattern('nntmux:distributed-worker:*')) as $key) {
                $key = (string) $key;
                $job = preg_replace('/^.*distributed-worker:/', '', $key) ?: $key;
                $ttl = $this->redisTtl($key, $prefix, $connectionName);

                $lines[] = $this->metric('nntmux_worker_lock_ttl_seconds', $ttl, [
                    'worker' => $job,
                    'prefix' => $prefix,
                ]);
            }
        } catch (Throwable) {
            $lines[] = $this->metric('nntmux_worker_lock_ttl_seconds', -1, [
                'worker' => 'redis_unavailable',
                'prefix' => '',
            ]);
        }

        return $lines;
    }

    private function redisTtl(string $key, string $prefix, string $connectionName): int
    {
        foreach ($this->redisKeyCandidates($key, $prefix) as $candidate) {
            $ttl = (int) Redis::connection($connectionName)->ttl($candidate);
            if ($ttl !== -2) {
                return $ttl;
            }
        }

        return -2;
    }

    private function redisValue(string $key, string $connectionName): int
    {
        foreach ($this->redisKeyCandidates($key, (string) config('cache.prefix')) as $candidate) {
            $value = Redis::connection($connectionName)->get($candidate);
            if ($value !== null) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private function scanRedisKeys(string $connectionName, string $pattern, int $count = 1000): array
    {
        /** @var PhpRedisConnection $connection */
        $connection = Redis::connection($connectionName);
        $defaultCursor = $this->defaultRedisScanCursor($connection);
        $cursor = $defaultCursor;
        $keys = [];
        $iterations = 0;

        do {
            // Laravel's Redis connection exposes SCAN dynamically with the same options shape used by RedisStore.
            $scanResult = $connection->scan($cursor, ['match' => $pattern, 'count' => $count]);
            if (! is_array($scanResult)) {
                break;
            }

            [$cursor, $chunk] = $scanResult;
            if (! is_array($chunk)) {
                break;
            }

            foreach ($chunk as $key) {
                $keys[] = (string) $key;
            }

            $iterations++;
        } while (! $this->redisScanComplete($cursor, $defaultCursor) && $iterations < 1000);

        return array_values(array_unique($keys));
    }

    private function redisPhysicalPattern(string $logicalPattern): string
    {
        return (string) config('database.redis.options.prefix').(string) config('cache.prefix').$logicalPattern;
    }

    private function defaultRedisScanCursor(mixed $connection): mixed
    {
        return $connection instanceof PhpRedisConnection && version_compare((string) phpversion('redis'), '6.1.0', '>=')
            ? null
            : '0';
    }

    private function redisScanComplete(mixed $cursor, mixed $defaultCursor): bool
    {
        if ($defaultCursor === null) {
            return $cursor === null || $cursor === 0 || $cursor === '0';
        }

        return (string) $cursor === (string) $defaultCursor;
    }

    /**
     * Redis::keys() returns the physical key with the Redis connection prefix,
     * while Redis::ttl() applies that prefix before lookup.
     *
     * @return list<string>
     */
    private function redisKeyCandidates(string $key, string $cachePrefix): array
    {
        $candidates = [$key];
        $redisPrefix = (string) config('database.redis.options.prefix');

        foreach ([$redisPrefix, $cachePrefix, $redisPrefix.$cachePrefix] as $prefix) {
            if ($prefix !== '' && str_starts_with($key, $prefix)) {
                $candidates[] = substr($key, strlen($prefix));
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function metric(string $name, float|int $value, array $labels = []): string
    {
        if ($labels === []) {
            return sprintf('%s %s', $name, $this->formatValue($value));
        }

        $pairs = [];
        foreach ($labels as $key => $label) {
            $pairs[] = sprintf('%s="%s"', $key, $this->escapeLabel($label));
        }

        return sprintf('%s{%s} %s', $name, implode(',', $pairs), $this->formatValue($value));
    }

    private function formatValue(float|int $value): string
    {
        return is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value;
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }
}
