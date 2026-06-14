<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MAX_INLINE_CBP_FK_RESTORE_ROWS = 1_000_000;

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('collections') || ! Schema::hasTable('binaries') || ! Schema::hasTable('parts')) {
            return;
        }

        if ($this->foreignKeysAlreadyRestored(
            $this->foreignKeyExists('binaries', 'FK_Collections'),
            $this->foreignKeyExists('parts', 'FK_binaries')
        )) {
            return;
        }

        $estimatedPartsRows = $this->estimatedTableRows('parts');
        $explicitOptIn = (bool) config('nntmux.allow_large_cbp_fk_restore', false);
        if ($this->shouldAbortInlineForeignKeyRestore($estimatedPartsRows, $explicitOptIn)) {
            throw new RuntimeException(sprintf(
                'Refusing to restore CBP foreign keys inline because parts has an estimated %s rows. '
                .'Run bounded orphan cleanup first, then rerun with NNTMUX_ALLOW_LARGE_CBP_FK_RESTORE=true if FK restoration is still required.',
                number_format($estimatedPartsRows)
            ));
        }

        // Remove orphans so FK creation cannot fail on existing bad rows.
        DB::statement(
            'DELETE p FROM parts p LEFT JOIN binaries b ON b.id = p.binaries_id WHERE b.id IS NULL'
        );
        DB::statement(
            'DELETE b FROM binaries b LEFT JOIN collections c ON c.id = b.collections_id WHERE c.id IS NULL'
        );

        $this->dropForeignKeyIfExists('parts', 'FK_binaries');
        $this->dropForeignKeyIfExists('binaries', 'FK_Collections');

        DB::statement(
            'ALTER TABLE `binaries`
                ADD CONSTRAINT `FK_Collections`
                FOREIGN KEY (`collections_id`)
                REFERENCES `collections` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE'
        );

        DB::statement(
            'ALTER TABLE `parts`
                ADD CONSTRAINT `FK_binaries`
                FOREIGN KEY (`binaries_id`)
                REFERENCES `binaries` (`id`)
                ON DELETE CASCADE
                ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('binaries') || ! Schema::hasTable('parts')) {
            return;
        }

        $this->dropForeignKeyIfExists('parts', 'FK_binaries');
        $this->dropForeignKeyIfExists('binaries', 'FK_Collections');
    }

    private function dropForeignKeyIfExists(string $table, string $constraintName): void
    {
        if ($this->foreignKeyExists($table, $constraintName)) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraintName}`");
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        return DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_TYPE = "FOREIGN KEY"
               AND TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?',
            [$table, $constraintName]
        ) !== [];
    }

    public function foreignKeysAlreadyRestored(bool $collectionsFkExists, bool $binariesFkExists): bool
    {
        return $collectionsFkExists && $binariesFkExists;
    }

    public function shouldAbortInlineForeignKeyRestore(int $estimatedPartsRows, bool $explicitOptIn): bool
    {
        return ! $explicitOptIn && $estimatedPartsRows > self::MAX_INLINE_CBP_FK_RESTORE_ROWS;
    }

    private function estimatedTableRows(string $tableName): int
    {
        $rows = DB::selectOne(
            'SELECT TABLE_ROWS AS estimated_rows
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?',
            [$tableName]
        );

        return (int) ($rows->estimated_rows ?? 0);
    }
};
