<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\BackfillExecutionGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class BackfillExecutionGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.require_backfill_permit' => true,
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('backfill_execution_ranges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation');
            $table->string('group_name');
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->string('status', 16);
            $table->dateTime('claimed_at');
            $table->dateTime('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['generation', 'first_article', 'last_article']);
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_lease_until', 'value' => (string) (time() + 60)],
            ['name' => 'orchestrator_bf_claimed', 'value' => '17'],
            ['name' => 'orchestrator_bfc_group', 'value' => 'alt.permitted'],
            ['name' => 'orchestrator_bfc_first', 'value' => '80000'],
            ['name' => 'orchestrator_bfc_last', 'value' => '99999'],
        ]);
    }

    public function test_exact_claimed_generation_group_and_range_are_accepted(): void
    {
        (new BackfillExecutionGuard)->assertRangeAllowed(17, 'alt.permitted', 90_000, 99_999);

        self::assertTrue(true);
    }

    #[DataProvider('immutableEnvelopeDrift')]
    public function test_generation_group_or_range_drift_is_rejected(
        int $generation,
        string $group,
        int $first,
        int $last,
    ): void {
        $this->expectException(RuntimeException::class);

        (new BackfillExecutionGuard)->assertRangeAllowed($generation, $group, $first, $last);
    }

    /** @return array<string, array{int, string, int, int}> */
    public static function immutableEnvelopeDrift(): array
    {
        return [
            'generation' => [18, 'alt.permitted', 90_000, 99_999],
            'group' => [17, 'alt.other', 90_000, 99_999],
            'below first' => [17, 'alt.permitted', 79_999, 89_999],
            'above last' => [17, 'alt.permitted', 90_000, 100_000],
            'reversed range' => [17, 'alt.permitted', 99_999, 90_000],
        ];
    }

    public function test_stale_controller_lease_is_rejected(): void
    {
        DB::table('settings')
            ->where('name', 'orchestrator_lease_until')
            ->update(['value' => (string) (time() - 1)]);

        $this->expectException(RuntimeException::class);

        (new BackfillExecutionGuard)->assertRangeAllowed(17, 'alt.permitted', 90_000, 99_999);
    }

    public function test_failed_generation_cannot_claim_another_range(): void
    {
        DB::table('settings')->insert([
            'name' => 'orchestrator_bf_failed',
            'value' => '17',
        ]);

        $this->expectException(RuntimeException::class);

        (new BackfillExecutionGuard)->claimRange(17, 'alt.permitted', 90_000, 99_999);
    }

    public function test_overlapping_parallel_or_replayed_ranges_are_rejected_atomically(): void
    {
        $guard = new BackfillExecutionGuard;
        $receipt = $guard->claimRange(17, 'alt.permitted', 90_000, 99_999);
        self::assertGreaterThan(0, $receipt);

        $this->expectException(RuntimeException::class);
        $guard->claimRange(17, 'alt.permitted', 85_000, 94_999);
    }
}
