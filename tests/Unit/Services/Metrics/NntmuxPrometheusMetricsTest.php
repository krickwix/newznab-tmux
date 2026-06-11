<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Metrics;

use App\Models\Category;
use App\Services\Metrics\NntmuxPrometheusMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class NntmuxPrometheusMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            adddate DATETIME NOT NULL,
            ishashed INTEGER DEFAULT 0,
            isrenamed INTEGER DEFAULT 0,
            categories_id INTEGER NOT NULL
        )');

        DB::statement('CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            root_categories_id INTEGER NULL
        )');

        DB::statement('CREATE TABLE root_categories (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255) NOT NULL
        )');

        DB::table('root_categories')->insert([
            ['id' => Category::OTHER_ROOT, 'title' => 'Other'],
            ['id' => Category::MOVIE_ROOT, 'title' => 'Movies'],
        ]);

        DB::table('categories')->insert([
            ['id' => Category::OTHER_HASHED, 'title' => 'Hashed', 'root_categories_id' => Category::OTHER_ROOT],
            ['id' => Category::MOVIE_HD, 'title' => 'HD', 'root_categories_id' => Category::MOVIE_ROOT],
        ]);
    }

    public function test_release_metrics_expose_category_based_hashed_backlog(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'adddate' => now(), 'ishashed' => 0, 'isrenamed' => 0, 'categories_id' => Category::OTHER_HASHED],
            ['id' => 2, 'adddate' => now(), 'ishashed' => 0, 'isrenamed' => 0, 'categories_id' => Category::OTHER_HASHED],
            ['id' => 3, 'adddate' => now(), 'ishashed' => 1, 'isrenamed' => 0, 'categories_id' => Category::MOVIE_HD],
            ['id' => 4, 'adddate' => now(), 'ishashed' => 0, 'isrenamed' => 1, 'categories_id' => Category::MOVIE_HD],
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('releaseMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_releases_state_total{state="hashed"} 1', $output);
        $this->assertStringContainsString('nntmux_releases_state_total{state="hashed_category"} 2', $output);
        $this->assertStringContainsString('nntmux_releases_state_total{state="hashed_effective"} 3', $output);
        $this->assertStringContainsString('nntmux_releases_state_total{state="renamed"} 1', $output);
    }

    public function test_release_metrics_work_after_legacy_ishashed_column_is_removed(): void
    {
        DB::statement('ALTER TABLE releases DROP COLUMN ishashed');

        DB::table('releases')->insert([
            ['id' => 1, 'adddate' => now(), 'isrenamed' => 0, 'categories_id' => Category::OTHER_HASHED],
            ['id' => 2, 'adddate' => now(), 'isrenamed' => 1, 'categories_id' => Category::MOVIE_HD],
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('releaseMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_releases_state_total{state="hashed"} 0', $output);
        $this->assertStringContainsString('nntmux_releases_state_total{state="hashed_category"} 1', $output);
        $this->assertStringContainsString('nntmux_releases_state_total{state="hashed_effective"} 1', $output);
        $this->assertStringContainsString('nntmux_releases_state_total{state="renamed"} 1', $output);
    }

    public function test_imdb_lookup_metrics_expose_reason_and_fallback_counters(): void
    {
        Redis::shouldReceive('keys')
            ->once()
            ->with('*metrics:imdb_lookup*')
            ->andReturn([
                'nntmux_database_nntmux-cache-metrics:imdb_lookup:outcome:failed:reason:waf_block:fallback:fallback_min_interval_active:source:none',
                'nntmux_database_nntmux-cache-metrics:imdb_lookup:outcome:success:reason:none:fallback:none:source:imdbapi_dev',
            ]);
        Redis::shouldReceive('get')
            ->with(Mockery::type('string'))
            ->andReturnUsing(static function (string $key): int {
                return str_contains($key, 'success') ? 3 : 7;
            });

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('imdbLookupMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_imdb_lookup_total{outcome="failed",reason="waf_block",fallback_reason="fallback_min_interval_active",source="none"} 7', $output);
        $this->assertStringContainsString('nntmux_imdb_lookup_total{outcome="success",reason="none",fallback_reason="none",source="imdbapi_dev"} 3', $output);
    }

    public function test_external_metadata_metrics_expose_srrdb_crc_rows_and_backlog(): void
    {
        DB::statement('CREATE TABLE predb (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            source VARCHAR(255) NOT NULL
        )');

        DB::statement('CREATE TABLE predb_crcs (
            id INTEGER PRIMARY KEY,
            predb_id INTEGER NOT NULL,
            crchash VARCHAR(8) NOT NULL,
            filesize INTEGER NOT NULL
        )');

        DB::table('predb')->insert([
            ['id' => 1, 'title' => 'Matched.Release-GRP', 'source' => 'srrdb'],
            ['id' => 2, 'title' => 'Missing.Crc-GRP', 'source' => 'srrdb'],
            ['id' => 3, 'title' => 'Other.Source-GRP', 'source' => 'xrel'],
        ]);
        DB::table('predb_crcs')->insert([
            ['id' => 1, 'predb_id' => 1, 'crchash' => 'AABBCCDD', 'filesize' => 15000000],
            ['id' => 2, 'predb_id' => 1, 'crchash' => 'EEFF0011', 'filesize' => 15000000],
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('externalMetadataMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_external_metadata_total{source="srrdb",state="crc_rows"} 2', $output);
        $this->assertStringContainsString('nntmux_external_metadata_total{source="srrdb",state="predb_without_crc"} 1', $output);
    }
}
