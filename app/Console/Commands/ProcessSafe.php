<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Distributed\BackfillExecutionGuard;
use App\Services\ForkingService;
use Illuminate\Console\Command;

class ProcessSafe extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:safe
                            {type : Type: binaries or backfill}
                            {--backfill-generation= : Claimed orchestrator backfill generation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safe binaries or backfill update using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');

        if (! \in_array($type, ['backfill', 'binaries'], true)) {
            $this->error('Type must be either: binaries or backfill');
            $this->line('');
            $this->line('binaries => Do Safe Binaries update');
            $this->line('backfill => Do Safe Backfill update');

            return self::FAILURE;
        }

        try {
            $service = new ForkingService;
            $generationOption = $this->option('backfill-generation');
            $generation = is_numeric($generationOption) && (int) $generationOption > 0
                ? (int) $generationOption
                : null;
            if ($type === 'backfill'
                && (new BackfillExecutionGuard)->enforcementEnabled()
                && $generation === null
            ) {
                (new BackfillExecutionGuard)->assertLegacyCommandAllowed('multiprocessing:safe backfill');
            }

            match ($type) {
                'binaries' => $service->safeBinaries(),
                'backfill' => $service->safeBackfill($generation),
            };

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
