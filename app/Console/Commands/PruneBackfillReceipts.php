<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Distributed\BackfillExecutionGuard;
use Illuminate\Console\Command;

/**
 * Floor sweep for backfill_execution_ranges.
 *
 * The receipt table had no retention of any kind: enforcement ran for ten days
 * in July 2026, left 1,075 rows behind, and they were still there 95 days
 * later. With generation-scoped permits enforced on the backfill lane it now
 * gains a row per chunk, so the growth needs a bound rather than another
 * manual cleanup.
 *
 * Dry-run by default, like the other destructive maintenance commands here;
 * the scheduled invocation passes --apply.
 */
final class PruneBackfillReceipts extends Command
{
    protected $signature = 'nntmux:prune-backfill-receipts
                            {--days= : Retention window in days; defaults to nntmux.backfill_receipt_retention_days}
                            {--limit=20000 : Maximum rows to delete in one pass}
                            {--apply : Delete the rows; default is a dry-run count}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Delete settled backfill range receipts older than the retention window';

    public function handle(BackfillExecutionGuard $guard): int
    {
        $summary = $guard->pruneSettledReceipts(
            $this->option('days') === null ? null : (int) $this->option('days'),
            (int) $this->option('limit'),
            (bool) $this->option('apply'),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($summary['floor_generation'] <= 0) {
            $this->warn('No settled generation marker; pruning nothing.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d receipt row(s) claimed before %s below generation %d (retention %d day(s)).',
            $summary['applied'] ? 'Deleted' : 'Dry-run found',
            $summary['deleted'],
            $summary['cutoff'],
            $summary['floor_generation'],
            $summary['retention_days'],
        ));

        return self::SUCCESS;
    }
}
