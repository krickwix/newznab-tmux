<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HopelessCollectionPurger;
use App\Services\Metrics\DistributedWorkerTelemetry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the hopeless single-part collection test over every group, page by
 * page, until it has scanned every collection once or hits its deadline.
 *
 * The release worker runs the same test as one 2,000-row page per slice,
 * shared with every other release stage, and waits 6 hours. A busy
 * obfuscated group such as boneless adds ~400k such collections an hour, so
 * this lane runs it on its own with a shorter age and larger pages. The
 * cursor is kept in the cache, so a run cut short by its deadline resumes
 * where it stopped instead of rescanning the oldest ids.
 */
final class PurgeHopelessCollections extends Command
{
    public const string CURSOR_KEY = 'nntmux:hopeless-purge:cursor';

    protected $signature = 'nntmux:purge-hopeless-collections
        {--age-minutes= : Minimum collection age; defaults to nntmux.hopeless_purge_age_minutes}
        {--window= : Collections scanned per page}
        {--deadline= : Seconds before the run stops and keeps its cursor}
        {--group=0 : Restrict to one group id (0 = all groups)}';

    protected $description = 'Delete one-part collections of per-article obfuscated posts, all groups';

    public function __construct(
        private readonly HopelessCollectionPurger $purger,
        private readonly DistributedWorkerTelemetry $workerTelemetry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ageMinutes = (int) ($this->option('age-minutes') ?? config('nntmux.hopeless_purge_age_minutes', 0));
        if ($ageMinutes <= 0) {
            $this->info('Hopeless purge disabled (age is 0).');

            return self::SUCCESS;
        }
        $window = max(100, (int) ($this->option('window') ?? config('nntmux.hopeless_purge_window', 20_000)));
        $deadline = microtime(true) + max(1, (int) ($this->option('deadline') ?? config('nntmux.hopeless_purge_deadline_seconds', 240)));
        $groupId = max(0, (int) $this->option('group'));
        $minParts = (int) config('nntmux.release_hopeless_singleton_min_parts', 50);
        $cutoff = now()->subMinutes($ageMinutes);
        $cursorKey = self::CURSOR_KEY.':'.$groupId;

        $start = $after = $this->cursor($cursorKey);
        $scanned = $deleted = 0;
        $wrapped = false;

        while (true) {
            $page = $this->purger->purgePage($groupId, $after, $cutoff, $minParts, $window);
            $scanned += $page['scanned'];
            $deleted += $page['deleted'];
            $after = $page['next'];

            if ($after === 0) {
                // Past the last collection. One wrap per run: starting again
                // from 0 is only useful when this run began mid-table.
                if ($wrapped || $start === 0) {
                    break;
                }
                $wrapped = true;
            }
            if ($wrapped && $after >= $start) {
                break;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        $this->storeCursor($cursorKey, $after);

        if ($scanned > 0) {
            $this->workerTelemetry->recordItem('hopeless-purge', 'collection', 'scanned', $scanned);
        }
        if ($deleted > 0) {
            $this->workerTelemetry->recordItem('hopeless-purge', 'collection', 'deleted', $deleted);
        }
        $this->info(sprintf(
            'Scanned %d collections, deleted %d hopeless (age >= %d min); cursor %d.',
            $scanned,
            $deleted,
            $ageMinutes,
            $after,
        ));

        return self::SUCCESS;
    }

    private function cursor(string $key): int
    {
        try {
            return max(0, (int) Cache::store((string) config('nntmux.distributed_lock_store', 'redis'))->get($key, 0));
        } catch (Throwable) {
            return 0;
        }
    }

    private function storeCursor(string $key, int $cursor): void
    {
        try {
            Cache::store((string) config('nntmux.distributed_lock_store', 'redis'))->forever($key, max(0, $cursor));
        } catch (Throwable) {
            // Without the cache the next run rescans from the start; still safe.
        }
    }
}
