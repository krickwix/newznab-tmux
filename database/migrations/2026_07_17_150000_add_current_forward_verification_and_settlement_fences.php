<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('current_forward_window_verifications')) {
            Schema::create('current_forward_window_verifications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('window_id');
                $table->unsignedBigInteger('provider_first');
                $table->unsignedBigInteger('provider_high');
                $table->dateTime('provider_observed_at');
                $table->unsignedInteger('headers');
                $table->unsignedInteger('yenc_headers');
                $table->unsignedInteger('multipart_headers');
                $table->unsignedInteger('complete_binary_files');
                $table->string('evidence_hash', 64);
                $table->string('policy_version', 32);
                $table->string('idempotency_key', 64);
                $table->dateTime('verified_at');
                $table->timestamps();
                $table->unique(['window_id', 'idempotency_key'], 'cf_verifications_window_key_uq');
                $table->index(['window_id', 'verified_at', 'id'], 'cf_verifications_window_latest_idx');
            });
        }

        Schema::table('current_forward_windows', function (Blueprint $table): void {
            if (! Schema::hasColumn('current_forward_windows', 'issued_verification_id')) {
                $table->unsignedBigInteger('issued_verification_id')->nullable();
            }
            if (! Schema::hasColumn('current_forward_windows', 'attribution_started_at')) {
                $table->dateTime('attribution_started_at')->nullable();
            }
            if (! Schema::hasColumn('current_forward_windows', 'zero_output_deadline_at')) {
                $table->dateTime('zero_output_deadline_at')->nullable();
            }
            if (! Schema::hasColumn('current_forward_windows', 'drain_deadline_at')) {
                $table->dateTime('drain_deadline_at')->nullable();
            }
            if (! Schema::hasColumn('current_forward_windows', 'observation_hash')) {
                $table->string('observation_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('current_forward_windows', 'observation_stable_since_at')) {
                $table->dateTime('observation_stable_since_at')->nullable();
            }
            if (! Schema::hasColumn('current_forward_windows', 'last_observed_at')) {
                $table->dateTime('last_observed_at')->nullable();
            }
            if (! Schema::hasColumn('current_forward_windows', 'outcome_release_high')) {
                $table->unsignedBigInteger('outcome_release_high')->nullable();
            }
            if (! Schema::hasColumn('current_forward_windows', 'outcome_pending_collections')) {
                $table->unsignedInteger('outcome_pending_collections')->nullable();
            }
        });

        if (! Schema::hasIndex('current_forward_windows', 'cf_windows_state_generation_id_idx')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->index(['state', 'generation', 'id'], 'cf_windows_state_generation_id_idx');
            });
        }
        if (! Schema::hasIndex('current_forward_windows', 'cf_windows_state_id_idx')) {
            Schema::table('current_forward_windows', function (Blueprint $table): void {
                $table->index(['state', 'id'], 'cf_windows_state_id_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('current_forward_windows')) {
            if (Schema::hasIndex('current_forward_windows', 'cf_windows_state_generation_id_idx')) {
                Schema::table('current_forward_windows', function (Blueprint $table): void {
                    $table->dropIndex('cf_windows_state_generation_id_idx');
                });
            }
            if (Schema::hasIndex('current_forward_windows', 'cf_windows_state_id_idx')) {
                Schema::table('current_forward_windows', function (Blueprint $table): void {
                    $table->dropIndex('cf_windows_state_id_idx');
                });
            }

            $columns = array_values(array_filter([
                'issued_verification_id',
                'attribution_started_at',
                'zero_output_deadline_at',
                'drain_deadline_at',
                'observation_hash',
                'observation_stable_since_at',
                'last_observed_at',
                'outcome_release_high',
                'outcome_pending_collections',
            ], static fn (string $column): bool => Schema::hasColumn('current_forward_windows', $column)));
            if ($columns !== []) {
                Schema::table('current_forward_windows', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }

        Schema::dropIfExists('current_forward_window_verifications');
    }
};
