<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Metrics;

use App\Models\Category;
use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Metrics\NntmuxPrometheusMetrics;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\MockInterface;
use PDO;
use ReflectionClass;
use Tests\TestCase;

class NntmuxPrometheusMetricsTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-prometheus-metrics-test.sqlite';

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('title', 'NNTmux Test'),
            ('home_link', '/')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

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
            categories_id INTEGER NOT NULL,
            nzbstatus INTEGER NOT NULL DEFAULT 0
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

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value);
        }

        if (isset($this->databasePath) && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    private function setEnvironmentValue(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
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
        config([
            'cache.prefix' => 'nntmux-cache-',
            'cache.stores.redis.connection' => 'cache',
            'database.redis.options.prefix' => 'nntmux_database_',
        ]);

        /** @var MockInterface $connection */
        $connection = Mockery::mock();
        Redis::shouldReceive('keys')->never();
        Redis::shouldReceive('connection')
            ->with('cache')
            ->andReturn($connection);
        $connection->shouldReceive('scan')
            // @phpstan-ignore-next-line Mockery fluent expectations expose once() dynamically.
            ->once()
            ->with('0', ['match' => 'nntmux_database_nntmux-cache-metrics:imdb_lookup*', 'count' => 1000])
            ->andReturn(['12', [
                'nntmux_database_nntmux-cache-metrics:imdb_lookup:outcome:failed:reason:waf_block:fallback:fallback_min_interval_active:source:none',
            ]]);
        $connection->shouldReceive('scan')
            // @phpstan-ignore-next-line Mockery fluent expectations expose once() dynamically.
            ->once()
            ->with('12', ['match' => 'nntmux_database_nntmux-cache-metrics:imdb_lookup*', 'count' => 1000])
            ->andReturn(['0', [
                'nntmux_database_nntmux-cache-metrics:imdb_lookup:outcome:success:reason:none:fallback:none:source:imdbapi_dev',
            ]]);
        $connection->shouldReceive('get')
            // @phpstan-ignore-next-line Mockery fluent expectations expose with() dynamically.
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

    public function test_lock_metrics_scan_lock_connection_without_keys(): void
    {
        config([
            'cache.prefix' => 'nntmux-cache-',
            'cache.stores.redis.lock_connection' => 'default',
            'database.redis.options.prefix' => 'nntmux_database_',
        ]);

        /** @var MockInterface $connection */
        $connection = Mockery::mock();
        Redis::shouldReceive('keys')->never();
        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturn($connection);
        $connection->shouldReceive('scan')
            // @phpstan-ignore-next-line Mockery fluent expectations expose once() dynamically.
            ->once()
            ->with('0', ['match' => 'nntmux_database_nntmux-cache-nntmux:distributed-worker:*', 'count' => 1000])
            ->andReturn(['0', [
                'nntmux_database_nntmux-cache-nntmux:distributed-worker:releases',
            ]]);
        $connection->shouldReceive('ttl')
            // @phpstan-ignore-next-line Mockery fluent expectations expose with() dynamically.
            ->with(Mockery::type('string'))
            ->andReturnUsing(static function (string $key): int {
                return str_ends_with($key, 'releases') ? 42 : -2;
            });

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('lockMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_worker_lock_ttl_seconds{worker="releases",prefix="nntmux-cache-"} 42', $output);
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

    public function test_collection_formation_metrics_expose_filecheck_and_pending_multifile_backlogs(): void
    {
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            active INTEGER DEFAULT 0,
            backfill INTEGER DEFAULT 0
        )');

        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            groups_id INTEGER NOT NULL,
            totalfiles INTEGER NOT NULL,
            filecheck INTEGER NOT NULL,
            collection_regexes_id INTEGER NOT NULL
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            collections_id INTEGER NOT NULL
        )');

        DB::table('usenet_groups')->insert([
            ['id' => 5, 'name' => 'alt.binaries.blu-ray', 'active' => 1, 'backfill' => 1],
            ['id' => 6, 'name' => 'alt.binaries.lossless', 'active' => 1, 'backfill' => 1],
            ['id' => 7, 'name' => 'alt.binaries.inactive', 'active' => 0, 'backfill' => 0],
        ]);

        DB::table('collections')->insert([
            ['id' => 101, 'groups_id' => 5, 'totalfiles' => 55, 'filecheck' => 0, 'collection_regexes_id' => 88],
            ['id' => 102, 'groups_id' => 5, 'totalfiles' => 55, 'filecheck' => 0, 'collection_regexes_id' => 88],
            ['id' => 103, 'groups_id' => 5, 'totalfiles' => 55, 'filecheck' => 4, 'collection_regexes_id' => 88],
            ['id' => 201, 'groups_id' => 6, 'totalfiles' => 1, 'filecheck' => 0, 'collection_regexes_id' => -10],
            ['id' => 301, 'groups_id' => 7, 'totalfiles' => 10, 'filecheck' => 0, 'collection_regexes_id' => 88],
        ]);

        DB::table('binaries')->insert([
            ['id' => 1, 'collections_id' => 101],
            ['id' => 2, 'collections_id' => 102],
            ['id' => 3, 'collections_id' => 103],
            ['id' => 4, 'collections_id' => 103],
            ['id' => 5, 'collections_id' => 201],
            ['id' => 6, 'collections_id' => 301],
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $reflection = new ReflectionClass($metrics);

        $this->assertTrue($reflection->hasMethod('collectionFormationMetrics'));

        $method = $reflection->getMethod('collectionFormationMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_collections_filecheck_total{group="alt.binaries.blu-ray",filecheck="0"} 2', $output);
        $this->assertStringContainsString('nntmux_collections_filecheck_total{group="alt.binaries.blu-ray",filecheck="4"} 1', $output);
        $this->assertStringContainsString('nntmux_collections_filecheck_total{group="alt.binaries.lossless",filecheck="0"} 1', $output);
        $this->assertStringContainsString('nntmux_collections_pending_multifile_total{group="alt.binaries.blu-ray",collection_regexes_id="88"} 2', $output);
        $this->assertStringNotContainsString('alt.binaries.inactive', $output);
    }

    public function test_group_metrics_include_backfill_only_sources_without_counting_them_as_active(): void
    {
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            active INTEGER DEFAULT 0,
            backfill INTEGER DEFAULT 0,
            first_record INTEGER DEFAULT 0,
            first_record_postdate DATETIME NULL
        )');
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.active', 'active' => 1, 'backfill' => 1, 'first_record' => 100, 'first_record_postdate' => '2026-07-01 00:00:00'],
            ['id' => 2, 'name' => 'alt.backfill-only', 'active' => 0, 'backfill' => 1, 'first_record' => 200, 'first_record_postdate' => '2026-07-02 00:00:00'],
            ['id' => 3, 'name' => 'alt.disabled', 'active' => 0, 'backfill' => 0, 'first_record' => 300, 'first_record_postdate' => '2026-07-03 00:00:00'],
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('groupMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_groups_total{state="active"} 1', $output);
        $this->assertStringContainsString('nntmux_groups_total{state="active_backfill"} 1', $output);
        $this->assertStringContainsString('nntmux_groups_total{state="backfill"} 2', $output);
        $this->assertStringContainsString('nntmux_group_first_record{group="alt.backfill-only"} 200', $output);
        $this->assertStringNotContainsString('alt.disabled', $output);
    }

    public function test_collection_lifecycle_metrics_are_aggregate_and_zero_safe(): void
    {
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            groups_id INTEGER NOT NULL,
            totalfiles INTEGER NOT NULL,
            filecheck INTEGER NOT NULL,
            collection_regexes_id INTEGER NOT NULL
        )');

        DB::table('collections')->insert([
            ['id' => 1, 'groups_id' => 1, 'totalfiles' => 1, 'filecheck' => 0, 'collection_regexes_id' => 1],
            ['id' => 2, 'groups_id' => 1, 'totalfiles' => 1, 'filecheck' => 0, 'collection_regexes_id' => 1],
            ['id' => 3, 'groups_id' => 1, 'totalfiles' => 1, 'filecheck' => 4, 'collection_regexes_id' => 1],
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('pipelineLifecycleMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_collections_filecheck_lifecycle_count{filecheck="0",state="new",lifecycle="backlog"} 2', $output);
        $this->assertStringContainsString('nntmux_collections_filecheck_lifecycle_count{filecheck="3",state="ready_for_release",lifecycle="ready"} 0', $output);
        $this->assertStringContainsString('nntmux_collections_filecheck_lifecycle_count{filecheck="4",state="inserted",lifecycle="nzb_pending"} 1', $output);
    }

    public function test_nzb_metrics_use_direct_status_and_inserted_proxy_counts(): void
    {
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            groups_id INTEGER NOT NULL,
            totalfiles INTEGER NOT NULL,
            filecheck INTEGER NOT NULL,
            collection_regexes_id INTEGER NOT NULL
        )');

        DB::table('releases')->insert([
            ['id' => 1, 'adddate' => now(), 'categories_id' => Category::MOVIE_HD, 'nzbstatus' => 0],
            ['id' => 2, 'adddate' => now(), 'categories_id' => Category::MOVIE_HD, 'nzbstatus' => 0],
            ['id' => 3, 'adddate' => now(), 'categories_id' => Category::MOVIE_HD, 'nzbstatus' => -1],
            ['id' => 4, 'adddate' => now(), 'categories_id' => Category::MOVIE_HD, 'nzbstatus' => 1],
        ]);
        DB::table('collections')->insert([
            ['id' => 1, 'groups_id' => 1, 'totalfiles' => 1, 'filecheck' => 4, 'collection_regexes_id' => 1],
            ['id' => 2, 'groups_id' => 1, 'totalfiles' => 1, 'filecheck' => 3, 'collection_regexes_id' => 1],
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('nzbBacklogMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_nzb_releases_count{state="pending"} 2', $output);
        $this->assertStringContainsString('nntmux_nzb_releases_count{state="failed"} 1', $output);
        $this->assertStringContainsString('nntmux_nzb_releases_count{state="added"} 1', $output);
        $this->assertStringContainsString('nntmux_nzb_inserted_collections_count 1', $output);
    }

    public function test_worker_metrics_are_zero_safe_and_expose_selector_age_and_config(): void
    {
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.inline_nzb_creation' => false,
            'nntmux.distributed_nzb_limit' => 1,
            'nntmux.distributed_nzb_sleep' => 60,
            'nntmux.build_version' => '20260710-v8',
            'app.env' => 'testing',
        ]);
        Cache::store('array')->flush();

        $telemetry = new DistributedWorkerTelemetry;
        $telemetry->startRun('nzb-backlog', 1_000.0);
        $telemetry->recordItem('nzb-backlog', 'nzb', 'created', 3);
        $telemetry->recordSelectorDuration(0.125);

        $metrics = new NntmuxPrometheusMetrics($telemetry, new DistributedJobCatalog);
        $method = (new ReflectionClass($metrics))->getMethod('workerTelemetryMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics, 1_025.0));

        $this->assertStringContainsString('nntmux_worker_telemetry_available 1', $output);
        $this->assertStringContainsString('nntmux_worker_runs_total{worker="releases",outcome="success"} 0', $output);
        $this->assertStringContainsString('nntmux_worker_items_total{worker="nzb-backlog",item="nzb",result="created"} 3', $output);
        $this->assertStringNotContainsString('nntmux_worker_items_total{worker="releases",item="nzb"', $output);
        $this->assertStringContainsString('nntmux_worker_in_progress{worker="nzb-backlog"} 1', $output);
        $this->assertStringContainsString('nntmux_worker_in_progress_age_seconds{worker="nzb-backlog"} 25', $output);
        $this->assertStringContainsString('nntmux_worker_last_started_timestamp_seconds{worker="nzb-backlog"} 1000', $output);
        $this->assertStringContainsString('nntmux_worker_last_completed_timestamp_seconds{worker="nzb-backlog"} 0', $output);
        $this->assertStringContainsString('nntmux_nzb_selector_in_progress_age_seconds 25', $output);
        $this->assertStringContainsString('nntmux_nzb_selector_last_duration_seconds 0.125', $output);
    }

    public function test_worker_metrics_omit_redis_derived_samples_when_telemetry_is_unavailable(): void
    {
        config(['nntmux.distributed_lock_store' => 'missing-worker-telemetry-store']);

        $metrics = new NntmuxPrometheusMetrics(
            new DistributedWorkerTelemetry,
            new DistributedJobCatalog,
        );
        $method = (new ReflectionClass($metrics))->getMethod('workerTelemetryMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics, 1_025.0));

        $this->assertStringContainsString('nntmux_worker_telemetry_available 0', $output);
        $this->assertStringNotContainsString('nntmux_worker_runs_total{', $output);
        $this->assertStringNotContainsString('nntmux_worker_items_total{', $output);
        $this->assertStringNotContainsString('nntmux_worker_last_success_timestamp_seconds{', $output);
        $this->assertDoesNotMatchRegularExpression('/^nntmux_nzb_selector_last_duration_seconds /m', $output);
    }

    public function test_build_and_nzb_worker_config_are_exported_without_dynamic_labels(): void
    {
        config([
            'app.env' => 'production',
            'nntmux.build_version' => '20260710-v8',
            'nntmux.inline_nzb_creation' => false,
            'nntmux.distributed_nzb_limit' => 2,
            'nntmux.distributed_nzb_sleep' => 45,
            'nntmux.distributed_nzb_scan_cap' => 5000,
            'nntmux.distributed_nzb_lock_seconds' => 7200,
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('buildConfigMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_build_info{version="20260710-v8",environment="production"} 1', $output);
        $this->assertStringContainsString('nntmux_nzb_worker_config_info{inline_creation="disabled"} 1', $output);
        $this->assertStringContainsString('nntmux_nzb_worker_batch_limit 2', $output);
        $this->assertStringContainsString('nntmux_nzb_worker_sleep_seconds 45', $output);
        $this->assertStringContainsString('nntmux_nzb_worker_scan_cap 5000', $output);
        $this->assertStringContainsString('nntmux_nzb_worker_lock_seconds 7200', $output);
    }

    public function test_orchestrator_metrics_export_the_zero_write_shadow_snapshot(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.high_watermarks.parts' => 1_000,
        ]);
        DB::statement('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_qty', 'value' => '200000'],
            ['name' => 'orchestrator_cf_permit', 'value' => '0'],
            ['name' => 'orchestrator_cf_claimed', 'value' => '17'],
            ['name' => 'orchestrator_cf_completed', 'value' => '16'],
            ['name' => 'orchestrator_cf_failed', 'value' => '0'],
            ['name' => 'orchestrator_cf_issued_at', 'value' => (string) (time() - 100)],
            ['name' => 'orchestrator_cf_blocks', 'value' => 'alt.one:1-10000,alt.two:1-10000'],
            ['name' => 'orchestrator_cf_halt', 'value' => '0'],
            ['name' => 'orchestrator_cf_group', 'value' => 'alt.test'],
        ]);
        Cache::store('array')->flush();
        Cache::store('array')->forever(WorkerControlStateStore::DECISION_KEY, [
            'mode' => 'shadow',
            'profile' => 'balanced',
            'observed_at' => time() - 10,
            'backfill_permitted' => true,
            'eligible_nzbs' => 0,
            'body_recovery_queue' => 17,
            'collection_backlogs' => ['total' => 70, 'ordinary' => 30, 'body_recovery_sources' => 40],
            'database_contention' => [
                'row_lock_waits' => 1868,
                'row_lock_delta' => 2,
                'instant_rate_per_minute' => 2.6,
                'window_rate_per_minute' => 1.5,
                'admission_blocked' => false,
                'hard_breach_at' => 0,
                'current_wait_started_at' => 0,
                'admission_safe' => true,
            ],
            'backlogs' => ['parts' => 100, 'binaries' => 20, 'collections' => 30, 'collections_total' => 70, 'recovery_sources' => 40, 'releases' => 4, 'nzbs' => 50],
            'rates_per_minute' => ['parts' => 1.5, 'collections_total' => -2.0, 'recovery_sources' => -2.0],
            'ewma_per_minute' => ['parts' => 0.5, 'collections_total' => -1.0, 'recovery_sources' => -1.0],
            'oldest_age_seconds' => ['binaries' => 11, 'collections' => 12, 'recovery_sources' => 15, 'releases' => 13, 'nzbs' => 14],
            'backfill_target' => ['group' => 'alt.test', 'cursor' => 12345, 'quantity' => 200000],
        ]);
        $store = new WorkerControlStateStore;
        $store->recordBackfillYield('alt.test', 10_000, 5, time());
        $store->storeState(new ControlState(ineffectiveBackfillPermitsByTarget: ['alt.test' => 1]));

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('orchestratorMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_orchestrator_mode_info{mode="shadow"} 1', $output);
        $this->assertStringContainsString('nntmux_orchestrator_profile_info{profile="balanced"} 1', $output);
        $this->assertStringContainsString('nntmux_orchestrator_stage_backlog{stage="parts"} 100', $output);
        $this->assertStringContainsString('nntmux_orchestrator_stage_rate_per_minute{stage="parts",estimator="ewma"} 0.5', $output);
        $this->assertStringContainsString('nntmux_orchestrator_stage_projected_runway_minutes{stage="parts"} 1800', $output);
        $this->assertStringContainsString('nntmux_orchestrator_stage_oldest_age_seconds{stage="nzbs"} 14', $output);
        $this->assertStringContainsString('nntmux_orchestrator_backfill_policy_permitted 1', $output);
        $this->assertStringContainsString('nntmux_orchestrator_body_recovery_queue 17', $output);
        $this->assertStringContainsString('nntmux_orchestrator_collection_backlog{scope="total"} 70', $output);
        $this->assertStringContainsString('nntmux_orchestrator_collection_backlog{scope="ordinary"} 30', $output);
        $this->assertStringContainsString('nntmux_orchestrator_collection_backlog{scope="body_recovery_sources"} 40', $output);
        $this->assertStringContainsString('nntmux_orchestrator_database_row_lock_waits 1868', $output);
        $this->assertStringContainsString('nntmux_orchestrator_database_row_lock_delta 2', $output);
        $this->assertStringContainsString('nntmux_orchestrator_database_row_lock_instant_rate_per_minute 2.6', $output);
        $this->assertStringContainsString('nntmux_orchestrator_database_row_lock_window_rate_per_minute 1.5', $output);
        $this->assertStringContainsString('nntmux_orchestrator_database_admission_safe 1', $output);
        $this->assertStringContainsString('nntmux_orchestrator_stage_backlog{stage="collections_total"} 70', $output);
        $this->assertStringContainsString('nntmux_orchestrator_stage_backlog{stage="recovery_sources"} 40', $output);
        $this->assertStringContainsString('nntmux_orchestrator_stage_rate_per_minute{stage="recovery_sources",estimator="instant"} -2', $output);
        $this->assertStringContainsString('nntmux_orchestrator_stage_oldest_age_seconds{stage="recovery_sources"} 15', $output);
        $this->assertStringContainsString('nntmux_orchestrator_backfill_permit_quantity 200000', $output);
        $this->assertStringContainsString('nntmux_orchestrator_backfill_target_info{group="alt.test"} 1', $output);
        $this->assertStringContainsString('nntmux_orchestrator_backfill_planned_quantity{group="alt.test"} 200000', $output);
        $this->assertStringContainsString('nntmux_orchestrator_backfill_yield_nzbs_per_10k{group="alt.test"} 5', $output);
        $this->assertStringContainsString('nntmux_orchestrator_backfill_yield_attempts{group="alt.test"} 1', $output);
        $this->assertStringContainsString('nntmux_orchestrator_backfill_target_ineffective_permits{group="alt.test"} 1', $output);
        $this->assertStringContainsString('nntmux_orchestrator_backfill_target_locked{group="alt.test"} 0', $output);
        $this->assertStringContainsString('nntmux_orchestrator_current_forward_permit 0', $output);
        $this->assertStringContainsString('nntmux_orchestrator_current_forward_claim_in_progress 1', $output);
        $this->assertStringContainsString('nntmux_orchestrator_current_forward_claim_age_seconds 100', $output);
        $this->assertStringContainsString('nntmux_orchestrator_current_forward_quarantined_windows 2', $output);
        $this->assertStringContainsString('nntmux_orchestrator_current_forward_halted 0', $output);
        $this->assertStringContainsString('nntmux_orchestrator_current_forward_target_info{group="alt.test"} 1', $output);
    }

    public function test_persisted_fail_safe_overrides_a_stale_redis_decision(): void
    {
        config(['nntmux.orchestrator.state_store' => 'array']);
        DB::statement('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        DB::table('settings')->insert([
            ['name' => 'orchestrator_mode', 'value' => 'failsafe'],
            ['name' => 'orchestrator_profile', 'value' => 'fail_safe'],
        ]);
        Cache::store('array')->flush();
        Cache::store('array')->forever(WorkerControlStateStore::DECISION_KEY, [
            'mode' => 'active',
            'profile' => 'balanced',
            'observed_at' => time() - 20,
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('orchestratorMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_orchestrator_mode_info{mode="failsafe"} 1', $output);
        $this->assertStringContainsString('nntmux_orchestrator_profile_info{profile="fail_safe"} 1', $output);
    }

    public function test_body_recovery_claim_metrics_split_ready_claimed_expired_and_exhausted_rows(): void
    {
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR NOT NULL)');
        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
            groups_id INTEGER NOT NULL,
            attempts INTEGER NOT NULL,
            recovery_kind VARCHAR(32) NULL,
            claim_token VARCHAR(64) NULL,
            claim_expires_at DATETIME NULL
        )');
        DB::table('usenet_groups')->insert(['id' => 5, 'name' => 'alt.binaries.lossless']);
        DB::table('missed_parts')->insert([
            ['id' => 1, 'groups_id' => 5, 'attempts' => 0, 'recovery_kind' => 'body_preamble', 'claim_token' => null, 'claim_expires_at' => null],
            ['id' => 2, 'groups_id' => 5, 'attempts' => 1, 'recovery_kind' => 'body_preamble', 'claim_token' => 'active', 'claim_expires_at' => now()->addMinute()],
            ['id' => 3, 'groups_id' => 5, 'attempts' => 2, 'recovery_kind' => 'body_preamble', 'claim_token' => 'expired', 'claim_expires_at' => now()->subMinute()],
            ['id' => 4, 'groups_id' => 5, 'attempts' => 3, 'recovery_kind' => 'body_preamble', 'claim_token' => null, 'claim_expires_at' => null],
            ['id' => 5, 'groups_id' => 5, 'attempts' => 1, 'recovery_kind' => 'body_preamble', 'claim_token' => null, 'claim_expires_at' => now()->addMinute()],
        ]);

        $metrics = new NntmuxPrometheusMetrics;
        $method = (new ReflectionClass($metrics))->getMethod('bodyRecoveryClaimMetrics');
        $method->setAccessible(true);
        $output = implode("\n", $method->invoke($metrics));

        $this->assertStringContainsString('nntmux_body_recovery_rows{group="alt.binaries.lossless",state="ready"} 1', $output);
        $this->assertStringContainsString('nntmux_body_recovery_rows{group="alt.binaries.lossless",state="cooling"} 1', $output);
        $this->assertStringContainsString('nntmux_body_recovery_rows{group="alt.binaries.lossless",state="claimed"} 1', $output);
        $this->assertStringContainsString('nntmux_body_recovery_rows{group="alt.binaries.lossless",state="expired"} 1', $output);
        $this->assertStringContainsString('nntmux_body_recovery_rows{group="alt.binaries.lossless",state="exhausted"} 1', $output);
    }
}
