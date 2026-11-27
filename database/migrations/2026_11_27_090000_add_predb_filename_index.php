<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exact-match lookups on predb.filename (`WHERE filename = ?`) had only the
 * FULLTEXT index, which equality cannot use, so each one read the whole
 * table (366k rows, ~156 ms).
 */
return new class extends Migration
{
    private const INDEX = 'ix_predb_filename';

    public function up(): void
    {
        if (! Schema::hasTable('predb') || $this->indexExists()) {
            return;
        }

        if (\in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Online build; fail instead of blocking predb writes if not possible.
            DB::statement('ALTER TABLE predb ADD INDEX '.self::INDEX.' (filename), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table('predb', function (Blueprint $table): void {
            $table->index('filename', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('predb') || ! $this->indexExists()) {
            return;
        }

        Schema::table('predb', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'predb' AND name = ?",
                [self::INDEX],
            ) !== [];
        }

        return DB::select('SHOW INDEX FROM `predb` WHERE Key_name = ?', [self::INDEX]) !== [];
    }
};
