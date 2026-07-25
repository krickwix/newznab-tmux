<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MovieLookupStatesMigrationTest extends TestCase
{
    public function test_movie_lookup_state_schema_round_trips_on_sqlite(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
        });

        $migration = require database_path('migrations/2026_07_21_120000_create_movie_lookup_states_table.php');
        $migration->up();

        self::assertTrue(Schema::hasColumns('movie_lookup_states', [
            'release_id',
            'status',
            'observed_imdbid',
            'attempted_imdbid',
            'observed_searchname',
            'observed_category_id',
            'reason_code',
            'attempts',
            'retry_count',
            'claim_token',
            'claim_expires_at',
            'next_attempt_at',
            'quarantined_at',
        ]));

        $indexes = collect(DB::select("PRAGMA index_list('movie_lookup_states')"))->pluck('name')->all();
        self::assertContains('ix_movie_lookup_states_due', $indexes);
        self::assertContains('ix_movie_lookup_states_claim', $indexes);

        $migration->down();
        self::assertFalse(Schema::hasTable('movie_lookup_states'));
    }
}
