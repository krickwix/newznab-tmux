<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ItunesService;
use App\Services\ReleaseImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BackfillMusicCovers extends Command
{
    protected $signature = 'music:backfill-covers
                            {--id=* : Specific musicinfo IDs to inspect}
                            {--limit=100 : Maximum number of missing covers to inspect}
                            {--dry-run : Show candidates without saving images or updating rows}';

    protected $description = 'Backfill missing music album cover images from stored iTunes collection IDs.';

    public function __construct(
        private readonly ItunesService $itunesService,
        private readonly ReleaseImageService $releaseImageService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $dryRun = (bool) $this->option('dry-run');
        $coverDirectory = rtrim((string) config('nntmux_settings.covers_path'), '/').'/music/';

        if (! $dryRun && ! File::isDirectory($coverDirectory)) {
            File::makeDirectory($coverDirectory, 0777, true);
        }

        $query = DB::table('musicinfo')
            ->select(['id', 'title', 'artist', 'asin', 'cover'])
            ->where('cover', 0)
            ->whereNotNull('asin');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->orderByDesc('id')->limit($limit);
        }

        $rows = $query->get();

        $backfilled = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if (! preg_match('/^\d+$/', (string) $row->asin)) {
                $skipped++;

                continue;
            }

            $musicId = (int) $row->id;
            $collectionId = (int) $row->asin;
            $targetPath = $coverDirectory.$musicId.'.jpg';

            if (File::isReadable($targetPath)) {
                if (! $dryRun) {
                    DB::table('musicinfo')->where('id', $musicId)->update(['cover' => 1]);
                }
                $backfilled++;

                continue;
            }

            $lookup = $this->itunesService->lookupById($collectionId);
            $coverUrl = $this->highResolutionCoverUrl((string) ($lookup['artworkUrl100'] ?? ''));

            if ($coverUrl === '') {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("Would backfill {$musicId}: {$row->artist} - {$row->title}");
                $backfilled++;

                continue;
            }

            $saved = $this->releaseImageService->saveImage((string) $musicId, $coverUrl, $coverDirectory, 250, 250);
            if ($saved === 1 && File::isReadable($targetPath)) {
                DB::table('musicinfo')->where('id', $musicId)->update(['cover' => 1]);
                $backfilled++;
            } else {
                $failed++;
            }
        }

        if ($backfilled > 0 && ! $dryRun) {
            Cache::flush();
        }

        $this->info("Backfilled music covers: {$backfilled}");
        $this->line("Skipped music covers: {$skipped}");
        $this->line("Failed music covers: {$failed}");

        return self::SUCCESS;
    }

    private function highResolutionCoverUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        return preg_replace('/\d+x\d+(bb)?\./', '800x800bb.', $url) ?? $url;
    }
}
