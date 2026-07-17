<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurrentForwardRefreshLedgerMigrationTest extends TestCase
{
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

        $migration->down();

        self::assertFalse(Schema::hasTable('current_forward_windows'));
        self::assertFalse(Schema::hasTable('current_forward_sources'));
    }
}
