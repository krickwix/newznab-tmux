<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnostics\BraceTokenIdentityRepairService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RepairBraceTokenIdentity extends Command
{
    protected $signature = 'nntmux:repair-brace-token-identity
                            {group : Group id or exact group name}
                            {--limit=50 : Maximum cohorts (distinct postings) to consider}
                            {--before= : Optional collection dateadded upper bound}
                            {--min-files= : Effective minfilestoformrelease; read from settings when omitted}
                            {--posting= : Repair only this exact posting stem, e.g. "Maddie\'s.Secret.2026"}
                            {--update : Apply the regrouping; default is dry-run}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Reclaim stranded brace-token collections into one collection per posting (dry-run by default)';

    public function handle(BraceTokenIdentityRepairService $service): int
    {
        try {
            $summary = $service->repair(
                (string) $this->argument('group'),
                (int) $this->option('limit'),
                $this->option('before') === null ? null : (string) $this->option('before'),
                (bool) $this->option('update'),
                $this->minFiles(),
                $this->posting()
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
            '%s %s (%d): scanned %d collections in %ss.',
            $summary['updated'] ? 'Repaired' : 'Dry-run for',
            $summary['group']['name'],
            $summary['group']['id'],
            $summary['collections_scanned'],
            $summary['elapsed_seconds']
        ));

        $this->table(
            ['metric', 'value'],
            [
                ['cohorts found (distinct postings)', $summary['cohorts_found']],
                ['collections in those cohorts', $summary['collections_in_cohorts']],
                ['real files in those cohorts', $summary['files_in_cohorts']],
                ['effective minfilestoformrelease', $summary['min_files']],
                ['cohorts merged', $summary['cohorts_merged']],
                ['cohorts skipped', $summary['cohorts_skipped']],
                ['collections removed', $summary['collections_removed']],
                ['binaries removed (duplicate articles)', $summary['binaries_removed']],
                ['binaries retained (one per real file)', $summary['binaries_retained']],
                ['parts rehomed', $summary['parts_moved']],
                ['cohort limit reached', $summary['cohort_limit_reached'] ? 'yes' : 'no'],
            ]
        );

        if (! $summary['group_normalization_enabled']) {
            $this->warn(
                'Brace-token normalization is NOT enabled for this group. Repairing would write keys ingest '
                .'never computes, so --update is refused until the group is configured.'
            );
        }

        if ($summary['cohorts_skipped'] > 0) {
            $this->warn(sprintf(
                '%d cohort(s) were left untouched. A merge is lossy and the release pipeline '
                .'deletes some shapes outright (below_min_files, par2_only), so a stranded row '
                .'is preferred over a merged-then-cascaded one:',
                $summary['cohorts_skipped']
            ));

            $this->table(
                ['posting', 'reason', 'files', 'collections'],
                array_map(static fn (array $row): array => [
                    $row['name'],
                    $row['reason'],
                    \count($row['files'] ?? []),
                    \count($row['collections'] ?? []),
                ], $summary['skipped'])
            );
        }

        if ($summary['cohort_limit_reached']) {
            $this->warn('The cohort limit was reached; more stranded files remain. Re-run to continue.');
        }

        if (! $summary['updated'] && $summary['cohorts_found'] > 0) {
            $this->line('Re-run with --update to apply the regrouping.');
        }

        return self::SUCCESS;
    }

    /** Null lets the service read the live site setting and group override. */
    private function minFiles(): ?int
    {
        $option = $this->option('min-files');

        return $option === null || $option === '' ? null : (int) $option;
    }

    /**
     * A staged drain names its target. --limit alone admits cohorts in
     * collection-id order, which says nothing about which posting is next.
     */
    private function posting(): ?string
    {
        $option = $this->option('posting');

        return $option === null || $option === '' ? null : (string) $option;
    }
}
