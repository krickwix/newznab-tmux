<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BackfillRunner extends BaseRunner
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function backfill(array $options = []): void
    {
        $select = 'SELECT name';
        if (($options[0] ?? false) !== false) {
            $select .= ', '.$options[0].' AS max';
        }
        $select .= ' FROM usenet_groups WHERE backfill = 1';
        $work = DB::select($select);

        $maxProcesses = (int) Settings::settingValue('backfillthreads');

        $count = count($work);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($work as $group) {
                $commands[] = PHP_BINARY.' artisan update:backfill '.$group->name.(isset($group->max) ? (' '.$group->max) : '');
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'backfill');

            return;
        }

        $this->headerStart('backfill', $count, $maxProcesses);

        // Build commands array for parallel execution
        $commands = [];
        foreach ($work as $group) {
            $commands[$group->name] = PHP_BINARY.' artisan update:backfill '.$group->name.(isset($group->max) ? (' '.$group->max) : '');
        }

        // Process using parallel commands with configurable timeout
        $results = $this->runParallelCommands($commands, $maxProcesses);

        foreach ($results as $groupName => $output) {
            echo $output;
            cli()->primary('Backfilled group '.$groupName);
        }
    }

    public function safeBackfill(): void
    {
        // make sure short_groups is up-to-date - Updated to use new script location (modernized)
        $this->executeCommand(PHP_BINARY.' app/Services/Tmux/Scripts/update_groups.php');

        $backfill_qty = (int) Settings::settingValue('backfill_qty');
        $backfill_days = (int) Settings::settingValue('backfill_days');
        $backfill_groups = max(1, (int) Settings::settingValue('backfill_groups'));
        $maxMessages = (int) Settings::settingValue('maxmssgs');
        $threads = (int) Settings::settingValue('backfillthreads');

        $backfilldays = '0';
        if ($backfill_days === 1) {
            $backfilldays = 'g.backfill_target';
        } elseif ($backfill_days === 2) {
            $backfilldays = (string) now()->diffInDays(Carbon::createFromFormat('Y-m-d', Settings::settingValue('safebackfilldate')), true);
        }

        $sql = 'SELECT g.name,
                g.first_record AS our_first,
                g.first_record_postdate AS cursor_postdate,
                MAX(a.first_record) AS their_first,
                MAX(a.last_record) AS their_last
            FROM usenet_groups g
            INNER JOIN short_groups a ON g.name = a.name
            WHERE g.first_record IS NOT NULL
            AND g.first_record_postdate IS NOT NULL
            AND g.backfill = 1
            AND (NOW() - INTERVAL '.$backfilldays.' DAY ) < g.first_record_postdate
            AND (CAST(g.first_record AS SIGNED) - CAST(a.first_record AS SIGNED)) > 0
            GROUP BY a.name, a.last_record, g.name, g.first_record, g.first_record_postdate
            ORDER BY g.first_record_postdate DESC, g.name ASC
            LIMIT '.$backfill_groups;

        $data = DB::select($sql);

        if (empty($data)) {
            $this->headerNone();

            return;
        }

        [$queues, $queueGroups] = $this->buildSafeBackfillQueues($data, $backfill_qty, $maxMessages, $threads);

        if ($queues === []) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($queues as $queue) {
                $commands[] = $this->buildDnrCommand($queue);
            }
            $this->runStreamingCommands($commands, $threads, 'safe_backfill');

            return;
        }

        $this->headerStart('safe_backfill', count($queues), $threads);

        // Build commands array for parallel execution
        $commands = [];
        foreach ($queues as $idx => $queue) {
            $commands[$idx] = $this->buildDnrCommand($queue);
        }

        // Process using parallel commands with configurable timeout
        $results = $this->runParallelCommands($commands, $threads);

        foreach ($results as $idx => $output) {
            echo $output;
            cli()->primary('Backfilled group '.$queueGroups[$idx]);
        }
    }

    /**
     * @param  array<int, object>  $data
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    protected function buildSafeBackfillQueues(array $data, int $backfillQty, int $maxMessages, int $threads): array
    {
        $queuesByChunk = [];
        $queueGroupsByChunk = [];
        foreach ($data as $group) {
            $ourFirst = (int) $group->our_first;
            $theirFirst = max(1, (int) $group->their_first);
            $count = $ourFirst - $theirFirst;

            if ($count <= 0) {
                if (config('nntmux.echocli')) {
                    cli()->primary('No backfill needed for group '.$group->name);
                }

                continue;
            }

            $getEach = ($count > ($backfillQty * $threads))
                ? (int) ceil(($backfillQty * $threads) / $maxMessages)
                : (int) ceil($count / $maxMessages);

            for ($i = 0; $i <= $getEach - 1; $i++) {
                $end = $ourFirst - $i * $maxMessages - 1;
                $start = max($theirFirst, $end - $maxMessages + 1);
                if ($end < $theirFirst || $start > $end) {
                    continue;
                }

                $key = $group->name.'#'.($i + 1);
                $queuesByChunk[$i][$key] = sprintf('get_range  backfill  %s  %s  %s  %s', $group->name, $start, $end, $i + 1);
                $queueGroupsByChunk[$i][$key] = $group->name;
            }
        }

        $queues = [];
        $queueGroups = [];
        ksort($queuesByChunk);
        foreach ($queuesByChunk as $chunk => $chunkQueues) {
            foreach ($chunkQueues as $key => $queue) {
                $queues[$key] = $queue;
                $queueGroups[$key] = $queueGroupsByChunk[$chunk][$key];
            }
        }

        return [$queues, $queueGroups];
    }
}
