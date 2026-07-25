<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string OLD_CONSTRAINT = 'cf_windows_range_chk';

    private const string RELAXED_CONSTRAINT = 'cf_windows_range_v180_chk';

    private const string COMMON_EXPRESSION = 'first_article > 0
        AND last_article = first_article + 9999
        AND provider_first > 0
        AND provider_first <= first_article';

    public function up(): void
    {
        if (! Schema::hasTable('current_forward_windows') || ! $this->supportsChecks()) {
            return;
        }

        // MariaDB DDL auto-commits. Install the relaxed superset first so a
        // failed migration can never leave the exact-window range unguarded.
        if (! $this->constraintExists(self::RELAXED_CONSTRAINT)) {
            DB::statement('ALTER TABLE current_forward_windows ADD CONSTRAINT '
                .self::RELAXED_CONSTRAINT.' CHECK ('
                .self::COMMON_EXPRESSION.' AND provider_high >= last_article + 19000)');
        }
        if ($this->constraintExists(self::OLD_CONSTRAINT)) {
            DB::statement('ALTER TABLE current_forward_windows '
                .$this->dropCheckKeyword().' '.self::OLD_CONSTRAINT);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('current_forward_windows') || ! $this->supportsChecks()) {
            return;
        }

        if (DB::table('current_forward_windows')
            ->whereRaw('provider_high < last_article + 20000')
            ->exists()
        ) {
            throw new RuntimeException(
                'Cannot restore the 20000-article provider reserve while relaxed current-forward evidence exists.',
            );
        }
        if (! $this->constraintExists(self::OLD_CONSTRAINT)) {
            DB::statement('ALTER TABLE current_forward_windows ADD CONSTRAINT '
                .self::OLD_CONSTRAINT.' CHECK ('
                .self::COMMON_EXPRESSION.' AND provider_high >= last_article + 20000)');
        }
        if ($this->constraintExists(self::RELAXED_CONSTRAINT)) {
            DB::statement('ALTER TABLE current_forward_windows '
                .$this->dropCheckKeyword().' '.self::RELAXED_CONSTRAINT);
        }
    }

    private function dropCheckKeyword(): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? 'DROP CHECK'
            : 'DROP CONSTRAINT';
    }

    private function supportsChecks(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function constraintExists(string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'current_forward_windows')
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }
};
