<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CurrentForwardTerminalSplitRepairMigrationTest extends TestCase
{
    public function test_terminal_split_repair_schema_is_retry_safe_unique_and_rollback_guarded_on_sqlite(): void
    {
        $this->useSqlite();
        $this->createParents();
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        self::assertTrue(Schema::hasColumns('current_forward_terminal_collection_repairs', [
            'handoff_id',
            'chain_root_id',
            'source_window_id',
            'target_window_id',
            'source_collection_id',
            'target_collection_id',
            'root_state',
            'root_failure_reason',
            'root_settled_at',
            'source_binary_count',
            'source_binary_ids_hash',
            'target_binary_count',
            'target_binary_ids_hash',
            'merged_binary_count',
            'merged_binary_ids_hash',
            'group_name',
            'anchor_totalparts',
            'anchor_article_span',
            'forward_article_gap',
            'residual',
            'policy_version',
            'pre_observation_hash',
            'pre_bad_set_hash',
            'chain_hash',
            'observation_rows_hash',
            'evidence_hash',
            'repaired_at',
        ]));
        self::assertTrue(Schema::hasColumns('current_forward_terminal_release_attributions', [
            'release_id',
            'repair_id',
            'handoff_id',
            'chain_root_id',
            'window_id',
            'target_collection_id',
            'target_binary_count',
            'target_binary_ids_hash',
            'release_categories_id',
            'release_nzbstatus',
            'release_size',
            'policy_version',
            'evidence_hash',
            'attributed_at',
        ]));
        foreach ([
            ['current_forward_terminal_collection_repairs', 'cf_terminal_repairs_handoff_uq'],
            ['current_forward_terminal_collection_repairs', 'cf_terminal_repairs_root_source_uq'],
            ['current_forward_terminal_collection_repairs', 'cf_terminal_repairs_target_uq'],
            ['current_forward_terminal_release_attributions', 'cf_terminal_attributions_release_uq'],
            ['current_forward_terminal_release_attributions', 'cf_terminal_attributions_repair_uq'],
        ] as [$table, $index]) {
            self::assertTrue(Schema::hasIndex($table, $index));
        }

        $repairId = (int) DB::table('current_forward_terminal_collection_repairs')
            ->insertGetId($this->repair());
        $this->assertQueryRejected(
            fn () => DB::table('current_forward_terminal_collection_repairs')->insert($this->repair()),
        );

        $attribution = $this->attribution($repairId);
        DB::table('current_forward_terminal_release_attributions')->insert($attribution);
        $this->assertQueryRejected(
            static fn () => DB::table('current_forward_terminal_release_attributions')->insert($attribution),
        );
        $this->assertQueryRejected(fn () => DB::table('current_forward_terminal_release_attributions')->insert([
            ...$this->attribution(999),
            'release_id' => 101,
        ]));

        try {
            $migration->down();
            self::fail('Rollback erased immutable terminal release attribution evidence.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
        DB::table('current_forward_terminal_release_attributions')->delete();
        try {
            $migration->down();
            self::fail('Rollback erased immutable terminal collection repair evidence.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }

        DB::table('current_forward_terminal_collection_repairs')->delete();
        $migration->down();
        $migration->down();
        self::assertFalse(Schema::hasTable('current_forward_terminal_release_attributions'));
        self::assertFalse(Schema::hasTable('current_forward_terminal_collection_repairs'));
    }

    public function test_terminal_split_repair_evidence_rolls_back_with_its_transaction_on_sqlite(): void
    {
        $this->useSqlite();
        $this->createParents();
        $migration = $this->migration();
        $migration->up();

        try {
            DB::transaction(function (): void {
                $repairId = (int) DB::table('current_forward_terminal_collection_repairs')
                    ->insertGetId($this->repair());
                DB::table('current_forward_terminal_release_attributions')
                    ->insert($this->attribution($repairId));
                throw new RuntimeException('force terminal evidence rollback');
            });
            self::fail('Terminal evidence transaction unexpectedly committed.');
        } catch (RuntimeException $exception) {
            self::assertSame('force terminal evidence rollback', $exception->getMessage());
        }

        self::assertSame(0, DB::table('current_forward_terminal_collection_repairs')->count());
        self::assertSame(0, DB::table('current_forward_terminal_release_attributions')->count());
        $migration->down();
    }

    public function test_terminal_split_repair_checks_and_foreign_keys_are_enforced_on_mariadb_when_opted_in(): void
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
        $migration = $this->migration();

        try {
            Schema::dropIfExists('current_forward_terminal_release_attributions');
            Schema::dropIfExists('current_forward_terminal_collection_repairs');
            Schema::dropIfExists('current_forward_collection_handoffs');
            Schema::dropIfExists('current_forward_windows');
            $this->createParents();
            $migration->up();
            $migration->up();

            self::assertSame('mariadb', DB::connection()->getDriverName());
            self::assertSame(2, DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
                ->whereIn('TABLE_NAME', [
                    'current_forward_terminal_collection_repairs',
                    'current_forward_terminal_release_attributions',
                ])
                ->where('CONSTRAINT_TYPE', 'CHECK')
                ->count());
            self::assertSame(8, DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
                ->whereIn('TABLE_NAME', [
                    'current_forward_terminal_collection_repairs',
                    'current_forward_terminal_release_attributions',
                ])
                ->where('DELETE_RULE', 'RESTRICT')
                ->count());

            $this->assertQueryRejected(fn () => DB::table('current_forward_terminal_collection_repairs')->insert([
                ...$this->repair(),
                'root_state' => 'PRODUCTIVE',
                'residual' => 1,
            ]));
        } finally {
            DB::table('current_forward_terminal_release_attributions')->delete();
            DB::table('current_forward_terminal_collection_repairs')->delete();
            $migration->down();
            Schema::dropIfExists('current_forward_collection_handoffs');
            Schema::dropIfExists('current_forward_windows');
        }
    }

    private function useSqlite(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge();
        DB::reconnect();
    }

    private function createParents(): void
    {
        Schema::create('current_forward_windows', function ($table): void {
            $table->id();
        });
        Schema::create('current_forward_collection_handoffs', function ($table): void {
            $table->id();
        });
        DB::table('current_forward_windows')->insert(['id' => 1]);
        DB::table('current_forward_collection_handoffs')->insert(['id' => 1]);
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_07_18_094500_add_current_forward_terminal_split_repairs.php',
        );
    }

    /** @return array<string, mixed> */
    private function repair(): array
    {
        return [
            'handoff_id' => 1,
            'chain_root_id' => 1,
            'source_window_id' => 1,
            'target_window_id' => 1,
            'source_collection_id' => 10,
            'target_collection_id' => 11,
            'root_state' => 'QUARANTINED',
            'root_failure_reason' => 'current_forward_incomplete_after_grace',
            'root_settled_at' => now()->subMinute(),
            'source_binary_count' => 1,
            'source_binary_ids_hash' => str_repeat('a', 64),
            'target_binary_count' => 1,
            'target_binary_ids_hash' => str_repeat('b', 64),
            'merged_binary_count' => 2,
            'merged_binary_ids_hash' => str_repeat('c', 64),
            'group_name' => 'alt.binaries.movies.dvd',
            'anchor_totalparts' => 7500,
            'anchor_article_span' => 3001,
            'forward_article_gap' => 4499,
            'residual' => 0,
            'policy_version' => 'terminal-split-v1',
            'pre_observation_hash' => str_repeat('d', 64),
            'pre_bad_set_hash' => str_repeat('e', 64),
            'chain_hash' => str_repeat('2', 64),
            'observation_rows_hash' => str_repeat('3', 64),
            'evidence_hash' => str_repeat('f', 64),
            'repaired_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function attribution(int $repairId): array
    {
        return [
            'release_id' => 100,
            'repair_id' => $repairId,
            'handoff_id' => 1,
            'chain_root_id' => 1,
            'window_id' => 1,
            'target_collection_id' => 11,
            'target_binary_count' => 2,
            'target_binary_ids_hash' => str_repeat('c', 64),
            'release_categories_id' => 5020,
            'release_nzbstatus' => 1,
            'release_size' => 1_100,
            'policy_version' => 'terminal-split-pair-repair-v1',
            'evidence_hash' => str_repeat('1', 64),
            'attributed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param callable(): mixed $operation */
    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected the terminal evidence schema to reject the query.');
        } catch (QueryException) {
            self::addToAssertionCount(1);
        }
    }
}
