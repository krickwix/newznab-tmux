<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\DistributedJobCatalog;
use Tests\TestCase;

class DistributedJobCatalogTest extends TestCase
{
    public function test_it_builds_core_job_commands_from_tmux_settings(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar([
            'binaries_run' => 1,
            'backfill' => 1,
            'releases_run' => 1,
        ]);

        $this->assertSame('multiprocessing:safe', $catalog->resolve('binaries', $runVar)['commands'][0]['command']);
        $this->assertSame(['type' => 'binaries'], $catalog->resolve('binaries', $runVar)['commands'][0]['arguments']);

        $this->assertSame('multiprocessing:safe', $catalog->resolve('backfill', $runVar)['commands'][0]['command']);
        $this->assertSame(['type' => 'backfill'], $catalog->resolve('backfill', $runVar)['commands'][0]['arguments']);

        $this->assertSame('multiprocessing:releases', $catalog->resolve('releases', $runVar)['commands'][0]['command']);
    }

    public function test_it_filters_disabled_core_jobs(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar([
            'binaries_run' => 0,
            'backfill' => 0,
            'releases_run' => 0,
        ]);

        foreach (['binaries', 'backfill', 'releases'] as $job) {
            $plan = $catalog->resolve($job, $runVar);

            $this->assertFalse($plan['enabled'], $job);
            $this->assertSame([], $plan['commands'], $job);
        }
    }

    public function test_backfill_is_disabled_when_no_group_level_safe_backfill_work_exists(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('backfill', $this->runVar(
            [
                'backfill' => 4,
                'backfill_days' => 1,
            ],
            [
                'backfill_groups_days' => 0,
            ],
        ));

        $this->assertFalse($plan['enabled']);
        $this->assertSame('no backfill groups to process', $plan['disabled_reason']);
        $this->assertSame([], $plan['commands']);
    }

    public function test_it_matches_tmux_sequential_mode_one(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar(
            [
                'binaries_run' => 1,
                'backfill' => 4,
                'releases_run' => 1,
                'post' => 3,
            ],
            [
                'work' => 1,
                'processnfo' => 1,
            ],
            ['sequential' => 1],
        );

        $this->assertFalse($catalog->resolve('binaries', $runVar)['enabled']);
        $this->assertFalse($catalog->resolve('backfill', $runVar)['enabled']);
        $this->assertFalse($catalog->resolve('per-group', $runVar)['enabled']);
        $this->assertTrue($catalog->resolve('releases', $runVar)['enabled']);
        $this->assertTrue($catalog->resolve('post-additional', $runVar)['enabled']);
    }

    public function test_it_matches_tmux_sequential_mode_two(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar(
            [
                'binaries_run' => 1,
                'backfill' => 4,
                'releases_run' => 1,
                'post' => 3,
            ],
            [
                'work' => 1,
                'processnfo' => 1,
            ],
            ['sequential' => 2],
        );

        foreach (['binaries', 'backfill', 'releases', 'fixnames', 'hashed-fixnames', 'post-additional'] as $job) {
            $this->assertFalse($catalog->resolve($job, $runVar)['enabled'], $job);
        }

        $this->assertTrue($catalog->resolve('per-group', $runVar)['enabled']);
    }

    public function test_it_filters_postprocess_jobs_by_enabled_flags_and_work_counts(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar(
            [
                'post' => 3,
                'post_non' => 1,
                'post_amazon' => 1,
                'processtvrage' => 1,
                'processanime' => 1,
                'processmovies' => 1,
            ],
            [
                'work' => 5,
                'processnfo' => 4,
                'processtv' => 3,
                'processanime' => 2,
                'processmovies' => 1,
                'processmusic' => 1,
            ],
        );

        $this->assertSame(['add', 'nfo'], $this->argumentValues($catalog->resolve('post-additional', $runVar), 'type'));
        $this->assertSame(['tv', 'ani'], $this->argumentValues($catalog->resolve('post-tv', $runVar), 'type'));
        $this->assertSame(['mov'], $this->argumentValues($catalog->resolve('post-movies', $runVar), 'type'));
        $this->assertSame(['ama'], $this->argumentValues($catalog->resolve('post-amazon', $runVar), 'type'));
    }

