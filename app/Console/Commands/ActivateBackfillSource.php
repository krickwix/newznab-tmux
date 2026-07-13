<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Orchestrator\BackfillSourceActivator;
use Illuminate\Console\Command;
use Throwable;

final class ActivateBackfillSource extends Command
{
    protected $signature = 'orchestrator:activate-backfill-source
                            {group : Exact local group name}
                            {--apply : Persist the verified backfill-only configuration}';

    protected $description = 'Verify and optionally activate one provider-backed group for orchestrated backfill only';

    public function handle(BackfillSourceActivator $activator): int
    {
        try {
            $inspection = $activator->inspect((string) $this->argument('group'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['check', 'value'], [
            ['group', $inspection['group']],
            ['provider range', $inspection['provider_first'].'-'.$inspection['provider_last']],
            ['readable cursor', $inspection['cursor'].' @ '.$inspection['cursor_postdate']],
            ['XOVER sample', $inspection['sample_start'].'-'.$inspection['sample_end']],
            ['headers', (string) $inspection['headers']],
            ['yEnc headers', (string) $inspection['yenc_headers']],
            ['multipart headers', (string) $inspection['multipart_headers']],
            ['complete binary files', (string) $inspection['complete_binary_files']],
        ]);

        if (! $this->option('apply')) {
            $this->info('Dry run passed; no database state was changed.');

            return self::SUCCESS;
        }

        try {
            $activated = $activator->apply($inspection);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($activated
            ? "Activated {$inspection['group']} with active=0 and backfill=1; awaiting the provider-range refresh."
            : "{$inspection['group']} is already safely configured; no state was changed.");

        return self::SUCCESS;
    }
}
