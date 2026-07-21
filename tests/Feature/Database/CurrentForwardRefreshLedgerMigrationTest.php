<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Settings;
use App\Services\Distributed\CurrentForwardPermitGate;
use App\Services\Orchestrator\PipelineSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurrentForwardRefreshLedgerMigrationTest extends TestCase
{
    public function test_verification_fence_migration_is_retry_safe_on_sqlite(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        $ledger = require database_path('migrations/2026_07_17_120000_create_current_forward_refresh_ledger.php');
        $ledger->up();
        $issuanceGuards = require database_path('migrations/2026_07_17_140000_add_current_forward_refresh_issuance_guards.php');
        $issuanceGuards->up();
        $verificationFences = require database_path('migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php');

        $verificationFences->up();
        $verificationFences->up();

        self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_state_generation_id_idx'));
        self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_state_id_idx'));

        $verificationFences->down();
        $verificationFences->down();

        self::assertFalse(Schema::hasTable('current_forward_window_verifications'));
        self::assertFalse(Schema::hasColumn('current_forward_windows', 'issued_verification_id'));

        $issuanceGuards->down();
        $ledger->down();
    }

    public function test_additive_ledger_schema_round_trips_on_sqlite(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        $migration = require database_path('migrations/2026_07_17_120000_create_current_forward_refresh_ledger.php');
        $migration->up();
        $checkRepair = require database_path('migrations/2026_07_17_130000_add_current_forward_refresh_ledger_checks.php');
        $checkRepair->up();
        $issuanceGuards = require database_path('migrations/2026_07_17_140000_add_current_forward_refresh_issuance_guards.php');
        $issuanceGuards->up();
        $verificationFences = require database_path('migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php');
        $verificationFences->up();

        self::assertTrue(Schema::hasColumns('current_forward_sources', [
            'groups_id',
            'group_name',
            'anchor_first',
            'audited_last',
            'state',
            'strikes',
        ]));
        self::assertTrue(Schema::hasColumns('current_forward_windows', [
            'source_id',
            'first_article',
            'last_article',
            'provider_high',
            'evidence_hash',
            'state',
            'generation',
            'cursor_end_postdate',
            'issued_verification_id',
            'observation_hash',
            'drain_deadline_at',
        ]));
        self::assertTrue(Schema::hasColumns('current_forward_window_verifications', [
            'window_id',
            'provider_observed_at',
            'evidence_hash',
            'idempotency_key',
            'verified_at',
        ]));

        DB::table('current_forward_sources')->insert([
            'id' => 1,
            'groups_id' => 1,
            'group_name' => 'alt.test',
            'anchor_first' => 101,
            'audited_last' => 10_100,
            'state' => 'READY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $window = [
            'source_id' => 1,
            'first_article' => 10_101,
            'last_article' => 20_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => now(),
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 1,
            'evidence_hash' => str_repeat('a', 64),
            'policy_version' => 'exact-xover-v1',
            'state' => 'AUDITED',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('current_forward_windows')->insert($window);
        try {
            DB::table('current_forward_windows')->insert($window);
            self::fail('The immutable source/range uniqueness constraint was not enforced.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('current_forward_windows')->count());
        }

        $verificationFences->down();
        $issuanceGuards->down();
        $checkRepair->down();
        $migration->down();

        self::assertFalse(Schema::hasTable('current_forward_windows'));
        self::assertFalse(Schema::hasTable('current_forward_sources'));
        self::assertFalse(Schema::hasTable('current_forward_window_verifications'));
    }

    public function test_check_migrations_explicitly_support_the_laravel_mariadb_driver(): void
    {
        foreach ([
            '2026_07_17_120000_create_current_forward_refresh_ledger.php',
            '2026_07_17_130000_add_current_forward_refresh_ledger_checks.php',
            '2026_07_17_140000_add_current_forward_refresh_issuance_guards.php',
            '2026_07_18_024500_add_current_forward_retry_attempts.php',
        ] as $filename) {
            $source = file_get_contents(database_path('migrations/'.$filename));

            self::assertIsString($source);
            self::assertStringContainsString("['mysql', 'mariadb']", $source);
        }
    }

    public function test_mariadb_driver_enforces_all_ledger_checks_when_opted_in(): void
    {
        if (getenv('NNTMUX_MARIADB_TEST') !== '1') {
            self::markTestSkipped('Set NNTMUX_MARIADB_TEST=1 with the isolated MariaDB connection variables.');
        }

        config([
            'database.default' => 'mariadb',
            'database.connections.mariadb.host' => (string) getenv('NNTMUX_MARIADB_HOST'),
            'database.connections.mariadb.port' => (string) (getenv('NNTMUX_MARIADB_PORT') ?: '3306'),
            'database.connections.mariadb.database' => (string) getenv('NNTMUX_MARIADB_DATABASE'),
            'database.connections.mariadb.username' => (string) getenv('NNTMUX_MARIADB_USERNAME'),
            'database.connections.mariadb.password' => (string) getenv('NNTMUX_MARIADB_PASSWORD'),
        ]);
        DB::purge();
        DB::reconnect();

        Schema::dropIfExists('current_forward_window_verifications');
        Schema::dropIfExists('current_forward_windows');
        Schema::dropIfExists('current_forward_sources');
        $migration = require database_path('migrations/2026_07_17_120000_create_current_forward_refresh_ledger.php');
        $checkRepair = require database_path('migrations/2026_07_17_130000_add_current_forward_refresh_ledger_checks.php');
        $issuanceGuards = require database_path('migrations/2026_07_17_140000_add_current_forward_refresh_issuance_guards.php');
        $verificationFences = require database_path('migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php');
        $retryAttempts = require database_path('migrations/2026_07_18_024500_add_current_forward_retry_attempts.php');

        try {
            $migration->up();
            $checkRepair->up();
            $issuanceGuards->up();
            $verificationFences->up();
            $retryAttempts->up();

            self::assertSame('mariadb', Schema::getConnection()->getDriverName());
            self::assertSame(6, DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
                ->whereIn('TABLE_NAME', ['current_forward_sources', 'current_forward_windows'])
                ->where('CONSTRAINT_TYPE', 'CHECK')
                ->count());
            self::assertSame(1, DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
                ->where('TABLE_NAME', 'current_forward_windows')
                ->where('CONSTRAINT_NAME', 'cf_windows_retry_parent_fk')
                ->where('DELETE_RULE', 'RESTRICT')
                ->count());
            self::assertSame([
                'source_id',
                'first_article',
                'last_article',
                'range_live_slot',
            ], DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
                ->where('TABLE_NAME', 'current_forward_windows')
                ->where('INDEX_NAME', 'cf_windows_source_range_live_uq')
                ->where('NON_UNIQUE', 0)
                ->orderBy('SEQ_IN_INDEX')
                ->pluck('COLUMN_NAME')
                ->all());
            $attemptColumn = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
                ->where('TABLE_NAME', 'current_forward_windows')
                ->where('COLUMN_NAME', 'attempt_ordinal')
                ->first();
            self::assertSame('NO', $attemptColumn->IS_NULLABLE ?? null);
            self::assertSame('1', (string) ($attemptColumn->COLUMN_DEFAULT ?? ''));
            self::assertStringContainsString('unsigned', (string) ($attemptColumn->COLUMN_TYPE ?? ''));
            $liveSlot = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
                ->where('TABLE_NAME', 'current_forward_windows')
                ->where('COLUMN_NAME', 'range_live_slot')
                ->first();
            self::assertStringContainsString('STORED GENERATED', (string) ($liveSlot->EXTRA ?? ''));
            self::assertSame(1, DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
                ->where('TABLE_NAME', 'current_forward_windows')
                ->where('INDEX_NAME', 'cf_windows_unresolved_uq')
                ->where('NON_UNIQUE', 0)
                ->count());
            self::assertTrue(Schema::hasColumns('current_forward_windows', [
                'issued_verification_id',
                'attribution_started_at',
                'zero_output_deadline_at',
                'drain_deadline_at',
                'observation_hash',
                'observation_stable_since_at',
                'last_observed_at',
                'outcome_release_high',
                'outcome_pending_collections',
            ]));
            self::assertTrue(Schema::hasColumns('current_forward_window_verifications', [
                'window_id',
                'provider_first',
                'provider_high',
                'provider_observed_at',
                'evidence_hash',
                'idempotency_key',
                'verified_at',
            ]));
            self::assertSame(['window_id', 'idempotency_key'], DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
                ->where('TABLE_NAME', 'current_forward_window_verifications')
                ->where('INDEX_NAME', 'cf_verifications_window_key_uq')
                ->where('NON_UNIQUE', 0)
                ->orderBy('SEQ_IN_INDEX')
                ->pluck('COLUMN_NAME')
                ->all());

            $sourceId = DB::table('current_forward_sources')->insertGetId([
                'groups_id' => 1,
                'group_name' => 'alt.valid',
                'anchor_first' => 1,
                'audited_last' => 20_000,
                'state' => 'READY',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $firstWindowId = DB::table('current_forward_windows')->insertGetId([
                'source_id' => $sourceId,
                'first_article' => 1,
                'last_article' => 10_000,
                'provider_first' => 1,
                'provider_high' => 30_000,
                'provider_observed_at' => now(),
                'headers' => 10_000,
                'yenc_headers' => 10_000,
                'multipart_headers' => 10_000,
                'complete_binary_files' => 1,
                'evidence_hash' => str_repeat('a', 64),
                'policy_version' => 'exact-xover-v1',
                'state' => 'AUDITED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $secondWindowId = DB::table('current_forward_windows')->insertGetId([
                'source_id' => $sourceId,
                'first_article' => 10_001,
                'last_article' => 20_000,
                'provider_first' => 1,
                'provider_high' => 40_000,
                'provider_observed_at' => now(),
                'headers' => 10_000,
                'yenc_headers' => 10_000,
                'multipart_headers' => 10_000,
                'complete_binary_files' => 1,
                'evidence_hash' => str_repeat('b', 64),
                'policy_version' => 'exact-xover-v1',
                'state' => 'AUDITED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $verification = [
                'window_id' => $firstWindowId,
                'provider_first' => 1,
                'provider_high' => 30_000,
                'provider_observed_at' => now(),
                'headers' => 10_000,
                'yenc_headers' => 10_000,
                'multipart_headers' => 10_000,
                'complete_binary_files' => 1,
                'evidence_hash' => str_repeat('a', 64),
                'policy_version' => 'exact-xover-v1',
                'idempotency_key' => str_repeat('c', 64),
                'verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('current_forward_window_verifications')->insert($verification);
            $this->assertQueryRejected(
                static fn () => DB::table('current_forward_window_verifications')->insert($verification),
                'The append-only verification idempotency fence was not enforced.',
            );

            DB::table('current_forward_windows')->where('id', $firstWindowId)->update(['state' => 'OFFERED']);
            $this->assertQueryRejected(
                static fn () => DB::table('current_forward_windows')
                    ->where('id', $secondWindowId)
                    ->update(['state' => 'CLAIMED']),
                'The single unresolved-window fence was not enforced.',
            );
            DB::table('current_forward_windows')->where('id', $firstWindowId)->update(['state' => 'PRODUCTIVE']);
            self::assertSame(
                1,
                DB::table('current_forward_windows')
                    ->where('id', $secondWindowId)
                    ->update(['state' => 'OFFERED']),
                'A terminal transition did not release the unresolved slot.',
            );

            $this->expectException(QueryException::class);
            DB::table('current_forward_sources')->insert([
                'groups_id' => 1,
                'group_name' => 'alt.invalid',
                'anchor_first' => 1,
                'audited_last' => 10_000,
                'state' => 'INVALID',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $retryAttempts->down();
            $verificationFences->down();
            $issuanceGuards->down();
            $checkRepair->down();
            $migration->down();
        }
    }

    public function test_mariadb_allows_exactly_one_concurrent_audited_window_offer_when_opted_in(): void
    {
        if (getenv('NNTMUX_MARIADB_TEST') !== '1') {
            self::markTestSkipped('Set NNTMUX_MARIADB_TEST=1 with the isolated MariaDB connection variables.');
        }
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('The MariaDB race test requires pcntl.');
        }

        config([
            'database.default' => 'mariadb',
            'database.connections.mariadb.host' => (string) getenv('NNTMUX_MARIADB_HOST'),
            'database.connections.mariadb.port' => (string) (getenv('NNTMUX_MARIADB_PORT') ?: '3306'),
            'database.connections.mariadb.database' => (string) getenv('NNTMUX_MARIADB_DATABASE'),
            'database.connections.mariadb.username' => (string) getenv('NNTMUX_MARIADB_USERNAME'),
            'database.connections.mariadb.password' => (string) getenv('NNTMUX_MARIADB_PASSWORD'),
            'cache.default' => 'array',
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.distributed_current_forward_max_run_seconds' => 60,
            'nntmux.orchestrator.current_forward_windows' => 'alt.test:101-10100@30100',
            'nntmux.orchestrator.current_forward_ledger_issuance_enabled' => true,
            'nntmux.orchestrator.current_forward_audit_max_age_seconds' => 900,
        ]);
        DB::purge();
        DB::reconnect();

        foreach (['current_forward_window_verifications', 'current_forward_windows', 'current_forward_sources', 'short_groups', 'usenet_groups', 'releases', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
        $migration = require database_path('migrations/2026_07_17_120000_create_current_forward_refresh_ledger.php');
        $checkRepair = require database_path('migrations/2026_07_17_130000_add_current_forward_refresh_ledger_checks.php');
        $issuanceGuards = require database_path('migrations/2026_07_17_140000_add_current_forward_refresh_issuance_guards.php');
        $verificationFences = require database_path('migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php');
        $files = [];
        $pids = [];

        try {
            Schema::create('settings', function ($table): void {
                $table->string('name', 25)->primary();
                $table->text('value');
            });
            Schema::create('usenet_groups', function ($table): void {
                $table->unsignedInteger('id')->primary();
                $table->string('name')->unique();
                $table->boolean('active');
                $table->boolean('backfill');
                $table->unsignedBigInteger('last_record');
                $table->dateTime('last_record_postdate')->nullable();
            });
            Schema::create('short_groups', function ($table): void {
                $table->id();
                $table->string('name')->index();
                $table->unsignedBigInteger('first_record');
                $table->unsignedBigInteger('last_record');
                $table->dateTime('updated');
            });
            Schema::create('releases', function ($table): void {
                $table->id();
            });
            $migration->up();
            $checkRepair->up();
            $issuanceGuards->up();
            $verificationFences->up();

            DB::table('usenet_groups')->insert([
                'id' => 1,
                'name' => 'alt.test',
                'active' => 0,
                'backfill' => 1,
                'last_record' => 10_100,
                'last_record_postdate' => now()->subMinute(),
            ]);
            DB::table('short_groups')->insert([
                'name' => 'alt.test',
                'first_record' => 1,
                'last_record' => 50_100,
                'updated' => now(),
            ]);
            $sourceId = DB::table('current_forward_sources')->insertGetId([
                'groups_id' => 1,
                'group_name' => 'alt.test',
                'anchor_first' => 101,
                'audited_last' => 20_100,
                'state' => 'READY',
                'last_audited_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $windowId = DB::table('current_forward_windows')->insertGetId([
                'source_id' => $sourceId,
                'first_article' => 10_101,
                'last_article' => 20_100,
                'provider_first' => 1,
                'provider_high' => 50_100,
                'provider_observed_at' => now(),
                'headers' => 10_000,
                'yenc_headers' => 10_000,
                'multipart_headers' => 10_000,
                'complete_binary_files' => 1,
                'evidence_hash' => str_repeat('a', 64),
                'policy_version' => 'exact-xover-v1',
                'state' => 'AUDITED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('current_forward_window_verifications')->insert([
                'window_id' => $windowId,
                'provider_first' => 1,
                'provider_high' => 50_100,
                'provider_observed_at' => now(),
                'headers' => 10_000,
                'yenc_headers' => 10_000,
                'multipart_headers' => 10_000,
                'complete_binary_files' => 1,
                'evidence_hash' => str_repeat('a', 64),
                'policy_version' => 'exact-xover-v1',
                'idempotency_key' => str_repeat('b', 64),
                'verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Settings::query()->insert([
                ['name' => 'orchestrator_mode', 'value' => 'active'],
                ['name' => 'orchestrator_profile', 'value' => 'fill'],
                ['name' => 'orchestrator_recovery_ok', 'value' => '1'],
                ['name' => 'orchestrator_lease_until', 'value' => (string) (time() + 600)],
                ['name' => 'orchestrator_bf_permit', 'value' => '0'],
                ['name' => 'orchestrator_bf_claimed', 'value' => '0'],
                ['name' => 'orchestrator_bf_completed', 'value' => '0'],
            ]);
            DB::disconnect();

            foreach ([41, 42] as $generation) {
                $file = tempnam(sys_get_temp_dir(), 'nntmux-cf-race-');
                self::assertIsString($file);
                $files[] = $file;
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    DB::purge('mariadb');
                    DB::reconnect('mariadb');
                    Settings::forgetCachedSettings();
                    $snapshot = new PipelineSnapshot(
                        100,
                        10,
                        2,
                        0,
                        0,
                        lowPressure: true,
                        databaseCurrentWaits: 0,
                        databaseAdmissionSafe: true,
                        eligibleNzbs: 0,
                    );
                    try {
                        $result = (new CurrentForwardPermitGate)->issue($snapshot, $generation);
                    } catch (\Throwable $e) {
                        $result = [
                            'granted' => false,
                            'reason' => 'child_exception',
                            'exception' => $e::class.':'.$e->getMessage(),
                        ];
                    }
                    file_put_contents($file, json_encode($result, JSON_THROW_ON_ERROR));
                    exit(0);
                }
                $pids[] = $pid;
            }
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge('mariadb');
            DB::reconnect('mariadb');
            $results = array_map(
                static fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR),
                $files,
            );

            foreach ($results as $result) {
                self::assertArrayNotHasKey(
                    'exception',
                    $result,
                    'A concurrent child threw instead of returning a fenced permit decision.',
                );
                self::assertArrayHasKey('granted', $result);
                self::assertArrayHasKey('reason', $result);
            }
            $winners = array_values(array_filter(
                $results,
                static fn (array $result): bool => $result['granted'] === true,
            ));
            $losers = array_values(array_filter(
                $results,
                static fn (array $result): bool => $result['granted'] === false,
            ));
            self::assertCount(1, $winners);
            self::assertCount(1, $losers);
            self::assertNotSame('child_exception', $losers[0]['reason']);
            self::assertSame(1, DB::table('current_forward_windows')->where('state', 'OFFERED')->count());
            $winningGeneration = (int) $winners[0]['generation'];
            self::assertContains($winningGeneration, [41, 42]);
            self::assertSame(
                $winningGeneration,
                (int) DB::table('current_forward_windows')->where('state', 'OFFERED')->value('generation'),
            );
            self::assertSame($winningGeneration, (int) Settings::settingValue('orchestrator_cf_permit'));
            self::assertNotNull(
                DB::table('current_forward_windows')->where('state', 'OFFERED')->value('issued_verification_id'),
            );
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
            DB::purge('mariadb');
            DB::reconnect('mariadb');
            $verificationFences->down();
            $issuanceGuards->down();
            $checkRepair->down();
            $migration->down();
            Schema::dropIfExists('short_groups');
            Schema::dropIfExists('usenet_groups');
            Schema::dropIfExists('releases');
            Schema::dropIfExists('settings');
        }
    }

    public function test_retry_attempt_schema_preserves_history_and_fences_duplicate_live_ranges_on_sqlite(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        $ledger = require database_path('migrations/2026_07_17_120000_create_current_forward_refresh_ledger.php');
        $continuations = require database_path('migrations/2026_07_17_160000_add_current_forward_continuation_chains.php');
        $retries = require database_path('migrations/2026_07_18_024500_add_current_forward_retry_attempts.php');
        $ledger->up();
        $continuations->up();
        $retries->up();
        $retries->up();

        self::assertTrue(Schema::hasColumns('current_forward_windows', [
            'attempt_ordinal',
            'retry_of_window_id',
        ]));
        self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_source_range_attempt_uq'));
        self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_retry_parent_uq'));
        self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_source_range_live_uq'));

        $sourceId = DB::table('current_forward_sources')->insertGetId([
            'groups_id' => 1,
            'group_name' => 'alt.retry',
            'anchor_first' => 1,
            'audited_last' => 10_000,
            'state' => 'READY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $base = [
            'source_id' => $sourceId,
            'first_article' => 1,
            'last_article' => 10_000,
            'provider_first' => 1,
            'provider_high' => 30_000,
            'provider_observed_at' => now(),
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 1,
            'evidence_hash' => str_repeat('a', 64),
            'policy_version' => 'exact-xover-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $firstId = DB::table('current_forward_windows')->insertGetId($base + [
            'state' => 'QUARANTINED',
            'attempt_ordinal' => 1,
        ]);
        $secondId = DB::table('current_forward_windows')->insertGetId($base + [
            'state' => 'AUDITED',
            'attempt_ordinal' => 2,
            'retry_of_window_id' => $firstId,
        ]);

        self::assertNotSame($firstId, $secondId);
        self::assertSame(2, DB::table('current_forward_windows')->count());
        $this->assertQueryRejected(
            static fn () => DB::table('current_forward_windows')->insert($base + [
                'state' => 'AUDITED',
                'attempt_ordinal' => 3,
                'retry_of_window_id' => 999,
            ]),
            'A second non-quarantined attempt for the same range was accepted.',
        );
        $this->assertQueryRejected(
            static fn () => DB::table('current_forward_windows')->insert($base + [
                'state' => 'QUARANTINED',
                'attempt_ordinal' => 3,
                'retry_of_window_id' => $firstId,
            ]),
            'A retry predecessor was allowed to branch.',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable retry attempts exist');
        $retries->down();
    }

    public function test_retry_attempt_schema_round_trips_before_retry_history_exists_on_sqlite(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        $ledger = require database_path('migrations/2026_07_17_120000_create_current_forward_refresh_ledger.php');
        $retries = require database_path('migrations/2026_07_18_024500_add_current_forward_retry_attempts.php');
        $ledger->up();
        $retries->up();
        $retries->down();
        $retries->down();

        self::assertFalse(Schema::hasColumn('current_forward_windows', 'attempt_ordinal'));
        self::assertFalse(Schema::hasColumn('current_forward_windows', 'retry_of_window_id'));
        self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_source_range_uq'));

        $ledger->down();
    }

    /** @param callable(): mixed $operation */
    private function assertQueryRejected(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail($message);
        } catch (QueryException) {
            return;
        }
    }
}
