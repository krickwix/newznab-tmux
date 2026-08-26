<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Distributed\NativeLeafStartupSmoke;
use App\Services\NameFixing\ExternalSources\ExternalMetadataRefreshService;
use Illuminate\Console\Command;

class PredbRefreshExternalMetadata extends Command
{
    protected $signature = 'predb:refresh-external-metadata
                            {--source=* : Source to refresh: all, srrdb, predb-net, predb-ovh, xrel, xrel-p2p, internet-archive-predb, nzbindex}
                            {--query=* : Explicit candidate query for title-oriented sources}
                            {--limit= : Maximum rows or candidate queries per source}
                            {--sleep-ms= : Delay between provider requests}
                            {--dry-run : Fetch and summarize without writing rows}';

    protected $description = 'Refresh external PreDB/source evidence used by strong NNTmux name-fixing passes';

    public function handle(ExternalMetadataRefreshService $service): int
    {
        $sources = $this->option('source');
        $sources = is_array($sources) && $sources !== [] ? array_values($sources) : ['all'];
        $queries = $this->option('query');
        $queries = is_array($queries) ? array_values(array_filter(array_map('strval', $queries))) : [];
        $limit = is_numeric($this->option('limit')) ? max(1, (int) $this->option('limit')) : (int) config('external_metadata.limit', 25);
        $sleepMs = is_numeric($this->option('sleep-ms')) ? max(0, (int) $this->option('sleep-ms')) : (int) config('external_metadata.sleep_ms', 500);

        $smokeArguments = [];
        foreach ($sources as $source) {
            $smokeArguments[] = '--source='.(string) $source;
        }
        foreach ($queries as $query) {
            $smokeArguments[] = '--query='.(string) $query;
        }
        $smokeArguments[] = '--limit='.$limit;
        $smokeArguments[] = '--sleep-ms='.$sleepMs;
        if ((bool) $this->option('dry-run')) {
            $smokeArguments[] = '--dry-run';
        }
        if (NativeLeafStartupSmoke::recordIfEnabled('predb:refresh-external-metadata', $smokeArguments)) {
            return self::SUCCESS;
        }

        $summary = $service->refresh(
            sources: array_map('strval', $sources),
            limit: $limit,
            sleepMs: $sleepMs,
            queries: $queries,
            dryRun: (bool) $this->option('dry-run'),
        );

        foreach ($summary->sources() as $sourceSummary) {
            $this->line(sprintf(
                '%s: queried=%d imported=%d skipped=%d failed=%d',
                $sourceSummary->source,
                $sourceSummary->queried,
                $sourceSummary->imported,
                $sourceSummary->skipped,
                $sourceSummary->failed,
            ));

            foreach ($sourceSummary->messages as $message) {
                $this->line('  - '.$message);
            }
        }

        return self::SUCCESS;
    }
}
