<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 0 and the observed-totalfiles repair select a group's filecheck-0
 * collections with `totalfiles = 0`, ordered by id. The stage 1 index has
 * totalfiles after id, so each call walked every filecheck-0 entry of the
 * group (393k for boneless, ~150 ms) to find none. With totalfiles as an
 * equality key before id the same queries read only matching rows.
 */
return new class extends Migration
{
    private const INDEX = 'ix_collections_release_stage0';

    public function up(): void
    {
        if (! Schema::hasTable('collections') || $this->indexExists()) {
            return;
        }

        if (\in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Online build; fail instead of blocking ingest writes if not possible.
            DB::statement('ALTER TABLE collections ADD INDEX '.self::INDEX.' (groups_id, filecheck, totalfiles, id), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table('collections', function (Blueprint $table): void {
            $table->index(['groups_id', 'filecheck', 'totalfiles', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('collections') || ! $this->indexExists()) {
            return;
        }

        Schema::table('collections', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'collections' AND name = ?",
                [self::INDEX],
            ) !== [];
        }

        return DB::select('SHOW INDEX FROM `collections` WHERE Key_name = ?', [self::INDEX]) !== [];
    }
};
