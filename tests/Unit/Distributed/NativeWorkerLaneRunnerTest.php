<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\NativeWorkerLaneRunner;
use Tests\TestCase;

class NativeWorkerLaneRunnerTest extends TestCase
{
    public function test_it_rejects_relative_binary_paths_without_searching_path(): void
    {
        config([
            'nntmux.native_worker_binary' => 'nntmux-worker',
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
            'nntmux.native_worker_lane_timeout_seconds' => 1,
        ]);

        $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), 'laravel-owner');

        $this->assertFalse($result->successful);
        $this->assertNull($result->exitCode);
        $this->assertStringContainsString('native worker binary must be an absolute executable path', $result->errorOutput);
    }

    public function test_it_rejects_blank_lock_owner_before_starting_native_binary(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, "#!/bin/sh\nexit 99\n");
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_lane_timeout_seconds' => 1,
            ]);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), '  ');

            $this->assertFalse($result->successful);
            $this->assertNull($result->exitCode);
            $this->assertStringContainsString('native worker lock owner is not configured', $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_runs_native_lane_with_plan_json_and_artisan_runtime_environment(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-worker-lane-args-');
        $stdinPath = tempnam(sys_get_temp_dir(), 'native-worker-lane-stdin-');
        $envPath = tempnam(sys_get_temp_dir(), 'native-worker-lane-env-');
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\ncat > %s\n( env | sort ) > %s\necho %s\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg((string) $stdinPath),
                escapeshellarg((string) $envPath),
                escapeshellarg($this->nativeLaneReport('binaries')),
            ));
            chmod($binary, 0700);

            putenv('APP_KEY=base64:secret');
            putenv('DB_PASSWORD=secret');
            putenv('REDIS_PASSWORD=secret');
            putenv('NNTP_PASSWORD=secret');
            putenv('SCRAPE_IRC_USERNAME=nntmuxbot');
            putenv('SCRAPE_IRC_PASSWORD=irc-secret');
            putenv('NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES=2');
            putenv('AWS_SECRET_ACCESS_KEY=secret');
            putenv('FILESYSTEM_DISK=local');
            putenv('PATH_TO_NZBS=/mnt/nzbs');
            putenv('MANTICORESEARCH_HOST=manticore');
            putenv('NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_GROUPS=alt.binaries.movies');
            putenv('NNTMUX_METADATA_REFRESH_TIMEOUT=7');
            putenv('NNTMUX_SRRDB_BASE_URL=http://srrdb.example.test/v1');
            putenv('NNTMUX_PREDB_NET_BASE_URL=http://predb-net.example.test');
            putenv('NNTMUX_PREDB_OVH_BASE_URL=http://predb-ovh.example.test');
            putenv('NNTMUX_XREL_BASE_URL=http://xrel.example.test/v2');
            putenv('NNTMUX_NZBINDEX_API_KEY=nzbindex-secret');
            putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1');
            putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG=/tmp/native-leaf-startup.log');
            foreach ([
                'APP_KEY' => 'base64:secret',
                'DB_PASSWORD' => 'secret',
                'REDIS_PASSWORD' => 'secret',
                'NNTP_PASSWORD' => 'secret',
                'SCRAPE_IRC_USERNAME' => 'nntmuxbot',
                'SCRAPE_IRC_PASSWORD' => 'irc-secret',
                'NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES' => '2',
                'AWS_SECRET_ACCESS_KEY' => 'secret',
                'FILESYSTEM_DISK' => 'local',
                'PATH_TO_NZBS' => '/mnt/nzbs',
                'MANTICORESEARCH_HOST' => 'manticore',
                'NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_GROUPS' => 'alt.binaries.movies',
                'NNTMUX_METADATA_REFRESH_TIMEOUT' => '7',
                'NNTMUX_SRRDB_BASE_URL' => 'http://srrdb.example.test/v1',
                'NNTMUX_PREDB_NET_BASE_URL' => 'http://predb-net.example.test',
                'NNTMUX_PREDB_OVH_BASE_URL' => 'http://predb-ovh.example.test',
                'NNTMUX_XREL_BASE_URL' => 'http://xrel.example.test/v2',
                'NNTMUX_NZBINDEX_API_KEY' => 'nzbindex-secret',
                'NNTMUX_NATIVE_LEAF_STARTUP_SMOKE' => '1',
                'NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG' => '/tmp/native-leaf-startup.log',
            ] as $key => $value) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_lane_timeout_seconds' => 1,
                'nntmux.native_worker_artisan_binary' => '/usr/bin/php',
                'nntmux.native_worker_artisan_script' => '/var/www/html/artisan',
            ]);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('"native_lane"', $result->output);
            $this->assertSame(
                "--plan\n-\n--run-lane\n--mysql-dsn-env\n--redis-addr-env\n--lock-owner-env\n--lock-mode\nheld\n--output\njson\n--artisan-binary\n/usr/bin/php\n--artisan-script\n/var/www/html/artisan\n--artisan-env\nNNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1\n--artisan-env\nNNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG=/tmp/native-leaf-startup.log\n",
                file_get_contents((string) $argsPath),
            );

            $stdinPlan = json_decode((string) file_get_contents((string) $stdinPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('binaries', $stdinPlan['job']['name']);

            $env = (string) file_get_contents((string) $envPath);
            $this->assertStringContainsString('NNTMUX_NATIVE_MYSQL_DSN=nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_REDIS_ADDR=redis:6379', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_LOCK_OWNER=laravel-owner', $env);
            $this->assertStringContainsString('APP_KEY=base64:secret', $env);
            $this->assertStringContainsString('DB_PASSWORD=secret', $env);
            $this->assertStringContainsString('REDIS_PASSWORD=secret', $env);
            $this->assertStringContainsString('NNTP_PASSWORD=secret', $env);
            $this->assertStringContainsString('SCRAPE_IRC_USERNAME=nntmuxbot', $env);
            $this->assertStringContainsString('SCRAPE_IRC_PASSWORD=irc-secret', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES=2', $env);
            $this->assertStringContainsString('FILESYSTEM_DISK=local', $env);
            $this->assertStringContainsString('PATH_TO_NZBS=/mnt/nzbs', $env);
            $this->assertStringContainsString('MANTICORESEARCH_HOST=manticore', $env);
            $this->assertStringContainsString('NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_GROUPS=alt.binaries.movies', $env);
            $this->assertStringContainsString('NNTMUX_METADATA_REFRESH_TIMEOUT=7', $env);
            $this->assertStringContainsString('NNTMUX_SRRDB_BASE_URL=http://srrdb.example.test/v1', $env);
            $this->assertStringContainsString('NNTMUX_PREDB_NET_BASE_URL=http://predb-net.example.test', $env);
            $this->assertStringContainsString('NNTMUX_PREDB_OVH_BASE_URL=http://predb-ovh.example.test', $env);
            $this->assertStringContainsString('NNTMUX_XREL_BASE_URL=http://xrel.example.test/v2', $env);
            $this->assertStringContainsString('NNTMUX_NZBINDEX_API_KEY=nzbindex-secret', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1', $env);
            $this->assertStringContainsString('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG=/tmp/native-leaf-startup.log', $env);
            $this->assertStringNotContainsString('AWS_SECRET_ACCESS_KEY=', $env);
        } finally {
            putenv('APP_KEY');
            putenv('DB_PASSWORD');
            putenv('REDIS_PASSWORD');
            putenv('NNTP_PASSWORD');
            putenv('SCRAPE_IRC_USERNAME');
            putenv('SCRAPE_IRC_PASSWORD');
            putenv('NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES');
            putenv('AWS_SECRET_ACCESS_KEY');
            putenv('FILESYSTEM_DISK');
            putenv('PATH_TO_NZBS');
            putenv('MANTICORESEARCH_HOST');
            putenv('NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_GROUPS');
            putenv('NNTMUX_METADATA_REFRESH_TIMEOUT');
            putenv('NNTMUX_SRRDB_BASE_URL');
            putenv('NNTMUX_PREDB_NET_BASE_URL');
            putenv('NNTMUX_PREDB_OVH_BASE_URL');
            putenv('NNTMUX_XREL_BASE_URL');
            putenv('NNTMUX_NZBINDEX_API_KEY');
            putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE');
            putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG');
            unset(
                $_ENV['APP_KEY'],
                $_ENV['DB_PASSWORD'],
                $_ENV['REDIS_PASSWORD'],
                $_ENV['NNTP_PASSWORD'],
                $_ENV['SCRAPE_IRC_USERNAME'],
                $_ENV['SCRAPE_IRC_PASSWORD'],
                $_ENV['NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES'],
                $_ENV['AWS_SECRET_ACCESS_KEY'],
                $_ENV['FILESYSTEM_DISK'],
                $_ENV['PATH_TO_NZBS'],
                $_ENV['MANTICORESEARCH_HOST'],
                $_ENV['NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_GROUPS'],
                $_ENV['NNTMUX_METADATA_REFRESH_TIMEOUT'],
                $_ENV['NNTMUX_SRRDB_BASE_URL'],
                $_ENV['NNTMUX_PREDB_NET_BASE_URL'],
                $_ENV['NNTMUX_PREDB_OVH_BASE_URL'],
                $_ENV['NNTMUX_NZBINDEX_API_KEY'],
                $_ENV['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'],
                $_ENV['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'],
                $_SERVER['APP_KEY'],
                $_SERVER['DB_PASSWORD'],
                $_SERVER['REDIS_PASSWORD'],
                $_SERVER['NNTP_PASSWORD'],
                $_SERVER['SCRAPE_IRC_USERNAME'],
                $_SERVER['SCRAPE_IRC_PASSWORD'],
                $_SERVER['NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES'],
                $_SERVER['AWS_SECRET_ACCESS_KEY'],
                $_SERVER['FILESYSTEM_DISK'],
                $_SERVER['PATH_TO_NZBS'],
                $_SERVER['MANTICORESEARCH_HOST'],
                $_SERVER['NNTMUX_BODY_PREAMBLE_DEOBFUSCATE_GROUPS'],
                $_SERVER['NNTMUX_METADATA_REFRESH_TIMEOUT'],
                $_SERVER['NNTMUX_SRRDB_BASE_URL'],
                $_SERVER['NNTMUX_PREDB_NET_BASE_URL'],
                $_SERVER['NNTMUX_PREDB_OVH_BASE_URL'],
                $_SERVER['NNTMUX_NZBINDEX_API_KEY'],
                $_SERVER['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'],
                $_SERVER['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'],
            );
            @unlink((string) $argsPath);
            @unlink((string) $stdinPath);
            @unlink((string) $envPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_passes_configured_first_lane_tuning_flags(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-worker-lane-args-');
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\ncat >/dev/null\necho %s\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg($this->nativeLaneReport('backfill')),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_lane_timeout_seconds' => 1,
                'nntmux.native_worker_lane_max_processes' => 7,
                'nntmux.native_worker_binaries_max_messages' => 12345,
                'nntmux.native_worker_binaries_max_headers' => 23456,
                'nntmux.native_worker_backfill_qty' => 34567,
                'nntmux.native_worker_backfill_max_messages' => 45678,
                'nntmux.native_worker_backfill_threads' => 8,
                'nntmux.native_worker_backfill_groups' => 9,
                'nntmux.native_worker_backfill_days' => 2,
                'nntmux.native_worker_backfill_safe_date' => '2026-06-01',
                'nntmux.native_worker_backfill_min_articles' => 321,
            ]);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('backfill'), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame(
                "--plan\n-\n--run-lane\n--mysql-dsn-env\n--redis-addr-env\n--lock-owner-env\n--lock-mode\nheld\n--output\njson\n--artisan-binary\n".PHP_BINARY."\n--artisan-script\n".base_path('artisan')."\n--lane-max-processes\n7\n--binaries-max-messages\n12345\n--binaries-max-headers\n23456\n--backfill-qty\n34567\n--backfill-max-messages\n45678\n--backfill-threads\n8\n--backfill-groups\n9\n--backfill-days\n2\n--backfill-safe-date\n2026-06-01\n--backfill-min-articles\n321\n",
                file_get_contents((string) $argsPath),
            );
        } finally {
            @unlink((string) $argsPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_runs_command_only_fixnames_lane_without_mysql_dsn(): void
    {
        $this->assertCommandOnlyLaneRunsWithoutMySQLDSN('fixnames');
    }

    public function test_it_requires_mysql_dsn_for_native_irc_lane(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, "#!/bin/sh\nexit 99\n");
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => '',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_lane_timeout_seconds' => 1,
            ]);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('irc'), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertNull($result->exitCode);
            $this->assertStringContainsString('native worker mysql dsn is not configured', $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_runs_command_only_metadata_refresh_lane_without_mysql_dsn(): void
    {
        $this->assertCommandOnlyLaneRunsWithoutMySQLDSN('metadata-refresh');
    }

    public function test_it_runs_command_only_hashed_fixnames_lane_without_mysql_dsn(): void
    {
        $this->assertCommandOnlyLaneRunsWithoutMySQLDSN('hashed-fixnames');
    }

    public function test_it_passes_post_additional_deferred_guard_flag_when_configured(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-worker-lane-args-');
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\ncat >/dev/null\necho %s\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg($this->nativeLaneReport('post-additional')),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_lane_timeout_seconds' => 1,
                'nntmux.native_worker_post_additional_deferred_execution_enabled' => true,
            ]);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('post-additional'), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertStringContainsString("--allow-deferred-post-additional\n", (string) file_get_contents((string) $argsPath));
        } finally {
            @unlink((string) $argsPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_rejects_zero_exit_native_lane_without_valid_json_report(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, "#!/bin/sh\ncat >/dev/null\necho 'not-json'\n");
            chmod($binary, 0700);

            $this->configureRunnableBinary($binary);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('native worker lane returned invalid json', $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_rejects_zero_exit_native_lane_report_with_failed_commands(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\ncat >/dev/null\necho %s\n",
                escapeshellarg($this->nativeLaneReport('binaries', [
                    'native_lane' => [
                        'commands' => 3,
                        'succeeded' => 2,
                        'failed' => 1,
                        'exit_code' => 1,
                    ],
                ])),
            ));
            chmod($binary, 0700);

            $this->configureRunnableBinary($binary);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('native worker lane reported failed commands', $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_summarizes_nonzero_native_lane_json_report_failures(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\ncat >/dev/null\necho %s\necho 'redis client warning' >&2\nexit 1\n",
                escapeshellarg($this->nativeLaneReport('binaries', [
                    'native_lane' => [
                        'commands' => 3,
                        'succeeded' => 2,
                        'failed' => 1,
                        'exit_code' => 1,
                        'failures' => [
                            'php artisan articles:get-range binaries alt.binaries.native.eval 1 1000',
                        ],
                    ],
                ])),
            ));
            chmod($binary, 0700);

            $this->configureRunnableBinary($binary);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertSame(1, $result->exitCode);
            $this->assertStringContainsString('native worker lane reported failed commands: failed=1 exit_code=1', $result->errorOutput);
            $this->assertStringContainsString('php artisan articles:get-range binaries alt.binaries.native.eval 1 1000', $result->errorOutput);
            $this->assertStringContainsString('redis client warning', $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_rejects_zero_exit_native_lane_report_without_all_commands_succeeded(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\ncat >/dev/null\necho %s\n",
                escapeshellarg($this->nativeLaneReport('binaries', [
                    'native_lane' => [
                        'commands' => 3,
                        'succeeded' => 2,
                        'failed' => 0,
                        'exit_code' => 0,
                    ],
                ])),
            ));
            chmod($binary, 0700);

            $this->configureRunnableBinary($binary);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('native worker lane reported incomplete command success', $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_rejects_zero_exit_native_lane_report_without_dispatched_commands(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\ncat >/dev/null\necho %s\n",
                escapeshellarg($this->nativeLaneReport('metadata-refresh', [
                    'native_lane' => [
                        'commands' => 0,
                        'succeeded' => 0,
                        'failed' => 0,
                        'exit_code' => 0,
                    ],
                ])),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => '',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_lane_timeout_seconds' => 1,
            ]);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('metadata-refresh'), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('native worker lane reported no dispatched commands', $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_allows_zero_command_reports_for_empty_db_backed_lane_queues(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\ncat >/dev/null\necho %s\n",
                escapeshellarg($this->nativeLaneReport('binaries', [
                    'native_lane' => [
                        'commands' => 0,
                        'succeeded' => 0,
                        'failed' => 0,
                        'exit_code' => 0,
                    ],
                ])),
            ));
            chmod($binary, 0700);

            $this->configureRunnableBinary($binary);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame(0, $result->exitCode);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_rejects_zero_exit_native_lane_report_for_unexpected_job(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\ncat >/dev/null\necho %s\n",
                escapeshellarg($this->nativeLaneReport('backfill')),
            ));
            chmod($binary, 0700);

            $this->configureRunnableBinary($binary);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('native worker lane reported unexpected job: expected binaries got backfill', $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_rejects_zero_exit_native_lane_report_with_invalid_replacement_metadata(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\ncat >/dev/null\necho %s\n",
                escapeshellarg($this->nativeLaneReport('binaries', ['dry_run' => true])),
            ));
            chmod($binary, 0700);

            $this->configureRunnableBinary($binary);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan('binaries'), 'laravel-owner');

            $this->assertFalse($result->successful);
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('native worker lane returned invalid native report metadata', $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(string $job): array
    {
        return [
            'version' => 1,
            'generated_at' => '2026-06-16T12:00:00.000000Z',
            'mode' => 'shadow',
            'job' => [
                'name' => $job,
                'description' => 'Download new headers for active groups',
                'enabled' => true,
                'disabled_reason' => null,
                'sleep' => 60,
            ],
            'lock' => [
                'name' => 'nntmux:distributed-worker:'.$job,
                'redis_key' => 'laravel-cache-nntmux:distributed-worker:'.$job,
                'seconds' => 42,
            ],
            'commands' => [
                [
                    'command' => 'multiprocessing:safe',
                    'arguments' => [
                        'type' => $job,
                    ],
                ],
            ],
        ];
    }

    private function configureRunnableBinary(string $binary): void
    {
        config([
            'nntmux.native_worker_binary' => $binary,
            'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux?parseTime=true',
            'nntmux.native_worker_redis_addr' => 'redis:6379',
            'nntmux.native_worker_lane_timeout_seconds' => 1,
        ]);
    }

    private function assertCommandOnlyLaneRunsWithoutMySQLDSN(string $job): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-worker-lane-args-');
        $envPath = tempnam(sys_get_temp_dir(), 'native-worker-lane-env-');
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-lane-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\n( env | sort ) > %s\ncat >/dev/null\necho %s\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg((string) $envPath),
                escapeshellarg($this->nativeLaneReport($job)),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => '',
                'nntmux.native_worker_redis_addr' => 'redis:6379',
                'nntmux.native_worker_lane_timeout_seconds' => 1,
            ]);

            $result = (new NativeWorkerLaneRunner)->runLane($this->plan($job), 'laravel-owner');

            $this->assertTrue($result->successful, $result->message());
            $this->assertStringNotContainsString("--mysql-dsn-env\n", (string) file_get_contents((string) $argsPath));
            $this->assertStringContainsString("--redis-addr-env\n", (string) file_get_contents((string) $argsPath));
            $this->assertStringNotContainsString('NNTMUX_NATIVE_MYSQL_DSN=', (string) file_get_contents((string) $envPath));
        } finally {
            @unlink((string) $argsPath);
            @unlink((string) $envPath);
            @unlink((string) $binary);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function nativeLaneReport(string $job, array $overrides = []): string
    {
        return json_encode(array_replace_recursive([
            'schema_version' => 1,
            'mode' => 'shadow',
            'dry_run' => false,
            'native_worker' => [
                'job' => $job,
            ],
            'native_lane' => [
                'commands' => 1,
                'succeeded' => 1,
                'failed' => 0,
                'exit_code' => 0,
            ],
        ], $overrides), JSON_THROW_ON_ERROR);
    }
}
