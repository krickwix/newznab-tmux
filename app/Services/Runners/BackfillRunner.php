<?php

declare(strict_types=1);

namespace App\Services\Runners;

use App\Models\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                $commands[$group->name] = PHP_BINARY.' artisan update:backfill '.$group->name.(isset($group->max) ? (' '.$group->max) : '');
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
        $minimumSafeRange = $this->minimumSafeBackfillRange();
        $orchestratorGroup = trim((string) Settings::settingValue('orchestrator_bf_group'));
        $orchestratorGroupFilter = $orchestratorGroup === ''
            ? ''
            : ' AND g.name = '.DB::getPdo()->quote($orchestratorGroup);

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
            AND CAST(g.first_record AS SIGNED) > 0
            AND g.first_record_postdate IS NOT NULL
            AND g.backfill = 1
            '.$orchestratorGroupFilter.'
            AND (NOW() - INTERVAL '.$backfilldays.' DAY ) < g.first_record_postdate
            AND CAST(a.first_record AS SIGNED) > 0
            AND CAST(a.last_record AS SIGNED) >= CAST(a.first_record AS SIGNED)
            AND (CAST(g.first_record AS SIGNED) - CAST(a.first_record AS SIGNED)) >= '.$minimumSafeRange.'
            GROUP BY a.name, a.last_record, g.name, g.first_record, g.first_record_postdate
            ORDER BY g.first_record_postdate DESC, g.name ASC
            LIMIT '.$backfill_groups;

        $data = DB::select($sql);

        if (empty($data)) {
            $this->reportSafeBackfillNoWork($backfilldays);
            $this->headerNone();

            return;
        }

        [$queues, $queueGroups] = $this->buildSafeBackfillQueues($data, $backfill_qty, $maxMessages, $threads);

        if ($queues === []) {
            $this->reportSafeBackfillNoWork($backfilldays);
            $this->headerNone();

            return;
        }

        // Streaming mode
        if ((bool) config('nntmux.stream_fork_output', false) === true) {
            $commands = [];
            foreach ($queues as $idx => $queue) {
                $commands[$idx] = $this->buildDnrCommand($queue);
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
        if ($maxMessages < 1) {
            $maxMessages = 20000;
        }

        $queuesByChunk = [];
        $queueGroupsByChunk = [];
        foreach ($data as $group) {
            $ourFirst = (int) $group->our_first;
            $theirFirst = (int) $group->their_first;
            $theirLast = isset($group->their_last) ? (int) $group->their_last : $theirFirst;

            if ($ourFirst <= 0 || $theirFirst <= 0 || $theirLast < $theirFirst) {
                if (config('nntmux.echocli')) {
                    cli()->warning('Skipping invalid safe backfill cursor for group '.$group->name);
                }

                continue;
            }

            $count = $ourFirst - $theirFirst;

            if ($count <= 0) {
                if (config('nntmux.echocli')) {
                    cli()->primary('No backfill needed for group '.$group->name);
                }

                continue;
            }

            if ($count < $this->minimumSafeBackfillRange()) {
                if (config('nntmux.echocli')) {
                    cli()->warning('Skipping near-floor safe backfill range for group '.$group->name);
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

    private function reportSafeBackfillNoWork(string $backfilldays): void
    {
        $context = [
            'enabled_backfill_groups' => $this->countScalar(
                'SELECT COUNT(*) FROM usenet_groups WHERE backfill = 1'
            ),
            'enabled_missing_cursor' => $this->countScalar(
                'SELECT COUNT(*) FROM usenet_groups WHERE backfill = 1 AND (first_record IS NULL OR first_record = 0 OR first_record_postdate IS NULL)'
            ),
            'enabled_invalid_provider_cursor' => $this->countScalar(
                'SELECT COUNT(*) FROM usenet_groups g INNER JOIN short_groups a ON a.name = g.name WHERE g.backfill = 1 AND (CAST(a.first_record AS SIGNED) <= 0 OR CAST(a.last_record AS SIGNED) < CAST(a.first_record AS SIGNED))'
            ),
            'active_disabled_with_target' => $this->countScalar(
                'SELECT COUNT(*) FROM usenet_groups WHERE active = 1 AND backfill = 0 AND backfill_target > 0'
            ),
            'enabled_without_provider_row' => $this->countScalar(
                'SELECT COUNT(*) FROM usenet_groups g LEFT JOIN short_groups a ON a.name = g.name WHERE g.backfill = 1 AND a.name IS NULL'
            ),
            'enabled_at_provider_floor' => $this->countScalar(
                'SELECT COUNT(*) FROM usenet_groups g INNER JOIN short_groups a ON a.name = g.name WHERE g.backfill = 1 AND CAST(g.first_record AS SIGNED) <= CAST(a.first_record AS SIGNED)'
            ),
            'enabled_near_provider_floor' => $this->countScalar(
                'SELECT COUNT(*) FROM usenet_groups g INNER JOIN short_groups a ON a.name = g.name WHERE g.backfill = 1 AND CAST(g.first_record AS SIGNED) > CAST(a.first_record AS SIGNED) AND (CAST(g.first_record AS SIGNED) - CAST(a.first_record AS SIGNED)) < '.$this->minimumSafeBackfillRange()
            ),
            'enabled_target_reached' => $this->countScalar(
                'SELECT COUNT(*) FROM usenet_groups g WHERE g.backfill = 1 AND g.first_record_postdate IS NOT NULL AND (NOW() - INTERVAL '.$backfilldays.' DAY) >= g.first_record_postdate'
            ),
        ];

        Log::info('Safe backfill found no eligible groups', $context);
        cli()->warning(sprintf(
            'Safe backfill diagnostics: enabled=%d, missing_cursor=%d, invalid_provider_cursor=%d, disabled_with_target=%d, no_provider_row=%d, at_provider_floor=%d, near_provider_floor=%d, target_reached=%d.',
            $context['enabled_backfill_groups'],
            $context['enabled_missing_cursor'],
            $context['enabled_invalid_provider_cursor'],
            $context['active_disabled_with_target'],
            $context['enabled_without_provider_row'],
            $context['enabled_at_provider_floor'],
            $context['enabled_near_provider_floor'],
            $context['enabled_target_reached'],
        ));
    }

    private function countScalar(string $sql): int
    {
        return (int) DB::scalar($sql);
    }

    private function minimumSafeBackfillRange(): int
    {
        return max(1, (int) config('nntmux.safe_backfill_min_articles', 100));
    }
}
