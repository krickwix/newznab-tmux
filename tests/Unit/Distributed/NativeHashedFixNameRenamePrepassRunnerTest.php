<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\NativeHashedFixNameRenamePrepassRunner;
use App\Services\NameFixing\NativeHashedFixNameRenameApplier;
use App\Services\NameFixing\NativeHashedFixNameWriteContractResolver;
use Mockery;
use Tests\TestCase;

class NativeHashedFixNameRenamePrepassRunnerTest extends TestCase
{
    public function test_it_runs_native_dry_run_with_mysql_dsn_in_environment_then_resolves_and_applies(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-rename-prepass-args-');
        $stdinPath = tempnam(sys_get_temp_dir(), 'native-rename-prepass-stdin-');
        $envPath = tempnam(sys_get_temp_dir(), 'native-rename-prepass-env-');
        $binary = tempnam(sys_get_temp_dir(), 'native-rename-prepass-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\ncat > %s\n( env | sort ) > %s\necho '%s'\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg((string) $stdinPath),
                escapeshellarg((string) $envPath),
                str_replace("'", "'\\''", json_encode($this->nativeReport(), JSON_THROW_ON_ERROR)),
            ));
            chmod($binary, 0700);

            putenv('APP_KEY=base64:secret');
            putenv('DB_PASSWORD=secret');
            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                'nntmux.native_worker_timeout_seconds' => 1,
                'nntmux.native_worker_rename_apply_test_enabled' => true,
            ]);

            $resolver = Mockery::mock(NativeHashedFixNameWriteContractResolver::class);
            $resolver->shouldReceive('resolve')
                ->once()
                ->with(Mockery::on(fn (array $payload): bool => $payload === $this->nativeReport()))
                ->andReturn($this->resolvedReport());

            $applier = Mockery::mock(NativeHashedFixNameRenameApplier::class);
            $applier->shouldReceive('apply')
                ->once()
                ->with($this->resolvedReport())
                ->andReturn([
                    'release_updates_seen' => 2,
                    'release_updates_applied' => 2,
                    'release_ids' => [100, 300],
                ]);

            $result = (new NativeHashedFixNameRenamePrepassRunner($resolver, $applier))->apply($this->plan());

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame(2, $result->releaseUpdatesSeen);
            $this->assertSame(2, $result->releaseUpdatesApplied);
            $this->assertSame([100, 300], $result->releaseIds);

            $this->assertSame("--plan\n-\n--dry-run\n--output\njson\n--mysql-dsn-env\n", (string) file_get_contents((string) $argsPath));
            $this->assertStringContainsString('"name":"hashed-fixnames"', (string) file_get_contents((string) $stdinPath));

            $env = (string) file_get_contents((string) $envPath);
            $this->assertStringContainsString('NNTMUX_NATIVE_MYSQL_DSN=nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true', $env);
            $this->assertStringNotContainsString('APP_KEY=', $env);
            $this->assertStringNotContainsString('DB_PASSWORD=', $env);
        } finally {
            putenv('APP_KEY');
            putenv('DB_PASSWORD');
            @unlink((string) $argsPath);
            @unlink((string) $stdinPath);
            @unlink((string) $envPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_refuses_non_native_test_database_before_spawning_binary(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-rename-prepass-args-');
        $binary = tempnam(sys_get_temp_dir(), 'native-rename-prepass-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\necho '%s'\n",
                escapeshellarg((string) $argsPath),
                str_replace("'", "'\\''", json_encode($this->nativeReport(), JSON_THROW_ON_ERROR)),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/production?parseTime=true',
                'nntmux.native_worker_rename_apply_test_enabled' => true,
            ]);

            $resolver = Mockery::mock(NativeHashedFixNameWriteContractResolver::class);
            $resolver->shouldNotReceive('resolve');
            $applier = Mockery::mock(NativeHashedFixNameRenameApplier::class);
            $applier->shouldNotReceive('apply');

            $result = (new NativeHashedFixNameRenamePrepassRunner($resolver, $applier))->apply($this->plan());

            $this->assertFalse($result->successful);
            $this->assertStringContainsString('allowlisted native test database', $result->message());
            $this->assertSame('', (string) file_get_contents((string) $argsPath));
        } finally {
            @unlink((string) $argsPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_validates_native_report_before_resolving(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-rename-prepass-bin-');

        try {
            file_put_contents($binary, "#!/bin/sh\necho '{\"schema_version\":1,\"mode\":\"shadow\",\"dry_run\":false,\"native_worker\":{\"job\":\"hashed-fixnames\",\"writes\":0}}'\n");
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                'nntmux.native_worker_rename_apply_test_enabled' => true,
            ]);

            $resolver = Mockery::mock(NativeHashedFixNameWriteContractResolver::class);
            $resolver->shouldNotReceive('resolve');
            $applier = Mockery::mock(NativeHashedFixNameRenameApplier::class);
            $applier->shouldNotReceive('apply');

            $result = (new NativeHashedFixNameRenamePrepassRunner($resolver, $applier))->apply($this->plan());

            $this->assertFalse($result->successful);
            $this->assertStringContainsString('invalid native rename prepass report', $result->message());
            $this->assertStringContainsString('dry_run=true', $result->message());
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_does_not_truncate_json_stdout_at_the_stderr_log_limit(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-rename-prepass-bin-');
        $nativeReport = $this->nativeReport();
        $nativeReport['debug_padding'] = str_repeat('x', 4096);

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\necho '%s'\n",
                str_replace("'", "'\\''", json_encode($nativeReport, JSON_THROW_ON_ERROR)),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                'nntmux.native_worker_log_stderr_bytes' => 64,
                'nntmux.native_worker_rename_apply_test_enabled' => true,
            ]);

            $resolver = Mockery::mock(NativeHashedFixNameWriteContractResolver::class);
            $resolver->shouldReceive('resolve')
                ->once()
                ->with(Mockery::on(static fn (array $payload): bool => ($payload['debug_padding'] ?? '') === str_repeat('x', 4096)))
                ->andReturn($this->resolvedReport());

            $applier = Mockery::mock(NativeHashedFixNameRenameApplier::class);
            $applier->shouldReceive('apply')
                ->once()
                ->with($this->resolvedReport())
                ->andReturn([
                    'release_updates_seen' => 2,
                    'release_updates_applied' => 2,
                    'release_ids' => [100, 300],
                ]);

            $result = (new NativeHashedFixNameRenamePrepassRunner($resolver, $applier))->apply($this->plan());

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame([100, 300], $result->releaseIds);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_requires_the_rename_apply_guard_before_spawning_binary(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-rename-prepass-args-');
        $binary = tempnam(sys_get_temp_dir(), 'native-rename-prepass-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\necho '%s'\n",
                escapeshellarg((string) $argsPath),
                str_replace("'", "'\\''", json_encode($this->nativeReport(), JSON_THROW_ON_ERROR)),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_mysql_dsn' => 'nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true',
                'nntmux.native_worker_rename_apply_test_enabled' => false,
            ]);

            $resolver = Mockery::mock(NativeHashedFixNameWriteContractResolver::class);
            $resolver->shouldNotReceive('resolve');
            $applier = Mockery::mock(NativeHashedFixNameRenameApplier::class);
            $applier->shouldNotReceive('apply');

            $result = (new NativeHashedFixNameRenamePrepassRunner($resolver, $applier))->apply($this->plan());

            $this->assertFalse($result->successful);
            $this->assertStringContainsString('NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=1', $result->message());
            $this->assertSame('', (string) file_get_contents((string) $argsPath));
        } finally {
            @unlink((string) $argsPath);
            @unlink((string) $binary);
        }
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
    private function nativeReport(): array
    {
        return [
            'schema_version' => 1,
            'mode' => 'shadow',
            'dry_run' => true,
            'native_worker' => [
                'job' => 'hashed-fixnames',
                'writes' => 0,
            ],
            'hashed_fixnames' => [
                'replacement_readiness' => [
                    'supported_methods' => ['16', '20'],
                    'unsupported_methods' => ['4'],
                    'unsupported_commands' => 1,
                    'blockers' => ['release rename, category, event, and search side effects remain PHP-owned'],
                ],
                'write_contract' => [
                    'release_updates' => [
                        ['release_id' => 100, 'method' => 'crc-predb'],
                        ['release_id' => 300, 'method' => 'par-hash'],
                    ],
                    'single_column_updates' => [],
                    'required_events' => [],
                    'search_updates' => [],
                    'writes' => 0,
                ],
                'writes' => 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedReport(): array
    {
        return [
            'schema_version' => 1,
            'mode' => 'native-write-contract-resolve',
            'dry_run' => true,
            'writes' => 0,
            'write_contract' => [
                'release_updates_seen' => 2,
                'release_updates_resolved' => 2,
                'release_updates_blocked' => 0,
                'resolved_release_updates' => [],
                'blocked_release_updates' => [],
                'writes' => 0,
            ],
        ];
    }
}
