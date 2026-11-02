<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Models\Settings;
use App\Services\Distributed\BackfillExecutionGuard;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BackfillReceiptPruneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.backfill_receipt_retention_days' => 14,
        ]);
        DB::purge('sqlite');
        Settings::forgetMemoizedSettings();
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
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
        });
    }

    public function test_it_prunes_only_settled_receipts_past_the_retention_window(): void
    {
        $this->markers(claimed: 500, completed: 500);
        $this->receipt(id: 1, generation: 100, claimedAt: '-30 days');
        $this->receipt(id: 2, generation: 200, claimedAt: '-15 days');
        $this->receipt(id: 3, generation: 300, claimedAt: '-13 days');
        $this->receipt(id: 4, generation: 400, claimedAt: '-1 hour');

        $summary = (new BackfillExecutionGuard)->pruneSettledReceipts(apply: true);

        self::assertSame(2, $summary['deleted']);
        self::assertSame(14, $summary['retention_days']);
        self::assertSame(500, $summary['floor_generation']);
        self::assertTrue($summary['applied']);
        self::assertSame([3, 4], $this->remainingIds());
    }

    public function test_the_in_flight_generation_and_anything_newer_is_never_pruned(): void
    {
        // An unsettled claim is the live generation: complete() still needs
        // its receipts, even though they are older than the window.
        $this->markers(claimed: 300, completed: 299);
        $this->receipt(id: 1, generation: 299, claimedAt: '-40 days');
        $this->receipt(id: 2, generation: 300, claimedAt: '-40 days');
        $this->receipt(id: 3, generation: 301, claimedAt: '-40 days');

        $summary = (new BackfillExecutionGuard)->pruneSettledReceipts(apply: true);

        self::assertSame(1, $summary['deleted']);
        self::assertSame([2, 3], $this->remainingIds());
    }

    public function test_a_missing_generation_marker_prunes_nothing(): void
    {
        $this->receipt(id: 1, generation: 10, claimedAt: '-90 days');

        $summary = (new BackfillExecutionGuard)->pruneSettledReceipts(apply: true);

        self::assertSame(0, $summary['floor_generation']);
        self::assertSame(0, $summary['deleted']);
        self::assertSame([1], $this->remainingIds());
    }

    public function test_dry_run_counts_without_deleting_and_the_limit_bounds_one_pass(): void
    {
        $this->markers(claimed: 500, completed: 500);
        foreach (range(1, 5) as $id) {
            $this->receipt(id: $id, generation: 100 + $id, claimedAt: '-30 days');
        }

        $dryRun = (new BackfillExecutionGuard)->pruneSettledReceipts();
        self::assertSame(5, $dryRun['deleted']);
        self::assertFalse($dryRun['applied']);
        self::assertCount(5, $this->remainingIds());

        $bounded = (new BackfillExecutionGuard)->pruneSettledReceipts(limit: 2, apply: true);
        self::assertSame(2, $bounded['deleted']);
        self::assertCount(3, $this->remainingIds());
    }

    public function test_a_failed_receipt_is_kept_for_the_whole_window_then_pruned(): void
    {
        $this->markers(claimed: 500, completed: 500);
        $this->receipt(id: 1, generation: 100, claimedAt: '-13 days', status: 'FAILED', completedAt: null);
        $this->receipt(id: 2, generation: 101, claimedAt: '-20 days', status: 'FAILED', completedAt: null);

        (new BackfillExecutionGuard)->pruneSettledReceipts(apply: true);

        // completed_at is null on a FAILED row, so the sweep has to key on
        // claimed_at or it would never reach these at all.
        self::assertSame([1], $this->remainingIds());
    }

    public function test_a_custom_retention_window_overrides_the_configured_default(): void
    {
        $this->markers(claimed: 500, completed: 500);
        $this->receipt(id: 1, generation: 100, claimedAt: '-3 days');
        $this->receipt(id: 2, generation: 101, claimedAt: '-10 days');

        $summary = (new BackfillExecutionGuard)->pruneSettledReceipts(retentionDays: 7, apply: true);

        self::assertSame(7, $summary['retention_days']);
        self::assertSame([1], $this->remainingIds());
    }

    public function test_the_sweep_is_scheduled_daily_outside_the_backfill_window(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains($event->command ?? '', 'nntmux:prune-backfill-receipts'),
        ));

        self::assertCount(1, $events, 'the sweep must be scheduled exactly once');
        self::assertStringContainsString('--apply', (string) $events[0]->command);
        self::assertSame('10 7 * * *', $events[0]->expression);
        // 02:00-06:00 UTC is the backfill window, where the row-lock hard gate
        // is already sensitive; a delete competing with header ingest there
        // buys nothing.
        self::assertNotContains((int) explode(' ', $events[0]->expression)[1], [2, 3, 4, 5]);
    }

    public function test_the_command_is_registered_and_applies_the_sweep(): void
    {
        $this->markers(claimed: 500, completed: 500);
        $this->receipt(id: 1, generation: 100, claimedAt: '-30 days');
        $this->receipt(id: 2, generation: 101, claimedAt: '-1 hour');

        $this->artisan('nntmux:prune-backfill-receipts --json')
            ->assertSuccessful();
        self::assertSame([1, 2], $this->remainingIds(), 'the default run must not delete');

        $this->artisan('nntmux:prune-backfill-receipts --apply')
            ->expectsOutputToContain('Deleted 1 receipt row(s)')
            ->assertSuccessful();
        self::assertSame([2], $this->remainingIds());
    }

    private function markers(int $claimed, int $completed): void
    {
        Settings::query()->insert([
            ['name' => 'orchestrator_bf_claimed', 'value' => (string) $claimed],
            ['name' => 'orchestrator_bf_completed', 'value' => (string) $completed],
        ]);
        Settings::forgetCachedSettings();
    }

    private function receipt(
        int $id,
        int $generation,
        string $claimedAt,
        string $status = 'COMPLETED',
        ?string $completedAt = 'same',
    ): void {
        $claimed = Carbon::parse($claimedAt);
        DB::table('backfill_execution_ranges')->insert([
            'id' => $id,
            'generation' => $generation,
            'group_name' => 'alt.test',
            'first_article' => $generation * 10_000,
            'last_article' => $generation * 10_000 + 9_999,
            'status' => $status,
            'claimed_at' => $claimed,
            'completed_at' => $completedAt === 'same' ? $claimed : $completedAt,
            'created_at' => $claimed,
            'updated_at' => $claimed,
        ]);
    }

    /** @return list<int> */
    private function remainingIds(): array
    {
        return DB::table('backfill_execution_ranges')->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
