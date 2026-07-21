<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurrentForwardContinuationSchemaMigrationTest extends TestCase
{
    public function test_continuation_schema_is_retry_safe_and_self_roots_existing_windows_on_sqlite(): void
    {
        $this->useSqlite();
        [$ledger, $issuanceGuards, $verificationFences] = $this->applyBaseMigrations();

        $sourceId = $this->insertSource();
        $windowId = $this->insertWindow($sourceId, state: 'QUARANTINED');
        $continuations = require database_path('migrations/2026_07_17_160000_add_current_forward_continuation_chains.php');

        $continuations->up();
        $continuations->up();

        self::assertTrue(Schema::hasColumns('current_forward_windows', [
            'chain_root_id',
            'parent_window_id',
            'chain_ordinal',
            'continuation_deadline_at',
        ]));
        self::assertTrue(Schema::hasColumns('current_forward_window_objects', [
            'window_id',
            'chain_root_id',
            'object_type',
            'object_id',
            'parent_object_id',
            'inserted_parts',
            'created_in_window',
            'touched_in_window',
        ]));
        self::assertTrue(Schema::hasColumns('current_forward_object_owners', [
            'object_type',
            'object_id',
            'chain_root_id',
        ]));
        self::assertTrue(Schema::hasColumns('current_forward_continuation_observations', [
            'window_id',
            'chain_root_id',
            'chain_ordinal',
            'baseline_present_parts',
            'current_present_parts',
            'useful_progress_parts',
            'expected_parts',
            'observed_files',
            'complete_files',
            'unresolved_collections',
            'cumulative_parts',
            'cumulative_binaries',
            'cumulative_collections',
            'cumulative_releases',
            'cumulative_ready_nzbs',
            'decision',
            'reason',
            'pipeline_hash',
            'cohort_hash',
            'idempotency_key',
        ]));

        $existing = DB::table('current_forward_windows')->where('id', $windowId)->first();
        self::assertNotNull($existing);
        self::assertSame($windowId, (int) $existing->chain_root_id);
        self::assertSame(1, (int) $existing->chain_ordinal);
        self::assertNull($existing->parent_window_id);
        self::assertSame('QUARANTINED', $existing->state);

        self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_chain_ordinal_uq'));
        self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_parent_uq'));
        self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_state_chain_id_idx'));
        self::assertTrue(Schema::hasIndex('current_forward_window_objects', 'cf_window_objects_window_type_object_uq'));
        self::assertTrue(Schema::hasIndex('current_forward_object_owners', 'cf_object_owners_type_object_uq'));
        self::assertTrue(Schema::hasIndex('current_forward_continuation_observations', 'cf_continuation_observations_window_uq'));

        $continuations->down();
        $continuations->down();

        self::assertFalse(Schema::hasTable('current_forward_window_objects'));
        self::assertFalse(Schema::hasTable('current_forward_object_owners'));
        self::assertFalse(Schema::hasTable('current_forward_continuation_observations'));
        self::assertFalse(Schema::hasColumn('current_forward_windows', 'chain_root_id'));
        self::assertSame('QUARANTINED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));

        $verificationFences->down();
        $issuanceGuards->down();
        $ledger->down();
    }

    public function test_continuation_schema_enforces_linear_chain_and_append_only_membership_on_sqlite(): void
    {
        $this->useSqlite();
        [$ledger, $issuanceGuards, $verificationFences] = $this->applyBaseMigrations();
        $continuations = require database_path('migrations/2026_07_17_160000_add_current_forward_continuation_chains.php');
        $continuations->up();

        $sourceId = $this->insertSource();
        $rootId = $this->insertWindow($sourceId, state: 'CONTINUATION_PENDING');
        DB::table('current_forward_windows')->where('id', $rootId)->update([
            'chain_root_id' => $rootId,
            'chain_ordinal' => 1,
        ]);
        $childId = $this->insertWindow(
            $sourceId,
            firstArticle: 10_001,
            state: 'AUDITED',
            extra: [
                'chain_root_id' => $rootId,
                'parent_window_id' => $rootId,
                'chain_ordinal' => 2,
            ],
        );

        $this->assertQueryRejected(fn () => $this->insertWindow(
            $sourceId,
            firstArticle: 20_001,
            state: 'AUDITED',
            extra: [
                'chain_root_id' => $rootId,
                'parent_window_id' => $rootId,
                'chain_ordinal' => 3,
            ],
        ));
        $this->assertQueryRejected(fn () => $this->insertWindow(
            $sourceId,
            firstArticle: 30_001,
            state: 'AUDITED',
            extra: [
                'chain_root_id' => $rootId,
                'parent_window_id' => $childId,
                'chain_ordinal' => 2,
            ],
        ));

        $object = [
            'window_id' => $childId,
            'chain_root_id' => $rootId,
            'object_type' => 'COLLECTION',
            'object_id' => 101,
            'parent_object_id' => null,
            'inserted_parts' => 100,
            'created_in_window' => true,
            'touched_in_window' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('current_forward_window_objects')->insert($object);
        $this->assertQueryRejected(
            static fn () => DB::table('current_forward_window_objects')->insert($object),
        );

        $observation = $this->observation($childId, $rootId);
        DB::table('current_forward_continuation_observations')->insert($observation);
        $this->assertQueryRejected(
            static fn () => DB::table('current_forward_continuation_observations')->insert($observation),
        );

        DB::table('current_forward_windows')->where('id', $rootId)->update(['state' => 'QUARANTINED']);
        $continuations->down();
        $verificationFences->down();
        $issuanceGuards->down();
        $ledger->down();
    }

    public function test_rollback_refuses_before_mutation_while_a_continuation_state_exists(): void
    {
        $this->useSqlite();
        [$ledger, $issuanceGuards, $verificationFences] = $this->applyBaseMigrations();
        $continuations = require database_path('migrations/2026_07_17_160000_add_current_forward_continuation_chains.php');
        $continuations->up();

        $sourceId = $this->insertSource();
        $rootId = $this->insertWindow($sourceId, state: 'CONTINUATION_PENDING');
        DB::table('current_forward_windows')->where('id', $rootId)->update([
            'chain_root_id' => $rootId,
        ]);

        $refused = false;
        try {
            $continuations->down();
        } catch (\RuntimeException $e) {
            $refused = true;
            self::assertStringContainsString('terminalize', $e->getMessage());
        }
        self::assertTrue($refused, 'Rollback removed continuation schema while a chain state was still present.');

        self::assertTrue(Schema::hasColumn('current_forward_windows', 'chain_root_id'));
        self::assertTrue(Schema::hasTable('current_forward_window_objects'));
        self::assertSame(
            'CONTINUATION_PENDING',
            DB::table('current_forward_windows')->where('id', $rootId)->value('state'),
        );

        DB::table('current_forward_windows')->where('id', $rootId)->update(['state' => 'QUARANTINED']);
        $continuations->down();
        $verificationFences->down();
        $issuanceGuards->down();
        $ledger->down();
    }

    public function test_release_disposition_schema_is_retry_safe_unique_and_reversible_on_sqlite(): void
    {
        $this->useSqlite();
        $dispositions = require database_path('migrations/2026_07_18_051500_add_current_forward_release_dispositions.php');

        $dispositions->up();
        $dispositions->up();

        self::assertTrue(Schema::hasColumns('current_forward_release_dispositions', [
            'release_id',
            'chain_root_id',
            'window_id',
            'parent_collection_id',
            'reason',
            'categories_id',
            'nzbstatus',
            'size',
            'disposed_at',
        ]));
        self::assertTrue(Schema::hasIndex(
            'current_forward_release_dispositions',
            'cf_release_dispositions_release_uq',
        ));
        $row = [
            'release_id' => 100,
            'chain_root_id' => 1,
            'window_id' => 1,
            'reason' => 'executable',
            'disposed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('current_forward_release_dispositions')->insert($row);
        $this->assertQueryRejected(
            static fn () => DB::table('current_forward_release_dispositions')->insert($row),
        );

        $refused = false;
        try {
            $dispositions->down();
        } catch (\RuntimeException $exception) {
            $refused = true;
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
        self::assertTrue($refused, 'Rollback erased immutable release disposition evidence.');
        DB::table('current_forward_release_dispositions')->delete();
        $dispositions->down();
        $dispositions->down();
        self::assertFalse(Schema::hasTable('current_forward_release_dispositions'));
    }

    public function test_collection_handoff_schema_is_retry_safe_unique_and_reversible_on_sqlite(): void
    {
        $this->useSqlite();
        $handoffs = require database_path('migrations/2026_07_18_081500_add_current_forward_collection_handoffs.php');

        $handoffs->up();
        $handoffs->up();

        self::assertTrue(Schema::hasColumns('current_forward_collection_handoffs', [
            'source_collection_id',
            'target_collection_id',
            'chain_root_id',
            'source_window_id',
            'target_window_id',
            'moved_binary_count',
            'moved_binary_ids_hash',
            'reason',
            'handed_off_at',
        ]));
        self::assertTrue(Schema::hasIndex(
            'current_forward_collection_handoffs',
            'cf_collection_handoffs_root_source_uq',
        ));
        $row = [
            'source_collection_id' => 100,
            'target_collection_id' => 101,
            'chain_root_id' => 1,
            'source_window_id' => 1,
            'target_window_id' => 1,
            'moved_binary_count' => 2,
            'moved_binary_ids_hash' => str_repeat('a', 64),
            'reason' => 'split_collection_merge',
            'handed_off_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('current_forward_collection_handoffs')->insert($row);
        $this->assertQueryRejected(
            static fn () => DB::table('current_forward_collection_handoffs')->insert($row),
        );

        try {
            $handoffs->down();
            self::fail('Rollback erased immutable collection handoff evidence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
        DB::table('current_forward_collection_handoffs')->delete();
        $handoffs->down();
        $handoffs->down();
        self::assertFalse(Schema::hasTable('current_forward_collection_handoffs'));
    }

    public function test_mariadb_enforces_retry_safe_continuation_checks_and_generated_slots_when_opted_in(): void
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

        foreach ([
            'current_forward_collection_handoffs',
            'current_forward_release_dispositions',
            'current_forward_continuation_observations',
            'current_forward_window_objects',
            'current_forward_object_owners',
            'current_forward_window_verifications',
            'current_forward_windows',
            'current_forward_sources',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $ledger = require database_path('migrations/2026_07_17_120000_create_current_forward_refresh_ledger.php');
        $checks = require database_path('migrations/2026_07_17_130000_add_current_forward_refresh_ledger_checks.php');
        $issuanceGuards = require database_path('migrations/2026_07_17_140000_add_current_forward_refresh_issuance_guards.php');
        $verificationFences = require database_path('migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php');
        $continuations = require database_path('migrations/2026_07_17_160000_add_current_forward_continuation_chains.php');
        $dispositions = require database_path('migrations/2026_07_18_051500_add_current_forward_release_dispositions.php');
        $providerReserve = require database_path('migrations/2026_07_18_074500_relax_current_forward_provider_reserve_floor.php');
        $handoffs = require database_path('migrations/2026_07_18_081500_add_current_forward_collection_handoffs.php');

        try {
            $ledger->up();
            $checks->up();
            $issuanceGuards->up();
            $verificationFences->up();
            $verificationFences->up();

            $sourceId = $this->insertSource();
            $firstRootId = $this->insertWindow($sourceId, state: 'QUARANTINED');
            $secondRootId = $this->insertWindow($sourceId, firstArticle: 10_001, state: 'QUARANTINED');

            $continuations->up();
            $continuations->up();
            $dispositions->up();
            $dispositions->up();
            $providerReserve->up();
            $providerReserve->up();
            $handoffs->up();
            $handoffs->up();

            $relaxedWindowId = $this->insertWindow(
                $sourceId,
                firstArticle: 40_001,
                state: 'AUDITED',
                extra: ['provider_high' => 69_000],
            );
            self::assertGreaterThan(0, $relaxedWindowId);
            $this->assertQueryRejected(fn () => $this->insertWindow(
                $sourceId,
                firstArticle: 50_001,
                state: 'AUDITED',
                extra: ['provider_high' => 78_999],
            ));
            try {
                $providerReserve->down();
                self::fail('Rollback restored the 20000-article reserve over relaxed evidence.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('relaxed current-forward evidence', $exception->getMessage());
            }
            DB::table('current_forward_windows')->where('id', $relaxedWindowId)->delete();
            $providerReserve->down();
            $this->assertQueryRejected(fn () => $this->insertWindow(
                $sourceId,
                firstArticle: 40_001,
                state: 'AUDITED',
                extra: ['provider_high' => 69_000],
            ));
            $providerReserve->up();

            self::assertSame('mariadb', Schema::getConnection()->getDriverName());
            self::assertSame($firstRootId, (int) DB::table('current_forward_windows')->where('id', $firstRootId)->value('chain_root_id'));
            self::assertSame('QUARANTINED', DB::table('current_forward_windows')->where('id', $firstRootId)->value('state'));
            self::assertTrue(Schema::hasColumn('current_forward_windows', 'open_chain_slot'));
            self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_open_chain_uq'));
            self::assertTrue(Schema::hasIndex('current_forward_windows', 'cf_windows_unresolved_uq'));
            self::assertTrue(Schema::hasIndex(
                'current_forward_release_dispositions',
                'cf_release_dispositions_release_uq',
            ));
            self::assertTrue(Schema::hasIndex(
                'current_forward_collection_handoffs',
                'cf_collection_handoffs_root_source_uq',
            ));
            DB::table('current_forward_collection_handoffs')->insert([
                'source_collection_id' => 10,
                'target_collection_id' => 11,
                'chain_root_id' => $firstRootId,
                'source_window_id' => $firstRootId,
                'target_window_id' => $firstRootId,
                'moved_binary_count' => 2,
                'moved_binary_ids_hash' => str_repeat('a', 64),
                'reason' => 'split_collection_merge',
                'handed_off_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertQueryRejected(
                static fn () => DB::table('current_forward_collection_handoffs')->insert([
                    'source_collection_id' => 12,
                    'target_collection_id' => 12,
                    'chain_root_id' => 1,
                    'source_window_id' => 1,
                    'target_window_id' => 1,
                    'moved_binary_count' => 0,
                    'moved_binary_ids_hash' => 'bad',
                    'reason' => 'cleanup',
                    'handed_off_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );
            DB::table('current_forward_collection_handoffs')->delete();
            DB::table('current_forward_release_dispositions')->insert([
                'release_id' => 100,
                'chain_root_id' => $firstRootId,
                'window_id' => $firstRootId,
                'reason' => 'nzb_failed',
                'nzbstatus' => -1,
                'disposed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::assertSame(-1, (int) DB::table('current_forward_release_dispositions')
                ->where('release_id', 100)
                ->value('nzbstatus'));
            DB::table('current_forward_release_dispositions')->delete();
            $this->assertQueryRejected(
                static fn () => DB::table('current_forward_release_dispositions')->insert([
                    'release_id' => 0,
                    'chain_root_id' => 1,
                    'window_id' => 1,
                    'reason' => 'invalid',
                    'disposed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );

            DB::table('current_forward_windows')->where('id', $firstRootId)->update([
                'state' => 'CONTINUATION_PENDING',
            ]);
            $this->assertQueryRejected(
                static fn () => DB::table('current_forward_windows')->where('id', $secondRootId)->update([
                    'state' => 'CONTINUATION_PENDING',
                ]),
            );

            $activeChildId = $this->insertWindow(
                $sourceId,
                firstArticle: 20_001,
                state: 'OFFERED',
                extra: [
                    'chain_root_id' => $firstRootId,
                    'parent_window_id' => $firstRootId,
                    'chain_ordinal' => 2,
                ],
            );
            self::assertSame('OFFERED', DB::table('current_forward_windows')->where('id', $activeChildId)->value('state'));
            $unrelatedId = $this->insertWindow($sourceId, firstArticle: 30_001, state: 'AUDITED');
            $this->assertQueryRejected(
                static fn () => DB::table('current_forward_windows')->where('id', $unrelatedId)->update([
                    'state' => 'CLAIMED',
                ]),
            );

            self::assertSame(
                1,
                DB::table('current_forward_windows')->where('id', $firstRootId)->update(['state' => 'CHAINED']),
            );
            $this->assertQueryRejected(
                static fn () => DB::table('current_forward_window_objects')->insert([
                    'window_id' => $activeChildId,
                    'chain_root_id' => $firstRootId,
                    'object_type' => 'INVALID',
                    'object_id' => 1,
                    'inserted_parts' => 0,
                    'created_in_window' => false,
                    'touched_in_window' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );

            DB::table('current_forward_windows')->where('id', $firstRootId)->update([
                'state' => 'QUARANTINED',
            ]);

            $handoffs->down();
            $handoffs->down();
            $dispositions->down();
            $dispositions->down();
            $continuations->down();
            $continuations->down();

            self::assertFalse(Schema::hasColumn('current_forward_windows', 'open_chain_slot'));
            self::assertTrue(Schema::hasColumn('current_forward_windows', 'unresolved_slot'));
            $this->assertQueryRejected(
                static fn () => DB::table('current_forward_windows')->where('id', $secondRootId)->update([
                    'state' => 'CONTINUATION_PENDING',
                ]),
            );
        } finally {
            DB::purge('mariadb');
            DB::reconnect('mariadb');
            $handoffs->down();
            $providerReserve->down();
            $dispositions->down();
            $continuations->down();
            $verificationFences->down();
            $verificationFences->down();
            $issuanceGuards->down();
            $checks->down();
            $ledger->down();
        }
    }

    private function useSqlite(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();
    }

    /** @return array{object, object, object} */
    private function applyBaseMigrations(): array
    {
        $ledger = require database_path('migrations/2026_07_17_120000_create_current_forward_refresh_ledger.php');
        $ledger->up();
        $issuanceGuards = require database_path('migrations/2026_07_17_140000_add_current_forward_refresh_issuance_guards.php');
        $issuanceGuards->up();
        $verificationFences = require database_path('migrations/2026_07_17_150000_add_current_forward_verification_and_settlement_fences.php');
        $verificationFences->up();

        return [$ledger, $issuanceGuards, $verificationFences];
    }

    private function insertSource(): int
    {
        return (int) DB::table('current_forward_sources')->insertGetId([
            'groups_id' => random_int(1, 1_000_000),
            'group_name' => 'alt.test.'.bin2hex(random_bytes(4)),
            'anchor_first' => 1,
            'audited_last' => 100_000,
            'state' => 'READY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function insertWindow(
        int $sourceId,
        int $firstArticle = 1,
        string $state = 'AUDITED',
        array $extra = [],
    ): int {
        return (int) DB::table('current_forward_windows')->insertGetId(array_merge([
            'source_id' => $sourceId,
            'first_article' => $firstArticle,
            'last_article' => $firstArticle + 9_999,
            'provider_first' => 1,
            'provider_high' => $firstArticle + 50_000,
            'provider_observed_at' => now(),
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 1,
            'evidence_hash' => hash('sha256', (string) $firstArticle),
            'policy_version' => 'exact-xover-v1',
            'state' => $state,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** @return array<string, mixed> */
    private function observation(int $windowId, int $rootId): array
    {
        return [
            'window_id' => $windowId,
            'chain_root_id' => $rootId,
            'chain_ordinal' => 2,
            'baseline_present_parts' => 100,
            'current_present_parts' => 250,
            'useful_progress_parts' => 150,
            'expected_parts' => 1_000,
            'observed_files' => 10,
            'complete_files' => 5,
            'unresolved_collections' => 1,
            'cumulative_parts' => 10_000,
            'cumulative_binaries' => 20,
            'cumulative_collections' => 3,
            'cumulative_releases' => 0,
            'cumulative_ready_nzbs' => 0,
            'decision' => 'CONTINUE',
            'reason' => 'partial_progress',
            'pipeline_hash' => str_repeat('a', 64),
            'cohort_hash' => str_repeat('b', 64),
            'idempotency_key' => str_repeat('c', 64),
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param callable(): mixed $operation */
    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected the schema uniqueness fence to reject the query.');
        } catch (QueryException) {
            self::addToAssertionCount(1);

            return;
        }
    }
}
