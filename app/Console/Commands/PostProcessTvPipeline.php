<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Distributed\NativeLeafStartupSmoke;
use App\Services\TvProcessing\TvProcessingPipeline;
use App\Services\TvProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PostProcessTvPipeline extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postprocess:tv-pipeline
                            {guid : First character of release leftguid}
                            {renamed? : Process renamed only (optional)}
                            {--mode=pipeline : Processing mode: pipeline, parallel, or laravel}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Post process TV releases by GUID character using pipelined providers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $guid = $this->argument('guid');
        $renamed = $this->argument('renamed') ?? '';
        $mode = $this->option('mode') ?? 'pipeline';

        if (! $this->isValidChar($guid)) {
            $this->error('GUID character must be a single alphanumeric character.');

            return self::FAILURE;
        }

        if (! in_array($mode, ['pipeline', 'parallel', 'laravel'], true)) {
            $this->error('Mode must be either "pipeline", "parallel", or "laravel".');

            return self::FAILURE;
        }

        $smokeArguments = [(string) $guid];
        if ($renamed !== '') {
            $smokeArguments[] = (string) $renamed;
        }
        $smokeArguments[] = '--mode='.(string) $mode;
        if (NativeLeafStartupSmoke::recordIfEnabled('postprocess:tv-pipeline', $smokeArguments)) {
            return self::SUCCESS;
        }

        try {
            if ($mode === 'laravel') {
                // Use the new Laravel Pipeline-based processor
                $pipeline = TvProcessingPipeline::createDefault(echoOutput: true);
                $pipeline->process('', $guid, $renamed);
            } else {
                // Use the legacy processor for backward compatibility
                $tvProcessor = new TvProcessor(true); // true = echo output
                $tvProcessor->process('', $guid, $renamed, $mode);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Check if the bucket is a single leftguid character.
     */
    private function isValidChar(string $char): bool
    {
        return preg_match('/^[A-Za-z0-9]$/', $char) === 1;
    }
}
