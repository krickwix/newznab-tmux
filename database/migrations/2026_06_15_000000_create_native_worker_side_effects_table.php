<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_worker_side_effects', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key', 191)->unique('ux_native_worker_side_effects_operation_key');
            $table->string('job', 64);
            $table->string('effect', 64);
            $table->unsignedBigInteger('release_id');
            $table->string('status_column', 32);
            $table->string('status_reason', 64);
            $table->unsignedTinyInteger('status_value');
            $table->string('payload_text', 255)->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id'], 'ix_native_worker_side_effects_status_available');
            $table->index(['release_id', 'status'], 'ix_native_worker_side_effects_release_status');
            $table->index(['job', 'effect', 'status'], 'ix_native_worker_side_effects_job_effect_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_worker_side_effects');
    }
};
