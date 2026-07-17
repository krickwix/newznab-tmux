<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Orchestrator\CurrentForwardRefreshAuditor;
use App\Services\Orchestrator\CurrentForwardRefreshLedger;
use Illuminate\Console\Command;
use Throwable;

final class AuditCurrentForwardRefresh extends Command
{
    protected $signature = 'orchestrator:audit-current-forward
                            {group? : Exact configured current-forward group}
                            {--seed : Explicitly register the group in the durable trust ledger}
                            {--record : Persist successful exact XOVER audits without issuing work}
                            {--json : Emit machine-readable evidence}';

    protected $description = 'Shadow-plan and exactly audit the next trusted current-forward 10k window';

    public function handle(CurrentForwardRefreshAuditor $auditor, CurrentForwardRefreshLedger $ledger): int
    {
        $group = $this->argument('group');
        $group = $group === null ? null : trim((string) $group);

        try {
            if ((bool) $this->option('seed')) {
                if ($group === null || $group === '') {
                    $this->error('--seed requires an exact group argument.');

                    return self::FAILURE;
                }
                $sourceId = $ledger->seedSource($group);
                $this->info("Registered trusted current-forward source {$group} as ledger source {$sourceId}.");
            }

            $result = $auditor->audit($group, (bool) $this->option('record'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($result, $group);
        }

        $this->info(sprintf('Current-forward audit: %s (%s)', $result['reason'], $result['enabled'] ? 'enabled' : 'disabled'));
        foreach ($result['audits'] as $audit) {
            $this->line(sprintf(
                '%s %d-%d headers=%d yenc=%d multipart=%d complete=%d recorded=%s',
                $audit['group'],
                $audit['first'],
                $audit['last'],
                $audit['headers'],
                $audit['yenc_headers'],
                $audit['multipart_headers'],
                $audit['complete_binary_files'],
                $audit['recorded'] ? 'yes' : 'no',
            ));
        }
        foreach ($result['rejections'] as $rejectedGroup => $reason) {
            $this->warn("{$rejectedGroup}: {$reason}");
        }

        return $this->exitCode($result, $group);
    }

    /** @param array{audits:list<array<string,mixed>>} $result */
    private function exitCode(array $result, ?string $group): int
    {
        if ($result['audits'] !== []) {
            return self::SUCCESS;
        }

        return $group === null && ! (bool) $this->option('record') && ! (bool) $this->option('seed')
            ? self::SUCCESS
            : self::FAILURE;
    }
}
