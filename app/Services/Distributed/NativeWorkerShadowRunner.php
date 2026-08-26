<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class NativeWorkerShadowRunner
{
    private const DEFAULT_TIMEOUT_SECONDS = 5.0;
    private const DEFAULT_LOG_BYTES = 2048;

    /**
     * @param  array<string, mixed>  $plan
     */
    public function dryRun(array $plan): NativeWorkerShadowResult
    {
        $binary = $this->binaryPath();
        if ($binary === null) {
            return new NativeWorkerShadowResult(
                successful: false,
                output: '',
                errorOutput: 'native worker binary is not configured',
                exitCode: null,
            );
        }

        if (! str_starts_with($binary, DIRECTORY_SEPARATOR) || ! is_file($binary) || ! is_executable($binary)) {
            return new NativeWorkerShadowResult(
                successful: false,
                output: '',
                errorOutput: 'native worker binary must be an absolute executable path',
                exitCode: null,
            );
        }

        try {
            $input = json_encode($plan, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new NativeWorkerShadowResult(
                successful: false,
                output: '',
                errorOutput: 'encode native worker plan: '.$e->getMessage(),
                exitCode: null,
            );
        }

        $process = new Process(
            [$binary, '--plan', '-', '--dry-run'],
            base_path('native'),
            $this->environment(),
            $input,
            $this->timeoutSeconds(),
        );
        $process->disableOutput();

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
        } catch (ProcessTimedOutException $e) {
            return new NativeWorkerShadowResult(
                successful: false,
                output: '',
                errorOutput: 'native worker dry-run timed out after '.$this->timeoutSeconds().' seconds',
                exitCode: null,
            );
        } catch (Throwable $e) {
            return new NativeWorkerShadowResult(
                successful: false,
                output: '',
                errorOutput: 'run native worker dry-run: '.$e->getMessage(),
                exitCode: null,
            );
        }

        return new NativeWorkerShadowResult(
            successful: $process->isSuccessful(),
            output: $this->finalizeLimitedOutput($output, $outputTruncated, $logBytes),
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

    private function timeoutSeconds(): float
    {
        $timeout = (float) config('nntmux.native_worker_timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS);

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * @return array<string, string|false>
     */
    private function environment(): array
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
}
