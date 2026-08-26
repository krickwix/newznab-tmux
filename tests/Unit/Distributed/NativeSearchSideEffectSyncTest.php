<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Facades\Search;
use App\Services\NameFixing\NativeHashedFixNameSearchSync;
use App\Services\NameFixing\NativeSearchSideEffectOutboxSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

class NativeSearchSideEffectSyncTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_syncs_search_for_committed_native_miss_status_release_ids(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(102);
        Search::shouldReceive('updateRelease')->once()->with(301);

        $result = (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'committed_release_ids' => [301, 102],
            'writes_committed' => 2,
        ]));

        $this->assertSame(1, $result['schema_version']);
        $this->assertSame('native-search-side-effect-sync', $result['mode']);
        $this->assertFalse($result['dry_run']);
        $this->assertSame('hashed-fixnames', $result['source_job']);
        $this->assertSame(2, $result['search_updates_seen']);
        $this->assertSame(2, $result['search_updates_synced']);
        $this->assertSame([102, 301], $result['release_ids']);
        $this->assertSame(0, $result['writes']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('redis_key', $encoded);
        $this->assertStringNotContainsString('nntmux_database', $encoded);
        $this->assertStringNotContainsString('--mysql-dsn', $encoded);
        $this->assertStringNotContainsString('Hash.Target', $encoded);
    }

    public function test_it_allows_no_op_reports_only_when_no_release_ids_are_present(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $result = (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'single_column_updates_attempted' => 0,
            'single_column_updates_committed' => 0,
            'single_column_updates_blocked' => 0,
            'single_column_rows_affected' => 0,
            'release_updates_blocked' => 0,
            'blocked_release_ids' => [],
            'blocked_status_release_ids' => [],
            'committed_release_ids' => [],
            'skipped_release_ids' => [],
            'writes_committed' => 0,
            'native_worker' => [
                'writes' => 0,
            ],
        ]));

        $this->assertSame(0, $result['search_updates_seen']);
        $this->assertSame(0, $result['search_updates_synced']);
        $this->assertSame([], $result['release_ids']);
    }

    public function test_it_rejects_dry_run_reports_without_search_updates(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native search sync requires a committed native report.');

        (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'dry_run' => true,
        ]));
    }

    public function test_it_rejects_reports_when_lock_was_not_acquired(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native search sync requires write_commit.lock_acquired=true.');

        (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'lock_acquired' => false,
        ]));
    }

    public function test_it_rejects_inconsistent_write_counts(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native search sync committed ID count does not match writes_committed.');

        (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'committed_release_ids' => [102],
            'writes_committed' => 2,
        ]));
    }

    public function test_it_rejects_mismatched_native_commit_count_fields(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native search sync write_commit.single_column_rows_affected does not match writes_committed.');

        (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'single_column_rows_affected' => 1,
        ]));
    }

    public function test_it_rejects_reports_with_fewer_attempted_updates_than_committed_writes(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native search sync write_commit.single_column_updates_attempted cannot be lower than writes_committed.');

        (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'single_column_updates_attempted' => 1,
            'single_column_updates_committed' => 2,
            'single_column_rows_affected' => 2,
            'writes_committed' => 2,
        ]));
    }

    public function test_it_rejects_duplicate_committed_release_ids(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native search sync committed_release_ids contains duplicate release ID [102].');

        (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'committed_release_ids' => [102, 301, 102],
        ]));
    }

    public function test_it_rejects_malformed_committed_release_ids(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native search sync committed_release_ids must contain positive integer release IDs.');

        (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'committed_release_ids' => [102, '301'],
        ]));
    }

    public function test_it_rejects_reports_with_only_skipped_or_blocked_release_ids(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native search sync cannot sync reports without committed release IDs when skipped or blocked release IDs are present.');

        (new NativeHashedFixNameSearchSync)->sync($this->commitReport([
            'single_column_updates_committed' => 0,
            'single_column_rows_affected' => 0,
            'blocked_release_ids' => [],
            'committed_release_ids' => [],
            'skipped_release_ids' => [102, 301],
            'writes_committed' => 0,
            'native_worker' => [
                'writes' => 0,
            ],
        ]));
    }

    public function test_it_reports_search_update_failures_without_committing_php_writes(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(102)->andThrow(new RuntimeException('backend URL contained credentials'));
        Search::shouldReceive('updateRelease')->never()->with(301);

        try {
            (new NativeHashedFixNameSearchSync)->sync($this->commitReport());
            $this->fail('Expected search sync failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Native search sync failed for release ID [102].', $exception->getMessage());
            $this->assertStringNotContainsString('credentials', $exception->getMessage());
        }
    }

    public function test_it_processes_pending_native_search_outbox_rows(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss');
        $this->insertNativeSearchSideEffect(301, 'proc_hash16k', 'par-hash-miss');

        Search::shouldReceive('updateRelease')->once()->with(102);
        Search::shouldReceive('updateRelease')->once()->with(301);

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame('native-search-side-effect-outbox-sync', $result['mode']);
        $this->assertSame(2, $result['search_updates_seen']);
        $this->assertSame(2, $result['search_updates_synced']);
        $this->assertSame(0, $result['search_updates_failed']);
        $this->assertSame([102, 301], $result['release_ids']);
        $this->assertSame([], $result['failed_release_ids']);
        $this->assertSame(0, $result['writes']);

        $rows = DB::table('native_worker_side_effects')->orderBy('release_id')->get();
        $this->assertSame('synced', $rows[0]->status);
        $this->assertSame(1, (int) $rows[0]->attempts);
        $this->assertNull($rows[0]->last_error_code);
        $this->assertNotNull($rows[0]->processed_at);
        $this->assertSame('synced', $rows[1]->status);
        $this->assertSame(1, (int) $rows[1]->attempts);
    }

    public function test_it_processes_pending_metadata_predb_search_outbox_rows(): void
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

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame('native-search-side-effect-outbox-sync', $result['mode']);
        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(1, $result['search_updates_synced']);
        $this->assertSame(0, $result['search_updates_failed']);
        $this->assertSame([], $result['release_ids']);
        $this->assertSame([501], $result['predb_ids']);

        $row = DB::table('native_worker_side_effects')->where('release_id', 501)->first();
        $this->assertSame('synced', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNull($row->last_error_code);
        $this->assertNotNull($row->processed_at);
    }

    public function test_it_processes_pending_irc_predb_search_outbox_rows(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        $this->createPredbTable();
        DB::table('predb')->insert([
            'id' => 601,
            'title' => 'Irc.Movie.2026.1080p-GRP',
            'source' => '#pre',
        ]);
        $this->insertNativePredbSearchSideEffect(601, [
            'operation_key' => 'irc:predb-search:v1:601',
            'job' => 'irc',
        ]);

        Search::shouldReceive('updateRelease')->never();
        Search::shouldReceive('insertPredb')
            ->once()
            ->with([
                'id' => 601,
                'title' => 'Irc.Movie.2026.1080p-GRP',
                'filename' => '',
                'source' => '#pre',
            ]);

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10, sourceJob: 'irc');

        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(1, $result['search_updates_synced']);
        $this->assertSame(0, $result['search_updates_failed']);
        $this->assertSame([], $result['release_ids']);
        $this->assertSame([601], $result['predb_ids']);

        $row = DB::table('native_worker_side_effects')->where('release_id', 601)->first();
        $this->assertSame('synced', $row->status);
        $this->assertSame(1, (int) $row->attempts);
    }

    public function test_unscoped_pending_outbox_includes_irc_predb_search_rows(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        $this->createPredbTable();
        DB::table('predb')->insert([
            'id' => 602,
            'title' => 'Irc.Show.2026-GRP',
            'source' => '#pre',
        ]);
        $this->insertNativePredbSearchSideEffect(602, [
            'operation_key' => 'irc:predb-search:v1:602',
            'job' => 'irc',
        ]);

        Search::shouldReceive('updateRelease')->never();
        Search::shouldReceive('insertPredb')->once();

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(1, $result['search_updates_synced']);
        $this->assertSame([602], $result['predb_ids']);
    }

    public function test_it_clears_claim_lease_metadata_after_syncing_pending_outbox_rows(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss', [
            'status' => 'processing',
            'attempts' => 2,
            'available_at' => now()->subMinute(),
            'last_error_code' => 'search-update-failed',
        ]);

        Search::shouldReceive('updateRelease')->once()->with(102);

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(1, $result['search_updates_synced']);

        $synced = DB::table('native_worker_side_effects')->where('release_id', 102)->first();
        $this->assertSame('synced', $synced->status);
        $this->assertSame(3, (int) $synced->attempts);
        $this->assertNull($synced->available_at);
        $this->assertNull($synced->last_error_code);
        $this->assertNotNull($synced->processed_at);
    }

    public function test_it_leaves_failed_outbox_rows_retryable_without_leaking_backend_errors(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        config(['nntmux.native_worker_search_outbox_max_attempts' => 5]);
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss');
        $this->insertNativeSearchSideEffect(301, 'proc_hash16k', 'par-hash-miss');

        Search::shouldReceive('updateRelease')->once()->with(102)->andThrow(new RuntimeException('backend URL contained credentials'));
        Search::shouldReceive('updateRelease')->once()->with(301);

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame(2, $result['search_updates_seen']);
        $this->assertSame(1, $result['search_updates_synced']);
        $this->assertSame(1, $result['search_updates_failed']);
        $this->assertSame(0, $result['search_updates_dead_lettered']);
        $this->assertSame([102], $result['failed_release_ids']);
        $this->assertStringNotContainsString('credentials', json_encode($result, JSON_THROW_ON_ERROR));

        $failed = DB::table('native_worker_side_effects')->where('release_id', 102)->first();
        $this->assertSame('pending', $failed->status);
        $this->assertSame(1, (int) $failed->attempts);
        $this->assertSame('search-update-failed', $failed->last_error_code);
        $this->assertNull($failed->processed_at);

        $synced = DB::table('native_worker_side_effects')->where('release_id', 301)->first();
        $this->assertSame('synced', $synced->status);
        $this->assertSame(1, (int) $synced->attempts);
    }

    public function test_it_dead_letters_outbox_rows_after_retry_budget_without_leaking_backend_errors(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        config(['nntmux.native_worker_search_outbox_max_attempts' => 2]);
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss', [
            'attempts' => 1,
        ]);

        Search::shouldReceive('updateRelease')->once()->with(102)->andThrow(new RuntimeException('backend URL contained credentials'));

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(0, $result['search_updates_synced']);
        $this->assertSame(1, $result['search_updates_failed']);
        $this->assertSame(1, $result['search_updates_dead_lettered']);
        $this->assertSame([102], $result['failed_release_ids']);
        $this->assertSame([102], $result['dead_lettered_release_ids']);
        $this->assertStringNotContainsString('credentials', json_encode($result, JSON_THROW_ON_ERROR));

        $failed = DB::table('native_worker_side_effects')->where('release_id', 102)->first();
        $this->assertSame('failed', $failed->status);
        $this->assertSame(2, (int) $failed->attempts);
        $this->assertSame('search-update-failed', $failed->last_error_code);
        $this->assertNull($failed->available_at);
        $this->assertNotNull($failed->processed_at);
    }

    public function test_it_retries_expired_processing_outbox_rows_but_skips_live_leases(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss', [
            'status' => 'processing',
            'available_at' => now()->subMinute(),
        ]);
        $this->insertNativeSearchSideEffect(301, 'proc_hash16k', 'par-hash-miss', [
            'status' => 'processing',
            'available_at' => now()->addMinutes(5),
        ]);

        Search::shouldReceive('updateRelease')->once()->with(102);
        Search::shouldReceive('updateRelease')->never()->with(301);

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(1, $result['search_updates_synced']);
        $this->assertSame([102], $result['release_ids']);

        $this->assertSame('synced', DB::table('native_worker_side_effects')->where('release_id', 102)->value('status'));
        $this->assertSame('processing', DB::table('native_worker_side_effects')->where('release_id', 301)->value('status'));
    }

    public function test_stale_success_does_not_overwrite_newer_retryable_outbox_state(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss');

        Search::shouldReceive('updateRelease')->once()->with(102)->andReturnUsing(function (): void {
            DB::table('native_worker_side_effects')
                ->where('release_id', 102)
                ->update([
                    'status' => 'pending',
                    'attempts' => 2,
                    'available_at' => now()->addMinute(),
                    'processed_at' => null,
                    'last_error_code' => 'search-update-failed',
                    'updated_at' => now(),
                ]);
        });

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(0, $result['search_updates_synced']);
        $this->assertSame(0, $result['search_updates_failed']);

        $row = DB::table('native_worker_side_effects')->where('release_id', 102)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame('search-update-failed', $row->last_error_code);
        $this->assertNotNull($row->available_at);
        $this->assertNull($row->processed_at);
    }

    public function test_stale_failure_does_not_overwrite_newer_synced_outbox_state(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss');

        Search::shouldReceive('updateRelease')->once()->with(102)->andReturnUsing(function (): void {
            DB::table('native_worker_side_effects')
                ->where('release_id', 102)
                ->update([
                    'status' => 'synced',
                    'attempts' => 2,
                    'available_at' => null,
                    'processed_at' => now(),
                    'last_error_code' => null,
                    'updated_at' => now(),
                ]);

            throw new RuntimeException('backend URL contained credentials');
        });

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(0, $result['search_updates_synced']);
        $this->assertSame(0, $result['search_updates_failed']);
        $this->assertSame([], $result['failed_release_ids']);

        $row = DB::table('native_worker_side_effects')->where('release_id', 102)->first();
        $this->assertSame('synced', $row->status);
        $this->assertSame(2, (int) $row->attempts);
        $this->assertNull($row->available_at);
        $this->assertNull($row->last_error_code);
        $this->assertNotNull($row->processed_at);
    }

    public function test_stale_dead_letter_does_not_overwrite_newer_synced_outbox_state(): void
    {
        $this->createNativeWorkerSideEffectsTable();
        config(['nntmux.native_worker_search_outbox_max_attempts' => 1]);
        $this->insertNativeSearchSideEffect(102, 'proc_crc32', 'crc-miss');

        Search::shouldReceive('updateRelease')->once()->with(102)->andReturnUsing(function (): void {
            DB::table('native_worker_side_effects')
                ->where('release_id', 102)
                ->update([
                    'status' => 'synced',
                    'attempts' => 2,
                    'available_at' => null,
                    'processed_at' => now(),
                    'last_error_code' => null,
                    'updated_at' => now(),
                ]);

            throw new RuntimeException('backend URL contained credentials');
        });

        $result = (new NativeSearchSideEffectOutboxSync)->syncPending(limit: 10);

        $this->assertSame(1, $result['search_updates_seen']);
        $this->assertSame(0, $result['search_updates_synced']);
        $this->assertSame(0, $result['search_updates_failed']);
        $this->assertSame(0, $result['search_updates_dead_lettered']);
        $this->assertSame([], $result['failed_release_ids']);
        $this->assertSame([], $result['dead_lettered_release_ids']);

        $row = DB::table('native_worker_side_effects')->where('release_id', 102)->first();
        $this->assertSame('synced', $row->status);
        $this->assertSame(2, (int) $row->attempts);
        $this->assertNull($row->available_at);
        $this->assertNull($row->last_error_code);
        $this->assertNotNull($row->processed_at);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commitReport(array $overrides = []): array
    {
        $writeCommit = [
            'single_column_updates_attempted' => 2,
            'single_column_updates_committed' => 2,
            'single_column_updates_skipped' => 0,
            'single_column_updates_blocked' => 1,
            'single_column_rows_affected' => 2,
            'release_updates_blocked' => 2,
            'blocked_release_ids' => [300, 100],
            'blocked_status_release_ids' => [100],
            'committed_release_ids' => [102, 301],
            'skipped_release_ids' => [],
            'lock_acquired' => true,
            'writes_committed' => 2,
        ];

        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $writeCommit)) {
                $writeCommit[$key] = $value;
                unset($overrides[$key]);
            }
        }

        return array_replace_recursive([
            'schema_version' => 1,
            'mode' => 'shadow',
            'dry_run' => false,
            'native_worker' => [
                'job' => 'hashed-fixnames',
                'writes' => 2,
            ],
            'hashed_fixnames' => [
                'write_commit' => $writeCommit,
            ],
        ], $overrides);
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
