<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class NativeWorkerCommitRunner
{
    private const DEFAULT_TIMEOUT_SECONDS = 30.0;

    private const DEFAULT_LOG_BYTES = 2048;

    private const DEFAULT_REPORT_BYTES = 1048576;

    /**
     * @param  array<string, mixed>  $plan
     */
    public function commitMissStatus(array $plan, string $lockOwner): NativeWorkerCommitResult
    {
        return $this->runCommit($plan, $lockOwner, '--commit-miss-status');
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function commitLaneWrites(array $plan, string $lockOwner): NativeWorkerCommitResult
    {
        return $this->runCommit($plan, $lockOwner, '--commit-lane-writes', $this->laneTuningArguments($plan));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $extraArguments
     */
    private function runCommit(array $plan, string $lockOwner, string $commitMode, array $extraArguments = []): NativeWorkerCommitResult
    {
        $binary = $this->binaryPath();
        if ($binary === null) {
            return new NativeWorkerCommitResult(false, '', 'native worker binary is not configured', null);
        }

        if (! str_starts_with($binary, DIRECTORY_SEPARATOR) || ! is_file($binary) || ! is_executable($binary)) {
            return new NativeWorkerCommitResult(false, '', 'native worker binary must be an absolute executable path', null);
        }

        $mysqlDsn = $this->mysqlDsn();
        if ($mysqlDsn === null) {
            return new NativeWorkerCommitResult(false, '', 'native worker mysql dsn is not configured', null);
        }

        $redisAddr = $this->redisAddr();
        if ($redisAddr === null) {
            return new NativeWorkerCommitResult(false, '', 'native worker redis addr is not configured', null);
        }

        $productionCommitJob = $this->productionCommitJob($plan);
        if (! $this->commitTestEnabled() && $productionCommitJob === null) {
            return new NativeWorkerCommitResult(false, '', 'native worker committed test writes require NNTMUX_NATIVE_WORKER_COMMIT_TEST_ENABLED=1', null);
        }

        $database = $this->databaseNameFromMysqlDsn($mysqlDsn);
        if ($productionCommitJob === null && ($database === null || ! $this->isAllowedNativeTestDatabase($database))) {
            return new NativeWorkerCommitResult(false, '', 'native worker mysql dsn must target an allowlisted native test database', null);
        }

        if ($productionCommitJob === null) {
            foreach (['NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB', 'NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB'] as $key) {
                if ($this->environmentValue($key) !== '1') {
                    return new NativeWorkerCommitResult(false, '', "{$key}=1 is required for native worker committed test writes", null);
                }
            }
        }

        try {
            $input = json_encode($plan, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new NativeWorkerCommitResult(false, '', 'encode native worker plan: '.$e->getMessage(), null);
        }

        $arguments = [
            $binary,
            '--plan',
            '-',
            $commitMode,
            '--lock-mode',
            'held',
            '--output',
            'json',
        ];
        if ($commitMode === '--commit-lane-writes' && $this->shouldAllowDeferredPostAdditional($plan)) {
            $arguments[] = '--allow-deferred-post-additional';
        }
        $arguments = array_merge($arguments, $extraArguments);
        if ($commitMode === '--commit-lane-writes' && $this->requiresOverviewSample($plan) && ! $this->usesOverviewSample($plan)) {
            return new NativeWorkerCommitResult(false, '', 'native worker acquisition commits require NNTMUX_NATIVE_WORKER_NNTP_OVERVIEW_SAMPLE > 0', null);
        }

        $process = new Process(
            $arguments,
            base_path('native'),
            $this->environment($mysqlDsn, $redisAddr, $lockOwner, $productionCommitJob, $commitMode === '--commit-lane-writes' && $this->usesOverviewSample($plan)),
            $input,
            $this->timeoutSeconds(),
        );

        $output = '';
        $errorOutput = '';
        $outputTruncated = false;
        $errorOutputTruncated = false;
        $logBytes = $this->logBytes();
        $reportBytes = $this->reportBytes();

        try {
            $process->run(function (string $type, string $data) use (&$output, &$errorOutput, &$outputTruncated, &$errorOutputTruncated, $logBytes, $reportBytes): void {
                if ($type === Process::ERR) {
                    $this->appendLimitedOutput($errorOutput, $errorOutputTruncated, $data, $logBytes);

                    return;
                }

                $this->appendLimitedOutput($output, $outputTruncated, $data, $reportBytes);
            });
        } catch (ProcessTimedOutException) {
            return new NativeWorkerCommitResult(false, '', 'native worker commit timed out after '.$this->timeoutSeconds().' seconds', null);
        } catch (Throwable $e) {
            return new NativeWorkerCommitResult(false, '', 'run native worker commit: '.$e->getMessage(), null);
        }

        if ($outputTruncated) {
            return new NativeWorkerCommitResult(
                successful: false,
                output: '',
                errorOutput: sprintf('native worker commit report exceeded %d bytes', $reportBytes),
                exitCode: $process->getExitCode(),
            );
        }

        return new NativeWorkerCommitResult(
            successful: $process->isSuccessful(),
            output: $output,
            errorOutput: $this->finalizeLimitedOutput($errorOutput, $errorOutputTruncated, $logBytes),
            exitCode: $process->getExitCode(),
        );
    }

    private function binaryPath(): ?string
    {
        $binary = config('nntmux.native_worker_binary');
        if (! is_string($binary) || trim($binary) === '') {
            return null;
        }

        return trim($binary);
    }

    private function mysqlDsn(): ?string
    {
        $dsn = config('nntmux.native_worker_mysql_dsn');
        if (! is_string($dsn) || trim($dsn) === '') {
            return null;
        }

        return trim($dsn);
    }

    private function redisAddr(): ?string
    {
        $addr = config('nntmux.native_worker_redis_addr');
        if (! is_string($addr) || trim($addr) === '') {
            return null;
        }

        return trim($addr);
    }

    private function timeoutSeconds(): float
    {
        $timeout = (float) config('nntmux.native_worker_commit_timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS);

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT_SECONDS;
    }

    private function commitTestEnabled(): bool
    {
        return (bool) config('nntmux.native_worker_commit_test_enabled', false);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function productionCommitJob(array $plan): ?string
    {
        $job = $plan['job']['name'] ?? null;
        if ($job === 'removecrap' && (bool) config('nntmux.native_worker_removecrap_production_commit_enabled', false)) {
            return 'removecrap';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function shouldAllowDeferredPostAdditional(array $plan): bool
    {
        $job = $plan['job']['name'] ?? null;

        return $job === 'post-additional'
            && (bool) config('nntmux.native_worker_post_additional_deferred_execution_enabled', false);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function laneTuningArguments(array $plan): array
    {
        $arguments = [];

        foreach ($this->numericLaneTuningOptions() as $configKey => $flag) {
            $value = $this->positiveIntConfig($configKey);
            if ($value !== null) {
                $arguments[] = $flag;
                $arguments[] = (string) $value;
            }
        }

        $overviewSample = $this->overviewSampleLimit($plan);
        if ($overviewSample !== null) {
            $arguments[] = '--nntp-overview-sample';
            $arguments[] = (string) $overviewSample;
        }

        $safeDate = config('nntmux.native_worker_backfill_safe_date');
        if (is_string($safeDate) && trim($safeDate) !== '') {
            $arguments[] = '--backfill-safe-date';
            $arguments[] = trim($safeDate);
        }

        $backfillMinArticles = $this->positiveIntConfig('nntmux.native_worker_backfill_min_articles');
        if ($backfillMinArticles !== null) {
            $arguments[] = '--backfill-min-articles';
            $arguments[] = (string) $backfillMinArticles;
        }

        return $arguments;
    }

    /**
     * @return array<string, string>
     */
    private function numericLaneTuningOptions(): array
    {
        return [
            'nntmux.native_worker_lane_max_processes' => '--lane-max-processes',
            'nntmux.native_worker_binaries_max_messages' => '--binaries-max-messages',
            'nntmux.native_worker_binaries_max_headers' => '--binaries-max-headers',
            'nntmux.native_worker_backfill_qty' => '--backfill-qty',
            'nntmux.native_worker_backfill_max_messages' => '--backfill-max-messages',
            'nntmux.native_worker_backfill_threads' => '--backfill-threads',
            'nntmux.native_worker_backfill_groups' => '--backfill-groups',
            'nntmux.native_worker_backfill_days' => '--backfill-days',
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function usesOverviewSample(array $plan): bool
    {
        return $this->overviewSampleLimit($plan) !== null;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function requiresOverviewSample(array $plan): bool
    {
        $job = $plan['job']['name'] ?? null;

        return $job === 'binaries' || $job === 'backfill';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function overviewSampleLimit(array $plan): ?int
    {
        $job = $plan['job']['name'] ?? null;
        if ($job !== 'binaries' && $job !== 'backfill') {
            return null;
        }

        return $this->positiveIntConfig('nntmux.native_worker_nntp_overview_sample');
    }

    private function positiveIntConfig(string $key): ?int
    {
        $value = config($key);
        if (is_bool($value) || (! is_int($value) && ! is_float($value) && ! is_string($value))) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function databaseNameFromMysqlDsn(string $dsn): ?string
    {
        if (preg_match('/@tcp\([^)]+\)\/([^?]+)/', $dsn, $matches) !== 1
            && preg_match('/^[^\/]+\/([^?]+)/', $dsn, $matches) !== 1) {
            return null;
        }

        $database = rawurldecode(trim((string) $matches[1]));

        return $database === '' ? null : $database;
    }

    private function isAllowedNativeTestDatabase(string $database): bool
    {
        return $database === 'nntmux_native_test'
            || $database === 'nntmux_native_eval'
            || str_starts_with($database, 'nntmux_native_test_')
            || str_ends_with($database, '_native_test');
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(string $mysqlDsn, string $redisAddr, string $lockOwner, ?string $productionCommitJob, bool $forwardNNTP): array
    {
        $environment = [];
        foreach ($this->environmentKeys() as $key) {
            $environment[$key] = false;
        }

        foreach (['PATH', 'SYSTEMROOT', 'SystemRoot', 'COMSPEC', 'PATHEXT', 'TMPDIR', 'TMP', 'TEMP'] as $key) {
            $value = $this->environmentValue($key);
            if ($value !== null) {
                $environment[$key] = $value;
            }
        }

        $environment['NNTMUX_NATIVE_MYSQL_DSN'] = $mysqlDsn;
        $environment['NNTMUX_NATIVE_REDIS_ADDR'] = $redisAddr;
        $environment['NNTMUX_NATIVE_LOCK_OWNER'] = $lockOwner;
        foreach (['NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB', 'NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB'] as $key) {
            $value = $this->environmentValue($key);
            if ($value !== null) {
                $environment[$key] = $value;
            }
        }
        if ($forwardNNTP) {
            foreach ($this->environmentKeys() as $key) {
                if (! $this->shouldForwardNativeNNTPEnvironment($key)) {
                    continue;
                }

                $value = $this->environmentValue($key);
                if ($value !== null) {
                    $environment[$key] = $value;
                }
            }
        }
        foreach ($this->environmentKeys() as $key) {
            if (! $this->shouldForwardNativeMetadataEnvironment($key)) {
                continue;
            }

            $value = $this->environmentValue($key);
            if ($value !== null) {
                $environment[$key] = $value;
            }
        }
        if ($productionCommitJob !== null) {
            $environment['NNTMUX_NATIVE_ALLOW_PRODUCTION_COMMIT'] = $productionCommitJob;
        }

        return $environment;
    }

    private function shouldForwardNativeNNTPEnvironment(string $key): bool
    {
        return $key === 'USE_ALTERNATE_NNTP_SERVER' || str_starts_with($key, 'NNTP_');
    }

    private function shouldForwardNativeMetadataEnvironment(string $key): bool
    {
        return $key === 'NNTMUX_SRRDB_BASE_URL'
            || $key === 'NNTMUX_PREDB_NET_BASE_URL'
            || $key === 'NNTMUX_PREDB_OVH_BASE_URL'
            || $key === 'NNTMUX_XREL_BASE_URL'
            || str_starts_with($key, 'NNTMUX_METADATA_');
    }

    /**
     * @return list<string>
     */
    private function environmentKeys(): array
    {
        $keys = [];
        $environment = getenv();

        if (is_array($environment)) {
            $keys = array_merge($keys, array_keys($environment));
        }

        $keys = array_merge($keys, array_keys($_ENV), array_keys($_SERVER));

        return array_values(array_unique(array_filter($keys, 'is_string')));
    }

    private function environmentValue(string $key): ?string
    {
        if (array_key_exists($key, $_ENV) && is_scalar($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER) && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }

        $value = getenv($key);

        return $value === false ? null : $value;
    }

    private function appendLimitedOutput(string &$output, bool &$truncated, string $chunk, int $bytes): void
    {
        if ($truncated) {
            return;
        }

        $remaining = $bytes - strlen($output);
        if ($remaining <= 0) {
            $truncated = true;

            return;
        }

        if (strlen($chunk) > $remaining) {
            $output .= substr($chunk, 0, $remaining);
            $truncated = true;

            return;
        }

        $output .= $chunk;
    }

    private function finalizeLimitedOutput(string $output, bool $truncated, int $bytes): string
    {
        if (! $truncated) {
            return $output;
        }

        return $output.sprintf("\n[truncated to %d bytes]", $bytes);
    }

    private function logBytes(): int
    {
        $bytes = (int) config('nntmux.native_worker_log_stderr_bytes', self::DEFAULT_LOG_BYTES);

        return $bytes > 0 ? $bytes : self::DEFAULT_LOG_BYTES;
    }

    private function reportBytes(): int
    {
        $bytes = (int) config('nntmux.native_worker_commit_report_bytes', self::DEFAULT_REPORT_BYTES);

        return $bytes > 0 ? $bytes : self::DEFAULT_REPORT_BYTES;
    }
}
