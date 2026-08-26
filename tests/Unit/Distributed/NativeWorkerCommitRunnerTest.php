<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\NativeWorkerCommitRunner;
use Tests\TestCase;

class NativeWorkerCommitRunnerTest extends TestCase
{
    public function test_it_runs_native_commit_with_held_lock_and_connection_settings_in_environment(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-commit-args-');
        $stdinPath = tempnam(sys_get_temp_dir(), 'native-commit-stdin-');
        $envPath = tempnam(sys_get_temp_dir(), 'native-commit-env-');
        $binary = tempnam(sys_get_temp_dir(), 'native-commit-bin-');

        try {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1');

            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\ncat > %s\n( env | sort ) > %s\necho '{\"schema_version\":1,\"mode\":\"shadow\",\"dry_run\":false}'\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg((string) $stdinPath),
                escapeshellarg((string) $envPath),
            ));
            chmod($binary, 0700);

            putenv('APP_KEY=base64:secret');
            putenv('DB_PASSWORD=secret');
            putenv('REDIS_PASSWORD=secret');
            putenv('NNTMUX_METADATA_REFRESH_TIMEOUT=7');
            putenv('NNTMUX_SRRDB_BASE_URL=http://srrdb.example.test/v1');
            putenv('NNTMUX_PREDB_NET_BASE_URL=http://predb-net.example.test');
            putenv('NNTMUX_PREDB_OVH_BASE_URL=http://predb-ovh.example.test');
            putenv('NNTMUX_XREL_BASE_URL=http://xrel.example.test/v2');

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_commit_test_enabled' => true,
                'nntmux.native_worker_commit_timeout_seconds' => 1,
            ]);

            $result = (new NativeWorkerCommitRunner)->commitMissStatus($this->plan(), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('"dry_run":false', $result->output);

            $args = (string) file_get_contents((string) $argsPath);
            $this->assertSame("--plan\n-\n--commit-miss-status\n--lock-mode\nheld\n--output\njson\n", $args);
            $this->assertStringNotContainsString('nntmux:nntmux', $args);
            $this->assertStringNotContainsString('redis:6379', $args);
            $this->assertStringNotContainsString('laravel-owner', $args);

            $stdinPlan = json_decode((string) file_get_contents((string) $stdinPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('hashed-fixnames', $stdinPlan['job']['name']);

            $env = (string) file_get_contents((string) $envPath);
            $this->assertStringContainsString('NNTMUX_NATIVE_MYSQL_DSN=nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_REDIS_ADDR=redis:6379', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_LOCK_OWNER=laravel-owner', $env);
            $this->assertStringContainsString('NNTMUX_METADATA_REFRESH_TIMEOUT=7', $env);
            $this->assertStringContainsString('NNTMUX_SRRDB_BASE_URL=http://srrdb.example.test/v1', $env);
            $this->assertStringContainsString('NNTMUX_PREDB_NET_BASE_URL=http://predb-net.example.test', $env);
            $this->assertStringContainsString('NNTMUX_PREDB_OVH_BASE_URL=http://predb-ovh.example.test', $env);
            $this->assertStringContainsString('NNTMUX_XREL_BASE_URL=http://xrel.example.test/v2', $env);
            $this->assertStringNotContainsString('APP_KEY=', $env);
            $this->assertStringNotContainsString('DB_PASSWORD=', $env);
            $this->assertStringNotContainsString('REDIS_PASSWORD=', $env);
        } finally {
            putenv('APP_KEY');
            putenv('DB_PASSWORD');
            putenv('REDIS_PASSWORD');
            putenv('NNTMUX_METADATA_REFRESH_TIMEOUT');
            putenv('NNTMUX_SRRDB_BASE_URL');
            putenv('NNTMUX_PREDB_NET_BASE_URL');
            putenv('NNTMUX_PREDB_OVH_BASE_URL');
            putenv('NNTMUX_XREL_BASE_URL');
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB');
            @unlink((string) $argsPath);
            @unlink((string) $stdinPath);
            @unlink((string) $envPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_runs_first_lane_native_commit_with_tuning_flags_and_held_lock(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-lane-commit-args-');
        $stdinPath = tempnam(sys_get_temp_dir(), 'native-lane-commit-stdin-');
        $envPath = tempnam(sys_get_temp_dir(), 'native-lane-commit-env-');
        $binary = tempnam(sys_get_temp_dir(), 'native-lane-commit-bin-');

        try {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1');
            putenv('NNTP_SERVER=news.example.test');
            putenv('NNTP_PORT=563');
            putenv('NNTP_USERNAME=nntp-user');
            putenv('NNTP_PASSWORD=nntp-secret');
            putenv('NNTP_SSLENABLED=true');
            putenv('NNTP_CONNECT_TIMEOUT=20');
            putenv('USE_ALTERNATE_NNTP_SERVER=false');
            foreach ([
                'NNTP_SERVER' => 'news.example.test',
                'NNTP_PORT' => '563',
                'NNTP_USERNAME' => 'nntp-user',
                'NNTP_PASSWORD' => 'nntp-secret',
                'NNTP_SSLENABLED' => 'true',
                'NNTP_CONNECT_TIMEOUT' => '20',
                'USE_ALTERNATE_NNTP_SERVER' => 'false',
            ] as $key => $value) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }

            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\ncat > %s\n( env | sort ) > %s\necho '{\"schema_version\":1,\"mode\":\"shadow\",\"dry_run\":false,\"native_worker\":{\"job\":\"binaries\",\"writes\":3},\"binaries_write_commit\":{\"rolled_back\":false,\"writes_committed\":3}}'\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg((string) $stdinPath),
                escapeshellarg((string) $envPath),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_eval?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_commit_test_enabled' => true,
                'nntmux.native_worker_commit_timeout_seconds' => 1,
                'nntmux.native_worker_lane_max_processes' => 2,
                'nntmux.native_worker_binaries_max_messages' => 10000,
                'nntmux.native_worker_binaries_max_headers' => 25000,
                'nntmux.native_worker_backfill_qty' => 75000,
                'nntmux.native_worker_backfill_max_messages' => 20000,
                'nntmux.native_worker_backfill_threads' => 4,
                'nntmux.native_worker_backfill_groups' => 10,
                'nntmux.native_worker_backfill_days' => 2,
                'nntmux.native_worker_backfill_safe_date' => '1999-01-01',
                'nntmux.native_worker_backfill_min_articles' => 100,
                'nntmux.native_worker_nntp_overview_sample' => 2,
            ]);

            $result = (new NativeWorkerCommitRunner)->commitLaneWrites($this->firstLanePlan(), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('binaries_write_commit', $result->output);

            $args = (string) file_get_contents((string) $argsPath);
            $this->assertSame(
                "--plan\n-\n--commit-lane-writes\n--lock-mode\nheld\n--output\njson\n--lane-max-processes\n2\n--binaries-max-messages\n10000\n--binaries-max-headers\n25000\n--backfill-qty\n75000\n--backfill-max-messages\n20000\n--backfill-threads\n4\n--backfill-groups\n10\n--backfill-days\n2\n--nntp-overview-sample\n2\n--backfill-safe-date\n1999-01-01\n--backfill-min-articles\n100\n",
                $args,
            );

            $stdinPlan = json_decode((string) file_get_contents((string) $stdinPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('binaries', $stdinPlan['job']['name']);

            $env = (string) file_get_contents((string) $envPath);
            $this->assertStringContainsString('NNTMUX_NATIVE_MYSQL_DSN=nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_eval?parseTime=true', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_REDIS_ADDR=redis:6379', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_LOCK_OWNER=laravel-owner', $env);
            $this->assertStringContainsString('NNTP_SERVER=news.example.test', $env);
            $this->assertStringContainsString('NNTP_PORT=563', $env);
            $this->assertStringContainsString('NNTP_USERNAME=nntp-user', $env);
            $this->assertStringContainsString('NNTP_PASSWORD=nntp-secret', $env);
            $this->assertStringContainsString('NNTP_SSLENABLED=true', $env);
            $this->assertStringContainsString('NNTP_CONNECT_TIMEOUT=20', $env);
            $this->assertStringContainsString('USE_ALTERNATE_NNTP_SERVER=false', $env);
        } finally {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB');
            putenv('NNTP_SERVER');
            putenv('NNTP_PORT');
            putenv('NNTP_USERNAME');
            putenv('NNTP_PASSWORD');
            putenv('NNTP_SSLENABLED');
            putenv('NNTP_CONNECT_TIMEOUT');
            putenv('USE_ALTERNATE_NNTP_SERVER');
            foreach ([
                'NNTP_SERVER',
                'NNTP_PORT',
                'NNTP_USERNAME',
                'NNTP_PASSWORD',
                'NNTP_SSLENABLED',
                'NNTP_CONNECT_TIMEOUT',
                'USE_ALTERNATE_NNTP_SERVER',
            ] as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
            @unlink((string) $argsPath);
            @unlink((string) $stdinPath);
            @unlink((string) $envPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_refuses_acquisition_lane_commit_without_overview_sample_before_spawning_binary(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-lane-commit-args-');
        $binary = tempnam(sys_get_temp_dir(), 'native-lane-commit-bin-');

        try {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1');

            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\necho '{\"schema_version\":1,\"mode\":\"shadow\",\"dry_run\":false}'\n",
                escapeshellarg((string) $argsPath),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_commit_test_enabled' => true,
                'nntmux.native_worker_nntp_overview_sample' => 0,
            ]);

            $result = (new NativeWorkerCommitRunner)->commitLaneWrites($this->firstLanePlan(), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertNull($result->exitCode);
            $this->assertStringContainsString('native worker acquisition commits require NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE > 0', $result->errorOutput);
            $this->assertSame('', (string) file_get_contents((string) $argsPath));
        } finally {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB');
            @unlink((string) $argsPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_runs_removecrap_production_lane_commit_with_lane_scoped_opt_in(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-removecrap-prod-args-');
        $stdinPath = tempnam(sys_get_temp_dir(), 'native-removecrap-prod-stdin-');
        $envPath = tempnam(sys_get_temp_dir(), 'native-removecrap-prod-env-');
        $binary = tempnam(sys_get_temp_dir(), 'native-removecrap-prod-bin-');

        try {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB');

            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\ncat > %s\n( env | sort ) > %s\necho '{\"schema_version\":1,\"mode\":\"shadow\",\"dry_run\":false,\"native_worker\":{\"job\":\"removecrap\",\"writes\":3},\"removecrap_write_commit\":{\"rolled_back\":false,\"writes_committed\":3,\"release_rows_affected\":1,\"collection_rows_affected\":1,\"deleted_release_ids\":[10],\"deleted_collection_ids\":[20],\"release_file_cleanup_rows_enqueued\":1}}'\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg((string) $stdinPath),
                escapeshellarg((string) $envPath),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_commit_test_enabled' => false,
                'nntmux.native_worker_removecrap_production_commit_enabled' => true,
                'nntmux.native_worker_commit_timeout_seconds' => 1,
                'nntmux.native_worker_nntp_overview_sample' => 2,
            ]);

            $result = (new NativeWorkerCommitRunner)->commitLaneWrites($this->removeCrapPlan(), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('removecrap_write_commit', $result->output);

            $this->assertSame("--plan\n-\n--commit-lane-writes\n--lock-mode\nheld\n--output\njson\n", (string) file_get_contents((string) $argsPath));

            $stdinPlan = json_decode((string) file_get_contents((string) $stdinPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('removecrap', $stdinPlan['job']['name']);

            $env = (string) file_get_contents((string) $envPath);
            $this->assertStringContainsString('NNTMUX_NATIVE_MYSQL_DSN=nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_REDIS_ADDR=redis:6379', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_LOCK_OWNER=laravel-owner', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_ALLOW_PRODUCTION_COMMIT=removecrap', $env);
            $this->assertStringNotContainsString('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1', $env);
            $this->assertStringNotContainsString('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1', $env);
        } finally {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB');
            @unlink((string) $argsPath);
            @unlink((string) $stdinPath);
            @unlink((string) $envPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_keeps_large_commit_json_reports_intact(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-large-commit-bin-');
        $report = json_encode([
            'schema_version' => 1,
            'mode' => 'shadow',
            'dry_run' => false,
            'native_worker' => [
                'job' => 'removecrap',
                'writes' => 3,
            ],
            'removecrap' => [
                'results' => array_fill(0, 100, [
                    'type' => 'gibberish',
                    'candidate_releases' => 1,
                    'candidate_rows' => 1,
                ]),
            ],
            'removecrap_write_commit' => [
                'rolled_back' => false,
                'writes_committed' => 3,
                'release_rows_affected' => 1,
                'collection_rows_affected' => 1,
                'deleted_release_ids' => [10],
                'deleted_collection_ids' => [20],
                'release_file_cleanup_rows_enqueued' => 1,
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1');

            file_put_contents($binary, "#!/bin/sh\ncat >/dev/null\nprintf '%s\n' ".escapeshellarg($report)."\n");
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_commit_test_enabled' => true,
                'nntmux.native_worker_commit_timeout_seconds' => 1,
                'nntmux.native_worker_log_stderr_bytes' => 64,
            ]);

            $result = (new NativeWorkerCommitRunner)->commitLaneWrites($this->removeCrapPlan(), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertGreaterThan(2048, strlen($result->output));
            $this->assertStringContainsString('removecrap_write_commit', $result->output);
            $this->assertStringNotContainsString('[truncated to ', $result->output);
        } finally {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB');
            @unlink((string) $binary);
        }
    }

    public function test_it_passes_post_additional_deferred_guard_flag_when_configured(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-lane-commit-args-');
        $stdinPath = tempnam(sys_get_temp_dir(), 'native-lane-commit-stdin-');
        $envPath = tempnam(sys_get_temp_dir(), 'native-lane-commit-env-');
        $binary = tempnam(sys_get_temp_dir(), 'native-lane-commit-bin-');

        try {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1');

            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\ncat > %s\n( env | sort ) > %s\necho '{\"schema_version\":1,\"mode\":\"shadow\",\"dry_run\":false,\"native_worker\":{\"job\":\"post-additional\",\"writes\":2},\"postprocess_write_commit\":{\"rolled_back\":false,\"writes_committed\":2}}'\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg((string) $stdinPath),
                escapeshellarg((string) $envPath),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_commit_test_enabled' => true,
                'nntmux.native_worker_commit_timeout_seconds' => 1,
                'nntmux.native_worker_post_additional_deferred_execution_enabled' => true,
            ]);

            $result = (new NativeWorkerCommitRunner)->commitLaneWrites($this->postAdditionalPlan(), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertStringContainsString('postprocess_write_commit', $result->output);
            $this->assertStringContainsString("--allow-deferred-post-additional\n", (string) file_get_contents((string) $argsPath));

            $stdinPlan = json_decode((string) file_get_contents((string) $stdinPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('post-additional', $stdinPlan['job']['name']);
        } finally {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB');
            @unlink((string) $argsPath);
            @unlink((string) $stdinPath);
            @unlink((string) $envPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_refuses_committed_test_path_without_php_smoke_guard_before_spawning_binary(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-commit-args-');
        $binary = tempnam(sys_get_temp_dir(), 'native-commit-bin-');

        try {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB=1');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB=1');

            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\necho '{\"schema_version\":1,\"mode\":\"shadow\",\"dry_run\":false}'\n",
                escapeshellarg((string) $argsPath),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_commit_test_enabled' => false,
            ]);

            $result = (new NativeWorkerCommitRunner)->commitMissStatus($this->plan(), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertNull($result->exitCode);
            $this->assertStringContainsString('native worker committed test writes require NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=1', $result->errorOutput);
            $this->assertSame('', (string) file_get_contents((string) $argsPath));
        } finally {
            putenv('NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB');
            putenv('NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB');
            @unlink((string) $argsPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_rejects_missing_native_commit_connection_settings(): void
    {
        config([
            'nntmux.native_worker_binary' => '/bin/true',
            'nntmux.native_worker_mysql_dsn' => '',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
        ]);

        $result = (new NativeWorkerCommitRunner)->commitMissStatus($this->plan(), 'owner');

        $this->assertFalse($result->successful);
        $this->assertNull($result->exitCode);
        $this->assertStringContainsString('native worker mysql dsn is not configured', $result->errorOutput);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        return [
            'version' => 1,
            'generated_at' => '2026-06-15T12:00:00.000000Z',
            'mode' => 'shadow',
            'job' => [
                'name' => 'hashed-fixnames',
                'description' => 'Run full-history name fixing passes for Other > Hashed backlogs',
                'enabled' => true,
                'disabled_reason' => null,
                'sleep' => 300,
            ],
            'lock' => [
                'name' => 'nntmux:distributed-worker:hashed-fixnames',
                'redis_key' => 'nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames',
                'seconds' => 42,
            ],
            'commands' => [
                [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => '20',
                        '--update' => true,
                        '--category' => 'hashed',
                        '--set-status' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function firstLanePlan(): array
    {
        return [
            'version' => 1,
            'generated_at' => '2026-06-15T12:00:00.000000Z',
            'mode' => 'shadow',
            'job' => [
                'name' => 'binaries',
                'description' => 'Download new headers for active groups',
                'enabled' => true,
                'disabled_reason' => null,
                'sleep' => 60,
            ],
            'lock' => [
                'name' => 'nntmux:distributed-worker:binaries',
                'redis_key' => 'nntmux_database_nntmux-cache-nntmux:distributed-worker:binaries',
                'seconds' => 42,
            ],
            'commands' => [
                [
                    'command' => 'multiprocessing:safe',
                    'arguments' => ['type' => 'binaries'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postAdditionalPlan(): array
    {
        return [
            'version' => 1,
            'generated_at' => '2026-06-15T12:00:00.000000Z',
            'mode' => 'shadow',
            'job' => [
                'name' => 'post-additional',
                'description' => 'Run additional and/or NFO post-processing',
                'enabled' => true,
                'disabled_reason' => null,
                'sleep' => 300,
            ],
            'lock' => [
                'name' => 'nntmux:distributed-worker:post-additional',
                'redis_key' => 'nntmux_database_nntmux-cache-nntmux:distributed-worker:post-additional',
                'seconds' => 42,
            ],
            'commands' => [
                [
                    'command' => 'multiprocessing:postprocess',
                    'arguments' => ['type' => 'add'],
                ],
                [
                    'command' => 'multiprocessing:postprocess',
                    'arguments' => ['type' => 'nfo'],
                ],
                [
                    'command' => 'predb:refresh-external-metadata',
                    'arguments' => ['--source' => ['all']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function removeCrapPlan(): array
    {
        return [
            'version' => 1,
            'generated_at' => '2026-06-15T12:00:00.000000Z',
            'mode' => 'shadow',
            'job' => [
                'name' => 'removecrap',
                'description' => 'Remove configured unwanted releases',
                'enabled' => true,
                'disabled_reason' => null,
                'sleep' => 300,
            ],
            'lock' => [
                'name' => 'nntmux:distributed-worker:removecrap',
                'redis_key' => 'nntmux_database_nntmux-cache-nntmux:distributed-worker:removecrap',
                'seconds' => 42,
            ],
            'commands' => [
                [
                    'command' => 'releases:remove-crap',
                    'arguments' => [
                        '--type' => 'gibberish',
                        '--time' => '4',
                        '--delete' => true,
                    ],
                ],
            ],
        ];
    }
}
