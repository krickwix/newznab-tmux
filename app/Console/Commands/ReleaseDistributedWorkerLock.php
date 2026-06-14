<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Distributed\DistributedJobCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ReleaseDistributedWorkerLock extends Command
{
    protected $signature = 'nntmux:release-worker-lock
                            {job : Distributed job name}
                            {--store= : Cache store to release from; defaults to nntmux.distributed_lock_store}';

    protected $description = 'Force-release a known NNTmux distributed worker cache lock';

    public function handle(DistributedJobCatalog $catalog): int
    {
        $job = (string) $this->argument('job');

        if (! array_key_exists($job, $catalog->jobs())) {
            $this->error("Unknown distributed job [{$job}].");
            $this->line('Available jobs: '.implode(', ', array_keys($catalog->jobs())));

            return self::FAILURE;
        }

        $storeOption = $this->option('store');
        $store = is_string($storeOption) && $storeOption !== ''
            ? $storeOption
            : (string) config('nntmux.distributed_lock_store', 'redis');
        $lockName = 'nntmux:distributed-worker:'.$job;

        try {
            Cache::store($store)->lock($lockName)->forceRelease();
        } catch (Throwable $e) {
            $this->error("Failed to release distributed worker lock [{$job}] from [{$store}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Released distributed worker lock [{$job}] from [{$store}].");

        return self::SUCCESS;
    }
}
