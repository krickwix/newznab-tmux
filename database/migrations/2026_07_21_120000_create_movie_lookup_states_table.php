<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_lookup_states', function (Blueprint $table): void {
            $table->unsignedInteger('release_id')->primary();
            $table->string('status', 16);
            $table->string('observed_imdbid', 100)->nullable();
            $table->string('attempted_imdbid', 100)->nullable();
            $table->string('observed_searchname');
            $table->integer('observed_category_id');
            $table->string('reason_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at', 'release_id'], 'ix_movie_lookup_states_due');
            $table->index(['claim_expires_at', 'release_id'], 'ix_movie_lookup_states_claim');
            $table->foreign('release_id')->references('id')->on('releases')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_lookup_states');
    }
};
