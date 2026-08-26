<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Services\NameFixing\NativeHashedFixNameRenameApplier;
use App\Services\NameFixing\NativeHashedFixNameWriteContractResolver;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class NativeHashedFixNameRenamePrepassRunner
{
    private const DEFAULT_TIMEOUT_SECONDS = 30.0;
    private const DEFAULT_LOG_BYTES = 2048;
    private const DEFAULT_REPORT_BYTES = 1048576;

    public function __construct(
        private readonly NativeHashedFixNameWriteContractResolver $resolver,
        private readonly NativeHashedFixNameRenameApplier $applier,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     */
    public function apply(array $plan): NativeHashedFixNameRenamePrepassResult
    {
        if (! (bool) config('nntmux.native_worker_rename_apply_test_enabled', false)) {
            return new NativeHashedFixNameRenamePrepassResult(
                successful: false,
                errorOutput: 'native rename prepass requires NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=1',
            );
        }

        $binary = $this->binaryPath();
        if ($binary === null) {
            return new NativeHashedFixNameRenamePrepassResult(false, errorOutput: 'native worker binary is not configured');
        }

        if (! str_starts_with($binary, DIRECTORY_SEPARATOR) || ! is_file($binary) || ! is_executable($binary)) {
            return new NativeHashedFixNameRenamePrepassResult(false, errorOutput: 'native worker binary must be an absolute executable path');
        }

        $mysqlDsn = $this->mysqlDsn();
        if ($mysqlDsn === null) {
            return new NativeHashedFixNameRenamePrepassResult(false, errorOutput: 'native worker mysql dsn is not configured');
        }

        $database = $this->databaseNameFromMysqlDsn($mysqlDsn);
        if ($database === null || ! $this->isAllowedNativeTestDatabase($database)) {
            return new NativeHashedFixNameRenamePrepassResult(false, errorOutput: 'native worker mysql dsn must target an allowlisted native test database');
        }

        try {
            $input = json_encode($plan, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new NativeHashedFixNameRenamePrepassResult(false, errorOutput: 'encode native worker plan: '.$e->getMessage());
        }

        $nativeResult = $this->runNativeDryRun($binary, $mysqlDsn, $input);
        if (! $nativeResult->successful) {
            return $nativeResult;
        }

        try {
            $nativeReport = $this->decodeNativeReport($nativeResult->output);
            $this->validateNativeReport($nativeReport);
            $resolvedReport = $this->resolver->resolve($nativeReport);
            $applyResult = $this->applier->apply($resolvedReport);
        } catch (Throwable $e) {
            return new NativeHashedFixNameRenamePrepassResult(
                successful: false,
                output: $nativeResult->output,
                errorOutput: 'native rename prepass apply: '.$e->getMessage(),
            );
        }

        return new NativeHashedFixNameRenamePrepassResult(
            successful: true,
            output: $nativeResult->output,
            errorOutput: $nativeResult->errorOutput,
            exitCode: $nativeResult->exitCode,
            releaseUpdatesSeen: max(0, (int) ($applyResult['release_updates_seen'] ?? 0)),
            releaseUpdatesApplied: max(0, (int) ($applyResult['release_updates_applied'] ?? 0)),
            releaseIds: $this->releaseIds($applyResult['release_ids'] ?? []),
        );
    }

    private function runNativeDryRun(string $binary, string $mysqlDsn, string $input): NativeHashedFixNameRenamePrepassResult
    {
        $process = new Process(
            [
                $binary,
                '--plan',
                '-',
                '--dry-run',
                '--output',
                'json',
                '--mysql-dsn-env',
            ],
            base_path('native'),
            $this->environment($mysqlDsn),
            $input,
            $this->timeoutSeconds(),
        );

        $output = '';
        $errorOutput = '';
        $outputTruncated = false;
        $errorOutputTruncated = false;
        $reportBytes = $this->reportBytes();
        $logBytes = $this->logBytes();

        try {
            $process->run(function (string $type, string $data) use (&$output, &$errorOutput, &$outputTruncated, &$errorOutputTruncated, $reportBytes, $logBytes): void {
                if ($type === Process::ERR) {
                    $this->appendLimitedOutput($errorOutput, $errorOutputTruncated, $data, $logBytes);

                    return;
                }

                $this->appendLimitedOutput($output, $outputTruncated, $data, $reportBytes);
            });
        } catch (ProcessTimedOutException) {
            return new NativeHashedFixNameRenamePrepassResult(
                successful: false,
                errorOutput: 'native rename prepass timed out after '.$this->timeoutSeconds().' seconds',
            );
        } catch (Throwable $e) {
            return new NativeHashedFixNameRenamePrepassResult(
                successful: false,
                errorOutput: 'run native rename prepass: '.$e->getMessage(),
            );
        }

        return new NativeHashedFixNameRenamePrepassResult(
            successful: $process->isSuccessful(),
            output: $this->finalizeLimitedOutput($output, $outputTruncated, $reportBytes),
            errorOutput: $this->finalizeLimitedOutput($errorOutput, $errorOutputTruncated, $logBytes),
            exitCode: $process->getExitCode(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeNativeReport(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid native rename prepass report: malformed JSON');
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('invalid native rename prepass report: expected JSON object');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function validateNativeReport(array $report): void
    {
        if (($report['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('invalid native rename prepass report: expected schema_version=1');
        }

        if (($report['mode'] ?? null) !== 'shadow') {
            throw new InvalidArgumentException('invalid native rename prepass report: expected mode=shadow');
        }

        if (($report['dry_run'] ?? null) !== true) {
            throw new InvalidArgumentException('invalid native rename prepass report: expected dry_run=true');
        }

        $nativeWorker = $report['native_worker'] ?? null;
        if (! is_array($nativeWorker) || ($nativeWorker['job'] ?? null) !== 'hashed-fixnames') {
            throw new InvalidArgumentException('invalid native rename prepass report: native worker job mismatch');
        }

        if (($nativeWorker['writes'] ?? null) !== 0) {
            throw new InvalidArgumentException('invalid native rename prepass report: expected native_worker.writes=0');
        }

        $hashedFixnames = $report['hashed_fixnames'] ?? null;
        $writeContract = is_array($hashedFixnames) ? ($hashedFixnames['write_contract'] ?? null) : null;
        if (! is_array($writeContract) || ($writeContract['writes'] ?? null) !== 0) {
            throw new InvalidArgumentException('invalid native rename prepass report: expected hashed_fixnames.write_contract.writes=0');
        }

        $readiness = is_array($hashedFixnames) ? ($hashedFixnames['replacement_readiness'] ?? null) : null;
        $supportedMethods = is_array($readiness) ? ($readiness['supported_methods'] ?? null) : null;
        if (! is_array($supportedMethods)) {
            throw new InvalidArgumentException('invalid native rename prepass report: missing replacement readiness methods');
        }

        foreach ($supportedMethods as $method) {
            if (! in_array($method, ['16', '20'], true)) {
                throw new InvalidArgumentException('invalid native rename prepass report: unsupported native rename method');
            }
        }
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

    private function timeoutSeconds(): float
    {
        $timeout = (float) config('nntmux.native_worker_rename_prepass_timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS);

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT_SECONDS;
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
            || str_starts_with($database, 'nntmux_native_test_')
            || str_ends_with($database, '_native_test');
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(string $mysqlDsn): array
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

        return $environment;
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
        $bytes = (int) config('nntmux.native_worker_rename_prepass_report_bytes', self::DEFAULT_REPORT_BYTES);

        return $bytes > 0 ? $bytes : self::DEFAULT_REPORT_BYTES;
    }

    /**
     * @return list<int>
     */
    private function releaseIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }

        sort($ids, SORT_NUMERIC);

        return array_values(array_unique($ids));
    }
}
