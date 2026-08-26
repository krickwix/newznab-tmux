<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class NativeWorkerLaneRunner
{
    private const DEFAULT_TIMEOUT_SECONDS = 3600.0;

    private const DEFAULT_LOG_BYTES = 2048;

    /**
     * @param  array<string, mixed>  $plan
     */
    public function runLane(array $plan, string $lockOwner): NativeWorkerLaneResult
    {
        $binary = $this->binaryPath();
        if ($binary === null) {
            return new NativeWorkerLaneResult(false, '', 'native worker binary is not configured', null);
        }

        if (! str_starts_with($binary, DIRECTORY_SEPARATOR) || ! is_file($binary) || ! is_executable($binary)) {
            return new NativeWorkerLaneResult(false, '', 'native worker binary must be an absolute executable path', null);
        }

        $commandOnlyLane = $this->isCommandOnlyLane($plan);
        $mysqlDsn = $commandOnlyLane ? null : $this->mysqlDsn();
        if (! $commandOnlyLane && $mysqlDsn === null) {
            return new NativeWorkerLaneResult(false, '', 'native worker mysql dsn is not configured', null);
        }

        $redisAddr = $this->redisAddr();
        if ($redisAddr === null) {
            return new NativeWorkerLaneResult(false, '', 'native worker redis addr is not configured', null);
        }

        if (trim($lockOwner) === '') {
            return new NativeWorkerLaneResult(false, '', 'native worker lock owner is not configured', null);
        }

        try {
            $input = json_encode($plan, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new NativeWorkerLaneResult(false, '', 'encode native worker plan: '.$e->getMessage(), null);
        }

        $arguments = [
            $binary,
            '--plan',
            '-',
            '--run-lane',
        ];
        if (! $commandOnlyLane) {
            $arguments[] = '--mysql-dsn-env';
        }
        if ($this->shouldAllowDeferredPostAdditional($plan)) {
            $arguments[] = '--allow-deferred-post-additional';
        }

        $arguments = array_merge(
            $arguments,
            [
                '--redis-addr-env',
                '--lock-owner-env',
                '--lock-mode',
                'held',
                '--output',
                'json',
                '--artisan-binary',
                $this->artisanBinary(),
                '--artisan-script',
                $this->artisanScript(),
            ],
            $this->artisanEnvironmentArguments(),
            $this->laneTuningArguments(),
        );

        $process = new Process(
            $arguments,
            base_path('native'),
            $this->environment($mysqlDsn, $redisAddr, trim($lockOwner)),
            $input,
            $this->timeoutSeconds(),
        );

        $output = '';
        $errorOutput = '';
        $outputTruncated = false;
        $errorOutputTruncated = false;
        $logBytes = $this->logBytes();

        try {
            $process->run(function (string $type, string $data) use (&$output, &$errorOutput, &$outputTruncated, &$errorOutputTruncated, $logBytes): void {
                if ($type === Process::ERR) {
                    $this->appendLimitedOutput($errorOutput, $errorOutputTruncated, $data, $logBytes);

                    return;
                }

                $this->appendLimitedOutput($output, $outputTruncated, $data, $logBytes);
            });
        } catch (ProcessTimedOutException) {
            return new NativeWorkerLaneResult(false, '', 'native worker lane timed out after '.$this->timeoutSeconds().' seconds', null);
        } catch (Throwable $e) {
            return new NativeWorkerLaneResult(false, '', 'run native worker lane: '.$e->getMessage(), null);
        }

        $finalOutput = $this->finalizeLimitedOutput($output, $outputTruncated, $logBytes);
        $finalErrorOutput = $this->finalizeLimitedOutput($errorOutput, $errorOutputTruncated, $logBytes);

        if (! $process->isSuccessful()) {
            if ($nativeLaneError = $this->nativeLaneReportErrorForNonzeroExit($finalOutput, $plan)) {
                $finalErrorOutput = trim($finalErrorOutput) !== ''
                    ? trim($finalErrorOutput).PHP_EOL.$nativeLaneError
                    : $nativeLaneError;
            }

            return new NativeWorkerLaneResult(false, $finalOutput, $finalErrorOutput, $process->getExitCode());
        }

        $validationError = $this->validateSuccessfulLaneReport($finalOutput, $plan);
        if ($validationError !== null) {
            return new NativeWorkerLaneResult(false, $finalOutput, $validationError, $process->getExitCode());
        }

        return new NativeWorkerLaneResult(
            successful: true,
            output: $finalOutput,
            errorOutput: $finalErrorOutput,
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
        $timeout = (float) config('nntmux.native_worker_lane_timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS);

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT_SECONDS;
    }

    private function artisanBinary(): string
    {
        $binary = config('nntmux.native_worker_artisan_binary');
        if (is_string($binary) && trim($binary) !== '') {
            return trim($binary);
        }

        return PHP_BINARY;
    }

    private function artisanScript(): string
    {
        $script = config('nntmux.native_worker_artisan_script');
        if (is_string($script) && trim($script) !== '') {
            return trim($script);
        }

        return base_path('artisan');
    }

    /**
     * @return list<string>
     */
    private function artisanEnvironmentArguments(): array
    {
        $arguments = [];

        foreach ([
            'NNTMUX_NATIVE_LEAF_STARTUP_SMOKE',
            'NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG',
        ] as $key) {
            $value = $this->environmentValue($key);
            if ($value === null) {
                continue;
            }

            $arguments[] = '--artisan-env';
            $arguments[] = $key.'='.$value;
        }

        return $arguments;
    }

    /**
     * @return list<string>
     */
    private function laneTuningArguments(): array
    {
        $arguments = [];

        foreach ($this->numericLaneTuningOptions() as $configKey => $flag) {
            $value = $this->positiveIntConfig($configKey);
            if ($value !== null) {
                $arguments[] = $flag;
                $arguments[] = (string) $value;
            }
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

    private function positiveIntConfig(string $key): ?int
    {
        $value = config($key);
        if (is_bool($value) || (! is_int($value) && ! is_float($value) && ! is_string($value))) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function validateSuccessfulLaneReport(string $output, array $plan): ?string
    {
        try {
            $report = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return 'native worker lane returned invalid json: '.$e->getMessage();
        }

        if (! is_array($report) || ! isset($report['native_lane']) || ! is_array($report['native_lane'])) {
            return 'native worker lane returned json without native_lane report';
        }

        if (
            ($report['schema_version'] ?? null) !== 1
            || ($report['mode'] ?? null) !== 'shadow'
            || ($report['dry_run'] ?? null) !== false
            || ! isset($report['native_worker'])
            || ! is_array($report['native_worker'])
            || ! isset($report['native_worker']['job'])
            || ! is_string($report['native_worker']['job'])
            || trim($report['native_worker']['job']) === ''
        ) {
            return 'native worker lane returned invalid native report metadata';
        }

        $expectedJob = $this->planJobName($plan);
        if ($expectedJob === null) {
            return 'native worker lane cannot validate plan job name';
        }

        $reportedJob = $report['native_worker']['job'];
        if ($reportedJob !== $expectedJob) {
            return sprintf(
                'native worker lane reported unexpected job: expected %s got %s',
                $expectedJob,
                $reportedJob,
            );
        }

        $nativeLane = $report['native_lane'];
        foreach (['commands', 'succeeded', 'failed', 'exit_code'] as $key) {
            if (! array_key_exists($key, $nativeLane) || ! is_int($nativeLane[$key])) {
                return sprintf('native worker lane returned invalid native_lane.%s', $key);
            }
        }

        if ($nativeLane['failed'] !== 0 || $nativeLane['exit_code'] !== 0) {
            return sprintf(
                'native worker lane reported failed commands: failed=%d exit_code=%d',
                $nativeLane['failed'],
                $nativeLane['exit_code'],
            );
        }

        if ($nativeLane['commands'] < 1 && $this->requiresDispatchedNativeLaneCommands($expectedJob)) {
            return 'native worker lane reported no dispatched commands';
        }

        if ($nativeLane['succeeded'] !== $nativeLane['commands']) {
            return sprintf(
                'native worker lane reported incomplete command success: commands=%d succeeded=%d',
                $nativeLane['commands'],
                $nativeLane['succeeded'],
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function nativeLaneReportErrorForNonzeroExit(string $output, array $plan): ?string
    {
        try {
            $report = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($report) || ! isset($report['native_lane']) || ! is_array($report['native_lane'])) {
            return null;
        }

        $validationError = $this->validateSuccessfulLaneReport($output, $plan);
        if ($validationError === null) {
            return 'native worker lane exited nonzero despite a successful native_lane report';
        }

        $failures = $report['native_lane']['failures'] ?? [];
        if (! is_array($failures) || $failures === []) {
            return $validationError;
        }

        $failureLines = [];
        foreach (array_slice($failures, 0, 5) as $failure) {
            if (is_string($failure) && trim($failure) !== '') {
                $failureLines[] = '- '.trim($failure);
            }
        }

        if ($failureLines === []) {
            return $validationError;
        }

        return $validationError.PHP_EOL.'failed commands:'.PHP_EOL.implode(PHP_EOL, $failureLines);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function planJobName(array $plan): ?string
    {
        if (! isset($plan['job']) || ! is_array($plan['job'])) {
            return null;
        }

        $name = $plan['job']['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function isCommandOnlyLane(array $plan): bool
    {
        return in_array($this->planJobName($plan), ['metadata-refresh', 'fixnames', 'hashed-fixnames'], true);
    }

    private function requiresDispatchedNativeLaneCommands(string $job): bool
    {
        return in_array($job, [
            'removecrap',
            'post-tv',
            'post-movies',
            'post-amazon',
            'post-additional',
            'metadata-refresh',
            'fixnames',
            'hashed-fixnames',
            'irc',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function shouldAllowDeferredPostAdditional(array $plan): bool
    {
        return $this->planJobName($plan) === 'post-additional'
            && (bool) config('nntmux.native_worker_post_additional_deferred_execution_enabled', false);
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(?string $mysqlDsn, string $redisAddr, string $lockOwner): array
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

        foreach ($this->environmentKeys() as $key) {
            if (! $this->shouldForwardArtisanRuntimeEnvironment($key)) {
                continue;
            }

            $value = $this->environmentValue($key);
            if ($value !== null) {
                $environment[$key] = $value;
            }
        }

        if ($mysqlDsn !== null) {
            $environment['NNTMUX_NATIVE_MYSQL_DSN'] = $mysqlDsn;
        }
        $environment['NNTMUX_NATIVE_REDIS_ADDR'] = $redisAddr;
        $environment['NNTMUX_NATIVE_LOCK_OWNER'] = $lockOwner;
        foreach ([
            'NNTMUX_NATIVE_FAKE_ARTISAN_LOCK_KEY',
            'NNTMUX_NATIVE_LEAF_STARTUP_SMOKE',
            'NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG',
        ] as $key) {
            $value = $this->environmentValue($key);
            if ($value !== null) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    private function shouldForwardArtisanRuntimeEnvironment(string $key): bool
    {
        foreach ([
            'APP_',
            'BROADCAST_',
            'CACHE_',
            'DB_',
            'ELASTICSEARCH_',
            'FILESYSTEM_',
            'LOG_',
            'MAIL_',
            'MANTICORE_',
            'MANTICORESEARCH_',
            'MEILISEARCH_',
            'NNTP_',
            'NNTMUX_BODY_PREAMBLE_',
            'NNTMUX_IA_PREDB_',
            'NNTMUX_METADATA_',
            'NNTMUX_NZBINDEX_',
            'NNTMUX_PREDB_',
            'QUEUE_',
            'REDIS_',
            'SCOUT_',
            'SCRAPE_IRC_',
            'SESSION_',
            'TELESCOPE_',
            'NNTMUX_NATIVE_WORKER_IRC_',
        ] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return in_array($key, [
            'MEDIAINFO_PATH',
            'NNTMUX_SRRDB_BASE_URL',
            'NNTMUX_XREL_BASE_URL',
            'NZB_IMPORT_FOLDER',
            'NZB_UPLOAD_FOLDER',
            'PATH_TO_NZBS',
            'RENAME_MUSIC_MEDIAINFO',
            'RENAME_PAR2',
            'TEMP_UNRAR_PATH',
            'TEMP_UNZIP_PATH',
        ], true);
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
}
