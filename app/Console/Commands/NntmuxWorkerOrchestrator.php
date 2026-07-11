<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Orchestrator\WorkerOrchestrator;
use Illuminate\Console\Command;
use Throwable;

class NntmuxWorkerOrchestrator extends Command
{
    protected $signature = 'nntmux:worker-orchestrator
                            {--once : Evaluate once and exit}
                            {--shadow : Observe and decide without changing database settings}
                            {--sleep=60 : Seconds between observations}
                            {--grant-backfill-permit : Request one bounded permit if every policy gate is green}';

    protected $description = 'Continuously balance NNTmux pipeline workers using deterministic bounded profiles';

    public function handle(WorkerOrchestrator $orchestrator): int
    {
        $sleep = max(30, (int) $this->option('sleep'));
        $shadow = (bool) $this->option('shadow');
        $grantPermit = (bool) $this->option('grant-backfill-permit');
        if ($grantPermit && ! (bool) $this->option('once')) {
            $this->error('--grant-backfill-permit requires --once so one request cannot issue repeated permits.');

            return self::FAILURE;
        }

        do {
            try {
                $result = $orchestrator->runOnce($shadow, $grantPermit);
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (Throwable $error) {
                $this->error($error->getMessage());
                if ((bool) $this->option('once')) {
                    return self::FAILURE;
                }
            }

            if ((bool) $this->option('once')) {
                return self::SUCCESS;
            }
            sleep($sleep);
        } while (true);
    }
}
