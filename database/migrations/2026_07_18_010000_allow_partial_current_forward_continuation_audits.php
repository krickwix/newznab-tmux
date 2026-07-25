<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string OLD_CONSTRAINT = 'cf_windows_quality_chk';

    private const string CONTINUATION_CONSTRAINT = 'cf_windows_continuation_quality_chk';

    private const string QUALITY_EXPRESSION = "headers BETWEEN 9000 AND 10000
        AND yenc_headers <= headers
        AND yenc_headers * 2 >= headers
        AND multipart_headers BETWEEN 1 AND headers
        AND (complete_binary_files >= 1 OR policy_version = 'exact-xover-continuation-v1')";

    public function up(): void
    {
        if (! Schema::hasTable('current_forward_windows') || ! $this->supportsCheckRepair()) {
            return;
        }
        // MariaDB DDL auto-commits. Install the safe superset first so a
        // failure can never leave the table without a quality constraint.
        if (! $this->constraintExists(self::CONTINUATION_CONSTRAINT)) {
            DB::statement(sprintf(
                'ALTER TABLE current_forward_windows ADD CONSTRAINT %s CHECK (%s)',
                self::CONTINUATION_CONSTRAINT,
                self::QUALITY_EXPRESSION,
            ));
        }
        if ($this->constraintExists(self::OLD_CONSTRAINT)) {
            DB::statement('ALTER TABLE current_forward_windows '.$this->dropCheckKeyword().' '.self::OLD_CONSTRAINT);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('current_forward_windows') || ! $this->supportsCheckRepair()) {
            return;
        }
        $partialRows = DB::table('current_forward_windows')
            ->where('complete_binary_files', '<', 1)
            ->count();
        if ($partialRows > 0) {
            throw new RuntimeException('Cannot restore the complete-file constraint while partial continuation audits exist.');
        }
        if (! $this->constraintExists(self::OLD_CONSTRAINT)) {
            DB::statement('ALTER TABLE current_forward_windows ADD CONSTRAINT '.self::OLD_CONSTRAINT.' CHECK (
                headers BETWEEN 9000 AND 10000
                AND yenc_headers <= headers
                AND yenc_headers * 2 >= headers
                AND multipart_headers BETWEEN 1 AND headers
                AND complete_binary_files >= 1
            )');
        }
        if ($this->constraintExists(self::CONTINUATION_CONSTRAINT)) {
            DB::statement('ALTER TABLE current_forward_windows '.$this->dropCheckKeyword().' '.self::CONTINUATION_CONSTRAINT);
        }
    }

    private function dropCheckKeyword(): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? 'DROP CHECK'
            : 'DROP CONSTRAINT';
    }

    private function supportsCheckRepair(): bool
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
