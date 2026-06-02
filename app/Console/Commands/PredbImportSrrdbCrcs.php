<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Predb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PredbImportSrrdbCrcs extends Command
{
    protected $signature = 'predb:import-srrdb-crcs
                            {--limit=25 : Maximum PreDB titles to query}
                            {--sleep-ms=500 : Delay between srrDB requests}
                            {--force : Re-query titles that already have imported CRCs}';

    protected $description = 'Import archived file CRCs from srrDB details into predb_crcs for CRC-based deobfuscation';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $force = (bool) $this->option('force');

        $query = Predb::query()
            ->where('source', 'srrdb')
            ->orderByDesc('id')
            ->select(['id', 'title']);

        if (! $force) {
            $query->whereNotExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('predb_crcs')
                    ->whereColumn('predb_crcs.predb_id', 'predb.id');
            });
        }

        $pres = $query->limit($limit)->get();
        if ($pres->isEmpty()) {
            $this->info('No srrDB PreDB titles need CRC import.');

            return self::SUCCESS;
        }

        $imported = 0;
        $queried = 0;

        foreach ($pres as $pre) {
            $queried++;
            $response = Http::timeout(20)
                ->withUserAgent('nntmux-predb-crc-import/1.0')
                ->acceptJson()
                ->get('https://api.srrdb.com/v1/details/'.rawurlencode((string) $pre->title));

            if (! $response->successful()) {
                $this->warn(sprintf('srrDB details failed for %s: HTTP %d', $pre->title, $response->status()));
                $this->sleepBetweenRequests($sleepMs);
                continue;
            }

            $files = $response->json('files');
            if (! is_array($files)) {
                $this->sleepBetweenRequests($sleepMs);
                continue;
            }

            $rows = [];
            foreach ($files as $file) {
                if (! is_array($file)) {
                    continue;
                }

                $crc = strtoupper(trim((string) ($file['crc'] ?? '')));
                $size = (int) ($file['size'] ?? 0);
                if (! preg_match('/^[A-F0-9]{8}$/', $crc) || $size <= 0) {
                    continue;
                }

                $rows[$crc.'#'.$size] = [
                    'predb_id' => (int) $pre->id,
                    'crchash' => $crc,
                    'filesize' => $size,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('predb_crcs')->insertOrIgnore(array_values($rows));
                $imported += count($rows);
            }

            $this->line(sprintf('%s: %d CRCs', $pre->title, count($rows)));
            $this->sleepBetweenRequests($sleepMs);
        }

        $this->info(sprintf('Imported %d CRC rows from %d srrDB title(s).', $imported, $queried));

        return self::SUCCESS;
    }

    private function sleepBetweenRequests(int $sleepMs): void
    {
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }
}
