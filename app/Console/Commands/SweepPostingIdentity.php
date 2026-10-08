<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs the split and fragmented posting-identity repairs over every group
 * that can hold collections, read from the database on each run.
 *
 * The hourly sweep used to carry a hard-coded group list in its CronJob, so a
 * group deleted in the admin failed the job ("Unknown Usenet group") and a new
 * one was never swept until the manifest was edited. A group is swept when it
 * is active or still has collections, so a group switched off keeps being
 * repaired until its leftovers drain, and a deleted group simply drops out.
 */
final class SweepPostingIdentity extends Command
{
    private const array PASSES = [
        'split' => 'nntmux:repair-split-posting-identity',
        'fragmented' => 'nntmux:repair-fragmented-posting-identity',
    ];

    protected $signature = 'nntmux:sweep-posting-identity
                            {--limit=25 : Maximum cohorts per group and pass}
                            {--before= : Optional collection dateadded upper bound}
                            {--update : Apply the repairs; default is dry-run}';

    protected $description = 'Repair split and fragmented postings in every active group or group with collections';

    public function handle(): int
    {
        $groups = self::sweepGroups();
        if ($groups === []) {
            $this->info('No active groups and no collections: nothing to sweep.');

            return self::SUCCESS;
        }
        $this->info(sprintf('Sweeping %d group(s): %s', count($groups), implode(', ', array_column($groups, 'name'))));

        $failed = 0;
        foreach ($groups as $group) {
            $parts = [];
            foreach (self::PASSES as $pass => $command) {
                $arguments = [
                    'group' => (string) $group['id'],
                    '--limit' => (int) $this->option('limit'),
                    '--json' => true,
                ];
                if ($this->option('before') !== null) {
                    $arguments['--before'] = (string) $this->option('before');
                }
                if ((bool) $this->option('update')) {
                    $arguments['--update'] = true;
                }

                $buffer = new BufferedOutput;
                $exit = Artisan::call($command, $arguments, $buffer);
                $result = json_decode(trim($buffer->fetch()), true);
                if ($exit !== self::SUCCESS || ! is_array($result) || isset($result['error'])) {
                    $failed++;
                    $this->error(sprintf(
                        'SWEEP FAILED: %s %s: %s',
                        $command,
                        $group['name'],
                        is_array($result) ? (string) ($result['error'] ?? 'no summary') : 'no summary',
                    ));

                    continue;
                }
                $parts[] = sprintf(
                    '%s %d/%d merged',
                    $pass,
                    (int) ($result['cohorts_merged'] ?? 0),
                    (int) ($result['cohorts_found'] ?? 0),
                );
            }
            if ($parts !== []) {
                $this->line(sprintf('%s (%d): %s', $group['name'], $group['id'], implode(', ', $parts)));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Active groups plus any group that still owns collections, by name.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function sweepGroups(): array
    {
        return DB::table('usenet_groups')
            ->where('active', 1)
            ->orWhereIn('id', DB::table('collections')->select('groups_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->values()
            ->all();
    }
}
