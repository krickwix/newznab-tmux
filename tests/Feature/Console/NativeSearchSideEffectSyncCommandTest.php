<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class NativeSearchSideEffectSyncCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $reportPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        foreach ([
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'innerfileblacklist' => '',
        ] as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->reportPath !== '' && is_file($this->reportPath)) {
            unlink($this->reportPath);
        }

        parent::tearDown();
    }

    public function test_it_syncs_search_side_effects_from_native_commit_report_file(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(102);
        Search::shouldReceive('updateRelease')->once()->with(301);

        $this->reportPath = sys_get_temp_dir().'/native-search-sync-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->reportPath, json_encode($this->commitReport(), JSON_THROW_ON_ERROR));

        $output = new BufferedOutput;
        $exitCode = Artisan::call('nntmux:native-search-side-effects:sync', [
            '--input' => $this->reportPath,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(0, $exitCode, $captured);

        $json = trim($captured);
        $this->assertStringStartsWith('{', $json, $json);
        $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('native-search-side-effect-sync', $result['mode']);
        $this->assertSame(2, $result['search_updates_synced']);
        $this->assertSame([102, 301], $result['release_ids']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('redis_key', $encoded);
        $this->assertStringNotContainsString('nntmux_database', $encoded);
        $this->assertStringNotContainsString('Hash.Target', $encoded);
    }

    public function test_it_fails_without_syncing_search_for_dry_run_input(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->reportPath = sys_get_temp_dir().'/native-search-sync-'.bin2hex(random_bytes(6)).'.json';
        $payload = $this->commitReport();
        $payload['dry_run'] = true;
        file_put_contents($this->reportPath, json_encode($payload, JSON_THROW_ON_ERROR));

        $output = new BufferedOutput;
        $exitCode = Artisan::call('nntmux:native-search-side-effects:sync', [
            '--input' => $this->reportPath,
        ], $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Native search sync requires a committed native report.', $output->fetch());
    }

    public function test_it_reports_search_update_failures_without_leaking_backend_errors(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(102)->andThrow(new RuntimeException('backend URL contained credentials'));
        Search::shouldReceive('updateRelease')->never()->with(301);

        $this->reportPath = sys_get_temp_dir().'/native-search-sync-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->reportPath, json_encode($this->commitReport(), JSON_THROW_ON_ERROR));

        $output = new BufferedOutput;
        $exitCode = Artisan::call('nntmux:native-search-side-effects:sync', [
            '--input' => $this->reportPath,
        ], $output);

        $captured = $output->fetch();
        $this->assertSame(1, $exitCode, $captured);

        $result = json_decode(trim($captured), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('native-search-side-effect-sync', $result['mode']);
        $this->assertSame(2, $result['search_updates_seen']);
        $this->assertSame(0, $result['search_updates_synced']);
        $this->assertSame(1, $result['search_updates_failed']);
        $this->assertSame([102], $result['failed_release_ids']);
        $this->assertStringNotContainsString('credentials', $captured);
    }

    public function test_it_syncs_pending_native_search_outbox_rows(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss');
        $this->insertNativeSearchSideEffect(301, 'proc_hash16k', 'par-hash-miss');

        Search::shouldReceive('updateRelease')->once()->with(102);
        Search::shouldReceive('updateRelease')->once()->with(301);

        $output = new BufferedOutput;
        $exitCode = Artisan::call('nntmux:native-search-side-effects:sync', [
            '--pending-outbox' => true,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(0, $exitCode, $captured);

        $result = json_decode(trim($captured), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('native-search-side-effect-outbox-sync', $result['mode']);
        $this->assertSame(2, $result['search_updates_seen']);
        $this->assertSame(2, $result['search_updates_synced']);
        $this->assertSame([102, 301], $result['release_ids']);

        $this->assertSame('synced', DB::table('native_worker_side_effects')->where('release_id', 102)->value('status'));
        $this->assertSame('synced', DB::table('native_worker_side_effects')->where('release_id', 301)->value('status'));
        $this->assertStringNotContainsString('Hash.Target', $captured);
        $this->assertStringNotContainsString('nntmux_database', $captured);
    }

    public function test_it_syncs_pending_metadata_predb_search_outbox_rows(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        $this->createPredbTable();
        DB::table('predb')->insert([
            'id' => 501,
            'title' => 'PredbNet.Movie.2026.1080p-GRP',
            'source' => 'predb-net',
        ]);
        $this->insertNativePredbSearchSideEffect(501);

        Search::shouldReceive('updateRelease')->never();
        Search::shouldReceive('insertPredb')
            ->once()
            ->with([
                'id' => 501,
                'title' => 'PredbNet.Movie.2026.1080p-GRP',
                'filename' => '',
                'source' => 'predb-net',
            ]);

        $output = new BufferedOutput;
        $exitCode = Artisan::call('nntmux:native-search-side-effects:sync', [
            '--pending-outbox' => true,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(0, $exitCode, $captured);

        $result = json_decode(trim($captured), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('native-search-side-effect-outbox-sync', $result['mode']);
        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(1, $result['search_updates_synced']);
        $this->assertSame([501], $result['predb_ids']);

        $this->assertSame('synced', DB::table('native_worker_side_effects')->where('release_id', 501)->value('status'));
        $this->assertStringNotContainsString('PredbNet.Movie', $captured);
        $this->assertStringNotContainsString('nntmux_database', $captured);
    }

    public function test_it_reports_dead_lettered_pending_native_search_outbox_rows(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        config(['nntmux.native_worker_search_outbox_max_attempts' => 2]);
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss', [
            'attempts' => 1,
        ]);

        Search::shouldReceive('updateRelease')->once()->with(102)->andThrow(new RuntimeException('backend URL contained credentials'));

        $output = new BufferedOutput;
        $exitCode = Artisan::call('nntmux:native-search-side-effects:sync', [
            '--pending-outbox' => true,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(1, $exitCode, $captured);

        $result = json_decode(trim($captured), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('native-search-side-effect-outbox-sync', $result['mode']);
        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(1, $result['search_updates_failed']);
        $this->assertSame(1, $result['search_updates_dead_lettered']);
        $this->assertSame([102], $result['dead_lettered_release_ids']);
        $this->assertSame('failed', DB::table('native_worker_side_effects')->where('release_id', 102)->value('status'));
        $this->assertStringNotContainsString('credentials', $captured);
    }

    /**
     * @return array<string, mixed>
     */
    private function commitReport(): array
    {
        return [
            'schema_version' => 1,
            'mode' => 'shadow',
            'dry_run' => false,
            'native_worker' => [
                'job' => 'hashed-fixnames',
                'writes' => 2,
            ],
            'hashed_fixnames' => [
                'write_commit' => [
                    'single_column_updates_committed' => 2,
                    'single_column_rows_affected' => 2,
                    'committed_release_ids' => [301, 102],
                    'lock_acquired' => true,
                    'writes_committed' => 2,
                ],
            ],
        ];
    }

    private function createNativeWorkerSideEffectsTable(): void
    {
        Schema::dropIfExists('native_worker_side_effects');
        Schema::create('native_worker_side_effects', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key')->unique();
            $table->string('job', 64);
            $table->string('effect', 64);
            $table->unsignedBigInteger('release_id');
            $table->string('status_column', 32);
            $table->string('status_reason', 64);
            $table->unsignedTinyInteger('status_value');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();
        });
    }

    private function createPredbTable(): void
    {
        Schema::dropIfExists('predb');
        Schema::create('predb', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('title');
            $table->string('source')->default('');
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertNativeSearchSideEffect(int $releaseId, string $statusColumn, string $statusReason, array $overrides = []): void
    {
        DB::table('native_worker_side_effects')->insert(array_replace([
            'operation_key' => "hashed-fixnames:miss-status:v1:{$releaseId}:{$statusColumn}:1:{$statusReason}",
            'job' => 'hashed-fixnames',
            'effect' => 'release-search-sync',
            'release_id' => $releaseId,
            'status_column' => $statusColumn,
            'status_reason' => $statusReason,
            'status_value' => 1,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertNativePredbSearchSideEffect(int $predbId, array $overrides = []): void
    {
        DB::table('native_worker_side_effects')->insert(array_replace([
            'operation_key' => "metadata-refresh:predb-search:v1:{$predbId}",
            'job' => 'metadata-refresh',
            'effect' => 'predb-search-sync',
            'release_id' => $predbId,
            'status_column' => 'predb_id',
            'status_reason' => 'predb-import',
            'status_value' => 1,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