    public function test_fixnames_includes_full_history_nfo_and_file_passes(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('fixnames', $this->runVar(
            ['fix_names' => 1],
            ['processrenames' => 1],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame(
            ['3', '4', '5', '6', '6', '21', '21', '8', '7', '9', '11', '13', '15', '17', '19'],
            $this->argumentValues($plan, 'method'),
        );
        $this->assertSame('movies', $plan['commands'][4]['arguments']['--category']);
        $this->assertSame(500, $plan['commands'][4]['arguments']['--limit']);
        $this->assertSame('other', $plan['commands'][5]['arguments']['--category']);
        $this->assertSame(500, $plan['commands'][5]['arguments']['--limit']);
        $this->assertSame('movies', $plan['commands'][6]['arguments']['--category']);
        $this->assertSame(500, $plan['commands'][6]['arguments']['--limit']);
        $this->assertSame('other', $plan['commands'][7]['arguments']['--category']);
        $this->assertSame(50, $plan['commands'][7]['arguments']['--limit']);
    }

    public function test_hashed_fixnames_runs_full_history_hashed_passes_when_hashed_count_exceeds_threshold(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('hashed-fixnames', $this->runVar(
            ['fix_names' => 1],
            ['other_hashed' => 101],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame(
            ['4', '6', '21', '18', '10', '14', '16', '20', '12', '8'],
            $this->argumentValues($plan, 'method'),
        );

        foreach ($plan['commands'] as $command) {
            $this->assertSame('releases:fix-names', $command['command']);
            $this->assertSame('hashed', $command['arguments']['--category']);
            $this->assertTrue($command['arguments']['--update']);
            $this->assertTrue($command['arguments']['--set-status']);
        }
    }

    public function test_hashed_fixnames_does_not_run_when_hashed_count_is_not_greater_than_threshold(): void
    {
        $catalog = new DistributedJobCatalog;

        foreach ([0, 100] as $count) {
            $plan = $catalog->resolve('hashed-fixnames', $this->runVar(
                ['fix_names' => 1],
                ['other_hashed' => $count],
            ));

            $this->assertFalse($plan['enabled'], 'count='.$count);
            $this->assertSame([], $plan['commands'], 'count='.$count);
        }
    }

    public function test_metadata_refresh_runs_external_source_refresh_before_strong_fixname_passes(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('metadata-refresh', $this->runVar(
            [
                'metadata_refresh' => 1,
                'metadata_refresh_limit' => 25,
                'metadata_refresh_sleep_ms' => 250,
                'metadata_refresh_timer' => 900,
            ],
            ['other_hashed' => 150],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame(900, $plan['sleep']);
        $this->assertSame('predb:refresh-external-metadata', $plan['commands'][0]['command']);
        $this->assertSame(25, $plan['commands'][0]['arguments']['--limit']);
        $this->assertSame(250, $plan['commands'][0]['arguments']['--sleep-ms']);
        $this->assertSame(['all'], $plan['commands'][0]['arguments']['--source']);
        $this->assertSame(['20', '16'], array_map(
            static fn (array $command): string => (string) $command['arguments']['method'],
            array_slice($plan['commands'], 1, 2),
        ));
        $this->assertSame('hashed', $plan['commands'][1]['arguments']['--category']);
        $this->assertSame('hashed', $plan['commands'][2]['arguments']['--category']);
    }

    public function test_post_additional_runs_external_metadata_refresh_after_postprocess_when_hashed_backlog_exists(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('post-additional', $this->runVar(
            [
                'post' => 3,
                'metadata_refresh' => 1,
                'metadata_refresh_postprocess' => 1,
                'metadata_refresh_postprocess_limit' => 7,
                'metadata_refresh_sleep_ms' => 125,
            ],
            [
                'work' => 5,
                'processnfo' => 4,
                'other_hashed' => 150,
            ],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame([
            'multiprocessing:postprocess',
            'multiprocessing:postprocess',
            'predb:refresh-external-metadata',
            'releases:fix-names',
            'releases:fix-names',
        ], array_column($plan['commands'], 'command'));
        $this->assertSame('add', $plan['commands'][0]['arguments']['type']);
        $this->assertSame('nfo', $plan['commands'][1]['arguments']['type']);
        $this->assertSame(['all'], $plan['commands'][2]['arguments']['--source']);
        $this->assertSame(7, $plan['commands'][2]['arguments']['--limit']);
        $this->assertSame(125, $plan['commands'][2]['arguments']['--sleep-ms']);
        $this->assertSame(['20', '16'], array_map(
            static fn (array $command): string => (string) $command['arguments']['method'],
            array_slice($plan['commands'], 3, 2),
        ));
    }

    public function test_post_additional_skips_external_metadata_refresh_when_postprocess_refresh_is_disabled(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('post-additional', $this->runVar(
            [
                'post' => 3,
                'metadata_refresh_postprocess' => 0,
            ],
            [
                'work' => 5,
                'processnfo' => 4,
                'other_hashed' => 150,
            ],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame([
            'multiprocessing:postprocess',
            'multiprocessing:postprocess',
        ], array_column($plan['commands'], 'command'));
    }

    public function test_post_additional_skips_external_metadata_refresh_when_hashed_backlog_is_below_threshold(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('post-additional', $this->runVar(
            [
                'post' => 3,
                'metadata_refresh_postprocess' => 1,
            ],
            [
                'work' => 5,
                'processnfo' => 4,
                'other_hashed' => 100,
            ],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame([
            'multiprocessing:postprocess',
            'multiprocessing:postprocess',
        ], array_column($plan['commands'], 'command'));
    }

    public function test_metadata_refresh_is_disabled_when_not_enabled(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('metadata-refresh', $this->runVar(
            ['metadata_refresh' => 0],
            ['other_hashed' => 150],
        ));

        $this->assertFalse($plan['enabled']);
        $this->assertSame([], $plan['commands']);
        $this->assertSame('disabled in settings', $plan['disabled_reason']);
    }

    public function test_it_uses_sleep_metadata_without_shell_sleep_commands(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('releases', $this->runVar([
            'releases_run' => 1,
            'rel_timer' => 123,
        ]));

        $this->assertSame(123, $plan['sleep']);

        foreach ($plan['commands'] as $command) {
            $this->assertStringNotContainsString('sleep', $command['command']);
            $this->assertStringNotContainsString('tmux', $command['command']);
            $this->assertStringNotContainsString('tee', $command['command']);
        }
    }

    public function test_native_worker_coverage_lists_stay_in_sync_with_catalog(): void
    {
        $catalogJobs = array_keys((new DistributedJobCatalog)->jobs());
        sort($catalogJobs);

        $goNativeJobs = $this->goSupportsNativeLaneJobs();
        sort($goNativeJobs);
        $this->assertSame($catalogJobs, $goNativeJobs, 'Go native --run-lane support must cover every catalog worker.');

        $evalAuditJobs = $this->evalAuditDefaultJobs();
        sort($evalAuditJobs);
        $this->assertSame($catalogJobs, $evalAuditJobs, 'Compose eval audit must validate every catalog worker.');

        $evalRunJobs = $this->evalRunDefaultJobs();
        sort($evalRunJobs);
        $this->assertSame($catalogJobs, $evalRunJobs, 'Compose eval execution must exercise every catalog worker.');

        $evalFixtureJobs = $this->evalFixtureRunDefaultJobs();
        sort($evalFixtureJobs);
        $this->assertSame($catalogJobs, $evalFixtureJobs, 'Fixture-backed Compose eval execution must exercise every catalog worker.');

        $composeServiceJobs = $this->evalComposeServiceRunDefaultJobs();
        sort($composeServiceJobs);
        $this->assertSame($catalogJobs, $composeServiceJobs, 'Compose native worker services must be runnable for every catalog worker.');

        $smokedJobs = $this->phpSmokeJobs();
        sort($smokedJobs);
        $this->assertSame($catalogJobs, $smokedJobs, 'PHP-orchestrated native smoke must cover every catalog worker.');
    }

    public function test_native_eval_compose_declares_one_shot_worker_services_for_every_catalog_job(): void
    {
        $catalogJobs = array_keys((new DistributedJobCatalog)->jobs());
        sort($catalogJobs);

        $composeJobs = $this->nativeEvalComposeWorkerJobs();
        sort($composeJobs);

        $this->assertSame($catalogJobs, $composeJobs, 'Compose native worker services must cover every catalog worker.');

        $source = (string) file_get_contents(base_path('docker-compose.native-eval.yml'));
        $this->assertStringContainsString('native-workers', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_WORKER_LANE_EXECUTION_ENABLED', $source);

        foreach ($catalogJobs as $job) {
            $service = 'native-'.$job.'-worker';
            $this->assertStringContainsString($service.':', $source);
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($service.':', '/').'.*?nntmux:worker.*?'.preg_quote($job, '/').'.*?--once.*?--stop-on-disabled.*?--lock-seconds=/s',
                $source,
                $service,
            );
        }
    }

    public function test_native_eval_compose_service_runner_executes_every_catalog_worker_service(): void
    {
        $source = $this->scriptSource('native/scripts/run-native-eval-compose-workers.sh');

        $this->assertStringContainsString('source native/scripts/native-eval-common.sh', $source);
        $this->assertStringContainsString('--profile native-workers', $source);
        $this->assertStringContainsString('config --services', $source);
        $this->assertStringContainsString('Missing compose native worker service', $source);
        $this->assertStringContainsString('seed_eval_worker_data', $source);
        $this->assertStringContainsString('configure_eval_lane', $source);
        $this->assertStringContainsString('native-${lane}-worker', $source);
        $this->assertStringContainsString('native lane completed', $source);
        $this->assertStringContainsString("redis-cli --scan --pattern '*nntmux:distributed-worker*'", $source);
    }

    public function test_native_eval_fixture_runner_uses_redis_cli_for_held_lock_setup(): void
    {
        $source = $this->scriptSource('native/scripts/run-native-eval-fixture-workers.sh');

        $this->assertStringContainsString('plan_lock_metadata()', $source);
        $this->assertStringContainsString('redis-cli SETEX "${lock_key}" "${lock_seconds}" "${owner}"', $source);
        $this->assertStringContainsString('redis-cli GET "${lock_key}"', $source);
        $this->assertStringContainsString('redis-cli DEL "${lock_key}"', $source);
        $this->assertStringNotContainsString('new Redis()', $source);
        $this->assertStringNotContainsString('maintnotifications', $source);
    }

    public function test_removecrap_production_commit_smoke_clears_native_test_guards(): void
    {
        $source = $this->scriptSource('native/scripts/verify-php-native-removecrap-production-commit-smoke.sh');

        $this->assertStringContainsString('nntmux-test-fixture --fixture removecrap', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_REMOVECRAP_PRODUCTION_COMMIT_SMOKE=1', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=0', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=', $source);
        $this->assertStringContainsString('php-orchestrated native removecrap production opt-in commit smoke verified', $source);
    }

    public function test_native_eval_nntp_k3s_sync_helper_is_secret_safe_and_repeatable(): void
    {
        $source = $this->scriptSource('native/scripts/sync-native-eval-nntp-from-k3s.sh');
        $gitignore = (string) file_get_contents(base_path('.gitignore'));

        $this->assertStringContainsString('--mode check|apply', $source);
        $this->assertStringContainsString('secretKeyRef', $source);
        $this->assertStringContainsString('configMapKeyRef', $source);
        $this->assertStringContainsString('envFrom', $source);
        $this->assertStringContainsString('NNTP_USERNAME', $source);
        $this->assertStringContainsString('NNTP_PASSWORD', $source);
        $this->assertStringContainsString('redacted', $source);
        $this->assertStringNotContainsString('print(value)', $source);
        $this->assertStringNotContainsString('echo "$value"', $source);
        $this->assertStringContainsString('.env.*', $gitignore);
    }

    public function test_eval_all_worker_runner_enables_every_lane_instead_of_skipping_disabled_plans(): void
    {
        $runner = $this->scriptSource('native/scripts/run-native-eval-all-workers.sh');
        $source = $runner
            ."\n"
            .$this->scriptSource('native/scripts/native-eval-common.sh');

        $this->assertStringContainsString('seed_eval_worker_data', $source);
        $this->assertStringContainsString('configure_eval_lane', $source);
        $this->assertStringContainsString('source native/scripts/native-eval-common.sh', $runner);
        $this->assertStringContainsString('require_native_eval_database "all-worker eval run"', $runner);
        $this->assertStringContainsString('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1', $source);
        $this->assertStringContainsString('fix_crap_opt", "Custom"', $source);
        $this->assertStringContainsString('metadata_refresh", "1"', $source);
        $this->assertStringContainsString('run_ircscraper", "1"', $source);
        $this->assertStringContainsString('sequential="2"', $source);
        $this->assertStringNotContainsString('env_value() {', $runner);
        $this->assertStringNotContainsString('INSERT INTO settings', $runner);
        $this->assertStringNotContainsString('skipped_lanes', $source);
        $this->assertStringNotContainsString('continue', $source);
    }

    public function test_eval_runners_clear_startup_smoke_environment_when_real_leaves_are_requested(): void
    {
        foreach ([
            'native/scripts/run-native-eval-first-lanes.sh',
            'native/scripts/run-native-eval-all-workers.sh',
            'native/scripts/run-native-eval-compose-workers.sh',
        ] as $path) {
            $source = $this->scriptSource($path)
                ."\n"
                .$this->scriptSource('native/scripts/native-eval-common.sh');

            $this->assertStringContainsString('allow_real_leaves', $source, $path);
            $this->assertStringContainsString('real_leaf_exec_environment_args', $source, $path);
            $this->assertStringContainsString('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=', $source, $path);
            $this->assertStringContainsString('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG=', $source, $path);
        }
    }

    public function test_eval_seed_can_target_a_real_nntp_group_for_real_leaf_runs(): void
    {
        $source = $this->scriptSource('native/scripts/native-eval-common.sh');

        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_GROUP_NAME', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_GROUP_FIRST_RECORD', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_GROUP_LAST_RECORD', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_SHORT_GROUP_FIRST_RECORD', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_SHORT_GROUP_LAST_RECORD', $source);
        $this->assertStringContainsString('env_unsigned_int', $source);
        $this->assertStringContainsString('sql_literal', $source);
        $this->assertStringContainsString('@eval_group_name', $source);
        $this->assertStringContainsString("description LIKE 'native eval%'", $source);
        $this->assertStringContainsString('DELETE FROM short_groups WHERE name = @eval_group_name', $source);
        $this->assertStringContainsString('alt.binaries.native.eval', $source);
    }

    public function test_eval_seed_includes_completed_release_creation_fixture(): void
    {
        $source = $this->scriptSource('native/scripts/native-eval-common.sh');

        $this->assertStringContainsString('Native Eval Release Proof', $source);
        $this->assertStringContainsString('native-eval-release-proof-collection', $source);
        $this->assertStringContainsString('DELETE FROM releases', $source);
        $this->assertStringContainsString('INSERT INTO binaries', $source);
        $this->assertStringContainsString('INSERT IGNORE INTO parts', $source);
        $this->assertStringContainsString('filecheck = VALUES(filecheck)', $source);
        $this->assertStringContainsString('releases_id = NULL', $source);
    }

    public function test_eval_all_worker_audit_enables_every_lane_instead_of_accepting_disabled_plans(): void
    {
        $source = (string) file_get_contents(base_path('native/scripts/audit-native-eval-all-workers.sh'))
            ."\n"
            .(string) file_get_contents(base_path('native/scripts/native-eval-common.sh'));

        $this->assertStringContainsString('seed_eval_worker_data', $source);
        $this->assertStringContainsString('configure_eval_lane', $source);
        $this->assertStringContainsString('metadata_refresh", "1"', $source);
        $this->assertStringContainsString('run_ircscraper", "1"', $source);
        $this->assertStringContainsString('sequential="2"', $source);
        $this->assertStringContainsString('if (! $enabled)', $source);
        $this->assertStringContainsString('exit(2)', $source);
        $this->assertStringContainsString('native_worker', $source);
        $this->assertStringContainsString('replacement_ready', $source);
        $this->assertStringContainsString('replacement_readiness', $source);
        $this->assertStringContainsString('blockers', $source);
        $this->assertStringContainsString('dry_run', $source);
        $this->assertStringContainsString('writes', $source);
    }

    public function test_replacement_readiness_audit_checks_lane_specific_blockers(): void
    {
        $source = $this->scriptSource('native/scripts/audit-native-replacement-readiness.sh');

        foreach ([
            'production backfill acquisition, full header persistence, and cursor ownership remain PHP-owned',
            'production binary header acquisition, full header persistence, and cursor ownership remain PHP-owned',
            'remaining regular fix-name methods are deferred to PHP',
            'release rename, category, event, and search side effects remain PHP-owned',
            'native IRC replacement still requires live deployment verification',
            'metadata-refresh embedded hashed fix-name commands are deferred to PHP',
            'group update, backfill, release creation, and post-processing side effects remain PHP-owned',
            'additional/NFO provider processing, NNTP/NZB/NFO reads, release events, and deferred metadata-refresh/hashed-fixnames side effects remain PHP-owned',
            'metadata-provider lookups, NZB/NFO reads, release events, and full postprocess side effects remain PHP-owned',
            'full release creation, categorization, and release-processing side effects remain PHP-owned',
            'removecrap production commit requires live rollout proof',
        ] as $blocker) {
            $this->assertStringContainsString($blocker, $source);
        }

        $this->assertStringContainsString('expected_blocker()', $source);
        $this->assertStringContainsString('missing expected replacement readiness blocker', $source);
        $this->assertStringContainsString('has_forbidden_detail', $source);
    }

    public function test_native_worker_image_smoke_checks_catalog_readiness_metadata(): void
    {
        $source = $this->scriptSource('native/scripts/verify-native-worker-image.sh');

        $this->assertStringContainsString('tests/Fixtures/native-worker/catalog/*.json', $source);
        $this->assertStringContainsString('native_worker.writes 0', $source);
        $this->assertStringContainsString('replacement_ready', $source);
        $this->assertStringContainsString('replacement_readiness', $source);
        $this->assertStringContainsString('blockers', $source);
        $this->assertStringContainsString('image report is missing replacement_ready=false', $source);
        $this->assertStringContainsString('image report is missing replacement readiness blockers', $source);
        $this->assertStringContainsString('--require-replacement-ready', $source);
        $this->assertStringContainsString('accepted replacement readiness', $source);
        $this->assertStringContainsString('catalog is not replacement-ready', $source);
    }

    public function test_native_eval_deploy_helper_uses_shared_env_overrides(): void
    {
        $source = $this->scriptSource('native/scripts/deploy-native-eval-compose.sh');

        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_ENV_FILE', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_COMPOSE_FILE', $source);
        $this->assertStringContainsString('source native/scripts/native-eval-common.sh', $source);
        $this->assertStringContainsString('docker compose --env-file "${env_file}" -f "${compose_file}"', $source);
        $this->assertStringContainsString('require_native_eval_database "native eval compose deploy"', $source);
        $this->assertStringContainsString('seed_eval_worker_data', $source);
        $this->assertStringContainsString('configure_eval_lane metadata-refresh', $source);
        $this->assertStringContainsString('metadata-refresh deploy smoke resolved disabled/no-op plan', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES', $source);
        $this->assertStringContainsString('native/scripts/run-native-eval-first-lanes.sh', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_RUN_FIRST_LANES must be 0 or 1', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS', $source);
        $this->assertStringContainsString('native/scripts/run-native-eval-all-workers.sh', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_RUN_ALL_WORKERS must be 0 or 1', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS', $source);
        $this->assertStringContainsString('native/scripts/run-native-eval-compose-workers.sh', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_EVAL_RUN_COMPOSE_WORKERS must be 0 or 1', $source);
        $this->assertStringContainsString('selected_runners', $source);
        $this->assertStringContainsString('mutually exclusive', $source);
        $this->assertStringNotContainsString('env_value() {', $source);
        $this->assertStringNotContainsString('--env-file .env.native-eval -f docker-compose.native-eval.yml', $source);
    }

    public function test_first_lane_runner_reuses_shared_eval_seed_helpers(): void
    {
        $source = (string) file_get_contents(base_path('native/scripts/run-native-eval-first-lanes.sh'));

        $this->assertStringContainsString('source native/scripts/native-eval-common.sh', $source);
        $this->assertStringContainsString('seed_eval_worker_data', $source);
        $this->assertStringContainsString('require_native_eval_database "first-lane eval run"', $source);
        $this->assertStringNotContainsString('env_value() {', $source);
        $this->assertStringNotContainsString('INSERT INTO settings', $source);
    }

    public function test_first_lane_commit_compose_runner_uses_native_commit_guards_and_fake_nntp(): void
    {
        $source = $this->scriptSource('native/scripts/run-native-eval-first-lane-commit-workers.sh');

        $this->assertStringContainsString('source native/scripts/native-eval-common.sh', $source);
        $this->assertStringContainsString('binaries backfill releases', $source);
        $this->assertStringContainsString('First-lane native commit eval supports only binaries, backfill, and releases', $source);
        $this->assertStringContainsString('config --services', $source);
        $this->assertStringContainsString('nntmux-fake-nntp', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_WORKER_FIRST_LANE_COMMIT_ENABLED=true', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=true', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1', $source);
        $this->assertStringContainsString('NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE=2', $source);
        $this->assertStringContainsString('NNTP_SERVER="${fake_nntp_container}"', $source);
        $this->assertStringContainsString('native lane commit completed ${lane}', $source);
        $this->assertStringContainsString("redis-cli --scan --pattern '*nntmux:distributed-worker*'", $source);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $constants
     * @return array<string, mixed>
     */
    private function runVar(array $settings = [], array $counts = [], array $constants = []): array
    {
        return [
            'constants' => array_merge([
                'run_ircscraper' => 0,
                'sequential' => 0,
            ], $constants),
            'settings' => array_merge([
                'bins_timer' => 60,
                'back_timer' => 600,
                'rel_timer' => 60,
                'fix_timer' => 300,
                'crap_timer' => 300,
                'post_timer' => 300,
                'post_timer_non' => 300,
                'post_timer_amazon' => 300,
            ], $settings),
            'counts' => [
                'now' => array_merge([
                    'collections_table' => 0,
                    'other_hashed' => 0,
                    'processrenames' => 0,
                    'work' => 0,
                    'processnfo' => 0,
                    'processtv' => 0,
                    'processanime' => 0,
                    'processmovies' => 0,
                    'processmusic' => 0,
                    'processbooks' => 0,
                    'processconsole' => 0,
                    'processgames' => 0,
                ], $counts),
            ],
            'killswitch' => [
                'pp' => false,
                'coll' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function argumentValues(array $plan, string $key): array
    {
        return array_map(
            static fn (array $command): string => (string) $command['arguments'][$key],
            $plan['commands'],
        );
    }

    /**
     * @return list<string>
     */
    private function goSupportsNativeLaneJobs(): array
    {
        $source = (string) file_get_contents(base_path('native/cmd/nntmux-worker/main.go'));
        $this->assertMatchesRegularExpression('/func supportsNativeLaneExecution\(job string\) bool \{(?P<body>.*?)\n\}/s', $source);
        preg_match('/func supportsNativeLaneExecution\(job string\) bool \{(?P<body>.*?)\n\}/s', $source, $matches);
        preg_match_all('/"([^"]+)"/', $matches['body'], $quoted);

        return array_values(array_unique($quoted[1]));
    }

    /**
     * @return list<string>
     */
    private function evalAuditDefaultJobs(): array
    {
        $source = (string) file_get_contents(base_path('native/scripts/audit-native-eval-all-workers.sh'));

        return $this->defaultNativeEvalJobs($source);
    }

    /**
     * @return list<string>
     */
    private function evalRunDefaultJobs(): array
    {
        $source = (string) file_get_contents(base_path('native/scripts/run-native-eval-all-workers.sh'));

        return $this->defaultNativeEvalJobs($source);
    }

    /**
     * @return list<string>
     */
    private function evalFixtureRunDefaultJobs(): array
    {
        $source = (string) file_get_contents(base_path('native/scripts/run-native-eval-fixture-workers.sh'));

        return $this->defaultNativeEvalJobs($source);
    }

    /**
     * @return list<string>
     */
    private function evalComposeServiceRunDefaultJobs(): array
    {
        return $this->defaultNativeEvalJobs($this->scriptSource('native/scripts/run-native-eval-compose-workers.sh'));
    }

    /**
     * @return list<string>
     */
    private function defaultNativeEvalJobs(string $source): array
    {
        $this->assertMatchesRegularExpression('/lanes="\$\{NNTMUX_NATIVE_EVAL_LANES:-(?P<jobs>[^}]*)\}"/', $source);
        preg_match('/lanes="\$\{NNTMUX_NATIVE_EVAL_LANES:-(?P<jobs>[^}]*)\}"/', $source, $matches);

        return preg_split('/\s+/', trim($matches['jobs'])) ?: [];
    }

    /**
     * @return list<string>
     */
    private function phpSmokeJobs(): array
    {
        $source = (string) file_get_contents(base_path('native/scripts/verify-php-native-lanes-smoke.sh'));
        preg_match_all('/^run_lane_smoke\s+([a-z-]+)/m', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    private function nativeEvalComposeWorkerJobs(): array
    {
        $source = (string) file_get_contents(base_path('docker-compose.native-eval.yml'));
        preg_match_all('/^\s{4}native-([a-z-]+)-worker:\s*$/m', $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function scriptSource(string $path): string
    {
        $fullPath = base_path($path);
        $this->assertFileExists($fullPath);

        return (string) file_get_contents($fullPath);
    }
}
