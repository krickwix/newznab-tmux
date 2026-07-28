<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnostics\Par2SetIdentityRepairService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RepairPar2SetIdentity extends Command
{
    protected $signature = 'nntmux:repair-par2-set-identity
                            {group : Group id or exact group name}
                            {--limit=200 : Maximum stalled par2 member rows to consider}
                            {--max-probes-per-cohort=3 : Article fetches allowed per cohort before giving up}
                            {--max-seconds=0 : Wall-clock budget for probing; 0 means unlimited}
                            {--before= : Optional collection dateadded upper bound}
                            {--update : Apply the regrouping; default is dry-run}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Regroup obfuscated hash-set collections by PAR2 RecoverySetID (dry-run by default)';

    public function handle(Par2SetIdentityRepairService $service): int
    {
        try {
            $summary = $service->repair(
                (string) $this->argument('group'),
                (int) $this->option('limit'),
                (int) $this->option('max-probes-per-cohort'),
                (float) $this->option('max-seconds'),
                $this->option('before') === null ? null : (string) $this->option('before'),
                (bool) $this->option('update')
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
            '%s %s (%d): scanned %d cohorts, probed %d articles in %ss.',
            $summary['updated'] ? 'Repaired' : 'Dry-run for',
            $summary['group']['name'],
            $summary['group']['id'],
            $summary['cohorts_scanned'],
            $summary['articles_probed'],
            $summary['elapsed_seconds']
        ));

        $this->table(
            ['metric', 'value'],
            [
                ['cohorts resolved to one set id', $summary['cohorts_resolved']],
                ['cohorts ambiguous (>1 set id)', $summary['cohorts_ambiguous']],
                ['cohorts unresolved (no packet)', $summary['cohorts_unresolved']],
                ['cohorts skipped (filenumber clash)', $summary['cohorts_skipped']],
                ['collections regrouped', $summary['collections_regrouped']],
                ['articles without a packet', $summary['articles_without_packet']],
                ['fetch errors', $summary['fetch_errors']],
                ['budget exhausted', $summary['budget_exhausted'] ? 'yes' : 'no'],
            ]
        );

        if ($summary['cohorts_ambiguous'] > 0) {
            $this->warn(sprintf(
                '%d cohort(s) contained more than one recovery set; these were left untouched for inspection.',
                $summary['cohorts_ambiguous']
            ));
        }

        if (! $summary['updated'] && $summary['cohorts_resolved'] > 0) {
            $this->line('Re-run with --update to apply the regrouping.');
        }

        return self::SUCCESS;
    }
}
