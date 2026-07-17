<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const CHECKS = [
        'current_forward_sources' => [
            'cf_sources_state_chk' => "state IN ('PROBATION','READY','HALTED','QUALITY_LOCKED')",
            'cf_sources_range_chk' => 'anchor_first > 0 AND audited_last >= anchor_first + 9999 AND strikes <= 2',
        ],
        'current_forward_windows' => [
            'cf_windows_state_chk' => "state IN ('DISCOVERED','AUDITED','OFFERED','CLAIMED','INGESTED','ATTRIBUTING','PRODUCTIVE','QUARANTINED')",
            'cf_windows_range_chk' => 'first_article > 0 AND last_article = first_article + 9999 AND provider_first > 0 AND provider_first <= first_article AND provider_high >= last_article + 20000',
            'cf_windows_quality_chk' => 'headers BETWEEN 9000 AND 10000 AND yenc_headers <= headers AND yenc_headers * 2 >= headers AND multipart_headers BETWEEN 1 AND headers AND complete_binary_files >= 1',
        ],
    ];

    public function up(): void
    {
        if (! $this->supportsChecks()) {
            return;
        }

        foreach (self::CHECKS as $table => $checks) {
            foreach ($checks as $name => $expression) {
                if (! $this->constraintExists($table, $name)) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        if (! $this->supportsChecks()) {
            return;
        }

        $dropKeyword = Schema::getConnection()->getDriverName() === 'mariadb'
            ? 'DROP CONSTRAINT'
            : 'DROP CHECK';
        foreach (self::CHECKS as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                if ($this->constraintExists($table, $name)) {
                    DB::statement("ALTER TABLE {$table} {$dropKeyword} {$name}");
                }
            }
        }
    }

    private function supportsChecks(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();
    }
};
