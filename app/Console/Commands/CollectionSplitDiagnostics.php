<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnostics\CollectionSplitDiagnosticsService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class CollectionSplitDiagnostics extends Command
{
    protected $signature = 'nntmux:collection-split-diagnostics
                            {group : Group id or exact group name}
                            {--limit=10 : Maximum split cohorts to show}
                            {--cohorts-only : Skip full totals and regex summaries; emit only split cohorts}
                            {--json : Emit the full summary as pretty JSON}';

    protected $description = 'Diagnose one-binary multi-file collection splitting for a Usenet group';

    public function handle(CollectionSplitDiagnosticsService $diagnostics): int
    {
        try {
            $summary = $diagnostics->summarizeGroup(
                (string) $this->argument('group'),
                (int) $this->option('limit'),
                includeTotals: ! (bool) $this->option('cohorts-only'),
                includeRegexes: ! (bool) $this->option('cohorts-only')
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Collection split diagnostics for %s (%d)',
            $summary['group']['name'],
            $summary['group']['id']
        ));

        $this->table(
            ['metric', 'count'],
            collect($summary['totals'])->map(static fn (int $count, string $metric): array => [$metric, $count])->values()->all()
        );

        $this->table(
            ['collection_regexes_id', 'one_binary_multifile'],
            $summary['regexes']
        );

        $this->table(
            [
                'noise',
                'totalfiles',
                'collections',
                'hashes',
                'posters',
                'binaries',
                'filenumbers',
                'dupes',
                'coverage',
                'xref_span',
                'xref_missing',
                'date_span_s',
                'complete',
                'incomplete',
                'parts',
                'classification',
                'regex_ids',
                'date_min',
                'date_max',
            ],
            array_map(static fn (array $row): array => [
                $row['noise'],
                $row['totalfiles'],
                $row['collections'],
                $row['hashes'],
                $row['posters'],
                $row['binaries'],
                $row['filenumber_span'],
                $row['duplicate_filenumbers'],
                $row['filenumber_coverage_percent'].'%',
                sprintf('%s-%s/%s', $row['xref_min_article'], $row['xref_max_article'], $row['xref_articles']),
                $row['xref_missing_articles'],
                $row['date_span_seconds'],
                $row['complete_binaries'],
                $row['incomplete_binaries'],
                sprintf('%s-%s/%s-%s', $row['min_currentparts'], $row['max_currentparts'], $row['min_totalparts'], $row['max_totalparts']),
                $row['classification'],
                $row['regex_ids'],
                $row['min_date'],
                $row['max_date'],
            ], $summary['cohorts'])
        );

        return self::SUCCESS;
    }
}
