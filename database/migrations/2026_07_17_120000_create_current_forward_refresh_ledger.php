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
        Schema::create('current_forward_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('groups_id')->unique('cf_sources_group_id_uq');
            $table->string('group_name')->unique('cf_sources_group_name_uq');
            $table->unsignedBigInteger('anchor_first');
            $table->unsignedBigInteger('audited_last');
            $table->string('state', 32)->default('PROBATION');
            $table->unsignedTinyInteger('strikes')->default(0);
            $table->unsignedBigInteger('last_productive_generation')->nullable();
            $table->unsignedBigInteger('last_productive_release_id')->nullable();
            $table->dateTime('last_productive_at')->nullable();
            $table->dateTime('last_audited_at')->nullable();
            $table->string('last_reason', 120)->nullable();
            $table->timestamps();
            $table->index(['state', 'last_audited_at'], 'cf_sources_state_audit_idx');
        });

        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('generation')->nullable()->unique('cf_windows_generation_uq');
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->unsignedBigInteger('provider_first');
            $table->unsignedBigInteger('provider_high');
            $table->dateTime('provider_observed_at');
            $table->unsignedInteger('headers');
            $table->unsignedInteger('yenc_headers');
            $table->unsignedInteger('multipart_headers');
            $table->unsignedInteger('complete_binary_files');
            $table->string('evidence_hash', 64);
            $table->string('policy_version', 32);
            $table->string('state', 32)->default('AUDITED');
            $table->unsignedBigInteger('release_baseline')->nullable();
            $table->dateTime('cursor_postdate')->nullable();
            $table->unsignedInteger('outcome_releases')->nullable();
            $table->unsignedInteger('outcome_ready_nzbs')->nullable();
            $table->unsignedBigInteger('outcome_target_bytes')->nullable();
            $table->unsignedBigInteger('outcome_non_target_bytes')->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->dateTime('offered_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('ingested_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['source_id', 'first_article', 'last_article'], 'cf_windows_source_range_uq');
            $table->index(['source_id', 'state'], 'cf_windows_source_state_idx');
            $table->index(['state', 'updated_at'], 'cf_windows_state_updated_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE current_forward_sources ADD CONSTRAINT cf_sources_state_chk CHECK (state IN ('PROBATION','READY','HALTED','QUALITY_LOCKED'))");
            DB::statement('ALTER TABLE current_forward_sources ADD CONSTRAINT cf_sources_range_chk CHECK (anchor_first > 0 AND audited_last >= anchor_first + 9999 AND strikes <= 2)');
            DB::statement("ALTER TABLE current_forward_windows ADD CONSTRAINT cf_windows_state_chk CHECK (state IN ('DISCOVERED','AUDITED','OFFERED','CLAIMED','INGESTED','ATTRIBUTING','PRODUCTIVE','QUARANTINED'))");
            DB::statement('ALTER TABLE current_forward_windows ADD CONSTRAINT cf_windows_range_chk CHECK (first_article > 0 AND last_article = first_article + 9999 AND provider_first > 0 AND provider_first <= first_article AND provider_high >= last_article + 20000)');
            DB::statement('ALTER TABLE current_forward_windows ADD CONSTRAINT cf_windows_quality_chk CHECK (headers BETWEEN 9000 AND 10000 AND yenc_headers <= headers AND yenc_headers * 2 >= headers AND multipart_headers BETWEEN 1 AND headers AND complete_binary_files >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('current_forward_windows');
        Schema::dropIfExists('current_forward_sources');
    }
};
