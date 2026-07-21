<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('backfill_execution_ranges')) {
            return;
        }

        Schema::create('backfill_execution_ranges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation')->index();
            $table->string('group_name');
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->string('status', 16)->index();
            $table->dateTime('claimed_at');
            $table->dateTime('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(
                ['generation', 'first_article', 'last_article'],
                'bf_ranges_generation_interval_uq',
            );
            $table->index(
                ['generation', 'status', 'id'],
                'bf_ranges_generation_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backfill_execution_ranges');
    }
};
