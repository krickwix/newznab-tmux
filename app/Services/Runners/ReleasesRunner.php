<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Settings;
use App\Models\UsenetGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleasesRunner extends BaseRunner
{
    /**
     * Per-group release readiness.
     *
     * The GROUP BY form this replaced aggregated every row of every active
     * group's collections (~650k rows through ix_collections_release_stage6,
     * plus "Using temporary; Using filesort") to answer two boolean questions,
     * and cost 1.5-2.5 s per call at 10.6 calls/min. The EXISTS form answers
     * each question with a short index probe per group: 66-79 ms measured on
     * live data, returning the same 15 rows in the same order with identical
     * actionable/ready values.
     *
     * The trailing guard reproduces the dropped INNER JOIN: a group with no
     * collections at all must not appear, because selectActionableGroups()
     * rotates its bounded idle sweep modulo the idle-group count, so padding
     * the list with collection-less groups would spend sweep slots launching
     * children that have nothing to process. It is written as a scalar
     * subquery rather than EXISTS on purpose -- EXISTS there makes the
     * optimizer materialize the whole groups_id index (~400 ms), while the
     * scalar form stays a per-group index probe.
     *
     * NOW() stays server-side: MariaDB's session clock runs on SYSTEM (UTC+1
     * here) while app.timezone is UTC, so computing the cutoff in PHP would
     * silently shift the 15-minute window by an hour.
     */
    public const string GROUP_READINESS_SQL = 'SELECT g.id, g.name,
                (EXISTS(SELECT 1 FROM collections c
                        WHERE c.groups_id = g.id AND c.filecheck IN (1, 2, 3, 15, 16))
                 OR EXISTS(SELECT 1 FROM collections c
                        WHERE c.groups_id = g.id AND c.filecheck = 0
                          AND c.dateadded >= (NOW() - INTERVAL 15 MINUTE))) AS actionable,
                EXISTS(SELECT 1 FROM collections c
                        WHERE c.groups_id = g.id AND c.filecheck = 3) AS ready
            FROM usenet_groups g
            WHERE (g.active = 1 OR g.backfill = 1)
              AND (SELECT 1 FROM collections c WHERE c.groups_id = g.id LIMIT 1) IS NOT NULL
            ORDER BY g.id';

    public function releases(): void
    {
        $rows = DB::select(self::GROUP_READINESS_SQL);
        $maxProcesses = (int) Settings::settingValue('releasethreads');
        $groups = array_map(static fn (object $group): array => [
            'id' => (int) $group->id,
            'name' => (string) $group->name,
        ], $rows);
        $actionableGroupIds = array_values(array_map(
            static fn (object $group): int => (int) $group->id,
            array_filter($rows, static fn (object $group): bool => (int) $group->actionable === 1),
        ));
        $readyGroupIds = array_values(array_map(
            static fn (object $group): int => (int) $group->id,
            array_filter($rows, static fn (object $group): bool => (int) $group->ready === 1),
        ));

        $uGroups = $this->selectActionableGroups(
            $groups,
            trim((string) Settings::settingValue('orchestrator_bfc_group')),
            $actionableGroupIds,
            $this->nextReleaseSweepOffset(),
            max(1, (int) config('nntmux.distributed_release_sweep_groups', 1)),
            $readyGroupIds,
        );

        $count = count($uGroups);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($uGroups as $group) {
                $commands[] = $this->buildDnrCommand('releases  '.$group['id']);
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'releases'); // @phpstan-ignore argument.type

            return;
        }

        $this->headerStart('releases', $count, $maxProcesses);

        // Process in batches using Laravel's native Concurrency facade
        $batches = array_chunk($uGroups, max(1, $maxProcesses));

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];
            foreach ($batch as $group) {
                $command = $this->buildDnrCommand('releases  '.$group['id']);
                $tasks[$group['id']] = fn () => $this->executeCommand($command);
            }

            try {
                $results = Concurrency::run($tasks, $this->concurrencyTimeout());

                foreach ($results as $groupId => $output) {
                    echo $output;
                    cli()->primary('Finished performing release processing for group ID: '.$groupId);
                }
            } catch (\Throwable $e) {
                Log::error('Release processing batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Put the group supplying the active immutable backfill permit first so its
     * collections reach release and NZB processing before unrelated backlog.
     * The remaining groups retain a stable ID/name order when no pin is active
     * or the pinned group has no eligible collections.
     *
     * @param  array<int, array{id: int|string, name: string}>  $groups
     * @return array<int, array{id: int|string, name: string}>
     */
    protected function prioritizePinnedGroup(array $groups, string $pinnedGroup): array
    {
        usort($groups, static function (array $left, array $right) use ($pinnedGroup): int {
            $leftPinned = $pinnedGroup !== '' && $left['name'] === $pinnedGroup;
            $rightPinned = $pinnedGroup !== '' && $right['name'] === $pinnedGroup;

            if ($leftPinned !== $rightPinned) {
                return $leftPinned ? -1 : 1;
            }

            $idOrder = (int) $left['id'] <=> (int) $right['id'];

            return $idOrder !== 0 ? $idOrder : $left['name'] <=> $right['name'];
        });

        return $groups;
    }

    /**
     * Select actionable groups plus a bounded rotating idle sweep. The sweep is
     * deliberately low-cardinality and prevents delayed/stuck cohorts from
     * starving without launching one child for every historical group.
     *
     * @param  array<int, array{id: int|string, name: string}>  $groups
     * @param  list<int>  $actionableGroupIds
     * @param  list<int>  $readyGroupIds
     * @return array<int, array{id: int|string, name: string}>
     */
    protected function selectActionableGroups(
        array $groups,
        string $pinnedGroup,
        array $actionableGroupIds,
        int $sweepOffset,
        int $sweepSize,
        array $readyGroupIds = [],
    ): array {
        $actionableLookup = array_fill_keys(array_map('intval', $actionableGroupIds), true);
        $readyLookup = array_fill_keys(array_map('intval', $readyGroupIds), true);
        $ready = [];
        $preparing = [];
        $idle = [];
        foreach ($groups as $group) {
            $groupId = (int) $group['id'];
            if (isset($readyLookup[$groupId])) {
                $ready[] = $group;
            } elseif (isset($actionableLookup[$groupId])) {
                $preparing[] = $group;
            } else {
                $idle[] = $group;
            }
        }

        $ready = $this->prioritizePinnedGroup($ready, $pinnedGroup);
        $preparing = $this->prioritizePinnedGroup($preparing, $pinnedGroup);
        $actionable = [...$ready, ...$preparing];
        $idle = $this->prioritizePinnedGroup($idle, '');
        if ($idle === [] || $sweepSize <= 0) {
            return $actionable;
        }

        $offset = max(0, $sweepOffset) % \count($idle);
        $rotated = [...array_slice($idle, $offset), ...array_slice($idle, 0, $offset)];

        return [...$actionable, ...array_slice($rotated, 0, min($sweepSize, \count($idle)))];
    }

    private function nextReleaseSweepOffset(): int
    {
        try {
            $store = Cache::store((string) config('nntmux.distributed_lock_store', 'redis'));
            $key = 'nntmux:releases:sweep-cursor';
            if (! $store->has($key)) {
                $store->forever($key, 0);
            }

            $current = max(0, (int) $store->get($key, 0));
            $store->forever($key, $current + 1);

            return $current;
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                Log::debug('Release sweep cursor unavailable: '.$e->getMessage());
            }

            return 0;
        }
    }

    public function updatePerGroup(): void
    {
        $groups = DB::select('SELECT id , name FROM usenet_groups WHERE (active = 1 OR backfill = 1)');
        $maxProcesses = (int) Settings::settingValue('releasethreads');

        $count = count($groups);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($groups as $group) {
                $commands[] = $this->buildDnrCommand('update_per_group  '.$group->id);
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'update_per_group'); // @phpstan-ignore argument.type

            return;
        }

        $this->headerStart('update_per_group', $count, $maxProcesses);

        // Process in batches using Laravel's native Concurrency facade
        $batches = array_chunk($groups, max(1, $maxProcesses));

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];
            foreach ($batch as $group) {
                $command = $this->buildDnrCommand('update_per_group  '.$group->id);
                $tasks[$group->id] = fn () => $this->executeCommand($command);
            }

            try {
                $results = Concurrency::run($tasks, $this->concurrencyTimeout());

                foreach ($results as $groupId => $output) {
                    echo $output;
                    $name = UsenetGroup::getNameByID($groupId);
                    cli()->primary('Finished updating binaries, processing releases and additional postprocessing for group: '.$name);
                }
            } catch (\Throwable $e) {
                Log::error('Update per group batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
            }
        }
    }

    public function fixRelNames(string $mode, int $maxPerRun, int $maxThreads): void
    {
        $maxThreads = max(1, min(16, $maxThreads));

        $leftGuids = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f'];

        if ($mode === 'predbft') {
            $preCount = DB::select(
                "SELECT COUNT(p.id) AS num FROM predb p WHERE LENGTH(p.title) >= 15 AND p.title NOT REGEXP '[\"\<\> ]' AND p.searched = 0 AND p.predate < (NOW() - INTERVAL 1 DAY)"
            );
            if (! empty($preCount) && (int) $preCount[0]->num > 0 && $maxPerRun > 0) {
                $leftGuids = \array_slice($leftGuids, 0, (int) ceil($preCount[0]->num / $maxPerRun));
            } else {
                $leftGuids = [];
            }
        }

        $queues = [];
        $idx = 0;
        foreach ($leftGuids as $leftGuid) {
            if ($maxPerRun > 0) {
                $idx++;
                $queues[$idx] = sprintf('%s %s %s %s', $mode, $leftGuid, $maxPerRun, $idx);
            }
        }

        $count = count($queues);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($queues as $queue) {
                // Updated to use new script location (modernized)
                $commands[] = PHP_BINARY.' app/Services/Tmux/Scripts/groupfixrelnames.php "'.$queue.'" true';
            }
            $this->runStreamingCommands($commands, $maxThreads, 'fixRelNames_'.$mode); // @phpstan-ignore argument.type

            return;
        }

        $this->headerStart('fixRelNames_'.$mode, $count, $maxThreads);

        // Process in batches using Laravel's native Concurrency facade
        $batches = array_chunk($queues, max(1, $maxThreads), true);

        foreach ($batches as $batchIndex => $batch) {
            $tasks = [];
            foreach ($batch as $idx => $queue) {
                // Updated to use new script location (modernized)
                $command = PHP_BINARY.' app/Services/Tmux/Scripts/groupfixrelnames.php "'.$queue.'" true';
                $tasks[$idx] = fn () => $this->executeCommand($command);
            }

            try {
                $results = Concurrency::run($tasks, $this->concurrencyTimeout());

                foreach ($results as $taskIdx => $output) {
                    echo $output;
                    cli()->primary('Task #'.$taskIdx.' Finished fixing releases names');
                }
            } catch (\Throwable $e) {
                Log::error('Fix rel names batch failed: '.$e->getMessage());
                cli()->error('Batch '.($batchIndex + 1).' failed: '.$e->getMessage());
            }
        }
    }
}
