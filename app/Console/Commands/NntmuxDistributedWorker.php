<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use Illuminate\Console\Command;

class NntmuxDistributedWorker extends Command
{
    protected $signature = 'nntmux:worker
                            {job? : Distributed job name}
                            {--once : Run one cycle and exit}
                            {--sleep= : Override sleep seconds between cycles}
                            {--lock-seconds= : Cache lock TTL for one job cycle}
                            {--stop-on-disabled : Exit instead of sleeping when a job is disabled/no work}
                            {--list : List available distributed jobs}';

    protected $description = 'Run one NNTmux processing lane without tmux, suitable for one Kubernetes pod per former pane';

    public function handle(DistributedJobCatalog $catalog): int
    {
        if ((bool) $this->option('list')) {
            foreach ($catalog->jobs() as $name => $description) {
                $this->line(sprintf('%-18s %s', $name, $description));
            }

            return self::SUCCESS;
        }

        $job = $this->argument('job');
        if (! is_string($job) || $job === '') {
            $this->error('A job name is required unless --list is used.');

            return self::FAILURE;
        }

        if (! array_key_exists($job, $catalog->jobs())) {
            $this->error("Unknown distributed job [{$job}].");
            $this->line('Available jobs: '.implode(', ', array_keys($catalog->jobs())));

            return self::FAILURE;
        }

        $worker = app(DistributedJobWorker::class);

        $sleep = $this->option('sleep');
        $lockSecondsOption = $this->option('lock-seconds');
        if (is_numeric($lockSecondsOption)) {
            $lockSeconds = max(1, (int) $lockSecondsOption);
        } elseif ($job === 'irc') {
            $lockSeconds = max(1, (int) config('nntmux.distributed_long_lock_seconds', 604800));
        } else {
            $lockSeconds = max(1, (int) config('nntmux.distributed_lock_seconds', 3600));
        }

        return $worker->run(
            job: $job,
            once: (bool) $this->option('once'),
            sleepOverride: is_numeric($sleep) ? max(1, (int) $sleep) : null,
            lockSeconds: $lockSeconds,
            output: $this->output,
            stopOnDisabled: (bool) $this->option('stop-on-disabled'),
        );
    }
}
