<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The post-processing candidate queries run once per GUID bucket
 * (`leftguid = ?`) with ORDER BY ... LIMIT and usually find nothing. With
 * no index matching their equality filters, MariaDB walked a whole ordered
 * index hoping to fill the LIMIT early: all ~130k releases, 120-500 ms each.
 *
 * - additional (AdditionalCandidateQuery): haspreview, passwordstatus,
 *   nzbstatus and leftguid as equalities, then postdate for the ORDER BY.
 * - TV (TvProcessingPipeline::getTvReleases): videos_id = 0,
 *   tv_episodes_id = 0 and leftguid, then postdate.
 * - movies (MovieService::processMovieReleases): leftguid, then the
 *   categories_id range; the few rows left are sorted by id.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const INDEXES = [
        'ix_releases_pp_candidates' => ['haspreview', 'passwordstatus', 'nzbstatus', 'leftguid', 'postdate'],
        'ix_releases_tv_candidates' => ['videos_id', 'tv_episodes_id', 'leftguid', 'postdate'],
        'ix_releases_movie_candidates' => ['leftguid', 'categories_id'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('releases')) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if ($this->indexExists($name)) {
                continue;
            }

            if (\in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                // Online build; fail instead of blocking release writes if not possible.
                DB::statement('ALTER TABLE releases ADD INDEX '.$name.' ('.implode(', ', $columns).'), ALGORITHM=INPLACE, LOCK=NONE');

                continue;
            }

            Schema::table('releases', function (Blueprint $table) use ($name, $columns): void {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('releases')) {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            if (! $this->indexExists($name)) {
                continue;
            }

            Schema::table('releases', function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }
    }

    private function indexExists(string $name): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'releases' AND name = ?",
                [$name],
            ) !== [];
        }

        return DB::select('SHOW INDEX FROM `releases` WHERE Key_name = ?', [$name]) !== [];
    }
};
