<?php

declare(strict_types=1);

namespace App\Services\Distributed;

class DistributedJobCatalog
{
    private const int HASHED_FIXNAMES_THRESHOLD = 100;

    /**
     * @return array<string, string>
     */
    public function jobs(): array
    {
        return [
            'binaries' => 'Download new headers for active groups',
            'backfill' => 'Backfill enabled groups',
            'releases' => 'Create and categorize releases',
            'nzb-backlog' => 'Create missing NZB files in a bounded independent lane',
            'fixnames' => 'Run release name fixing passes',
            'hashed-fixnames' => 'Run full-history name fixing passes for Other > Hashed backlogs',
            'removecrap' => 'Remove configured unwanted releases',
            'post-additional' => 'Run additional and/or NFO post-processing',
            'metadata-refresh' => 'Refresh external release-name evidence and run strong fix-name passes',
            'post-tv' => 'Run TV and anime post-processing',
            'post-movies' => 'Run movie post-processing',
            'post-amazon' => 'Run books, music, console, and games post-processing',
            'irc' => 'Run the IRC scraper',
            'per-group' => 'Run the per-group all-in-one processing worker',
        ];
    }

    /**
     * @param  array{
     *     settings?: array<string, mixed>,
     *     constants?: array<string, mixed>,
     *     counts?: array{now?: array<string, mixed>},
     *     killswitch?: array<string, mixed>
     * }  $runVar
     * @return array{
     *     name: string,
     *     description: string,
     *     enabled: bool,
     *     disabled_reason: string|null,
     *     commands: list<array{command: string, arguments: array<string, mixed>}>,
     *     sleep: int
     * }
     */
    public function resolve(string $job, array $runVar): array
    {
        if (! array_key_exists($job, $this->jobs())) {
            throw new \InvalidArgumentException("Unknown distributed job [{$job}].");
        }

        $settings = $runVar['settings'] ?? [];
        $constants = $runVar['constants'] ?? [];
        $counts = $runVar['counts']['now'] ?? [];
        $killswitch = $runVar['killswitch'] ?? [];
        $sequential = (int) ($constants['sequential'] ?? 0);

        if ($modeDisabled = $this->disabledBySequentialMode($job, $sequential, $settings)) {
            return $modeDisabled;
        }

        return match ($job) {
            'binaries' => $this->binaries($settings, $killswitch),
            'backfill' => $this->backfill($settings, $counts, $killswitch),
            'releases' => $this->simple(
                $job,
                (int) ($settings['releases_run'] ?? 0) > 0,
                'disabled in settings',
                'multiprocessing:releases',
                [],
                $this->timer($settings, 'rel_timer', 60)
            ),
            'nzb-backlog' => $this->simple(
                $job,
                true,
                null,
                'nntmux:nzb-create-backlog',
                [
                    '--limit' => $this->nzbLimit($settings),
                    '--order' => 'desc',
                ],
                $this->nzbSleep($settings)
            ),
            'fixnames' => $this->fixNames($settings, $counts),
            'hashed-fixnames' => $this->hashedFixNames($settings, $counts),
            'removecrap' => $this->removeCrap($settings),
            'post-additional' => $this->postAdditional($settings, $counts),
            'metadata-refresh' => $this->metadataRefresh($settings, $counts),
            'post-tv' => $this->postTv($settings, $counts),
            'post-movies' => $this->postMovies($settings, $counts),
            'post-amazon' => $this->postAmazon($settings, $counts),
            'irc' => $this->simple(
                $job,
                (int) ($constants['run_ircscraper'] ?? 0) === 1,
                'disabled in settings',
                'irc:scrape',
                [],
                (int) ($settings['monitor'] ?? config('tmux.monitor.delay', 10))
            ),
            'per-group' => $this->simple(
                $job,
                $sequential === 2,
                'only enabled when sequential mode is 2',
                'multiprocessing:update-per-group',
                [],
                (int) ($settings['seq_timer'] ?? 300)
            ),
            default => throw new \InvalidArgumentException("Unknown distributed job [{$job}]."),
        };
    }

