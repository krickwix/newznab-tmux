<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnostics\FragmentedPostingIdentityRepairService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RepairFragmentedPostingIdentity extends Command
{
    protected $signature = 'nntmux:repair-fragmented-posting-identity
                            {group : Group id or exact group name}
                            {--limit=50 : Maximum cohorts (distinct postings) to consider}
                            {--before= : Optional collection dateadded upper bound}
                            {--min-files= : Effective minfilestoformrelease; read from settings when omitted}
                            {--update : Apply the regrouping; default is dry-run}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Reunite per-file collections of one posting whose files share no name stem (dry-run by default)';

    public function handle(FragmentedPostingIdentityRepairService $service): int
    {
        try {
            $summary = $service->repair(
                (string) $this->argument('group'),
                (int) $this->option('limit'),
                $this->option('before') === null ? null : (string) $this->option('before'),
                $this->minFiles(),
                (bool) $this->option('update'),
            );
        } catch (InvalidArgumentException $e) {
            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %s (%d): %d cohort(s) found, %d mergeable, %d skipped.',
            $summary['updated'] ? 'Repaired' : 'Dry-run for',
            $summary['group']['name'],
            $summary['group']['id'],
            $summary['cohorts_found'],
            $summary['cohorts_mergeable'],
            $summary['cohorts_skipped'],
        ));

        $rows = [];
        foreach ($summary['cohorts'] as $cohort) {
            $rows[] = [
                substr((string) $cohort['fromname'], 0, 30),
                $cohort['declared_files'],
                $cohort['collections'],
                substr((string) ($cohort['sample_files'][0] ?? ''), 0, 34),
            ];
        }
        if ($rows !== []) {
            $this->table(['fromname', 'files', 'collections', 'sample file'], $rows);
        }

        foreach ($summary['skipped'] as $cohort) {
            $this->warn(sprintf(
                '  skipped %s (%d files, %d collections): %s %s',
                substr((string) $cohort['fromname'], 0, 30),
                $cohort['declared_files'],
                $cohort['collections'],
                (string) $cohort['refusal'],
                $cohort['refusal_values'] === [] ? '' : (string) json_encode($cohort['refusal_values']),
            ));
        }

        if ($summary['updated']) {
            $this->info(sprintf(
                'Merged %d cohort(s): %d binaries rehomed, %d collections removed, %d files retained.',
                $summary['cohorts_merged'],
                $summary['binaries_moved'],
                $summary['collections_removed'],
                $summary['files_retained'],
            ));
        } else {
            $this->comment('Dry-run only. Re-run with --update to apply.');
        }

        if ($summary['cohort_limit_reached']) {
            $this->comment('Cohort limit reached; more may remain.');
        }

        return self::SUCCESS;
    }

    private function minFiles(): ?int
    {
        $value = $this->option('min-files');

        return $value === null || $value === '' ? null : (int) $value;
    }
}
