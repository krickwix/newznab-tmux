<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Models\Settings;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class DistributedJobWorker
{
    public function __construct(
        private readonly DistributedJobCatalog $catalog,
        private readonly TmuxMonitorService $monitorService,
        private readonly DistributedWorkerTelemetry $workerTelemetry,
        private readonly ?BackfillPermitGate $backfillPermitGate = null,
    ) {}

    public function run(
        string $job,
        bool $once,
        ?int $sleepOverride,
        int $lockSeconds,
        OutputInterface $output,
        bool $stopOnDisabled = false,
    ): int {
        $this->monitorService->initializeMonitor();

        do {
            $runVar = $this->monitorService->collectStatistics();
            $plan = $this->catalog->resolve($job, $runVar);
            $sleep = $sleepOverride ?? (int) $plan['sleep'];

            if (! $plan['enabled']) {
                $this->workerTelemetry->recordRunOutcome($job, 'disabled');
                $output->writeln(sprintf(
                    '[%s] disabled: %s',
                    now()->toDateTimeString(),
                    $plan['disabled_reason'] ?? 'disabled'
                ));

                if ($once || $stopOnDisabled) {
                    return 0;
                }

                $this->sleep($sleep, $output);
                $this->monitorService->incrementIteration();

                continue;
            }

            $exitCode = $this->runLockedPlan($plan, $lockSeconds, $output);

            if ($once) {
                return $exitCode;
            }

            $this->sleep($sleep, $output);
            $this->monitorService->incrementIteration();
        } while ($this->shouldContinue());

        $output->writeln(sprintf('[%s] exit setting requested shutdown', now()->toDateTimeString()));

        return 0;
    }

    /**
     * @param  array{
     *     name: string,
     *     description: string,
     *     enabled: bool,
     *     disabled_reason: string|null,
     *     commands: list<array{command: string, arguments: array<string, mixed>}>,
     *     sleep: int
     * }  $plan
     */
    private function runLockedPlan(array $plan, int $lockSeconds, OutputInterface $output): int
    {
        $lockName = 'nntmux:distributed-worker:'.$plan['name'];
        $lockStore = (string) config('nntmux.distributed_lock_store', 'redis');
        $lock = Cache::store($lockStore)->lock($lockName, $lockSeconds);

        try {
            $acquired = $lock->get();
        } catch (Throwable $e) {
            $this->workerTelemetry->recordRunOutcome($plan['name'], 'lock_error');
            $output->writeln(sprintf(
                '[%s] skipped %s: failed to acquire %s lock [%s]: %s',
                now()->toDateTimeString(),
                $plan['name'],
                $lockStore,
                $lockName,
                $e->getMessage()
            ));

            return 1;
        }

        if (! $acquired) {
            $this->workerTelemetry->recordRunOutcome($plan['name'], 'lock_contended');
            $output->writeln(sprintf('[%s] skipped %s: another worker holds %s', now()->toDateTimeString(), $plan['name'], $lockName));

            return 0;
        }

        if ($plan['name'] === 'backfill' && ! ($this->backfillPermitGate ?? app(BackfillPermitGate::class))->claim()) {
            $this->workerTelemetry->recordRunOutcome('backfill', 'disabled');
            $output->writeln(sprintf('[%s] skipped backfill: adaptive permit was absent or stale', now()->toDateTimeString()));
            $lock->release();

            return 0;
        }

        $startedAt = $this->workerTelemetry->startRun($plan['name']);
        $runOutcome = 'failure';
        $restoreSignalHandlers = $this->registerLockTerminationHandlers(
            $lock,
            $lockName,
            $plan['name'],
            $startedAt,
            $output,
        );

        try {
            $output->writeln(sprintf(
                '[%s] starting %s: %s',
                now()->toDateTimeString(),
                $plan['name'],
                $plan['description']
            ));

            foreach ($plan['commands'] as $command) {
                $exitCode = $this->call($command['command'], $command['arguments'], $output);

                if ($exitCode !== 0) {
                    $output->writeln(sprintf(
                        '[%s] %s failed with exit code %d',
                        now()->toDateTimeString(),
                        $command['command'],
                        $exitCode
                    ));

                    return $exitCode;
                }
            }

            $output->writeln(sprintf('[%s] completed %s', now()->toDateTimeString(), $plan['name']));
            $runOutcome = 'success';

            return 0;
        } finally {
            $this->workerTelemetry->finishRun($plan['name'], $runOutcome, $startedAt);
            $restoreSignalHandlers();
            $lock->release();
        }
    }

    /**
     * Release a held distributed lock when Kubernetes terminates this pod.
     *
     * Without this, a Recreate rollout can leave the next pod polling until
     * the full lock TTL expires even though no worker process remains.
     */
    private function registerLockTerminationHandlers(
        mixed $lock,
        string $lockName,
        string $job,
        float $startedAt,
        OutputInterface $output,
    ): callable {
        if (! function_exists('pcntl_signal')) {
            return static fn (): null => null;
        }

        $signals = array_values(array_filter([
            defined('SIGTERM') ? SIGTERM : null,
            defined('SIGINT') ? SIGINT : null,
            defined('SIGHUP') ? SIGHUP : null,
        ]));

        if ($signals === []) {
            return static fn (): null => null;
        }

        $previousAsyncSignals = null;
        if (function_exists('pcntl_async_signals')) {
            $previousAsyncSignals = pcntl_async_signals();
            pcntl_async_signals(true);
        }

        $previousHandlers = [];
        foreach ($signals as $signal) {
            $previousHandlers[$signal] = function_exists('pcntl_signal_get_handler')
                ? pcntl_signal_get_handler($signal)
                : SIG_DFL;

            pcntl_signal($signal, function (int $receivedSignal) use ($lock, $lockName, $job, $startedAt, $output): void {
                $output->writeln($this->formatTerminationSignalMessage($receivedSignal, $job, $lockName));
                $this->workerTelemetry->finishRun($job, 'terminated', $startedAt);

                try {
                    $lock->release();
                } catch (Throwable $e) {
                    $output->writeln(sprintf(
                        '[%s] failed to release %s after signal %d: %s',
                        now()->toDateTimeString(),
                        $lockName,
                        $receivedSignal,
                        $e->getMessage(),
                    ));
                }

                exit(128 + $receivedSignal);
            });
        }

        return static function () use ($previousHandlers, $previousAsyncSignals): void {
            foreach ($previousHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($previousAsyncSignals !== null && function_exists('pcntl_async_signals')) {
                pcntl_async_signals($previousAsyncSignals);
            }
        };
    }

    private function formatTerminationSignalMessage(int $signal, string $job, string $lockName): string
    {
        return sprintf(
            '[%s] received signal %d while running %s; releasing %s before exit',
            now()->toDateTimeString(),
            $signal,
            $job,
            $lockName,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function call(string $command, array $arguments, OutputInterface $output): int
    {
        $output->writeln(sprintf('[%s] php artisan %s %s', now()->toDateTimeString(), $command, $this->formatArguments($arguments)));

        return Artisan::call($command, $arguments, $output);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function formatArguments(array $arguments): string
    {
        $parts = [];
        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = is_string($key) && str_starts_with($key, '--')
                        ? $key.'='.(string) $item
                        : (string) $item;
                }

                continue;
            }

            if (is_bool($value)) {
                if ($value) {
                    $parts[] = (string) $key;
                }

                continue;
            }

            $parts[] = is_string($key) && str_starts_with($key, '--')
                ? $key.'='.$value
                : (string) $value;
        }

        return trim(implode(' ', $parts));
    }

    private function sleep(int $seconds, OutputInterface $output): void
    {
        $output->writeln(sprintf('[%s] sleeping for %d seconds', now()->toDateTimeString(), $seconds));
        sleep(max(1, $seconds));
    }

    private function shouldContinue(): bool
    {
        return (int) Settings::settingValue('exit') === 0;
    }
}
