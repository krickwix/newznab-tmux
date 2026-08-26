<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Facades\Search;
use App\Models\Settings;
use App\Services\CollectionCleanupService;
use App\Services\NameFixing\NativeSearchSideEffectOutboxSync;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Tmux\TmuxMonitorService;
use App\Support\ReleaseSearchIndexSync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class DistributedJobWorker
{
    public function __construct(
        private readonly DistributedJobCatalog $catalog,
        private readonly TmuxMonitorService $monitorService,
        private readonly NativeWorkerPlanExporter $nativeWorkerPlanExporter,
        private readonly NativeWorkerShadowRunner $nativeWorkerShadowRunner,
        private readonly NativeWorkerCommitRunner $nativeWorkerCommitRunner,
        private readonly NativeSearchSideEffectOutboxSync $nativeSearchSideEffectOutboxSync,
        private readonly NativeHashedFixNameRenamePrepassRunner $nativeHashedFixNameRenamePrepassRunner,
        private readonly NativeWorkerLaneRunner $nativeWorkerLaneRunner,
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

            $cycle = $this->runLockedPlan($plan, $lockSeconds, $output);

            if ($once || $cycle['stop_worker']) {
                return $cycle['exit_code'];
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
     * @return array{exit_code: int, stop_worker: bool}
     */
    private function runLockedPlan(array $plan, int $lockSeconds, OutputInterface $output): array
    {
        $lockName = 'nntmux:distributed-worker:'.$plan['name'];
        $lockStore = (string) config('nntmux.distributed_lock_store', 'redis');
        // Laravel exposes lock() on concrete cache repositories, but the facade
        // contract does not currently declare it for static analysis.
        /** @phpstan-ignore-next-line method.notFound */
        $lock = Cache::store($lockStore)->lock($lockName, $lockSeconds);

        try {
            $acquired = $lock->get();
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '[%s] skipped %s: failed to acquire %s lock [%s]: %s',
                now()->toDateTimeString(),
                $plan['name'],
                $lockStore,
                $lockName,
                $e->getMessage()
            ));

            return $this->cycleResult(1);
        }

        if (! $acquired) {
            $output->writeln(sprintf('[%s] skipped %s: another worker holds %s', now()->toDateTimeString(), $plan['name'], $lockName));

            return $this->cycleResult(0);
        }

        $restoreSignalHandlers = $this->registerLockTerminationHandlers($lock, $lockName, $plan['name'], $output);

        try {
            $output->writeln(sprintf(
                '[%s] starting %s: %s',
                now()->toDateTimeString(),
                $plan['name'],
                $plan['description']
            ));

            $this->runNativeShadowValidation($plan, $lockSeconds, $output);
            $commands = $plan['commands'];
            $nativeExitCode = $this->runNativeLaneCommit($plan, $lockSeconds, (string) $lock->owner(), $output);
            if ($nativeExitCode !== null) {
                if ($nativeExitCode !== 0) {
                    return $this->cycleResult($nativeExitCode);
                }

                if ($this->shouldRunDeferredMetadataRefreshCommands($plan)) {
                    $commands = $this->deferredMetadataRefreshCommands($plan['commands']);
                } elseif ($this->shouldRunDeferredFixnamesCommands($plan)) {
                    $commands = $this->deferredFixnamesCommands($plan['commands']);
                } elseif ($this->shouldRunDeferredPostAdditionalCommands($plan)) {
                    $commands = $this->deferredPostAdditionalCommands($plan['commands']);
                } else {
                    return $this->cycleResult(0);
                }
            } else {
                $nativeExitCode = $this->runNativeMissStatusPrepass($plan, $lockSeconds, (string) $lock->owner(), $output);
                if ($nativeExitCode !== null) {
                    return $this->cycleResult($nativeExitCode, stopWorker: true);
                }

                $nativeExitCode = $this->runNativeRenamePrepass($plan, $lockSeconds, $output);
                if ($nativeExitCode !== null) {
                    return $this->cycleResult($nativeExitCode, stopWorker: true);
                }

                $nativeExitCode = $this->runNativeLaneExecution($plan, $lockSeconds, (string) $lock->owner(), $output);
                if ($nativeExitCode !== null) {
                    if ($nativeExitCode !== 0) {
                        return $this->cycleResult($nativeExitCode);
                    }

                    if (! $this->shouldRunDeferredPostAdditionalCommands($plan)) {
                        return $this->cycleResult(0);
                    }

                    $commands = $this->deferredPostAdditionalCommands($plan['commands']);
                }
            }

            foreach ($commands as $command) {
                $exitCode = $this->call($command['command'], $command['arguments'], $output);

                if ($exitCode !== 0) {
                    $output->writeln(sprintf(
                        '[%s] %s failed with exit code %d',
                        now()->toDateTimeString(),
                        $command['command'],
                        $exitCode
                    ));

                    return $this->cycleResult($exitCode);
                }
            }

            $output->writeln(sprintf('[%s] completed %s', now()->toDateTimeString(), $plan['name']));

            return $this->cycleResult(0);
        } finally {
            $restoreSignalHandlers();
            $lock->release();
        }
    }

    /**
     * @return array{exit_code: int, stop_worker: bool}
     */
    private function cycleResult(int $exitCode, bool $stopWorker = false): array
    {
        return [
            'exit_code' => $exitCode,
            'stop_worker' => $stopWorker,
        ];
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
    private function runNativeLaneCommit(array $plan, int $lockSeconds, string $lockOwner, OutputInterface $output): ?int
    {
        if (! in_array($plan['name'], $this->nativeLaneCommitJobs(), true)) {
            return null;
        }

        $laneCommitEnabled = (bool) config('nntmux.native_worker_lane_commit_enabled', false);
        $firstLaneCommitEnabled = (bool) config('nntmux.native_worker_first_lane_commit_enabled', false)
            && in_array($plan['name'], $this->nativeFirstLaneCommitJobs(), true);
        if (! $laneCommitEnabled && ! $firstLaneCommitEnabled) {
            return null;
        }

        try {
            $nativePlan = $this->nativeWorkerPlanExporter->export($plan, $lockSeconds);
            $result = $this->nativeWorkerCommitRunner->commitLaneWrites($nativePlan, $lockOwner);
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '[%s] native lane commit failed for %s: %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($e->getMessage()),
            ));

            return 1;
        }

        if (! $result->successful) {
            $output->writeln(sprintf(
                '[%s] native lane commit failed for %s: %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($result->message()),
            ));

            return $result->exitCode ?? 1;
        }

        try {
            $commit = $this->validateNativeLaneCommitReport($result->output, $plan['name']);
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '[%s] native lane commit failed for %s: %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($e->getMessage()),
            ));

            return 1;
        }

        if ($plan['name'] === 'releases') {
            $searchSync = $this->syncNativeReleaseSearchSideEffects($commit['committed_release_ids']);
            $searchFailures = (int) $searchSync['search_updates_failed'];
            $output->writeln(sprintf(
                '[%s] native release search synced %s: seen=%d synced=%d failed=%d',
                now()->toDateTimeString(),
                $plan['name'],
                (int) $searchSync['search_updates_seen'],
                (int) $searchSync['search_updates_synced'],
                $searchFailures,
            ));

            if ($searchFailures > 0) {
                return 1;
            }
        }

        if ($plan['name'] === 'removecrap') {
            $searchSync = $this->syncNativeDeletedReleaseSearchSideEffects($commit['deleted_release_ids']);
            $searchFailures = (int) $searchSync['search_updates_failed'];
            $output->writeln(sprintf(
                '[%s] native removecrap search deleted %s: seen=%d synced=%d failed=%d',
                now()->toDateTimeString(),
                $plan['name'],
                (int) $searchSync['search_updates_seen'],
                (int) $searchSync['search_updates_synced'],
                $searchFailures,
            ));

            if ($searchFailures > 0) {
                return 1;
            }

            $collectionCleanup = $this->syncNativeRemoveCrapCollectionCleanupSideEffects($commit['deleted_collection_ids']);
            $collectionCleanupFailures = (int) $collectionCleanup['collection_cleanup_failed'];
            $output->writeln(sprintf(
                '[%s] native removecrap collection descendants cleaned %s: seen=%d deleted=%d failed=%d',
                now()->toDateTimeString(),
                $plan['name'],
                (int) $collectionCleanup['collection_ids_seen'],
                (int) $collectionCleanup['collections_deleted'],
                $collectionCleanupFailures,
            ));

            if ($collectionCleanupFailures > 0) {
                return 1;
            }

            $fileCleanup = $this->syncNativeRemoveCrapFileCleanupSideEffects(
                $commit['deleted_release_ids'],
                $commit['release_file_cleanup_rows_enqueued'],
            );
            $fileCleanupFailures = (int) $fileCleanup['release_file_cleanup_failed'];
            $output->writeln(sprintf(
                '[%s] native removecrap release files cleaned %s: seen=%d cleaned=%d failed=%d',
                now()->toDateTimeString(),
                $plan['name'],
                (int) $fileCleanup['release_file_cleanup_seen'],
                (int) $fileCleanup['release_file_cleanup_cleaned'],
                $fileCleanupFailures,
            ));

            if ($fileCleanupFailures > 0) {
                return 1;
            }
        }

        if ($this->isNativePostprocessCommitJob($plan['name'])) {
            $searchSync = $this->syncNativePostprocessSearchSideEffects($commit['committed_release_ids']);
            $searchFailures = (int) $searchSync['search_updates_failed'];
            $output->writeln(sprintf(
                '[%s] native postprocess search synced %s: seen=%d synced=%d failed=%d',
                now()->toDateTimeString(),
                $plan['name'],
                (int) $searchSync['search_updates_seen'],
                (int) $searchSync['search_updates_synced'],
                $searchFailures,
            ));

            if ($searchFailures > 0) {
                return 1;
            }
        }

        if (in_array($plan['name'], ['fixnames', 'metadata-refresh'], true)) {
            $sync = $this->nativeSearchSideEffectOutboxSync->syncPending((int) config('nntmux.native_worker_search_outbox_limit', 100), $plan['name']);
            $failed = (int) $sync['search_updates_failed'];
            $output->writeln(sprintf(
                '[%s] native search outbox synced %s: seen=%d synced=%d failed=%d',
                now()->toDateTimeString(),
                $plan['name'],
                (int) $sync['search_updates_seen'],
                (int) $sync['search_updates_synced'],
                $failed,
            ));

            if ($failed > 0) {
                return 1;
            }
        }

        $output->writeln(sprintf(
            '[%s] native lane commit completed %s: writes=%d',
            now()->toDateTimeString(),
            $plan['name'],
            $commit['writes'],
        ));

        return 0;
    }

    /**
     * @return list<string>
     */
    private function nativeLaneCommitJobs(): array
    {
        $jobs = [
            'binaries',
            'backfill',
            'releases',
            'per-group',
            'removecrap',
            'metadata-refresh',
            'post-tv',
            'post-movies',
            'post-amazon',
            'fixnames',
        ];

        if ((bool) config('nntmux.native_worker_post_additional_deferred_execution_enabled', false)) {
            $jobs[] = 'post-additional';
        }

        return $jobs;
    }

    /**
     * @return list<string>
     */
    private function nativeFirstLaneCommitJobs(): array
    {
        return ['binaries', 'backfill', 'releases'];
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
    private function runNativeLaneExecution(array $plan, int $lockSeconds, string $lockOwner, OutputInterface $output): ?int
    {
        if (! in_array($plan['name'], $this->nativeLaneExecutionJobs(), true)) {
            return null;
        }

        if (! (bool) config('nntmux.native_worker_lane_execution_enabled', false)) {
            return null;
        }

        try {
            $nativePlan = $this->nativeWorkerPlanExporter->export($plan, $lockSeconds);
            $result = $this->nativeWorkerLaneRunner->runLane($nativePlan, $lockOwner);
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '[%s] native lane failed for %s: %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($e->getMessage()),
            ));

            return 1;
        }

        if (! $result->successful) {
            $output->writeln(sprintf(
                '[%s] native lane failed for %s: %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($result->message()),
            ));

            return $result->exitCode ?? 1;
        }

        if ($plan['name'] === 'irc') {
            $sync = $this->nativeSearchSideEffectOutboxSync->syncPending((int) config('nntmux.native_worker_search_outbox_limit', 100), $plan['name']);
            $failed = (int) $sync['search_updates_failed'];
            $output->writeln(sprintf(
                '[%s] native search outbox synced %s: seen=%d synced=%d failed=%d',
                now()->toDateTimeString(),
                $plan['name'],
                (int) $sync['search_updates_seen'],
                (int) $sync['search_updates_synced'],
                $failed,
            ));

            if ($failed > 0) {
                return 1;
            }
        }

        $output->writeln(sprintf('[%s] native lane completed %s', now()->toDateTimeString(), $plan['name']));

        return 0;
    }

    /**
     * @return list<string>
     */
    private function nativeLaneExecutionJobs(): array
    {
        $jobs = ['binaries', 'backfill', 'releases', 'per-group', 'removecrap', 'post-tv', 'post-movies', 'post-amazon', 'metadata-refresh', 'fixnames', 'hashed-fixnames', 'irc'];

        if ((bool) config('nntmux.native_worker_post_additional_deferred_execution_enabled', false)) {
            $jobs[] = 'post-additional';
        }

        return $jobs;
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
    private function shouldRunDeferredPostAdditionalCommands(array $plan): bool
    {
        return $plan['name'] === 'post-additional'
            && (bool) config('nntmux.native_worker_post_additional_deferred_execution_enabled', false);
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
    private function shouldRunDeferredMetadataRefreshCommands(array $plan): bool
    {
        return $plan['name'] === 'metadata-refresh'
            && $this->deferredMetadataRefreshCommands($plan['commands']) !== [];
    }

    /**
     * @param  list<array{command: string, arguments: array<string, mixed>}>  $commands
     * @return list<array{command: string, arguments: array<string, mixed>}>
     */
    private function deferredMetadataRefreshCommands(array $commands): array
    {
        return array_values(array_filter(
            $commands,
            fn (array $command): bool => $command['command'] !== 'predb:refresh-external-metadata',
        ));
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
    private function shouldRunDeferredFixnamesCommands(array $plan): bool
    {
        return $plan['name'] === 'fixnames'
            && $this->deferredFixnamesCommands($plan['commands']) !== [];
    }

    /**
     * @param  list<array{command: string, arguments: array<string, mixed>}>  $commands
     * @return list<array{command: string, arguments: array<string, mixed>}>
     */
    private function deferredFixnamesCommands(array $commands): array
    {
        return array_values(array_filter(
            $commands,
            fn (array $command): bool => ! $this->isNativeRegularFixnameStatusCommand($command),
        ));
    }

    /**
     * @param  array{command: string, arguments: array<string, mixed>}  $command
     */
    private function isNativeRegularFixnameStatusCommand(array $command): bool
    {
        if ($command['command'] !== 'releases:fix-names') {
            return false;
        }

        $arguments = $command['arguments'];
        $method = (string) ($arguments['method'] ?? '');
        $category = (string) ($arguments['--category'] ?? '');

        return in_array($method, ['15', '19'], true)
            && in_array($category, ['other', 'movies'], true);
    }

    /**
     * @param  list<array{command: string, arguments: array<string, mixed>}>  $commands
     * @return list<array{command: string, arguments: array<string, mixed>}>
     */
    private function deferredPostAdditionalCommands(array $commands): array
    {
        return array_values(array_filter(
            $commands,
            fn (array $command): bool => ! $this->isNativePostAdditionalCommand($command),
        ));
    }

    /**
     * @param  array{command: string, arguments: array<string, mixed>}  $command
     */
    private function isNativePostAdditionalCommand(array $command): bool
    {
        if ($command['command'] !== 'multiprocessing:postprocess') {
            return false;
        }

        $type = $command['arguments']['type'] ?? null;

        return in_array($type, ['add', 'nfo'], true);
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
    private function runNativeRenamePrepass(array $plan, int $lockSeconds, OutputInterface $output): ?int
    {
        if ($plan['name'] !== 'hashed-fixnames') {
            return null;
        }

        if (! (bool) config('nntmux.native_worker_hashed_fixnames_rename_prepass_enabled', false)) {
            return null;
        }

        try {
            $nativePlan = $this->nativeWorkerPlanExporter->export($plan, $lockSeconds);
            $result = $this->nativeHashedFixNameRenamePrepassRunner->apply($nativePlan);
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '[%s] native rename prepass failed for %s: %s; %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($e->getMessage()),
                $this->nativeHashedFixnamesFailureAction(),
            ));

            return $this->nativeHashedFixnamesFailureExitCode();
        }

        if (! $result->successful) {
            $output->writeln(sprintf(
                '[%s] native rename prepass failed for %s: %s; %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($result->message()),
                $this->nativeHashedFixnamesFailureAction(),
            ));

            return $this->nativeHashedFixnamesFailureExitCode();
        }

        $output->writeln(sprintf(
            '[%s] native rename prepass applied %s: seen=%d applied=%d release_ids=%s',
            now()->toDateTimeString(),
            $plan['name'],
            $result->releaseUpdatesSeen,
            $result->releaseUpdatesApplied,
            implode(',', $result->releaseIds),
        ));

        return null;
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
    private function runNativeMissStatusPrepass(array $plan, int $lockSeconds, string $lockOwner, OutputInterface $output): ?int
    {
        if ($plan['name'] !== 'hashed-fixnames') {
            return null;
        }

        if (! (bool) config('nntmux.native_worker_hashed_fixnames_commit_enabled', false)) {
            return null;
        }

        try {
            $nativePlan = $this->nativeWorkerPlanExporter->export($plan, $lockSeconds);
            $result = $this->nativeWorkerCommitRunner->commitMissStatus($nativePlan, $lockOwner);
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '[%s] native miss-status prepass failed for %s: %s; %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($e->getMessage()),
                $this->nativeHashedFixnamesFailureAction(),
            ));

            return $this->nativeHashedFixnamesFailureExitCode();
        }

        if (! $result->successful) {
            $output->writeln(sprintf(
                '[%s] native miss-status prepass failed for %s: %s; %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($result->message()),
                $this->nativeHashedFixnamesFailureAction(),
            ));

            return $this->nativeHashedFixnamesFailureExitCode();
        }

        try {
            $this->validateNativeCommitReport($result->output, $plan['name']);
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '[%s] native miss-status prepass failed for %s: %s; %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($e->getMessage()),
                $this->nativeHashedFixnamesFailureAction(),
            ));

            return $this->nativeHashedFixnamesFailureExitCode();
        }

        $output->writeln(sprintf('[%s] native miss-status prepass committed %s', now()->toDateTimeString(), $plan['name']));

        try {
            $sync = $this->nativeSearchSideEffectOutboxSync->syncPending($this->nativeSearchOutboxLimit());
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '[%s] native search outbox sync failed for %s: %s; %s',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($e->getMessage()),
                $this->nativeHashedFixnamesFailureAction(),
            ));

            return $this->nativeHashedFixnamesFailureExitCode();
        }

        $failed = (int) ($sync['search_updates_failed'] ?? 0);
        $deadLettered = (int) ($sync['search_updates_dead_lettered'] ?? 0);
        $hasSearchFailures = $failed > 0 || $deadLettered > 0;
        $output->writeln(sprintf(
            '[%s] native search outbox synced %s: seen=%d synced=%d failed=%d dead-lettered=%d%s',
            now()->toDateTimeString(),
            $plan['name'],
            (int) ($sync['search_updates_seen'] ?? 0),
            (int) ($sync['search_updates_synced'] ?? 0),
            $failed,
            $deadLettered,
            $hasSearchFailures ? '; '.$this->nativeHashedFixnamesFailureAction() : '',
        ));

        return $hasSearchFailures ? $this->nativeHashedFixnamesFailureExitCode() : null;
    }

    private function nativeHashedFixnamesFailureAction(): string
    {
        return $this->nativeHashedFixnamesFailClosed()
            ? 'stopping PHP worker'
            : 'continuing with PHP worker';
    }

    private function nativeHashedFixnamesFailureExitCode(): ?int
    {
        return $this->nativeHashedFixnamesFailClosed() ? 1 : null;
    }

    private function nativeHashedFixnamesFailClosed(): bool
    {
        return (bool) config('nntmux.native_worker_hashed_fixnames_fail_closed_enabled', false);
    }

    private function validateNativeCommitReport(string $reportJson, string $job): void
    {
        try {
            $report = json_decode($reportJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid native commit report: malformed JSON');
        }

        if (! is_array($report)) {
            throw new InvalidArgumentException('invalid native commit report: expected JSON object');
        }

        if (($report['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('invalid native commit report: expected schema_version=1');
        }

        if (($report['mode'] ?? null) !== 'shadow') {
            throw new InvalidArgumentException('invalid native commit report: expected mode=shadow');
        }

        if (($report['dry_run'] ?? null) !== false) {
            throw new InvalidArgumentException('invalid native commit report: expected dry_run=false');
        }

        $nativeWorker = $report['native_worker'] ?? null;
        if (! is_array($nativeWorker) || ($nativeWorker['job'] ?? null) !== $job) {
            throw new InvalidArgumentException('invalid native commit report: native worker job mismatch');
        }

        $hashedFixnames = $report['hashed_fixnames'] ?? null;
        $writeCommit = is_array($hashedFixnames) ? ($hashedFixnames['write_commit'] ?? null) : null;
        if (! is_array($writeCommit)) {
            throw new InvalidArgumentException('invalid native commit report: missing write_commit');
        }

        if (($writeCommit['lock_acquired'] ?? null) !== true || ($writeCommit['lock_mode'] ?? null) !== 'held') {
            throw new InvalidArgumentException('invalid native commit report: expected held lock ownership');
        }

        $writesCommitted = $this->requiredNonNegativeInt($writeCommit, 'writes_committed');
        $singleColumnCommitted = $this->requiredNonNegativeInt($writeCommit, 'single_column_updates_committed');
        $singleColumnRowsAffected = $this->requiredNonNegativeInt($writeCommit, 'single_column_rows_affected');
        $nativeWorkerWrites = $this->requiredNonNegativeInt($nativeWorker, 'writes');
        $committedReleaseIds = $writeCommit['committed_release_ids'] ?? null;

        if (! is_array($committedReleaseIds)) {
            throw new InvalidArgumentException('invalid native commit report: committed_release_ids must be an array');
        }

        if (
            $writesCommitted !== $singleColumnCommitted
            || $writesCommitted !== $singleColumnRowsAffected
            || $writesCommitted !== $nativeWorkerWrites
            || $writesCommitted !== count($committedReleaseIds)
        ) {
            throw new InvalidArgumentException('invalid native commit report: committed write counts mismatch');
        }

        foreach ($committedReleaseIds as $releaseId) {
            if (! is_int($releaseId) || $releaseId <= 0) {
                throw new InvalidArgumentException('invalid native commit report: committed release IDs must be positive integers');
            }
        }
    }

    /**
     * @return array{writes: int, committed_release_ids: list<int>, deleted_release_ids: list<int>, deleted_collection_ids: list<int>, release_file_cleanup_rows_enqueued: int}
     */
    private function validateNativeLaneCommitReport(string $reportJson, string $job): array
    {
        try {
            $report = json_decode($reportJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('invalid native lane commit report: malformed JSON');
        }

        if (! is_array($report)) {
            throw new InvalidArgumentException('invalid native lane commit report: expected JSON object');
        }

        if (($report['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('invalid native lane commit report: expected schema_version=1');
        }

        if (($report['mode'] ?? null) !== 'shadow') {
            throw new InvalidArgumentException('invalid native lane commit report: expected mode=shadow');
        }

        if (($report['dry_run'] ?? null) !== false) {
            throw new InvalidArgumentException('invalid native lane commit report: expected dry_run=false');
        }

        $nativeWorker = $report['native_worker'] ?? null;
        if (! is_array($nativeWorker) || ($nativeWorker['job'] ?? null) !== $job) {
            throw new InvalidArgumentException('invalid native lane commit report: native worker job mismatch');
        }

        $commitKey = $this->nativeLaneCommitReportKey($job);
        $writeCommit = $report[$commitKey] ?? null;
        if (! is_array($writeCommit)) {
            throw new InvalidArgumentException('invalid native lane commit report: missing '.$commitKey);
        }

        if (($writeCommit['rolled_back'] ?? null) !== false) {
            throw new InvalidArgumentException('invalid native lane commit report: expected committed writes');
        }

        $writesCommitted = $this->requiredNonNegativeInt($writeCommit, 'writes_committed');
        $nativeWorkerWrites = $this->requiredNonNegativeInt($nativeWorker, 'writes');
        if ($writesCommitted !== $nativeWorkerWrites) {
            throw new InvalidArgumentException('invalid native lane commit report: committed write counts mismatch');
        }

        $validated = [
            'writes' => $writesCommitted,
            'committed_release_ids' => [],
            'deleted_release_ids' => [],
            'deleted_collection_ids' => [],
            'release_file_cleanup_rows_enqueued' => 0,
        ];

        if ($commitKey === 'releases_write_commit') {
            $releaseRowsAffected = $this->requiredNonNegativeInt($writeCommit, 'release_rows_affected');
            $validated['committed_release_ids'] = $this->validatedCommittedReleaseIds(
                $writeCommit['committed_release_ids'] ?? null,
                $releaseRowsAffected,
            );
        }

        if ($commitKey === 'postprocess_write_commit') {
            $validated['committed_release_ids'] = $this->validatedCommittedReleaseIds(
                $writeCommit['committed_release_ids'] ?? null,
                $writesCommitted,
            );
        }

        if ($commitKey === 'fixnames_write_commit') {
            $validated['committed_release_ids'] = $this->validatedCommittedReleaseIds(
                $writeCommit['committed_release_ids'] ?? null,
                $writesCommitted,
            );
            $singleColumnCommitted = $this->requiredNonNegativeInt($writeCommit, 'single_column_updates_committed');
            $singleColumnRowsAffected = $this->requiredNonNegativeInt($writeCommit, 'single_column_rows_affected');
            $searchUpdatesEnqueued = $this->requiredNonNegativeInt($writeCommit, 'search_updates_enqueued');
            if (
                $singleColumnCommitted !== $writesCommitted
                || $singleColumnRowsAffected !== $writesCommitted
                || $searchUpdatesEnqueued !== $writesCommitted
            ) {
                throw new InvalidArgumentException('invalid native lane commit report: fixnames status write counts mismatch');
            }
        }

        if ($commitKey === 'removecrap_write_commit') {
            $releaseRowsAffected = $this->requiredNonNegativeInt($writeCommit, 'release_rows_affected');
            $collectionRowsAffected = $this->requiredNonNegativeInt($writeCommit, 'collection_rows_affected');
            $validated['deleted_release_ids'] = $this->validatedReleaseIds(
                $writeCommit['deleted_release_ids'] ?? null,
                $releaseRowsAffected,
                'deleted_release_ids',
            );
            $validated['deleted_collection_ids'] = $this->validatedReleaseIds(
                $writeCommit['deleted_collection_ids'] ?? null,
                $collectionRowsAffected,
                'deleted_collection_ids',
            );
            $validated['release_file_cleanup_rows_enqueued'] = $this->requiredNonNegativeInt($writeCommit, 'release_file_cleanup_rows_enqueued');
            if ($validated['release_file_cleanup_rows_enqueued'] !== $releaseRowsAffected) {
                throw new InvalidArgumentException('invalid native lane commit report: release file cleanup side-effect count mismatch');
            }
        }

        return $validated;
    }

    /**
     * @return list<int>
     */
    private function validatedCommittedReleaseIds(mixed $committedReleaseIds, int $expectedCount): array
    {
        return $this->validatedReleaseIds($committedReleaseIds, $expectedCount, 'committed_release_ids');
    }

    /**
     * @return list<int>
     */
    private function validatedReleaseIds(mixed $releaseIds, int $expectedCount, string $field): array
    {
        if (! is_array($releaseIds)) {
            throw new InvalidArgumentException("invalid native lane commit report: {$field} must be an array");
        }
        if (count($releaseIds) !== $expectedCount) {
            throw new InvalidArgumentException("invalid native lane commit report: {$field} count mismatch");
        }

        $validated = [];
        $seenReleaseIds = [];
        foreach ($releaseIds as $releaseId) {
            if (! is_int($releaseId) || $releaseId <= 0) {
                throw new InvalidArgumentException("invalid native lane commit report: {$field} must contain positive integers");
            }
            if (isset($seenReleaseIds[$releaseId])) {
                throw new InvalidArgumentException("invalid native lane commit report: duplicate {$field} value [{$releaseId}]");
            }

            $seenReleaseIds[$releaseId] = true;
            $validated[] = $releaseId;
        }

        sort($validated, SORT_NUMERIC);

        return $validated;
    }

    /**
     * @param  list<int>  $releaseIds
     * @return array{search_updates_seen: int, search_updates_synced: int, search_updates_failed: int}
     */
    private function syncNativeDeletedReleaseSearchSideEffects(array $releaseIds): array
    {
        $synced = 0;
        $failed = 0;

        foreach ($releaseIds as $releaseId) {
            try {
                Search::deleteRelease($releaseId);
                $synced++;
            } catch (Throwable) {
                $failed++;
                break;
            }
        }

        return [
            'search_updates_seen' => count($releaseIds),
            'search_updates_synced' => $synced,
            'search_updates_failed' => $failed,
        ];
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array{collection_ids_seen: int, collections_deleted: int, collection_cleanup_failed: int}
     */
    private function syncNativeRemoveCrapCollectionCleanupSideEffects(array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [
                'collection_ids_seen' => 0,
                'collections_deleted' => 0,
                'collection_cleanup_failed' => 0,
            ];
        }

        try {
            $deleted = app(CollectionCleanupService::class)->deleteCollectionsAndDescendants(
                $collectionIds,
                'Native removecrap descendant cleanup',
                false,
            );
        } catch (Throwable) {
            return [
                'collection_ids_seen' => count($collectionIds),
                'collections_deleted' => 0,
                'collection_cleanup_failed' => 1,
            ];
        }

        return [
            'collection_ids_seen' => count($collectionIds),
            'collections_deleted' => $deleted,
            'collection_cleanup_failed' => 0,
        ];
    }

    /**
     * @param  list<int>  $releaseIds
     * @return array{release_file_cleanup_seen: int, release_file_cleanup_cleaned: int, release_file_cleanup_failed: int}
     */
    private function syncNativeRemoveCrapFileCleanupSideEffects(array $releaseIds, int $expectedRows): array
    {
        if ($expectedRows === 0 && $releaseIds === []) {
            return [
                'release_file_cleanup_seen' => 0,
                'release_file_cleanup_cleaned' => 0,
                'release_file_cleanup_failed' => 0,
            ];
        }

        $rows = DB::table('native_worker_side_effects')
            ->where('job', 'removecrap')
            ->where('effect', 'release-file-cleanup')
            ->where('status', 'pending')
            ->whereIn('release_id', $releaseIds)
            ->orderBy('release_id')
            ->get();

        if ($rows->count() !== $expectedRows) {
            return [
                'release_file_cleanup_seen' => $rows->count(),
                'release_file_cleanup_cleaned' => 0,
                'release_file_cleanup_failed' => 1,
            ];
        }

        $cleaned = 0;
        foreach ($rows as $row) {
            $guid = (string) ($row->payload_text ?? '');
            if ($guid === ''
                || $row->status_column !== 'release_guid'
                || $row->status_reason !== 'delete-release-files'
                || (int) $row->status_value !== 1) {
                $this->markNativeRemoveCrapFileCleanupFailed((int) $row->id);

                return [
                    'release_file_cleanup_seen' => $rows->count(),
                    'release_file_cleanup_cleaned' => $cleaned,
                    'release_file_cleanup_failed' => 1,
                ];
            }

            DB::table('native_worker_side_effects')
                ->where('id', (int) $row->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'processing',
                    'attempts' => DB::raw('attempts + 1'),
                    'available_at' => now()->addMinutes(5),
                    'updated_at' => now(),
                ]);

            try {
                app(NzbService::class)->deleteNzb($guid);
                app(ReleaseImageService::class)->delete($guid);
            } catch (Throwable) {
                $this->markNativeRemoveCrapFileCleanupFailed((int) $row->id);

                return [
                    'release_file_cleanup_seen' => $rows->count(),
                    'release_file_cleanup_cleaned' => $cleaned,
                    'release_file_cleanup_failed' => 1,
                ];
            }

            DB::table('native_worker_side_effects')
                ->where('id', (int) $row->id)
                ->where('status', 'processing')
                ->update([
                    'status' => 'synced',
                    'available_at' => null,
                    'processed_at' => now(),
                    'last_error_code' => null,
                    'updated_at' => now(),
                ]);
            $cleaned++;
        }

        return [
            'release_file_cleanup_seen' => $rows->count(),
            'release_file_cleanup_cleaned' => $cleaned,
            'release_file_cleanup_failed' => 0,
        ];
    }

    private function markNativeRemoveCrapFileCleanupFailed(int $rowId): void
    {
        DB::table('native_worker_side_effects')
            ->where('id', $rowId)
            ->update([
                'status' => 'failed',
                'available_at' => null,
                'processed_at' => now(),
                'last_error_code' => 'release-file-cleanup-failed',
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<int>  $releaseIds
     * @return array{search_updates_seen: int, search_updates_synced: int, search_updates_failed: int}
     */
    private function syncNativeReleaseSearchSideEffects(array $releaseIds): array
    {
        $synced = 0;
        $failed = 0;

        foreach ($releaseIds as $releaseId) {
            try {
                ReleaseSearchIndexSync::forIds([$releaseId]);
                $synced++;
            } catch (Throwable) {
                $failed++;
                break;
            }
        }

        return [
            'search_updates_seen' => count($releaseIds),
            'search_updates_synced' => $synced,
            'search_updates_failed' => $failed,
        ];
    }

    /**
     * @param  list<int>  $releaseIds
     * @return array{search_updates_seen: int, search_updates_synced: int, search_updates_failed: int}
     */
    private function syncNativePostprocessSearchSideEffects(array $releaseIds): array
    {
        return $this->syncNativeReleaseSearchSideEffects($releaseIds);
    }

    private function isNativePostprocessCommitJob(string $job): bool
    {
        return in_array($job, ['post-tv', 'post-movies', 'post-amazon', 'post-additional'], true);
    }

    private function nativeLaneCommitReportKey(string $job): string
    {
        return match ($job) {
            'binaries' => 'binaries_write_commit',
            'backfill' => 'backfill_write_commit',
            'releases' => 'releases_write_commit',
            'per-group' => 'per_group_write_commit',
            'removecrap' => 'removecrap_write_commit',
            'metadata-refresh' => 'metadata_refresh_write_commit',
            'fixnames' => 'fixnames_write_commit',
            'post-tv', 'post-movies', 'post-amazon', 'post-additional' => 'postprocess_write_commit',
            default => throw new InvalidArgumentException('invalid native lane commit report: unsupported commit job'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredNonNegativeInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("invalid native commit report: {$key} must be a non-negative integer");
        }

        return $value;
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
    private function runNativeShadowValidation(array $plan, int $lockSeconds, OutputInterface $output): void
    {
        if (! (bool) config('nntmux.native_worker_shadow_enabled', false)) {
            return;
        }

        try {
            $nativePlan = $this->nativeWorkerPlanExporter->export($plan, $lockSeconds);
            $result = $this->nativeWorkerShadowRunner->dryRun($nativePlan);
        } catch (Throwable $e) {
            $output->writeln(sprintf(
                '[%s] native shadow failed for %s: %s; continuing with PHP worker',
                now()->toDateTimeString(),
                $plan['name'],
                $this->limitNativeWorkerMessage($e->getMessage()),
            ));

            return;
        }

        if ($result->successful) {
            $output->writeln(sprintf('[%s] native shadow validated %s', now()->toDateTimeString(), $plan['name']));

            return;
        }

        $output->writeln(sprintf(
            '[%s] native shadow failed for %s: %s; continuing with PHP worker',
            now()->toDateTimeString(),
            $plan['name'],
            $this->limitNativeWorkerMessage($result->message()),
        ));
    }

    private function limitNativeWorkerMessage(string $message): string
    {
        $message = $this->redactNativeWorkerMessage($message);

        $bytes = (int) config('nntmux.native_worker_log_stderr_bytes', 2048);
        $bytes = $bytes > 0 ? $bytes : 2048;

        if (strlen($message) <= $bytes) {
            return $message;
        }

        return substr($message, 0, $bytes).sprintf("\n[truncated to %d bytes]", $bytes);
    }

    private function redactNativeWorkerMessage(string $message): string
    {
        foreach ([
            config('nntmux.native_worker_mysql_dsn'),
            config('nntmux.native_worker_redis_addr'),
        ] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        $patterns = [
            '/--(mysql-dsn|redis-addr|lock-owner)(?:=|\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/' => '--$1 [redacted]',
            '/\b(mysql_dsn|mysql-dsn|redis_addr|redis-addr|redis_key|lock_owner|lock-owner|dsn|address|release_name)=(?:"[^"]*"|\'[^\']*\'|\S+)/i' => '$1=[redacted]',
            '/"arguments"\s*:\s*\{[^}]*\}/' => '"native_args":"[redacted]"',
            '/"arguments"\s*:\s*\{[^\s]*/' => '"native_args":"[redacted]"',
            '/"failures"\s*:\s*\[[^\]]*\]/' => '"native_failures":"[redacted]"',
            '/"failures"\s*:\s*\[[^\s]*/' => '"native_failures":"[redacted]"',
            '/"(redis_key|lock_owner|mysql_dsn|mysql-dsn|redis_addr|redis-addr|dsn|address|old_name|new_name|release_name|searchname|filename|fromname)"\s*:\s*"([^"\\\\]|\\\\.)*"/i' => '"native_field":"[redacted]"',
            '/"(redis_key|lock_owner|mysql_dsn|mysql-dsn|redis_addr|redis-addr|dsn|address|old_name|new_name|release_name|searchname|filename|fromname)"\s*:\s*"[^"\s]*/i' => '"native_field":"[redacted]"',
            '/nntmux_database[^\s"\',;}]+/' => '[redacted]',
            '/[A-Za-z0-9_.-]+:[^@\s"\']+@tcp\([^)]+\)\/[^\s"\']+/' => '[redacted]',
            '/[A-Za-z0-9_.-]+:[^@\s"\']+@tcp\([^\s"\']*/' => '[redacted]',
            '/\bdsn\s+[A-Za-z0-9_.-]+:[^\s"\']+/i' => 'dsn [redacted]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $redacted = preg_replace($pattern, $replacement, $message);
            if (is_string($redacted)) {
                $message = $redacted;
            }
        }

        return $message;
    }

    private function nativeSearchOutboxLimit(): int
    {
        $limit = (int) config('nntmux.native_worker_search_outbox_limit', 100);

        return max(1, $limit);
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

            pcntl_signal($signal, function (int $receivedSignal) use ($lock, $lockName, $job, $output): void {
                $output->writeln($this->formatTerminationSignalMessage($receivedSignal, $job, $lockName));

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
