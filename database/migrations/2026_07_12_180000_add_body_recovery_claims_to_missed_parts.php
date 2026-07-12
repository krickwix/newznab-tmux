<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('missed_parts')) {
            return;
        }

        Schema::table('missed_parts', function (Blueprint $table): void {
            if (! Schema::hasColumn('missed_parts', 'recovery_kind')) {
                $table->string('recovery_kind', 32)->nullable();
            }
            if (! Schema::hasColumn('missed_parts', 'recovery_source_collection_id')) {
                $table->unsignedBigInteger('recovery_source_collection_id')->nullable();
            }
            if (! Schema::hasColumn('missed_parts', 'recovery_source_binary_id')) {
                $table->unsignedBigInteger('recovery_source_binary_id')->nullable();
            }
            if (! Schema::hasColumn('missed_parts', 'claim_token')) {
                $table->string('claim_token', 64)->nullable();
            }
            if (! Schema::hasColumn('missed_parts', 'claim_owner')) {
                $table->string('claim_owner', 128)->nullable();
            }
            if (! Schema::hasColumn('missed_parts', 'claim_expires_at')) {
                $table->timestamp('claim_expires_at')->nullable();
            }
        });

        if (! $this->indexExists('missed_parts', 'ix_missed_parts_recovery_claim')) {
            Schema::table('missed_parts', function (Blueprint $table): void {
                $table->index(
                    ['groups_id', 'recovery_kind', 'attempts', 'claim_expires_at', 'id'],
                    'ix_missed_parts_recovery_claim'
                );
            });
        }

        if (! $this->indexExists('missed_parts', 'ix_missed_parts_claim_token_id')) {
            Schema::table('missed_parts', function (Blueprint $table): void {
                $table->index(['claim_token', 'id'], 'ix_missed_parts_claim_token_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('missed_parts')) {
            return;
        }

        if ($this->indexExists('missed_parts', 'ix_missed_parts_claim_token_id')) {
            Schema::table('missed_parts', function (Blueprint $table): void {
                $table->dropIndex('ix_missed_parts_claim_token_id');
            });
        }

        if ($this->indexExists('missed_parts', 'ix_missed_parts_recovery_claim')) {
            Schema::table('missed_parts', function (Blueprint $table): void {
                $table->dropIndex('ix_missed_parts_recovery_claim');
            });
        }

        Schema::table('missed_parts', function (Blueprint $table): void {
            $columns = [
                'recovery_kind',
                'recovery_source_collection_id',
                'recovery_source_binary_id',
                'claim_token',
                'claim_owner',
                'claim_expires_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('missed_parts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $indexName]
            ) !== [];
        }

        return DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]) !== [];
    }
};
