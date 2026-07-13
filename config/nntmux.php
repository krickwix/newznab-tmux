<?php

return [
    'db_name' => env('DB_DATABASE', 'nntmux'),
    'items_per_page' => env('ITEMS_PER_PAGE', 50),
    'items_per_cover_page' => env('ITEMS_PER_COVER_PAGE', 20),
    'max_pager_results' => env('MAX_PAGER_RESULTS', 125000),
    'echocli' => env('ECHOCLI', true),
    'rename_par2' => env('RENAME_PAR2', true),
    'rename_music_mediainfo' => env('RENAME_MUSIC_MEDIAINFO', true),
    'cache_expiry_short' => (int) env('CACHE_EXPIRY_SHORT', 5),
    'cache_expiry_medium' => (int) env('CACHE_EXPIRY_MEDIUM', 10),
    'cache_expiry_long' => (int) env('CACHE_EXPIRY_LONG', 15),
    'admin_username' => env('ADMIN_USER', 'admin'),
    'admin_password' => env('ADMIN_PASS', 'admin'),
    'admin_email' => env('ADMIN_EMAIL', 'admin@example.com'),
    'crc_token' => env('CRC_TOKEN', null),
    'multiprocessing_max_child_time' => env('NN_MULTIPROCESSING_MAX_CHILD_TIME', 1800),
    'concurrency_timeout' => env('NN_CONCURRENCY_TIMEOUT'),
    'stream_fork_output' => env('STREAM_FORK_OUTPUT', false),
    'inline_nzb_creation' => (bool) env('NNTMUX_INLINE_NZB_CREATION', true),
    'build_version' => env('NNTMUX_BUILD_VERSION', 'unknown'),
    'distributed_nzb_limit' => (int) env('NNTMUX_DISTRIBUTED_NZB_LIMIT', 1),
    'distributed_nzb_sleep' => (int) env('NNTMUX_DISTRIBUTED_NZB_SLEEP', 60),
    'distributed_nzb_scan_cap' => (int) env('NNTMUX_DISTRIBUTED_NZB_SCAN_CAP', 10000),
    'distributed_nzb_lock_seconds' => (int) env('NNTMUX_DISTRIBUTED_NZB_LOCK_SECONDS', 7200),
    'orchestrator' => [
        'leader_lock_seconds' => min(600, max(120, (int) env('NNTMUX_ORCHESTRATOR_LEADER_LOCK_SECONDS', 120))),
        'lock_store' => env('NNTMUX_ORCHESTRATOR_LOCK_STORE', 'redis'),
        'state_store' => env('NNTMUX_ORCHESTRATOR_STATE_STORE', 'redis'),
        'auto_backfill' => filter_var(env('NNTMUX_ORCHESTRATOR_AUTO_BACKFILL', false), FILTER_VALIDATE_BOOL),
        'permit_observation_seconds' => max(300, (int) env('NNTMUX_ORCHESTRATOR_PERMIT_OBSERVATION_SECONDS', 1200)),
        'permit_claim_grace_seconds' => max(120, (int) env('NNTMUX_ORCHESTRATOR_PERMIT_CLAIM_GRACE_SECONDS', 120)),
        'backfill_probe_groups' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'NNTMUX_ORCHESTRATOR_BACKFILL_PROBE_GROUPS',
                'alt.binaries.tv,alt.binaries.appletv.movies,alt.binaries.movies.dvd,alt.binaries.movies.xvid,alt.binaries.sounds.lossless.metal,alt.binaries.sounds.lossless,alt.binaries.dvd.classics,alt.binaries.dvd.criterion,alt.binaries.dvd-freak,alt.binaries.dvd-r,alt.binaries.dvd'
            ))
        ))),
        'backfill_yield_ttl_seconds' => max(3600, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_YIELD_TTL_SECONDS', 86400)),
        'backfill_cohort_postdate_tolerance_seconds' => min(86400, max(0, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_COHORT_POSTDATE_TOLERANCE_SECONDS', 3600))),
        'backfill_min_payload_bytes' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_MIN_PAYLOAD_BYTES', 104_857_600)),
        'backfill_exploit_attempts_before_explore' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_EXPLOIT_ATTEMPTS_BEFORE_EXPLORE', 3)),
        'backfill_aggressive_explore_below_yield' => max(0.0, (float) env('NNTMUX_ORCHESTRATOR_BACKFILL_AGGRESSIVE_EXPLORE_BELOW_YIELD', 0.0)),
        'backfill_scale_min_yield' => max(0.0, (float) env('NNTMUX_ORCHESTRATOR_BACKFILL_SCALE_MIN_YIELD', 1.0)),
        'backfill_scale_min_attempts' => max(2, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_SCALE_MIN_ATTEMPTS', 2)),
        'backfill_target_nzbs_per_permit' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_TARGET_NZBS_PER_PERMIT', 60)),
        'backfill_zero_output_grace_seconds' => min(1200, max(300, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_ZERO_OUTPUT_GRACE_SECONDS', 300))),
        'backfill_incomplete_release_grace_seconds' => min(1200, max(600, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_INCOMPLETE_RELEASE_GRACE_SECONDS', 600))),
        'backfill_delayed_attribution_seconds' => min(21_600, max(7_200, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_DELAYED_ATTRIBUTION_SECONDS', 9_000))),
        'backfill_max_quantity' => max(10000, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_MAX_QUANTITY', 200000)),
        'backfill_headroom_fraction' => min(0.5, max(0.01, (float) env('NNTMUX_ORCHESTRATOR_BACKFILL_HEADROOM_FRACTION', 0.10))),
        'backfill_growth_per_10k' => [
            'parts' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_PARTS_PER_10K', 10000)),
            'binaries' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_BINARIES_PER_10K', 500)),
            'collections' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_COLLECTIONS_PER_10K', 100)),
            'releases' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_RELEASES_PER_10K', 100)),
            'nzbs' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_NZBS_PER_10K', 100)),
        ],
        'backfill_growth_learning_min_samples' => max(12, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_GROWTH_LEARNING_MIN_SAMPLES', 12)),
        'backfill_growth_learning_safety_multiplier' => min(4.0, max(1.25, (float) env('NNTMUX_ORCHESTRATOR_BACKFILL_GROWTH_LEARNING_SAFETY_MULTIPLIER', 2.0))),
        'backfill_growth_learning_prior_floor_fraction' => min(1.0, max(0.10, (float) env('NNTMUX_ORCHESTRATOR_BACKFILL_GROWTH_LEARNING_PRIOR_FLOOR_FRACTION', 0.25))),
        'backfill_growth_learning_latest_sample_seconds' => min(86400, max(300, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_GROWTH_LEARNING_LATEST_SAMPLE_SECONDS', 7200))),
        'backfill_target_lock_retry_seconds' => min(86400, max(300, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_TARGET_LOCK_RETRY_SECONDS', 21600))),
        'backfill_terminal_min_attempts' => max(3, (int) env('NNTMUX_ORCHESTRATOR_BACKFILL_TERMINAL_MIN_ATTEMPTS', 3)),
        'backfill_terminal_min_yield' => max(1.0, (float) env('NNTMUX_ORCHESTRATOR_BACKFILL_TERMINAL_MIN_YIELD', 1.0)),
        'prometheus_url' => env('NNTMUX_ORCHESTRATOR_PROMETHEUS_URL', 'http://monitoring-kube-prometheus-prometheus.monitoring.svc.cluster.local:9090'),
        'prometheus_retry_attempts' => min(5, max(1, (int) env('NNTMUX_ORCHESTRATOR_PROMETHEUS_RETRY_ATTEMPTS', 3))),
        'prometheus_sample_max_age_seconds' => min(600, max(30, (int) env('NNTMUX_ORCHESTRATOR_PROMETHEUS_SAMPLE_MAX_AGE_SECONDS', 120))),
        'snapshot_max_age_seconds' => min(600, max(60, (int) env('NNTMUX_ORCHESTRATOR_SNAPSHOT_MAX_AGE_SECONDS', 180))),
        'database_memory_limit_bytes' => (int) env('NNTMUX_ORCHESTRATOR_DB_MEMORY_LIMIT_BYTES', 4456448000),
        'database_cpu_limit_cores' => (float) env('NNTMUX_ORCHESTRATOR_DB_CPU_LIMIT_CORES', 3),
        'pressure_projection_horizon_minutes' => max(1, (int) env('NNTMUX_ORCHESTRATOR_PRESSURE_HORIZON_MINUTES', 120)),
        'body_recovery_source_regex_ids' => array_values(array_map(
            'intval',
            array_filter(array_map('trim', explode(',', (string) env('NNTMUX_ORCHESTRATOR_BODY_RECOVERY_SOURCE_REGEX_IDS', '-20'))), static fn (string $value): bool => $value !== '')
        )),
        'body_recovery_source_groups' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NNTMUX_ORCHESTRATOR_BODY_RECOVERY_SOURCE_GROUPS', 'alt.binaries.lossless'))
        ))),
        'body_recovery_source_max_current_parts' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BODY_RECOVERY_SOURCE_MAX_CURRENT_PARTS', 2)),
        'body_recovery_source_min_total_parts' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BODY_RECOVERY_SOURCE_MIN_TOTAL_PARTS', 10)),
        'body_recovery_source_cutoff_hours' => max(1, (int) env('NNTMUX_ORCHESTRATOR_BODY_RECOVERY_SOURCE_CUTOFF_HOURS', 2)),
        'storage_floor_bytes' => (int) env('NNTMUX_ORCHESTRATOR_STORAGE_FLOOR_BYTES', 18500000000),
        'high_watermarks' => [
            'parts' => (int) env('NNTMUX_ORCHESTRATOR_PARTS_HIGH', 300000000),
            'binaries' => (int) env('NNTMUX_ORCHESTRATOR_BINARIES_HIGH', 1000000),
            'collections' => (int) env('NNTMUX_ORCHESTRATOR_COLLECTIONS_HIGH', 20000),
            'collections_total' => (int) env('NNTMUX_ORCHESTRATOR_COLLECTIONS_TOTAL_HIGH', 80000),
            'recovery_sources' => (int) env('NNTMUX_ORCHESTRATOR_RECOVERY_SOURCES_HIGH', 60000),
            'releases' => (int) env('NNTMUX_ORCHESTRATOR_RELEASES_HIGH', 20000),
            'nzbs' => (int) env('NNTMUX_ORCHESTRATOR_NZBS_HIGH', 12000),
        ],
        'low_watermarks' => [
            'collections_total' => (int) env('NNTMUX_ORCHESTRATOR_COLLECTIONS_TOTAL_LOW', 48000),
            'recovery_sources' => (int) env('NNTMUX_ORCHESTRATOR_RECOVERY_SOURCES_LOW', 36000),
        ],
        'age_slo_seconds' => [
            'binaries' => (int) env('NNTMUX_ORCHESTRATOR_BINARIES_AGE_SLO', 172800),
            'collections' => (int) env('NNTMUX_ORCHESTRATOR_COLLECTIONS_AGE_SLO', 172800),
            'releases' => (int) env('NNTMUX_ORCHESTRATOR_RELEASES_AGE_SLO', 86400),
            'nzbs' => (int) env('NNTMUX_ORCHESTRATOR_NZBS_AGE_SLO', 86400),
        ],
        'promql' => [
            'storage_available' => env('NNTMUX_ORCHESTRATOR_STORAGE_PROMQL', 'kubelet_volume_stats_available_bytes{namespace="media",persistentvolumeclaim="data-nntmux-mariadb-0"}'),
            'database_memory' => env('NNTMUX_ORCHESTRATOR_DB_MEMORY_PROMQL', 'container_memory_working_set_bytes{namespace="media",pod="nntmux-mariadb-0",container!="",container!="POD"}'),
            'database_cpu' => env('NNTMUX_ORCHESTRATOR_DB_CPU_PROMQL', 'sum(rate(container_cpu_usage_seconds_total{namespace="media",pod="nntmux-mariadb-0",container!="",container!="POD"}[5m]))'),
        ],
        'promql_freshness' => [
            'storage_available' => env('NNTMUX_ORCHESTRATOR_STORAGE_FRESHNESS_PROMQL', 'max(timestamp(kubelet_volume_stats_available_bytes{namespace="media",persistentvolumeclaim="data-nntmux-mariadb-0"}))'),
            'database_memory' => env('NNTMUX_ORCHESTRATOR_DB_MEMORY_FRESHNESS_PROMQL', 'max(timestamp(container_memory_working_set_bytes{namespace="media",pod="nntmux-mariadb-0",container!="",container!="POD"}))'),
            'database_cpu' => env('NNTMUX_ORCHESTRATOR_DB_CPU_FRESHNESS_PROMQL', 'max(timestamp(container_cpu_usage_seconds_total{namespace="media",pod="nntmux-mariadb-0",container!="",container!="POD"}))'),
        ],
    ],
    'distributed_lock_seconds' => (int) env('NNTMUX_DISTRIBUTED_LOCK_SECONDS', 900),
    'distributed_long_lock_seconds' => (int) env('NNTMUX_DISTRIBUTED_LONG_LOCK_SECONDS', 3600),
    'distributed_lock_store' => env('NNTMUX_DISTRIBUTED_LOCK_STORE', 'redis'),
    'allow_large_cbp_fk_restore' => (bool) env('NNTMUX_ALLOW_LARGE_CBP_FK_RESTORE', false),
    'purge_inactive_users' => env('PURGE_INACTIVE_USERS', false),
    'purge_inactive_users_days' => env('PURGE_INACTIVE_USERS_DAYS', 180),
    'mysql_search_fallback' => env('MYSQL_SEARCH_FALLBACK', false), // Disable MySQL LIKE fallback when Manticore/Elasticsearch return no results

    /*
    |--------------------------------------------------------------------------
    | Release dedupe (import-time)
    |--------------------------------------------------------------------------
    |
    | Size tolerance for matching an existing release when deduping imports
    | (collections / NZB). Default 0.05 = ±5% on total bytes (par2/RAR drift).
    |
    */
    'release_dedupe_size_tolerance' => (float) env('RELEASE_DEDUPE_SIZE_TOLERANCE', 0.05),
    'btcpay_webhook_secret' => env('BTCPAY_SECRET'),
    'tmp_unrar_path' => env('TEMP_UNRAR_PATH', storage_path('tmp/unrar/')),
    'tmp_unzip_path' => env('TEMP_UNZIP_PATH', storage_path('tmp/unzip/')),
    'nzb_import_folder' => env('NZB_IMPORT_FOLDER'),
    'nzb_upload_folder' => env('NZB_UPLOAD_FOLDER'),
    'redis_fast_degrade' => (bool) env('REDIS_FAST_DEGRADE', true),
    'redis_tcp_check_seconds' => (float) env('REDIS_TCP_CHECK_SECONDS', 0.2),
    'body_preamble_deobfuscate_groups' => env('NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_GROUPS', ''),
    'body_preamble_deobfuscate_limit' => (int) env('NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_LIMIT', 0),
    'body_preamble_line_limit' => (int) env('NNTMUX_BODY_PREAMBLE_LINE_LIMIT', 8),
    'body_preamble_deobfuscate_max_seconds' => (float) env('NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_MAX_SECONDS', 0),
    'categorization' => [
        'log' => (bool) env('NNTMUX_CATEGORIZATION_LOG', false),
    ],
];
