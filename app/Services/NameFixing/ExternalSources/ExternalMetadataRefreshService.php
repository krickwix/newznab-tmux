<?php

declare(strict_types=1);

namespace App\Services\NameFixing\ExternalSources;

use App\Services\NameFixing\ExternalSources\Clients\NzbIndexClient;
use App\Services\NameFixing\ExternalSources\Clients\PredbNetClient;
use App\Services\NameFixing\ExternalSources\Clients\PredbOvhClient;
use App\Services\NameFixing\ExternalSources\Clients\SrrdbClient;
use App\Services\NameFixing\ExternalSources\Clients\XrelClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExternalMetadataRefreshService
{
    private const array ALL_SOURCES = [
        'srrdb',
        'predb-net',
        'predb-ovh',
        'xrel',
        'xrel-p2p',
        'internet-archive-predb',
        'nzbindex',
    ];

    public function __construct(
        private readonly SrrdbClient $srrdbClient,
        private readonly PredbNetClient $predbNetClient,
        private readonly PredbOvhClient $predbOvhClient,
        private readonly XrelClient $xrelClient,
        private readonly NzbIndexClient $nzbIndexClient,
    ) {}

    /**
     * @param  list<string>  $sources
     * @param  list<string>  $queries
     */
    public function refresh(
        array $sources,
        int $limit,
        int $sleepMs,
        array $queries = [],
        bool $dryRun = false,
    ): ExternalMetadataRefreshSummary {
        $summary = new ExternalMetadataRefreshSummary;
        $sources = $this->normalizeSources($sources);
        $queries = $queries === [] ? $this->candidateQueries($limit) : $queries;

        foreach ($sources as $source) {
            if (! (bool) config("external_metadata.sources.{$source}.enabled", true)) {
                $summary->source($source)->skipped++;
                $summary->source($source)->message('disabled by config');

                continue;
            }

            match ($source) {
                'srrdb' => $this->refreshSrrdb($summary, $limit, $sleepMs, $dryRun),
                'predb-net' => $this->refreshSearchSource($summary, 'predb-net', $queries, $limit, $sleepMs, $dryRun, fn (string $query, int $count): array => $this->predbNetClient->search($query, $count)),
                'predb-ovh' => $this->refreshSearchSource($summary, 'predb-ovh', $queries, $limit, $sleepMs, $dryRun, fn (string $query, int $count): array => $this->predbOvhClient->search($query, $count)),
                'xrel' => $this->refreshSearchSource($summary, 'xrel', $queries, $limit, $sleepMs, $dryRun, fn (string $query, int $count): array => $this->xrelClient->search($query, false, $count)),
                'xrel-p2p' => $this->refreshSearchSource($summary, 'xrel-p2p', $queries, $limit, $sleepMs, $dryRun, fn (string $query, int $count): array => $this->xrelClient->search($query, true, $count)),
                'nzbindex' => $this->refreshPreviewOnlySource($summary, 'nzbindex', $queries, $limit, $sleepMs, fn (string $query, int $count): array => $this->nzbIndexClient->search($query, $count)),
                'internet-archive-predb' => $this->refreshInternetArchivePredb($summary),
                default => null,
            };
        }

        return $summary;
    }

    /**
     * @param  list<string>  $sources
     * @return list<string>
     */
    private function normalizeSources(array $sources): array
    {
        $sources = array_map(static fn (string $source): string => strtolower(trim($source)), $sources);
        if ($sources === [] || in_array('all', $sources, true)) {
            return self::ALL_SOURCES;
        }

        return array_values(array_intersect(self::ALL_SOURCES, $sources));
    }

    private function refreshSrrdb(ExternalMetadataRefreshSummary $summary, int $limit, int $sleepMs, bool $dryRun): void
    {
        $source = $summary->source('srrdb');
        if (! Schema::hasTable('predb') || ! Schema::hasTable('predb_crcs')) {
            $source->skipped++;
            $source->message('predb or predb_crcs table missing');

            return;
        }

        $pres = DB::table('predb')
            ->select(['id', 'title'])
            ->where('source', 'srrdb')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('predb_crcs')
                    ->whereColumn('predb_crcs.predb_id', 'predb.id');
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($pres as $pre) {
            $source->queried++;
            $details = $this->srrdbClient->details((string) $pre->title);
            if ($details === null) {
                $source->failed++;
                $this->sleep($sleepMs);

                continue;
            }

            $rows = [];
            foreach ($details['files'] as $file) {
                $rows[$file['crc'].'#'.$file['size']] = [
                    'predb_id' => (int) $pre->id,
                    'crchash' => $file['crc'],
                    'filesize' => (int) $file['size'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== [] && ! $dryRun) {
                DB::table('predb_crcs')->insertOrIgnore(array_values($rows));
            }
            $source->imported += count($rows);
            $this->sleep($sleepMs);
        }
    }

    /**
     * @param  list<string>  $queries
     * @param  callable(string, int): list<ExternalReleaseHit>  $search
     */
    private function refreshSearchSource(
        ExternalMetadataRefreshSummary $summary,
        string $sourceName,
        array $queries,
        int $limit,
        int $sleepMs,
        bool $dryRun,
        callable $search,
    ): void {
        $source = $summary->source($sourceName);
        if (! Schema::hasTable('predb')) {
            $source->skipped++;
            $source->message('predb table missing');

            return;
        }

        if ($queries === []) {
            $source->skipped++;
            $source->message('no searchable local candidate queries');

            return;
        }

        foreach (array_slice($queries, 0, $limit) as $query) {
            $source->queried++;
            $hits = $search($query, min(10, $limit));
            foreach ($hits as $hit) {
                if ($this->importPredbHit($hit, $dryRun)) {
                    $source->imported++;
                }
            }
            $this->sleep($sleepMs);
        }
    }

    /**
     * @param  list<string>  $queries
     * @param  callable(string, int): list<ExternalReleaseHit>  $search
     */
    private function refreshPreviewOnlySource(
        ExternalMetadataRefreshSummary $summary,
        string $sourceName,
        array $queries,
        int $limit,
        int $sleepMs,
        callable $search,
    ): void {
        $source = $summary->source($sourceName);
        if ($queries === []) {
            $source->skipped++;
            $source->message('no searchable local candidate queries');

            return;
        }

        foreach (array_slice($queries, 0, $limit) as $query) {
            $source->queried++;
            $hits = $search($query, min(10, $limit));
            $source->imported += count($hits);
            $this->sleep($sleepMs);
        }
        $source->message('preview-only source; no rename-authoritative rows written');
    }

    private function refreshInternetArchivePredb(ExternalMetadataRefreshSummary $summary): void
    {
        $source = $summary->source('internet-archive-predb');
        $dumpPath = config('external_metadata.sources.internet-archive-predb.dump_path');
        if (! is_string($dumpPath) || $dumpPath === '' || ! is_readable($dumpPath)) {
            $source->skipped++;
            $source->message('static PreDB archive requires NNTMUX_IA_PREDB_DUMP_PATH; no live download performed');

            return;
        }

        $source->skipped++;
        $source->message('configured dump path detected; bulk SQL import is intentionally handled outside postprocess');
    }

    private function importPredbHit(ExternalReleaseHit $hit, bool $dryRun): bool
    {
        if ($hit->title === '' || ! Schema::hasTable('predb')) {
            return false;
        }

        if (DB::table('predb')->where('title', $hit->title)->exists()) {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        DB::table('predb')->insertOrIgnore([
            'title' => $hit->title,
            'filename' => '',
            'source' => $hit->source,
            'requestid' => 0,
            'groups_id' => 0,
            'nuked' => 0,
            'nukereason' => null,
            'category' => $hit->category,
            'size' => $hit->size !== null ? (string) $hit->size : null,
            'files' => $hit->files !== null ? (string) $hit->files : null,
            'predate' => $hit->pretime !== null ? Carbon::createFromTimestampUTC($hit->pretime)->toDateTimeString() : null,
            'searched' => false,
            'nfo' => null,
        ]);

        return true;
    }

    /**
     * @return list<string>
     */
    private function candidateQueries(int $limit): array
    {
        if (! Schema::hasTable('release_files')) {
            return [];
        }

        $names = DB::table('release_files')
            ->where('name', '!=', '')
            ->orderByDesc('created_at')
            ->limit(max($limit * 10, 50))
            ->pluck('name');

        $queries = [];
        foreach ($names as $name) {
            $query = $this->queryFromFileName((string) $name);
            if ($query !== null) {
                $queries[$query] = $query;
            }

            if (count($queries) >= $limit) {
                break;
            }
        }

        return array_values($queries);
    }

    private function queryFromFileName(string $name): ?string
    {
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $base = preg_replace('/\.(?:part\d+|vol\d+\+\d+)$/i', '', $base) ?? $base;
        $base = preg_replace('/[._-]+/', ' ', $base) ?? $base;
        $base = trim($base);

        if ($base === '' || strlen($base) < 8) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9]{24,}$/', $base)) {
            return null;
        }

        if (! preg_match('/(?:19|20)\d{2}|bluray|web|hdtv|x264|x265|h264|h265|remux/i', $base)) {
            return null;
        }

        return $base;
    }

    private function sleep(int $sleepMs): void
    {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }
}