    /**
     * Keep distributed lanes aligned with the tmux monitor's mode routing.
     *
     * Mode 0 runs the full pane set, mode 1 runs release/post-processing panes
     * only, and mode 2 delegates work to the per-group worker.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function disabledBySequentialMode(string $job, int $sequential, array $settings): ?array
    {
        $sleep = match ($job) {
            'binaries' => $this->timer($settings, 'bins_timer', 60),
            'backfill' => $this->backfillSleep($settings, []),
            'releases' => $this->timer($settings, 'rel_timer', 60),
            'nzb-backlog' => $this->nzbSleep($settings),
            'fixnames', 'hashed-fixnames' => (int) ($settings['fix_timer'] ?? 300),
            'removecrap' => (int) ($settings['crap_timer'] ?? 300),
            'post-additional' => (int) ($settings['post_timer'] ?? 300),
            'metadata-refresh' => (int) ($settings['metadata_refresh_timer'] ?? config('external_metadata.timer', 900)),
            'post-tv', 'post-movies' => (int) ($settings['post_timer_non'] ?? 300),
            'post-amazon' => (int) ($settings['post_timer_amazon'] ?? 300),
            'per-group' => (int) ($settings['seq_timer'] ?? 300),
            default => 300,
        };

        if (in_array($job, ['irc', 'nzb-backlog'], true)) {
            return null;
        }

        if ($sequential === 2 && $job !== 'per-group') {
            return $this->disabled($job, 'disabled by sequential mode 2', $sleep);
        }

        if ($sequential === 1 && in_array($job, ['binaries', 'backfill', 'per-group'], true)) {
            return $this->disabled($job, 'disabled by sequential mode 1', $sleep);
        }

        if ($sequential !== 2 && $job === 'per-group') {
            return $this->disabled($job, 'only enabled when sequential mode is 2', $sleep);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $killswitch
     * @return array<string, mixed>
     */
    private function binaries(array $settings, array $killswitch): array
    {
        $enabled = (int) ($settings['binaries_run'] ?? 0);

        if ($enabled !== 1) {
            return $this->disabled('binaries', 'disabled in settings', $this->timer($settings, 'bins_timer', 60));
        }

        if (($killswitch['pp'] ?? false) === true) {
            return $this->disabled('binaries', 'postprocess kill limit exceeded', $this->timer($settings, 'bins_timer', 60));
        }

        return $this->simple(
            'binaries',
            true,
            null,
            'multiprocessing:safe',
            ['type' => 'binaries'],
            $this->timer($settings, 'bins_timer', 60)
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $killswitch
     * @return array<string, mixed>
     */
    private function backfill(array $settings, array $counts, array $killswitch): array
    {
        $enabled = (int) ($settings['backfill'] ?? 0);
        $sleep = $this->backfillSleep($settings, $counts);

        if ($this->isOrchestratorManaged($settings) && ! $this->hasFreshActiveBackfillPermit($settings)) {
            return $this->disabled('backfill', 'adaptive orchestrator has not granted a fresh permit', $sleep);
        }

        if ($enabled === 0) {
            return $this->disabled('backfill', 'disabled in settings', $sleep);
        }

        if (($killswitch['coll'] ?? false) === true || ($killswitch['pp'] ?? false) === true) {
            return $this->disabled('backfill', 'kill limit exceeded', $sleep);
        }

        $groupWork = $this->backfillGroupWorkCount($settings, $counts);
        if ($groupWork !== null && $groupWork === 0) {
            return $this->disabled('backfill', 'no backfill groups to process', $sleep);
        }

        return $this->simple('backfill', true, null, 'multiprocessing:safe', ['type' => 'backfill'], $sleep);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     */
    private function backfillGroupWorkCount(array $settings, array $counts): ?int
    {
        $countKey = ((int) ($settings['backfill_days'] ?? 1)) === 2
            ? 'backfill_groups_date'
            : 'backfill_groups_days';

        if (! array_key_exists($countKey, $counts)) {
            return null;
        }

        return (int) $counts[$countKey];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     */
    private function backfillSleep(array $settings, array $counts): int
    {
        $baseSleep = $this->timer($settings, 'back_timer', 600);
        $collections = (int) ($counts['collections_table'] ?? 0);
        $progressive = (int) ($settings['progressive'] ?? 0);

        if ($progressive === 1 && floor($collections / 500) > $baseSleep) {
            return (int) floor($collections / 500);
        }

        return $baseSleep;
    }

    /** @param array<string, mixed> $settings */
    private function timer(array $settings, string $name, int $default): int
    {
        $static = max(1, (int) ($settings[$name] ?? $default));
        if (! $this->hasFreshActiveLease($settings)) {
            return $this->isOrchestratorTimerManaged($settings) ? $this->failSafeTimer($name) : $static;
        }

        return max(1, (int) ($settings['orchestrator_'.$name] ?? $static));
    }

    /** @param array<string, mixed> $settings */
    private function nzbSleep(array $settings): int
    {
        $static = max(1, (int) config('nntmux.distributed_nzb_sleep', 60));
        if (! $this->hasFreshActiveLease($settings)) {
            return $this->isOrchestratorTimerManaged($settings) ? 180 : $static;
        }

        return max(20, min(180, (int) ($settings['orchestrator_nzb_timer'] ?? $static)));
    }

    /** @param array<string, mixed> $settings */
    private function nzbLimit(array $settings): int
    {
        $static = max(1, (int) config('nntmux.distributed_nzb_limit', 1));
        if (! $this->hasFreshActiveLease($settings)) {
            return $this->isOrchestratorTimerManaged($settings) ? min(5, $static) : $static;
        }

        return max(5, min(20, (int) ($settings['orchestrator_nzb_limit'] ?? $static)));
    }

    /** @param array<string, mixed> $settings */
    private function hasFreshActiveBackfillPermit(array $settings): bool
    {
        return $this->hasFreshActiveLease($settings)
            && (int) ($settings['orchestrator_backfill_paused'] ?? 1) === 0
            && (int) ($settings['orchestrator_backfill_permit'] ?? 0) > 0;
    }

    /** @param array<string, mixed> $settings */
    private function hasFreshActiveLease(array $settings): bool
    {
        return (string) ($settings['orchestrator_mode'] ?? '') === 'active'
            && (int) ($settings['orchestrator_lease_until'] ?? 0) >= time();
    }

    /** @param array<string, mixed> $settings */
    private function isOrchestratorManaged(array $settings): bool
    {
        return in_array((string) ($settings['orchestrator_mode'] ?? ''), ['shadow', 'active', 'failsafe'], true);
    }

    /** @param array<string, mixed> $settings */
    private function isOrchestratorTimerManaged(array $settings): bool
    {
        return in_array((string) ($settings['orchestrator_mode'] ?? ''), ['active', 'failsafe'], true);
    }

    private function failSafeTimer(string $name): int
    {
        return match ($name) {
            'bins_timer' => 300,
            'back_timer' => 1800,
            'rel_timer' => 180,
            default => 300,
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function fixNames(array $settings, array $counts): array
    {
        $sleep = (int) ($settings['fix_timer'] ?? 300);

        if ((int) ($settings['fix_names'] ?? 0) !== 1) {
            return $this->disabled('fixnames', 'disabled in settings', $sleep);
        }

        if ((int) ($counts['processrenames'] ?? 0) === 0) {
            return $this->disabled('fixnames', 'no releases to process', $sleep);
        }

        $commands = [];
        foreach ([3, 4, 5, 6] as $method) {
            $commands[] = [
                'command' => 'releases:fix-names',
                'arguments' => [
                    'method' => (string) $method,
                    '--update' => true,
                    '--category' => 'other',
                    '--set-status' => true,
                    '--show' => true,
                ],
            ];
        }
        $commands[] = [
            'command' => 'releases:fix-names',
            'arguments' => [
                'method' => '6',
                '--update' => true,
                '--category' => 'movies',
                '--set-status' => true,
                '--limit' => 500,
                '--show' => true,
            ],
        ];
        $commands[] = [
            'command' => 'releases:fix-names',
            'arguments' => [
                'method' => '21',
                '--update' => true,
                '--category' => 'other',
                '--set-status' => true,
                '--limit' => 500,
                '--show' => true,
            ],
        ];
        $commands[] = [
            'command' => 'releases:fix-names',
            'arguments' => [
                'method' => '21',
                '--update' => true,
                '--category' => 'movies',
                '--set-status' => true,
                '--limit' => 500,
                '--show' => true,
            ],
        ];
        $commands[] = [
            'command' => 'releases:fix-names',
            'arguments' => [
                'method' => '8',
                '--update' => true,
                '--category' => 'other',
                '--set-status' => true,
                '--limit' => 50,
                '--show' => true,
            ],
        ];
        foreach ([7, 9, 11, 13, 15, 17, 19] as $method) {
            $commands[] = [
                'command' => 'releases:fix-names',
                'arguments' => [
                    'method' => (string) $method,
                    '--update' => true,
                    '--category' => 'other',
                    '--set-status' => true,
                    '--show' => true,
                ],
            ];
        }

        return $this->job('fixnames', true, null, $commands, $sleep);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function hashedFixNames(array $settings, array $counts): array
    {
        $sleep = (int) ($settings['fix_timer'] ?? 300);

        if ((int) ($settings['fix_names'] ?? 0) !== 1) {
            return $this->disabled('hashed-fixnames', 'disabled in settings', $sleep);
        }

        $hashedCount = (int) ($counts['other_hashed'] ?? 0);
        if ($hashedCount <= self::HASHED_FIXNAMES_THRESHOLD) {
            return $this->disabled(
                'hashed-fixnames',
                sprintf('Other > Hashed count %d is not greater than %d', $hashedCount, self::HASHED_FIXNAMES_THRESHOLD),
                $sleep
            );
        }

        $commands = [];
        foreach ([4, 6, 21, 18, 10, 14, 16, 20, 12, 8] as $method) {
            $commands[] = [
                'command' => 'releases:fix-names',
                'arguments' => [
                    'method' => (string) $method,
                    '--update' => true,
                    '--category' => 'hashed',
                    '--set-status' => true,
                    '--show' => true,
                ],
            ];
        }

        return $this->job('hashed-fixnames', true, null, $commands, $sleep);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function removeCrap(array $settings): array
    {
        $sleep = (int) ($settings['crap_timer'] ?? 300);
        $option = $settings['fix_crap_opt'] ?? 'Disabled';

        if ($option === 'Disabled' || $option === 0 || $option === '0') {
            return $this->disabled('removecrap', 'disabled in settings', $sleep);
        }

        if ($option === 'All') {
            return $this->simple(
                'removecrap',
                true,
                null,
                'releases:remove-crap',
                ['--time' => '2', '--delete' => true],
                $sleep
            );
        }

        if ($option !== 'Custom') {
            return $this->disabled('removecrap', 'invalid configuration', $sleep);
        }

        $types = $settings['fix_crap'] ?? '';
        $types = is_array($types) ? $types : explode(',', (string) $types);
        $types = array_values(array_filter(array_map('trim', $types)));

        if ($types === []) {
            return $this->disabled('removecrap', 'no crap types selected', $sleep);
        }

        $commands = [];
        foreach ($types as $type) {
            $commands[] = [
                'command' => 'releases:remove-crap',
                'arguments' => ['--type' => $type, '--time' => '4', '--delete' => true],
            ];
        }

        return $this->job('removecrap', true, null, $commands, $sleep);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function postAdditional(array $settings, array $counts): array
    {
        $post = (int) ($settings['post'] ?? 0);
        $sleep = (int) ($settings['post_timer'] ?? 300);

        if ($post === 0) {
            return $this->disabled('post-additional', 'disabled in settings', $sleep);
        }

        $commands = [];
        if (($post === 1 || $post === 3) && (int) ($counts['work'] ?? 0) > 0) {
            $commands[] = ['command' => 'multiprocessing:postprocess', 'arguments' => ['type' => 'add']];
        }
        if (($post === 2 || $post === 3) && (int) ($counts['processnfo'] ?? 0) > 0) {
            $commands[] = ['command' => 'multiprocessing:postprocess', 'arguments' => ['type' => 'nfo']];
        }

        if ($commands === []) {
            return $this->disabled('post-additional', 'no additional work or NFOs to process', $sleep);
        }

        array_push($commands, ...$this->postprocessMetadataRefreshCommands($settings, $counts));

        return $this->job('post-additional', true, null, $commands, $sleep);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function metadataRefresh(array $settings, array $counts): array
    {
        $sleep = (int) ($settings['metadata_refresh_timer'] ?? config('external_metadata.timer', 900));
        $enabled = (int) ($settings['metadata_refresh'] ?? ((bool) config('external_metadata.enabled', false) ? 1 : 0));

        if ($enabled !== 1) {
            return $this->disabled('metadata-refresh', 'disabled in settings', $sleep);
        }

        $commands = [$this->metadataRefreshCommand(
            (int) ($settings['metadata_refresh_limit'] ?? config('external_metadata.limit', 25)),
            (int) ($settings['metadata_refresh_sleep_ms'] ?? config('external_metadata.sleep_ms', 500)),
        )];

        array_push($commands, ...$this->strongHashedFixNameCommands($counts));

        return $this->job('metadata-refresh', true, null, $commands, $sleep);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @return list<array{command: string, arguments: array<string, mixed>}>
     */
    private function postprocessMetadataRefreshCommands(array $settings, array $counts): array
    {
        $enabled = (int) ($settings['metadata_refresh_postprocess'] ?? (
            (bool) config('external_metadata.postprocess_enabled', false) ? 1 : 0
        ));

        if ($enabled !== 1 || (int) ($counts['other_hashed'] ?? 0) <= self::HASHED_FIXNAMES_THRESHOLD) {
            return [];
        }

        return [
            $this->metadataRefreshCommand(
                (int) ($settings['metadata_refresh_postprocess_limit'] ?? config('external_metadata.postprocess_limit', 10)),
                (int) ($settings['metadata_refresh_sleep_ms'] ?? config('external_metadata.sleep_ms', 500)),
            ),
            ...$this->strongHashedFixNameCommands($counts),
        ];
    }

    /**
     * @return array{command: string, arguments: array<string, mixed>}
     */
    private function metadataRefreshCommand(int $limit, int $sleepMs): array
    {
        return [
            'command' => 'predb:refresh-external-metadata',
            'arguments' => [
                '--source' => ['all'],
                '--limit' => max(1, $limit),
                '--sleep-ms' => max(0, $sleepMs),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return list<array{command: string, arguments: array<string, mixed>}>
     */
    private function strongHashedFixNameCommands(array $counts): array
    {
        if ((int) ($counts['other_hashed'] ?? 0) > self::HASHED_FIXNAMES_THRESHOLD) {
            $commands = [];
            foreach ([20, 16] as $method) {
                $commands[] = [
                    'command' => 'releases:fix-names',
                    'arguments' => [
                        'method' => (string) $method,
                        '--update' => true,
                        '--category' => 'hashed',
                        '--set-status' => true,
                        '--limit' => 500,
                        '--show' => true,
                    ],
                ];
            }

            return $commands;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function postTv(array $settings, array $counts): array
    {
        $sleep = (int) ($settings['post_timer_non'] ?? 300);

        if ((int) ($settings['post_non'] ?? 0) !== 1) {
            return $this->disabled('post-tv', 'disabled in settings', $sleep);
        }

        $commands = [];
        if ((int) ($settings['processtvrage'] ?? 0) > 0 && (int) ($counts['processtv'] ?? 0) > 0) {
            $commands[] = ['command' => 'multiprocessing:postprocess', 'arguments' => ['type' => 'tv']];
        }
        if ((int) ($settings['processanime'] ?? 0) > 0 && (int) ($counts['processanime'] ?? 0) > 0) {
            $commands[] = ['command' => 'multiprocessing:postprocess', 'arguments' => ['type' => 'ani']];
        }

        if ($commands === []) {
            return $this->disabled('post-tv', 'no work for enabled types', $sleep);
        }

        return $this->job('post-tv', true, null, $commands, $sleep);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function postMovies(array $settings, array $counts): array
    {
        $sleep = (int) ($settings['post_timer_non'] ?? 300);

        if ((int) ($settings['post_non'] ?? 0) !== 1 || (int) ($settings['processmovies'] ?? 0) === 0) {
            return $this->disabled('post-movies', 'disabled in settings', $sleep);
        }

        if ((int) ($counts['processmovies'] ?? 0) === 0) {
            return $this->disabled('post-movies', 'no work available', $sleep);
        }

        return $this->simple(
            'post-movies',
            true,
            null,
            'multiprocessing:postprocess',
            ['type' => 'mov', 'renamed' => 'false'],
            $sleep
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function postAmazon(array $settings, array $counts): array
    {
        $sleep = (int) ($settings['post_timer_amazon'] ?? 300);

        if ((int) ($settings['post_amazon'] ?? 0) !== 1) {
            return $this->disabled('post-amazon', 'disabled in settings', $sleep);
        }

        $hasWork = (int) ($counts['processmusic'] ?? 0) > 0
            || (int) ($counts['processbooks'] ?? 0) > 0
            || (int) ($counts['processconsole'] ?? 0) > 0
            || (int) ($counts['processgames'] ?? 0) > 0;

        if (! $hasWork) {
            return $this->disabled('post-amazon', 'no music/books/games to process', $sleep);
        }

        return $this->simple(
            'post-amazon',
            true,
            null,
            'multiprocessing:postprocess',
            ['type' => 'ama'],
            $sleep
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function simple(string $job, bool $enabled, ?string $disabledReason, string $command, array $arguments, int $sleep): array
    {
        return $this->job(
            $job,
            $enabled,
            $enabled ? null : $disabledReason,
            $enabled ? [['command' => $command, 'arguments' => $arguments]] : [],
            $sleep
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function disabled(string $job, string $reason, int $sleep): array
    {
        return $this->job($job, false, $reason, [], $sleep);
    }

    /**
     * @param  list<array{command: string, arguments: array<string, mixed>}>  $commands
     * @return array<string, mixed>
     */
    private function job(string $job, bool $enabled, ?string $disabledReason, array $commands, int $sleep): array
    {
        $jobs = $this->jobs();

        return [
            'name' => $job,
            'description' => $jobs[$job],
            'enabled' => $enabled,
            'disabled_reason' => $disabledReason,
            'commands' => $commands,
            'sleep' => max(1, $sleep),
        ];
    }
}
