<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Services\NameFixing\NameFixingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Runs the revisit-page SQL against a real MariaDB. Set
 * NNTMUX_TEST_MARIADB_DSN (mysql://user:pass@host:port/db) to enable it; the
 * SQLite-backed ReleaseNameFixedRecategorizationTest cannot cover MariaDB
 * syntax such as GROUP BY with ORDER BY rel.id.
 */
final class NameFixingMariaDbRevisitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $dsn = (string) env('NNTMUX_TEST_MARIADB_DSN', '');
        if ($dsn === '') {
            $this->markTestSkipped('NNTMUX_TEST_MARIADB_DSN is not set.');
        }
        $parts = parse_url($dsn);
        config([
            'database.default' => 'mariadb_t',
            'database.connections.mariadb_t' => [
                'driver' => 'mariadb', 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? 3306,
                'database' => ltrim($parts['path'] ?? '/t', '/'), 'username' => $parts['user'] ?? 'root',
                'password' => $parts['pass'] ?? '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '', 'strict' => false,
            ],
            'cache.default' => 'array',
            'nntmux.namefix_subject_revisit_limit' => 2,
            'nntmux.namefix_revisit_min_sweep_seconds' => 0,
        ]);
        DB::purge('mariadb_t');
        Cache::store('array')->flush();
        Schema::dropIfExists('media_infos');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->unsignedInteger('categories_id')->default(0);
            $table->unsignedInteger('groups_id')->default(1);
            $table->string('fromname')->default('');
            $table->unsignedInteger('predb_id')->default(0);
            $table->tinyInteger('isrenamed')->default(0);
            $table->tinyInteger('proc_files')->default(0);
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
        });
        Schema::create('media_infos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('releases_id');
            $table->string('movie_name')->nullable();
            $table->string('file_name')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('media_infos');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('settings');
        parent::tearDown();
    }

    private function release(int $id, int $procFiles = 1): void
    {
        DB::table('releases')->insert([
            'id' => $id, 'name' => 'Some Software Title 2024 yEnc '.$id, 'searchname' => 'x'.$id,
            'categories_id' => 7020, 'proc_files' => $procFiles, 'postdate' => now(), 'adddate' => now(),
        ]);
    }

    /** @return list<int> */
    private function subjectIds(int $time, int $cats, int $limit): array
    {
        $service = app(NameFixingService::class);
        $base = 'SELECT rel.id AS releases_id, rel.name, rel.searchname FROM releases rel WHERE rel.predb_id = 0 AND rel.isrenamed = 0';
        $rows = (new ReflectionMethod($service, 'subjectReleases'))->invoke($service, $base, $time, $cats, $limit, true);

        return collect($rows)->pluck('releases_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_revisit_sql_sweeps_caps_and_wraps_in_every_scope(): void
    {
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->release($id);
        }
        $this->release(9, 0);

        // (time, cats): full/other, full/all, full/movies, full/hashed, 6h/other.
        foreach ([[2, 1], [2, 2], [2, 4], [2, 5], [1, 1]] as [$time, $cats]) {
            Cache::store('array')->flush();
            $category = match ($cats) {
                5 => Category::OTHER_HASHED,
                4 => Category::MOVIE_HD,
                default => Category::OTHER_MISC,
            };
            DB::table('releases')->update(['categories_id' => $category]);
            $ids = fn (int $limit): array => $this->subjectIds($time, $cats, $limit);

            self::assertSame([9, 5, 4], $ids(500), "scope {$time}/{$cats}");
            self::assertSame([9, 3, 2], $ids(500), "scope {$time}/{$cats}");
            self::assertSame([9, 1], $ids(500), "scope {$time}/{$cats}");
            self::assertSame([9, 5, 4], $ids(500), "scope {$time}/{$cats} wrapped");
            self::assertSame([9, 3, 2], $ids(0), "scope {$time}/{$cats} no limit");
        }
    }

    public function test_method_eighteen_sql_executes_and_skips_empty_movie_names(): void
    {
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        foreach ([1, 2, 3] as $id) {
            $this->release($id);
            DB::table('media_infos')->insert(['releases_id' => $id, 'movie_name' => $id === 3 ? '' : null, 'file_name' => 'f']);
        }

        app(NameFixingService::class)->fixNamesWithMediaMovieName(2, true, 5, true, false);
        app(NameFixingService::class)->fixNamesWithMediaMovieName(2, true, 3, true, false);

        self::assertSame(0, (int) DB::table('releases')->where('proc_files', 99)->count());
        self::assertNull(Cache::store('array')->get('nntmux:namefix:mediainfo:cursor:2:5'));
    }

    public function test_mediainfo_revisit_page_pages_the_join_query_by_cursor(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->release($id);
            DB::table('releases')->where('id', $id)->update(['categories_id' => Category::OTHER_HASHED]);
            DB::table('media_infos')->insert(['releases_id' => $id, 'movie_name' => 'Some Movie', 'file_name' => 'f']);
        }
        $service = app(NameFixingService::class);
        $method = new ReflectionMethod($service, 'revisitPage');
        $query = 'SELECT rel.id AS releases_id, rf.movie_name AS movie_name FROM releases rel INNER JOIN media_infos rf ON rf.releases_id = rel.id WHERE rel.isrenamed = 0 AND rel.predb_id = 0 AND rf.movie_name IS NOT NULL AND rf.movie_name <> \'\' AND rel.categories_id IN ('.Category::OTHER_MISC.','.Category::OTHER_HASHED.')';
        $ids = fn (): array => collect($method->invoke($service, 'mediainfo', $query, 2, 2, 2, true))->pluck('releases_id')->map(fn ($id): int => (int) $id)->all();

        self::assertSame([3, 2], $ids());
        self::assertSame([1], $ids());
        self::assertSame([3, 2], $ids());
    }

    public function test_mediainfo_revisit_rests_after_a_wrap(): void
    {
        config(['nntmux.namefix_revisit_min_sweep_seconds' => 3600, 'nntmux.namefix_subject_revisit_limit' => 5]);
        foreach ([1, 2] as $id) {
            $this->release($id);
            DB::table('releases')->where('id', $id)->update(['categories_id' => Category::OTHER_HASHED]);
            DB::table('media_infos')->insert(['releases_id' => $id, 'movie_name' => 'Some Movie', 'file_name' => 'f']);
        }
        $service = app(NameFixingService::class);
        $method = new ReflectionMethod($service, 'revisitPage');
        $query = 'SELECT rel.id AS releases_id FROM releases rel INNER JOIN media_infos rf ON rf.releases_id = rel.id WHERE rel.isrenamed = 0';
        $ids = fn (): array => collect($method->invoke($service, 'mediainfo', $query, 2, 5, 5, true))->pluck('releases_id')->map(fn ($id): int => (int) $id)->all();

        self::assertSame([2, 1], $ids());
        self::assertSame([], $ids());
        Cache::forever('nntmux:namefix:mediainfo:cursor:2:5:wrapped_at', time() - 3601);
        self::assertSame([2, 1], $ids());
    }
}
