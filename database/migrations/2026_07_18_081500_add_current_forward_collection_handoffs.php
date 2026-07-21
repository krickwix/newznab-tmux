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
        if (! Schema::hasTable('current_forward_collection_handoffs')) {
            Schema::create('current_forward_collection_handoffs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('source_collection_id');
                $table->unsignedBigInteger('target_collection_id');
                $table->unsignedBigInteger('chain_root_id');
                $table->unsignedBigInteger('source_window_id');
                $table->unsignedBigInteger('target_window_id');
                $table->unsignedInteger('moved_binary_count');
                $table->char('moved_binary_ids_hash', 64);
                $table->string('reason', 40);
                $table->dateTime('handed_off_at');
                $table->timestamps();
                $table->unique(
                    ['chain_root_id', 'source_collection_id'],
                    'cf_collection_handoffs_root_source_uq',
                );
                $table->index(
                    ['chain_root_id', 'target_collection_id'],
                    'cf_collection_handoffs_root_target_idx',
                );
                $table->index(
                    ['target_window_id', 'source_collection_id'],
                    'cf_collection_handoffs_target_window_source_idx',
                );
            });
        }

        if ($this->supportsChecks()
            && ! $this->constraintExists('current_forward_collection_handoffs', 'cf_collection_handoffs_valid_chk')
        ) {
            DB::statement(
                'ALTER TABLE current_forward_collection_handoffs '
                .'ADD CONSTRAINT cf_collection_handoffs_valid_chk CHECK ('
                .'source_collection_id > 0 AND target_collection_id > 0 '
                .'AND source_collection_id <> target_collection_id '
                .'AND chain_root_id > 0 AND source_window_id > 0 AND target_window_id > 0 '
                .'AND moved_binary_count > 0 AND CHAR_LENGTH(moved_binary_ids_hash) = 64 '
                ."AND reason IN ('split_collection_merge','split_collection_fanout_merge'))",
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('current_forward_collection_handoffs')
            && DB::table('current_forward_collection_handoffs')->exists()
        ) {
            throw new RuntimeException(
                'Refusing to drop immutable current-forward collection handoffs; preserve or explicitly archive the evidence first.',
            );
        }
        Schema::dropIfExists('current_forward_collection_handoffs');
    }

    private function supportsChecks(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();
    }
};
