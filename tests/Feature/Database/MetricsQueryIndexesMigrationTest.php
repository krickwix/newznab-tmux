<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MetricsQueryIndexesMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        Schema::create('predb_crcs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('predb_id');
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('categories_id');
        });
    }

    public function test_migration_adds_and_removes_metrics_query_indexes(): void
    {
        $migration = require database_path('migrations/2026_07_21_130000_add_metrics_query_indexes.php');
        $migration->up();

        self::assertContains('predb_crcs_predb_id_index', $this->indexNames('predb_crcs'));
        self::assertContains('ix_releases_categories_id', $this->indexNames('releases'));

        $migration->down();

        self::assertNotContains('predb_crcs_predb_id_index', $this->indexNames('predb_crcs'));
        self::assertNotContains('ix_releases_categories_id', $this->indexNames('releases'));
    }

    /** @return list<string> */
    private function indexNames(string $table): array
    {
        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->pluck('name')
            ->all();
    }
}
