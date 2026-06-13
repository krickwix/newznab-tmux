<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('releases_groups')) {
            return;
        }

        if (! $this->indexExists('releases_groups', 'ix_releases_groups_group_release')) {
            Schema::table('releases_groups', function (Blueprint $table): void {
                $table->index(['groups_id', 'releases_id'], 'ix_releases_groups_group_release');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('releases_groups')) {
            return;
        }

        if ($this->indexExists('releases_groups', 'ix_releases_groups_group_release')) {
            Schema::table('releases_groups', function (Blueprint $table): void {
                $table->dropIndex('ix_releases_groups_group_release');
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
