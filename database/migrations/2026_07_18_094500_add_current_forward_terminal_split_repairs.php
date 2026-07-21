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
        if (! Schema::hasTable('current_forward_terminal_collection_repairs')) {
            Schema::create('current_forward_terminal_collection_repairs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('handoff_id');
                $table->unsignedBigInteger('chain_root_id');
                $table->unsignedBigInteger('source_window_id');
                $table->unsignedBigInteger('target_window_id');
                $table->unsignedBigInteger('source_collection_id');
                $table->unsignedBigInteger('target_collection_id');
                $table->string('root_state', 32);
                $table->string('root_failure_reason', 120);
                $table->dateTime('root_settled_at');
                $table->unsignedInteger('source_binary_count');
                $table->char('source_binary_ids_hash', 64);
                $table->unsignedInteger('target_binary_count');
                $table->char('target_binary_ids_hash', 64);
                $table->unsignedInteger('merged_binary_count');
                $table->char('merged_binary_ids_hash', 64);
                $table->string('group_name', 255);
                $table->unsignedInteger('anchor_totalparts');
                $table->unsignedInteger('anchor_article_span');
                $table->unsignedInteger('forward_article_gap');
                $table->integer('residual');
                $table->string('policy_version', 32);
                $table->char('pre_observation_hash', 64);
                $table->char('pre_bad_set_hash', 64);
                $table->char('chain_hash', 64);
                $table->char('observation_rows_hash', 64);
                $table->char('evidence_hash', 64);
                $table->dateTime('repaired_at');
                $table->timestamps();

                $table->unique('handoff_id', 'cf_terminal_repairs_handoff_uq');
                $table->unique(
                    ['chain_root_id', 'source_collection_id'],
                    'cf_terminal_repairs_root_source_uq',
                );
                $table->index(
                    ['chain_root_id', 'target_collection_id'],
                    'cf_terminal_repairs_root_target_idx',
                );
                $table->unique('target_collection_id', 'cf_terminal_repairs_target_uq');
                $table->index(
                    ['target_window_id', 'repaired_at'],
                    'cf_terminal_repairs_window_repaired_idx',
                );
                $table->foreign('handoff_id', 'cf_terminal_repairs_handoff_fk')
                    ->references('id')->on('current_forward_collection_handoffs')->restrictOnDelete();
                $table->foreign('chain_root_id', 'cf_terminal_repairs_root_fk')
                    ->references('id')->on('current_forward_windows')->restrictOnDelete();
                $table->foreign('source_window_id', 'cf_terminal_repairs_source_window_fk')
                    ->references('id')->on('current_forward_windows')->restrictOnDelete();
                $table->foreign('target_window_id', 'cf_terminal_repairs_target_window_fk')
                    ->references('id')->on('current_forward_windows')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('current_forward_terminal_release_attributions')) {
            Schema::create('current_forward_terminal_release_attributions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('release_id');
                $table->unsignedBigInteger('repair_id');
                $table->unsignedBigInteger('handoff_id');
                $table->unsignedBigInteger('chain_root_id');
                $table->unsignedBigInteger('window_id');
                $table->unsignedBigInteger('target_collection_id');
                $table->unsignedInteger('target_binary_count');
                $table->char('target_binary_ids_hash', 64);
                $table->unsignedInteger('release_categories_id');
                $table->smallInteger('release_nzbstatus');
                $table->unsignedBigInteger('release_size');
                $table->string('policy_version', 32);
                $table->char('evidence_hash', 64);
                $table->dateTime('attributed_at');
                $table->timestamps();

                $table->unique('release_id', 'cf_terminal_attributions_release_uq');
                $table->unique('repair_id', 'cf_terminal_attributions_repair_uq');
                $table->index(
                    ['chain_root_id', 'target_collection_id'],
                    'cf_terminal_attributions_root_target_idx',
                );
                $table->index(
                    ['repair_id', 'handoff_id'],
                    'cf_terminal_attributions_repair_handoff_idx',
                );
                $table->foreign('repair_id', 'cf_terminal_attributions_repair_fk')
                    ->references('id')->on('current_forward_terminal_collection_repairs')->restrictOnDelete();
                $table->foreign('handoff_id', 'cf_terminal_attributions_handoff_fk')
                    ->references('id')->on('current_forward_collection_handoffs')->restrictOnDelete();
                $table->foreign('chain_root_id', 'cf_terminal_attributions_root_fk')
                    ->references('id')->on('current_forward_windows')->restrictOnDelete();
                $table->foreign('window_id', 'cf_terminal_attributions_window_fk')
                    ->references('id')->on('current_forward_windows')->restrictOnDelete();
            });
        }

        $this->ensureChecks();
    }

    public function down(): void
    {
        if ((Schema::hasTable('current_forward_terminal_release_attributions')
                && DB::table('current_forward_terminal_release_attributions')->exists())
            || (Schema::hasTable('current_forward_terminal_collection_repairs')
                && DB::table('current_forward_terminal_collection_repairs')->exists())
        ) {
            throw new RuntimeException(
                'Refusing to drop immutable current-forward terminal split repair evidence; preserve or explicitly archive it first.',
            );
        }

        Schema::dropIfExists('current_forward_terminal_release_attributions');
        Schema::dropIfExists('current_forward_terminal_collection_repairs');
    }

    private function ensureChecks(): void
    {
        if (! $this->supportsChecks()) {
            return;
        }
        if (! $this->constraintExists(
            'current_forward_terminal_collection_repairs',
            'cf_terminal_repairs_valid_chk',
        )) {
            DB::statement(
                'ALTER TABLE current_forward_terminal_collection_repairs '
                .'ADD CONSTRAINT cf_terminal_repairs_valid_chk CHECK ('
                .'handoff_id > 0 AND chain_root_id > 0 '
                .'AND source_window_id > 0 AND target_window_id > 0 '
                .'AND source_collection_id > 0 AND target_collection_id > 0 '
                .'AND source_collection_id <> target_collection_id '
                ."AND root_state = 'QUARANTINED' AND CHAR_LENGTH(root_failure_reason) > 0 "
                .'AND root_settled_at <= repaired_at '
                .'AND source_binary_count > 0 AND target_binary_count > 0 '
                .'AND merged_binary_count = source_binary_count + target_binary_count '
                .'AND CHAR_LENGTH(source_binary_ids_hash) = 64 '
                .'AND CHAR_LENGTH(target_binary_ids_hash) = 64 '
                .'AND CHAR_LENGTH(merged_binary_ids_hash) = 64 '
                .'AND CHAR_LENGTH(group_name) > 0 '
                .'AND anchor_totalparts BETWEEN 1 AND 12000 '
                .'AND anchor_article_span > 0 AND anchor_article_span < anchor_totalparts '
                .'AND forward_article_gap BETWEEN 1 AND 12000 '
                .'AND residual BETWEEN -3 AND 0 '
                .'AND CHAR_LENGTH(policy_version) > 0 '
                .'AND CHAR_LENGTH(pre_observation_hash) = 64 '
                .'AND CHAR_LENGTH(pre_bad_set_hash) = 64 '
                .'AND CHAR_LENGTH(chain_hash) = 64 '
                .'AND CHAR_LENGTH(observation_rows_hash) = 64 '
                .'AND CHAR_LENGTH(evidence_hash) = 64)',
            );
        }
        if (! $this->constraintExists(
            'current_forward_terminal_release_attributions',
            'cf_terminal_attributions_valid_chk',
        )) {
            DB::statement(
                'ALTER TABLE current_forward_terminal_release_attributions '
                .'ADD CONSTRAINT cf_terminal_attributions_valid_chk CHECK ('
                .'release_id > 0 AND repair_id > 0 AND handoff_id > 0 '
                .'AND chain_root_id > 0 AND window_id > 0 AND target_collection_id > 0 '
                .'AND target_binary_count > 0 '
                .'AND CHAR_LENGTH(target_binary_ids_hash) = 64 '
                .'AND CHAR_LENGTH(policy_version) > 0 '
                .'AND CHAR_LENGTH(evidence_hash) = 64)',
            );
        }
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
