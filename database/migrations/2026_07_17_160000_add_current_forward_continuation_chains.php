<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORIGINAL_STATE_CHECK = "state IN ('DISCOVERED','AUDITED','OFFERED','CLAIMED','INGESTED','ATTRIBUTING','PRODUCTIVE','QUARANTINED')";

    private const CONTINUATION_STATE_CHECK = "state IN ('DISCOVERED','AUDITED','OFFERED','CLAIMED','INGESTED','ATTRIBUTING','CONTINUATION_PENDING','CHAINED','PRODUCTIVE','QUARANTINED')";

    public function up(): void
    {
        $this->addWindowColumns();

        DB::table('current_forward_windows')
            ->whereNull('chain_root_id')
            ->update(['chain_root_id' => DB::raw('id')]);
        DB::table('current_forward_windows')
            ->whereNull('chain_ordinal')
            ->update(['chain_ordinal' => 1]);

        $this->ensureWindowIndexes();
        $this->ensureOwnershipTable();
        $this->ensureObjectTable();
        $this->ensureObservationTable();
        $this->replaceWindowStateCheck(self::CONTINUATION_STATE_CHECK);
        $this->ensureOpenChainGuard();
    }

    public function down(): void
    {
        if (! Schema::hasTable('current_forward_windows')) {
            Schema::dropIfExists('current_forward_continuation_observations');
            Schema::dropIfExists('current_forward_window_objects');
            Schema::dropIfExists('current_forward_object_owners');

            return;
        }
        if (DB::table('current_forward_windows')
            ->whereIn('state', ['CONTINUATION_PENDING', 'CHAINED'])
            ->exists()) {
            throw new RuntimeException(
                'Refusing to remove continuation schema: terminalize every continuation chain first.',
            );
        }

        $this->dropOpenChainGuard();
        $this->replaceWindowStateCheck(self::ORIGINAL_STATE_CHECK);

        Schema::dropIfExists('current_forward_continuation_observations');
        Schema::dropIfExists('current_forward_window_objects');
        Schema::dropIfExists('current_forward_object_owners');

        foreach ([
            'cf_windows_state_chain_id_idx',
            'cf_windows_parent_uq',
            'cf_windows_chain_ordinal_uq',
        ] as $index) {
            if (Schema::hasIndex('current_forward_windows', $index)) {
                Schema::table('current_forward_windows', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }

        $columns = array_values(array_filter([
            'chain_root_id',
            'parent_window_id',
            'chain_ordinal',
            'continuation_deadline_at',
        ], static fn (string $column): bool => Schema::hasColumn('current_forward_windows', $column)));
        if ($columns !== []) {
            Schema::table('current_forward_windows', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function addWindowColumns(): void
    {
        if (! Schema::hasColumn('current_forward_windows', 'chain_root_id')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->unsignedBigInteger('chain_root_id')->nullable();
            });
        }
        if (! Schema::hasColumn('current_forward_windows', 'parent_window_id')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->unsignedBigInteger('parent_window_id')->nullable();
            });
        }
        if (! Schema::hasColumn('current_forward_windows', 'chain_ordinal')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->unsignedTinyInteger('chain_ordinal')->nullable()->default(1);
            });
        }
        if (! Schema::hasColumn('current_forward_windows', 'continuation_deadline_at')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->dateTime('continuation_deadline_at')->nullable();
            });
        }
    }

    private function ensureWindowIndexes(): void
    {
        if (! Schema::hasIndex('current_forward_windows', 'cf_windows_chain_ordinal_uq')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->unique(['chain_root_id', 'chain_ordinal'], 'cf_windows_chain_ordinal_uq');
            });
        }
        if (! Schema::hasIndex('current_forward_windows', 'cf_windows_parent_uq')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->unique('parent_window_id', 'cf_windows_parent_uq');
            });
        }
        if (! Schema::hasIndex('current_forward_windows', 'cf_windows_state_chain_id_idx')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->index(['state', 'chain_root_id', 'id'], 'cf_windows_state_chain_id_idx');
            });
        }
    }

    private function ensureObjectTable(): void
    {
        if (! Schema::hasTable('current_forward_window_objects')) {
            Schema::create('current_forward_window_objects', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('window_id');
                $table->unsignedBigInteger('chain_root_id');
                $table->string('object_type', 16);
                $table->unsignedBigInteger('object_id');
                $table->unsignedBigInteger('parent_object_id')->nullable();
                $table->unsignedInteger('inserted_parts')->default(0);
                $table->boolean('created_in_window')->default(false);
                $table->boolean('touched_in_window')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('current_forward_window_objects', 'cf_window_objects_window_type_object_uq')) {
            Schema::table('current_forward_window_objects', function (Blueprint $table): void {
                $table->unique(
                    ['window_id', 'object_type', 'object_id'],
                    'cf_window_objects_window_type_object_uq',
                );
            });
        }
        if (! Schema::hasIndex('current_forward_window_objects', 'cf_window_objects_root_type_object_idx')) {
            Schema::table('current_forward_window_objects', function (Blueprint $table): void {
                $table->index(
                    ['chain_root_id', 'object_type', 'object_id'],
                    'cf_window_objects_root_type_object_idx',
                );
            });
        }
        if (! Schema::hasIndex('current_forward_window_objects', 'cf_window_objects_root_parent_idx')) {
            Schema::table('current_forward_window_objects', function (Blueprint $table): void {
                $table->index(
                    ['chain_root_id', 'parent_object_id'],
                    'cf_window_objects_root_parent_idx',
                );
            });
        }

        if ($this->supportsChecks() && ! $this->constraintExists('current_forward_window_objects', 'cf_window_objects_type_chk')) {
            DB::statement("ALTER TABLE current_forward_window_objects ADD CONSTRAINT cf_window_objects_type_chk CHECK (object_type IN ('COLLECTION','BINARY','RELEASE'))");
        }
    }

    private function ensureOwnershipTable(): void
    {
        if (! Schema::hasTable('current_forward_object_owners')) {
            Schema::create('current_forward_object_owners', function (Blueprint $table): void {
                $table->id();
                $table->string('object_type', 16);
                $table->unsignedBigInteger('object_id');
                $table->unsignedBigInteger('chain_root_id');
                $table->timestamps();
                $table->unique(
                    ['object_type', 'object_id'],
                    'cf_object_owners_type_object_uq',
                );
                $table->index(
                    ['chain_root_id', 'object_type'],
                    'cf_object_owners_root_type_idx',
                );
            });
        }

        if ($this->supportsChecks() && ! $this->constraintExists('current_forward_object_owners', 'cf_object_owners_type_chk')) {
            DB::statement("ALTER TABLE current_forward_object_owners ADD CONSTRAINT cf_object_owners_type_chk CHECK (object_type IN ('COLLECTION','BINARY','RELEASE'))");
        }
    }

    private function ensureObservationTable(): void
    {
        if (! Schema::hasTable('current_forward_continuation_observations')) {
            Schema::create('current_forward_continuation_observations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('window_id');
                $table->unsignedBigInteger('chain_root_id');
                $table->unsignedTinyInteger('chain_ordinal');
                $table->unsignedBigInteger('baseline_present_parts');
                $table->unsignedBigInteger('current_present_parts');
                $table->unsignedBigInteger('useful_progress_parts');
                $table->unsignedBigInteger('expected_parts');
                $table->unsignedInteger('observed_files');
                $table->unsignedInteger('complete_files');
                $table->unsignedInteger('unresolved_collections');
                $table->unsignedBigInteger('cumulative_parts');
                $table->unsignedInteger('cumulative_binaries');
                $table->unsignedInteger('cumulative_collections');
                $table->unsignedInteger('cumulative_releases');
                $table->unsignedInteger('cumulative_ready_nzbs');
                $table->string('decision', 32);
                $table->string('reason', 120);
                $table->string('pipeline_hash', 64);
                $table->string('cohort_hash', 64);
                $table->string('idempotency_key', 64);
                $table->dateTime('observed_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('current_forward_continuation_observations', 'cf_continuation_observations_window_uq')) {
            Schema::table('current_forward_continuation_observations', function (Blueprint $table): void {
                $table->unique('window_id', 'cf_continuation_observations_window_uq');
            });
        }
        if (! Schema::hasIndex('current_forward_continuation_observations', 'cf_continuation_observations_key_uq')) {
            Schema::table('current_forward_continuation_observations', function (Blueprint $table): void {
                $table->unique('idempotency_key', 'cf_continuation_observations_key_uq');
            });
        }
        if (! Schema::hasIndex('current_forward_continuation_observations', 'cf_continuation_observations_root_ordinal_idx')) {
            Schema::table('current_forward_continuation_observations', function (Blueprint $table): void {
                $table->index(
                    ['chain_root_id', 'chain_ordinal'],
                    'cf_continuation_observations_root_ordinal_idx',
                );
            });
        }
    }

    private function ensureOpenChainGuard(): void
    {
        if (! $this->supportsGeneratedGuard()) {
            return;
        }

        if (! Schema::hasColumn('current_forward_windows', 'open_chain_slot')) {
            DB::statement(
                "ALTER TABLE current_forward_windows
                 ADD COLUMN open_chain_slot TINYINT
                 GENERATED ALWAYS AS (
                     CASE
                         WHEN state = 'CONTINUATION_PENDING'
                          AND parent_window_id IS NULL
                          AND chain_ordinal = 1
                         THEN 1
                         ELSE NULL
                     END
                 ) STORED",
            );
        }
        if (! Schema::hasIndex('current_forward_windows', 'cf_windows_open_chain_uq')) {
            DB::statement(
                'ALTER TABLE current_forward_windows
                 ADD UNIQUE INDEX cf_windows_open_chain_uq (open_chain_slot)',
            );
        }
    }

    private function dropOpenChainGuard(): void
    {
        if (! $this->supportsGeneratedGuard()) {
            return;
        }

        if (Schema::hasIndex('current_forward_windows', 'cf_windows_open_chain_uq')) {
            DB::statement('ALTER TABLE current_forward_windows DROP INDEX cf_windows_open_chain_uq');
        }
        if (Schema::hasColumn('current_forward_windows', 'open_chain_slot')) {
            DB::statement('ALTER TABLE current_forward_windows DROP COLUMN open_chain_slot');
        }
    }

    private function replaceWindowStateCheck(string $expression): void
    {
        if (! $this->supportsChecks()) {
            return;
        }

        if ($this->constraintExists('current_forward_windows', 'cf_windows_state_chk')) {
            $dropKeyword = Schema::getConnection()->getDriverName() === 'mariadb'
                ? 'DROP CONSTRAINT'
                : 'DROP CHECK';
            DB::statement("ALTER TABLE current_forward_windows {$dropKeyword} cf_windows_state_chk");
        }
        DB::statement("ALTER TABLE current_forward_windows ADD CONSTRAINT cf_windows_state_chk CHECK ({$expression})");
    }

    private function supportsChecks(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function supportsGeneratedGuard(): bool
    {
        return $this->supportsChecks();
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
