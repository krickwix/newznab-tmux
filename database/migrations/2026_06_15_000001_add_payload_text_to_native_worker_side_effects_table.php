<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('native_worker_side_effects')
            || Schema::hasColumn('native_worker_side_effects', 'payload_text')) {
            return;
        }

        Schema::table('native_worker_side_effects', function (Blueprint $table): void {
            $table->string('payload_text', 255)->nullable()->after('status_value');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('native_worker_side_effects')
            || ! Schema::hasColumn('native_worker_side_effects', 'payload_text')) {
            return;
        }

        Schema::table('native_worker_side_effects', function (Blueprint $table): void {
            $table->dropColumn('payload_text');
        });
    }
};
