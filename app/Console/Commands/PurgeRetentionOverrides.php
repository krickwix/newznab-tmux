<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Settings;
use App\Services\CollectionCleanupService;
use App\Support\CollectionRetentionOverrides;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Drains the collections that NNTMUX_COLLECTION_RETENTION_HOURS_OVERRIDES
 * has already expired, group by group, in fixed batches.
 *
 * The release pump applies the overrides too, but only as one 200-row
 * slice per group turn, shared with every other group: about 100-150 rows
 * a minute per group. When an override is first switched on, a group can
 * hold hundreds of thousands of expired incompletes; this command clears
 * that backlog at a pace set by --batch and --pause-ms, then exits.
 *
 * Each batch picks the oldest ids of one group, so a run cut short resumes
 * naturally. --dry-run reports the first batch per group without deleting.
 */
final class PurgeRetentionOverrides extends Command
{
    protected $signature = 'nntmux:purge-retention-overrides
        {--batch=500 : Collections deleted per batch (1-2000)}
        {--pause-ms=250 : Pause between batches, to leave room for the pipeline}
        {--deadline=3600 : Seconds before the run stops; 0 runs until drained}
        {--dry-run : Report the first batch per group without deleting}';

    protected $description = 'Delete collections already expired by the per-group retention overrides';

    public function __construct(
        private readonly CollectionCleanupService $collectionCleanupService,
    ) {
        parent::__construct();
    }

    /** @throws Throwable */
    public function handle(): int
    {
        $retentionHours = (int) Settings::settingValue('partretentionhours');
        $cutoffs = CollectionRetentionOverrides::cutoffs($retentionHours);
        if ($cutoffs === []) {
            $this->info('No retention override shorter than partretentionhours applies to a known group.');

            return self::SUCCESS;
        }

        $batch = min(2000, max(1, (int) $this->option('batch')));
        $pauseUs = max(0, (int) $this->option('pause-ms')) * 1000;
        $deadlineSeconds = max(0, (int) $this->option('deadline'));
        $deadline = $deadlineSeconds === 0 ? INF : microtime(true) + $deadlineSeconds;
        $dryRun = (bool) $this->option('dry-run');
        $started = microtime(true);
        $total = 0;

        foreach ($cutoffs as $groupId => $cutoff) {
            $deleted = $batches = 0;
            do {
                $ids = CollectionRetentionOverrides::whereExpired(DB::table('collections as c'), $groupId, $cutoff)
                    ->orderBy('c.id')
                    ->limit($batch)
                    ->pluck('c.id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                if ($dryRun) {
                    $this->line(sprintf('group %d: first batch holds %d expired collections (cutoff %s)', $groupId, \count($ids), $cutoff->format('Y-m-d H:i:s')));
                    break;
                }
                if ($ids === []) {
                    break;
                }

                $deleted += $this->collectionCleanupService->deleteCollectionsAndDescendants($ids, 'Retention override purge');
                if (++$batches % 20 === 0) {
                    $this->line(sprintf('%s group %d: %d deleted so far', now()->format('H:i:s'), $groupId, $deleted));
                }
                if ($pauseUs > 0) {
                    usleep($pauseUs);
                }
            } while (\count($ids) === $batch && microtime(true) < $deadline);

            $total += $deleted;
            if (! $dryRun) {
                $this->info(sprintf('group %d: deleted %d expired collections', $groupId, $deleted));
            }
            if (microtime(true) >= $deadline) {
                $this->warn('Deadline reached; run again to continue.');
                break;
            }
        }

        if (! $dryRun) {
            $elapsed = max(1.0, microtime(true) - $started);
            $this->info(sprintf('Deleted %d collections in %ds (%.0f/min).', $total, (int) $elapsed, $total / $elapsed * 60));
        }

        return self::SUCCESS;
    }
}
