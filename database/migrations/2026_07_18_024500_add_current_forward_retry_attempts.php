<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string RANGE_INDEX = 'cf_windows_source_range_uq';

    private const string ATTEMPT_INDEX = 'cf_windows_source_range_attempt_uq';

    private const string RETRY_PARENT_INDEX = 'cf_windows_retry_parent_uq';

    private const string RANGE_LATEST_INDEX = 'cf_windows_range_latest_idx';

    private const string RANGE_LIVE_INDEX = 'cf_windows_source_range_live_uq';

    public function up(): void
    {
        if (! Schema::hasTable('current_forward_windows')) {
            return;
        }

        Schema::table('current_forward_windows', function (Blueprint $table): void {
            if (! Schema::hasColumn('current_forward_windows', 'attempt_ordinal')) {
                $table->unsignedSmallInteger('attempt_ordinal')->default(1)->after('source_id');
            }
            if (! Schema::hasColumn('current_forward_windows', 'retry_of_window_id')) {
                $table->unsignedBigInteger('retry_of_window_id')->nullable()->after('attempt_ordinal');
            }
        });

        DB::table('current_forward_windows')
            ->whereNull('attempt_ordinal')
            ->update(['attempt_ordinal' => 1]);

        if (! Schema::hasIndex('current_forward_windows', self::ATTEMPT_INDEX)) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->unique(
                    ['source_id', 'first_article', 'last_article', 'attempt_ordinal'],
                    self::ATTEMPT_INDEX,
                );
            });
        }
        if (! Schema::hasIndex('current_forward_windows', self::RETRY_PARENT_INDEX)) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->unique('retry_of_window_id', self::RETRY_PARENT_INDEX);
            });
        }
        if (! Schema::hasIndex('current_forward_windows', self::RANGE_LATEST_INDEX)) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->index(
                    ['source_id', 'first_article', 'last_article', 'attempt_ordinal', 'id'],
                    self::RANGE_LATEST_INDEX,
                );
            });
        }

        if ($this->isMariaDbFamily()) {
            if (! Schema::hasColumn('current_forward_windows', 'range_live_slot')) {
                DB::statement(
                    "ALTER TABLE current_forward_windows
                     ADD COLUMN range_live_slot TINYINT
                     GENERATED ALWAYS AS (CASE WHEN state <> 'QUARANTINED' THEN 1 ELSE NULL END) STORED",
                );
            }
            if (! Schema::hasIndex('current_forward_windows', self::RANGE_LIVE_INDEX)) {
                DB::statement(
                    'ALTER TABLE current_forward_windows
                     ADD UNIQUE INDEX '.self::RANGE_LIVE_INDEX.'
                     (source_id, first_article, last_article, range_live_slot)',
                );
            }
            if (! $this->constraintExists('cf_windows_attempt_chk')) {
                DB::statement(
                    'ALTER TABLE current_forward_windows
                     ADD CONSTRAINT cf_windows_attempt_chk CHECK (
                         attempt_ordinal > 0
                         AND ((attempt_ordinal = 1 AND retry_of_window_id IS NULL)
                           OR (attempt_ordinal > 1 AND retry_of_window_id IS NOT NULL))
                     )',
                );
            }
            if (! $this->foreignKeyExists('cf_windows_retry_parent_fk')) {
                DB::statement(
                    'ALTER TABLE current_forward_windows
                     ADD CONSTRAINT cf_windows_retry_parent_fk
                     FOREIGN KEY (retry_of_window_id) REFERENCES current_forward_windows(id)
                     ON DELETE RESTRICT',
                );
            }

        } else {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.self::RANGE_LIVE_INDEX.'
                 ON current_forward_windows (source_id, first_article, last_article)
                 WHERE state <> \'QUARANTINED\'',
            );
        }

        // MariaDB auto-commits DDL. Keep the legacy range fence until every
        // replacement fence is installed so concurrent writers never see an
        // unguarded range.
        if (Schema::hasIndex('current_forward_windows', self::RANGE_INDEX)) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->dropUnique(self::RANGE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('current_forward_windows')) {
            return;
        }
        $hasAttempt = Schema::hasColumn('current_forward_windows', 'attempt_ordinal');
        $hasRetryParent = Schema::hasColumn('current_forward_windows', 'retry_of_window_id');
        if (! $hasAttempt && ! $hasRetryParent) {
            return;
        }
        if (! $hasAttempt || ! $hasRetryParent) {
            throw new RuntimeException(
                'Refusing to remove a partially applied current-forward retry schema.',
            );
        }
        if (DB::table('current_forward_windows')
            ->where(function ($query): void {
                $query->where('attempt_ordinal', '>', 1)
                    ->orWhereNotNull('retry_of_window_id');
            })
            ->exists()
        ) {
            throw new RuntimeException(
                'Refusing to remove current-forward retry schema while immutable retry attempts exist.',
            );
        }
        $duplicateRange = DB::table('current_forward_windows')
            ->select(['source_id', 'first_article', 'last_article'])
            ->groupBy(['source_id', 'first_article', 'last_article'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateRange) {
            throw new RuntimeException(
                'Refusing to remove current-forward retry schema while duplicate logical ranges exist.',
            );
        }

        // Restore the legacy fence before removing any retry fence. If this
        // fails, no retry artifacts have been changed.
        if (! Schema::hasIndex('current_forward_windows', self::RANGE_INDEX)) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->unique(
                    ['source_id', 'first_article', 'last_article'],
                    self::RANGE_INDEX,
                );
            });
        }

        if ($this->isMariaDbFamily()) {
            if ($this->foreignKeyExists('cf_windows_retry_parent_fk')) {
                DB::statement('ALTER TABLE current_forward_windows DROP FOREIGN KEY cf_windows_retry_parent_fk');
            }
            if ($this->constraintExists('cf_windows_attempt_chk')) {
                DB::statement(
                    'ALTER TABLE current_forward_windows '.$this->dropCheckKeyword().' cf_windows_attempt_chk',
                );
            }
            if (Schema::hasIndex('current_forward_windows', self::RANGE_LIVE_INDEX)) {
                DB::statement('ALTER TABLE current_forward_windows DROP INDEX '.self::RANGE_LIVE_INDEX);
            }
            if (Schema::hasColumn('current_forward_windows', 'range_live_slot')) {
                DB::statement('ALTER TABLE current_forward_windows DROP COLUMN range_live_slot');
            }
        } else {
            DB::statement('DROP INDEX IF EXISTS '.self::RANGE_LIVE_INDEX);
        }

        foreach ([self::RANGE_LATEST_INDEX, self::RETRY_PARENT_INDEX, self::ATTEMPT_INDEX] as $index) {
            if (Schema::hasIndex('current_forward_windows', $index)) {
                Schema::table('current_forward_windows', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }
        Schema::table('current_forward_windows', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['attempt_ordinal', 'retry_of_window_id'],
                static fn (string $column): bool => Schema::hasColumn('current_forward_windows', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function isMariaDbFamily(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', 'current_forward_windows')
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }

    private function foreignKeyExists(string $name): bool
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', Schema::getConnection()->getDatabaseName())
            ->where('TABLE_NAME', 'current_forward_windows')
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }

    private function dropCheckKeyword(): string
    {
        return Schema::getConnection()->getDriverName() === 'mysql'
            ? 'DROP CHECK'
            : 'DROP CONSTRAINT';
    }
};
