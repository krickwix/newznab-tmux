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
        if (! Schema::hasTable('current_forward_release_dispositions')) {
            Schema::create('current_forward_release_dispositions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('release_id');
                $table->unsignedBigInteger('chain_root_id');
                $table->unsignedBigInteger('window_id');
                $table->unsignedBigInteger('parent_collection_id')->nullable();
                $table->string('reason', 120);
                $table->unsignedInteger('categories_id')->nullable();
                $table->tinyInteger('nzbstatus')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->dateTime('disposed_at');
                $table->timestamps();
                $table->unique('release_id', 'cf_release_dispositions_release_uq');
                $table->index(
                    ['chain_root_id', 'disposed_at'],
                    'cf_release_dispositions_root_disposed_idx',
                );
                $table->index(
                    ['window_id', 'release_id'],
                    'cf_release_dispositions_window_release_idx',
                );
            });
        }

        if ($this->supportsChecks()
            && ! $this->constraintExists(
                'current_forward_release_dispositions',
                'cf_release_dispositions_positive_chk',
            )
        ) {
            DB::statement(
                'ALTER TABLE current_forward_release_dispositions '
                .'ADD CONSTRAINT cf_release_dispositions_positive_chk '
                .'CHECK (release_id > 0 AND chain_root_id > 0 AND window_id > 0)',
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('current_forward_release_dispositions')
            && DB::table('current_forward_release_dispositions')->exists()
        ) {
            throw new RuntimeException(
                'Refusing to drop immutable current-forward release dispositions; preserve or explicitly archive the evidence first.',
            );
        }
        Schema::dropIfExists('current_forward_release_dispositions');
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
