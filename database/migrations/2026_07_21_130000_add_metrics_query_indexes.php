<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('predb_crcs')
            && Schema::hasColumn('predb_crcs', 'predb_id')
            && ! $this->indexExists('predb_crcs', 'predb_crcs_predb_id_index')
        ) {
            Schema::table('predb_crcs', function (Blueprint $table): void {
                $table->index('predb_id', 'predb_crcs_predb_id_index');
            });
        }

        if (Schema::hasTable('releases')
            && Schema::hasColumn('releases', 'categories_id')
            && ! $this->indexExists('releases', 'ix_releases_categories_id')
        ) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->index('categories_id', 'ix_releases_categories_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('releases') && $this->indexExists('releases', 'ix_releases_categories_id')) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->dropIndex('ix_releases_categories_id');
            });
        }

        if (Schema::hasTable('predb_crcs') && $this->indexExists('predb_crcs', 'predb_crcs_predb_id_index')) {
            Schema::table('predb_crcs', function (Blueprint $table): void {
                $table->dropIndex('predb_crcs_predb_id_index');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $indexName],
            ) !== [];
        }

        return DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]) !== [];
    }
};
