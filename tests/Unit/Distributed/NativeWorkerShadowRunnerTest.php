<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\NativeWorkerShadowRunner;
use Tests\TestCase;

class NativeWorkerShadowRunnerTest extends TestCase
{
    public function test_it_rejects_relative_binary_paths_without_searching_path(): void
    {
        config([
            'nntmux.native_worker_binary' => 'nntmux-worker',
            'nntmux.native_worker_timeout_seconds' => 1,
        ]);

        $result = (new NativeWorkerShadowRunner)->dryRun($this->plan());

        $this->assertFalse($result->successful);
        $this->assertNull($result->exitCode);
        $this->assertStringContainsString('native worker binary must be an absolute executable path', $result->errorOutput);
    }

    public function test_it_runs_native_binary_with_argv_and_plan_json_on_stdin(): void
    {
        $argsPath = tempnam(sys_get_temp_dir(), 'native-worker-args-');
        $stdinPath = tempnam(sys_get_temp_dir(), 'native-worker-stdin-');
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\nprintf '%%s\\n' \"$@\" > %s\ncat > %s\necho 'native worker dry-run'\n",
                escapeshellarg((string) $argsPath),
                escapeshellarg((string) $stdinPath),
            ));
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_timeout_seconds' => 1,
            ]);

            $result = (new NativeWorkerShadowRunner)->dryRun($this->plan());

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame(0, $result->exitCode);
            $this->assertStringContainsString('native worker dry-run', $result->output);
            $this->assertSame("--plan\n-\n--dry-run\n", file_get_contents((string) $argsPath));

            $stdinPlan = json_decode((string) file_get_contents((string) $stdinPath), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('metadata-refresh', $stdinPlan['job']['name']);
            $this->assertSame('nntmux:distributed-worker:metadata-refresh', $stdinPlan['lock']['name']);
            $this->assertArrayHasKey('redis_key', $stdinPlan['lock']);
            $this->assertStringEndsWith($stdinPlan['lock']['name'], $stdinPlan['lock']['redis_key']);
        } finally {
            @unlink((string) $argsPath);
            @unlink((string) $stdinPath);
            @unlink((string) $binary);
        }
    }

    public function test_it_bounds_native_error_output(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-bin-');

        try {
            file_put_contents($binary, "#!/bin/sh\nprintf 'abcdefghijklmnopqrstuvwxyz' >&2\nexit 17\n");
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_timeout_seconds' => 1,
                'nntmux.native_worker_log_stderr_bytes' => 10,
            ]);

            $result = (new NativeWorkerShadowRunner)->dryRun($this->plan());

            $this->assertFalse($result->successful);
            $this->assertSame(17, $result->exitCode);
            $this->assertSame("abcdefghij\n[truncated to 10 bytes]", $result->errorOutput);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_bounds_native_output_while_streaming(): void
    {
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-bin-');

        try {
            file_put_contents($binary, "#!/bin/sh\nprintf 'abcdefghijklmnopqrstuvwxyz'\n");
            chmod($binary, 0700);

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_timeout_seconds' => 1,
                'nntmux.native_worker_log_stderr_bytes' => 10,
            ]);

            $result = (new NativeWorkerShadowRunner)->dryRun($this->plan());

            $this->assertTrue($result->successful, $result->message());
            $this->assertSame("abcdefghij\n[truncated to 10 bytes]", $result->output);
        } finally {
            @unlink((string) $binary);
        }
    }

    public function test_it_runs_native_binary_with_minimal_environment(): void
    {
        $envPath = tempnam(sys_get_temp_dir(), 'native-worker-env-');
        $binary = tempnam(sys_get_temp_dir(), 'native-worker-bin-');

        try {
            file_put_contents($binary, sprintf(
                "#!/bin/sh\n( env | sort ) > %s\n",
                escapeshellarg((string) $envPath),
            ));
            chmod($binary, 0700);

            putenv('APP_KEY=base64:secret');
            putenv('DB_PASSWORD=secret');
            putenv('REDIS_PASSWORD=secret');
            putenv('NNTP_PASSWORD=secret');
            putenv('AWS_SECRET_ACCESS_KEY=secret');

            config([
                'nntmux.native_worker_binary' => $binary,
                'nntmux.native_worker_timeout_seconds' => 1,
            ]);

            $result = (new NativeWorkerShadowRunner)->dryRun($this->plan());

            $this->assertTrue($result->successful, $result->message());

            $env = (string) file_get_contents((string) $envPath);
            $this->assertStringNotContainsString('APP_KEY=', $env);
            $this->assertStringNotContainsString('DB_PASSWORD=', $env);
            $this->assertStringNotContainsString('REDIS_PASSWORD=', $env);
            $this->assertStringNotContainsString('NNTP_PASSWORD=', $env);
            $this->assertStringNotContainsString('AWS_SECRET_ACCESS_KEY=', $env);
        } finally {
            putenv('APP_KEY');
            putenv('DB_PASSWORD');
            putenv('REDIS_PASSWORD');
            putenv('NNTP_PASSWORD');
            putenv('AWS_SECRET_ACCESS_KEY');
            @unlink((string) $envPath);
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
                'name' => 'metadata-refresh',
                'description' => 'Refresh external release-name evidence and run strong fix-name passes',
                'enabled' => true,
                'disabled_reason' => null,
                'sleep' => 900,
            ],
            'lock' => [
                'name' => 'nntmux:distributed-worker:metadata-refresh',
                'redis_key' => 'laravel-cache-nntmux:distributed-worker:metadata-refresh',
                'seconds' => 42,
            ],
            'commands' => [
                [
                    'command' => 'predb:refresh-external-metadata',
                    'arguments' => [
                        '--source' => ['all'],
                        '--limit' => 25,
                    ],
                ],
            ],
        ];
    }
}
