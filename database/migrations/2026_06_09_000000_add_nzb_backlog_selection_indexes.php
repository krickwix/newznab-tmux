<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('releases')) {
            return;
        }

        if (! $this->indexExists('releases', 'ix_releases_nzbstatus_id')) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->index(['nzbstatus', 'id'], 'ix_releases_nzbstatus_id');
            });
        }

        if (! $this->indexExists('releases', 'ix_releases_nzb_backlog_partition')) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->index(['nzbstatus', 'groups_id', 'leftguid', 'id'], 'ix_releases_nzb_backlog_partition');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('releases')) {
            return;
        }

        if ($this->indexExists('releases', 'ix_releases_nzb_backlog_partition')) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->dropIndex('ix_releases_nzb_backlog_partition');
            });
        }

        if ($this->indexExists('releases', 'ix_releases_nzbstatus_id')) {
            Schema::table('releases', function (Blueprint $table): void {
                $table->dropIndex('ix_releases_nzbstatus_id');
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
