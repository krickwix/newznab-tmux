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
                            {--grant-backfill-permit : Request one bounded permit if every policy gate is green}
                            {--grant-current-forward-permit : Request the one configured exact current-forward window}';

    protected $description = 'Continuously balance NNTmux pipeline workers using deterministic bounded profiles';

    public function handle(WorkerOrchestrator $orchestrator): int
    {
        $sleep = $this->normalizedSleepSeconds((int) $this->option('sleep'));
        $shadow = (bool) $this->option('shadow');
        $grantPermit = (bool) $this->option('grant-backfill-permit');
        $grantCurrentForwardPermit = (bool) $this->option('grant-current-forward-permit');
        if ($grantPermit && $grantCurrentForwardPermit) {
            $this->error('Only one permit type may be requested per control cycle.');

            return self::FAILURE;
        }
        if ($grantPermit && ! (bool) $this->option('once')) {
            $this->error('--grant-backfill-permit requires --once so one request cannot issue repeated permits.');

            return self::FAILURE;
        }
        if ($grantCurrentForwardPermit && (! (bool) $this->option('once') || $shadow)) {
            $this->error('--grant-current-forward-permit requires --once and cannot run in shadow mode.');

            return self::FAILURE;
        }

        do {
            try {
                $result = $orchestrator->runOnce($shadow, $grantPermit, $grantCurrentForwardPermit);
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

    private function normalizedSleepSeconds(int $seconds): int
    {
        return max(15, $seconds);
    }
}
