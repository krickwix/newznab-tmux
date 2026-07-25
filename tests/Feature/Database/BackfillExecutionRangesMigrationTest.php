<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BackfillExecutionRangesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
    }

    public function test_migration_creates_generation_range_receipts_and_indexes(): void
    {
        $migration = require database_path('migrations/2026_07_20_120000_create_backfill_execution_ranges.php');
        $migration->up();

        self::assertSame([
            'id',
            'generation',
            'group_name',
            'first_article',
            'last_article',
            'status',
            'claimed_at',
            'completed_at',
            'error',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('backfill_execution_ranges'));

        $indexes = collect(DB::select("PRAGMA index_list('backfill_execution_ranges')"))
            ->pluck('name')
            ->all();
        self::assertContains('bf_ranges_generation_interval_uq', $indexes);
        self::assertContains('bf_ranges_generation_status_idx', $indexes);

        $migration->down();
        self::assertFalse(Schema::hasTable('backfill_execution_ranges'));
    }
}
