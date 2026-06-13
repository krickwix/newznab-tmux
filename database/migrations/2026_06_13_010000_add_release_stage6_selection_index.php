<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collections')) {
            return;
        }

        if (! $this->indexExists('collections', 'ix_collections_release_stage6')) {
            Schema::table('collections', function (Blueprint $table): void {
                $table->index(['groups_id', 'filecheck', 'dateadded', 'id'], 'ix_collections_release_stage6');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('collections')) {
            return;
        }

        if ($this->indexExists('collections', 'ix_collections_release_stage6')) {
            Schema::table('collections', function (Blueprint $table): void {
                $table->dropIndex('ix_collections_release_stage6');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $rows = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $indexName],
            );

            return $rows !== [];
        }

        $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return $rows !== [];
    }
};
