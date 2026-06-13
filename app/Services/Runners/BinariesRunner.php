<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Settings;
use Illuminate\Support\Facades\DB;

class BinariesRunner extends BaseRunner
{
    public function binaries(int $maxPerGroup): void
    {
        $work = DB::select(
            sprintf(
                'SELECT name, %d AS max FROM usenet_groups WHERE active = 1',
                $maxPerGroup
            )
        );

        $maxProcesses = (int) Settings::settingValue('binarythreads');

        $count = count($work);
        if ($count === 0) {
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($work as $group) {
                $commands[] = PHP_BINARY.' artisan update:binaries '.$group->name.' '.$group->max;
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'binaries'); // @phpstan-ignore argument.type

            return;
        }

        $this->headerStart('binaries', $count, $maxProcesses);

        // Build commands array for parallel execution
        $commands = [];
        foreach ($work as $group) {
            $commands[$group->name] = PHP_BINARY.' artisan update:binaries '.$group->name.' '.$group->max;
        }

        // Process using parallel commands with configurable timeout
        $results = $this->runParallelCommands($commands, $maxProcesses);

        foreach ($results as $groupName => $output) {
            echo $output;
            cli()->primary('Updated group '.$groupName);
        }
    }

    public function safeBinaries(): void
    {
        // update group stats - Updated to use new script location (modernized)
        $this->executeCommand(PHP_BINARY.' app/Services/Tmux/Scripts/update_groups.php');

        $maxHeaders = (int) Settings::settingValue('max_headers_iteration') ?: 1000000;
        $maxMessages = (int) Settings::settingValue('maxmssgs');

        // Prevent division by zero - ensure maxmssgs is at least 1
        if ($maxMessages < 1) {
            $defaultMaxMessages = 20000;
            cli()->warning('maxmssgs setting is invalid or not set, using default of '.$defaultMaxMessages);
            $maxMessages = $defaultMaxMessages;
        }

        $maxProcesses = (int) Settings::settingValue('binarythreads');

        $groups = DB::select(
            '
            SELECT g.name AS groupname, g.last_record AS our_last,
                a.last_record AS their_last
            FROM usenet_groups g
            INNER JOIN short_groups a ON g.active = 1 AND g.name = a.name
            ORDER BY a.last_record DESC'
        );

        if (empty($groups)) {
            $this->headerNone();

            return;
        }

        $i = 1;
        $queues = [];
        foreach ($groups as $group) {
            foreach ($this->safeBinaryQueueEntries($group, $maxMessages, $maxHeaders) as $queueFactory) {
                $queues[$i] = $queueFactory($i);
                $i++;
            }
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($queues as $queue) {
                $commands[] = $this->buildDnrCommand($queue);
            }
            $this->runStreamingCommands($commands, $maxProcesses, 'safe_binaries'); // @phpstan-ignore argument.type

            return;
        }

        $this->headerStart('safe_binaries', count($queues), $maxProcesses);

        // Build commands array with group info for parallel execution
        $commands = [];
        $groupMapping = [];
        foreach ($queues as $idx => $queue) {
            preg_match('/alt\..+/i', $queue, $hit);
            $commands[$idx] = $this->buildDnrCommand($queue);
            $groupMapping[$idx] = $hit[0] ?? '';
        }

        // Process using parallel commands with configurable timeout
        $results = $this->runParallelCommands($commands, $maxProcesses);

        foreach ($results as $idx => $output) {
            $group = $groupMapping[$idx] ?? '';
            if (! empty($group)) {
                echo $output;
                cli()->primary('Updated group '.$group);
            }
        }
    }

    /**
     * @return list<callable(int): string>
     */
    public function safeBinaryQueueEntries(object $group, int $maxMessages, int $maxHeaders): array
    {
        if ((int) $group->our_last === 0) {
            return [
                static fn (int $index): string => sprintf('update_group_headers  %s', $group->groupname),
            ];
        }

        $count = (int) $group->their_last - (int) $group->our_last - 20000; // skip first 20k
        if ($count <= $maxMessages * 2) {
            return [
                static fn (int $index): string => sprintf('update_group_headers  %s', $group->groupname),
            ];
        }

        $limit = min($count, $maxHeaders);
        $fullChunks = (int) floor($limit / $maxMessages);
        $remaining = (int) ($limit - $fullChunks * $maxMessages);

        $queues = [
            static fn (int $index): string => sprintf('part_repair  %s', $group->groupname),
        ];

        for ($j = 0; $j < $fullChunks; $j++) {
            $start = (int) $group->our_last + $j * $maxMessages + 1;
            $end = (int) $group->our_last + $j * $maxMessages + $maxMessages;
            $queues[] = static fn (int $index): string => sprintf(
                'get_range  binaries  %s  %s  %s  %s',
                $group->groupname,
                $start,
                $end,
                $index
            );
        }

        if ($remaining > 0) {
            $start = (int) $group->our_last + $fullChunks * $maxMessages + 1;
            $end = $start + $remaining - 1;
            $queues[] = static fn (int $index): string => sprintf(
                'get_range  binaries  %s  %s  %s  %s',
                $group->groupname,
                $start,
                $end,
                $index
            );
        }

        return $queues;
    }
}
