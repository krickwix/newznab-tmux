<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The post-processing candidate queries filter on equalities and sort a
 * per-bucket page; the column order is what lets each one read only its
 * own rows instead of walking every release.
 */
final class PostprocessCandidateIndexesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY, categories_id INT, leftguid CHAR(1), postdate DATETIME NULL,
            haspreview INT, passwordstatus INT, nzbstatus INT, videos_id INT, tv_episodes_id INT
        )');
    }

    public function test_indexes_lead_with_the_equality_filters_then_the_sort_key(): void
    {
        $migration = require database_path('migrations/2026_11_27_230000_add_postprocess_candidate_indexes.php');
        $migration->up();
        $migration->up(); // idempotent

        self::assertSame(['haspreview', 'passwordstatus', 'nzbstatus', 'leftguid', 'postdate'], $this->columns('ix_releases_pp_candidates'));
        self::assertSame(['videos_id', 'tv_episodes_id', 'leftguid', 'postdate'], $this->columns('ix_releases_tv_candidates'));
        self::assertSame(['leftguid', 'categories_id'], $this->columns('ix_releases_movie_candidates'));

        $migration->down();
        self::assertSame([], $this->columns('ix_releases_pp_candidates'));
        self::assertSame([], $this->columns('ix_releases_tv_candidates'));
        self::assertSame([], $this->columns('ix_releases_movie_candidates'));
    }

    public function test_mariadb_builds_them_online(): void
    {
        $source = file_get_contents(__DIR__.'/../../database/migrations/2026_11_27_230000_add_postprocess_candidate_indexes.php');

        self::assertIsString($source);
        self::assertStringContainsString('ALGORITHM=INPLACE, LOCK=NONE', $source);
    }

    /** @return list<string> */
    private function columns(string $index): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->name,
            DB::select('SELECT name FROM pragma_index_info(?) ORDER BY seqno', [$index]),
        );
    }
}
