<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('current_forward_windows', 'cursor_end_postdate')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->dateTime('cursor_end_postdate')->nullable()->after('cursor_postdate');
            });
        }

        if (! $this->supportsGeneratedGuard()) {
            return;
        }
        if (! Schema::hasColumn('current_forward_windows', 'unresolved_slot')) {
            DB::statement(
                "ALTER TABLE current_forward_windows
                 ADD COLUMN unresolved_slot TINYINT
                 GENERATED ALWAYS AS (
                     CASE WHEN state IN ('OFFERED','CLAIMED','INGESTED','ATTRIBUTING') THEN 1 ELSE NULL END
                 ) STORED",
            );
        }
        if (! $this->indexExists('cf_windows_unresolved_uq')) {
            DB::statement(
                'ALTER TABLE current_forward_windows
                 ADD UNIQUE INDEX cf_windows_unresolved_uq (unresolved_slot)',
            );
        }
    }

    public function down(): void
    {
        if ($this->supportsGeneratedGuard()) {
            if ($this->indexExists('cf_windows_unresolved_uq')) {
                DB::statement('ALTER TABLE current_forward_windows DROP INDEX cf_windows_unresolved_uq');
            }
            if (Schema::hasColumn('current_forward_windows', 'unresolved_slot')) {
                DB::statement('ALTER TABLE current_forward_windows DROP COLUMN unresolved_slot');
            }
        }

        if (Schema::hasColumn('current_forward_windows', 'cursor_end_postdate')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->dropColumn('cursor_end_postdate');
            });
        }
    }

    private function supportsGeneratedGuard(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function indexExists(string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', 'current_forward_windows')
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
