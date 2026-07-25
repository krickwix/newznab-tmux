<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Models\Settings;
use App\Services\Distributed\BackfillPermitGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackfillPermitGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation')->nullable()->unique();
            $table->string('state', 32);
        });
    }

    public function test_it_atomically_consumes_one_fresh_active_permit(): void
    {
        $this->settings('active', time() + 60, 0, 17);

        $gate = new BackfillPermitGate;
        self::assertSame(17, $gate->claimGeneration());
        self::assertSame(0, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame(17, Settings::settingValue('orchestrator_bf_claimed'));
        self::assertSame('alt.test', Settings::settingValue('orchestrator_bfc_group'));
        self::assertSame(160_000, Settings::settingValue('orchestrator_bfc_qty'));
        self::assertSame(0, Settings::settingValue('orchestrator_bfc_stop'));
        self::assertFalse($gate->claim());
        self::assertTrue($gate->complete(17));
        self::assertSame(17, Settings::settingValue('orchestrator_bf_completed'));
        self::assertFalse($gate->complete(18));
        self::assertSame(17, Settings::settingValue('orchestrator_bf_completed'));
    }

    public function test_it_denies_a_permit_without_a_pinned_target_group(): void
    {
        $this->settings('active', time() + 60, 0, 17);
        Settings::query()->where('name', 'orchestrator_bf_group')->update(['value' => '']);

        self::assertFalse((new BackfillPermitGate)->claim());
        self::assertSame(17, Settings::settingValue('orchestrator_bf_permit'));
    }

    public function test_it_atomically_copies_a_matching_audited_stop_cursor(): void
    {
        config()->set('nntmux.orchestrator.backfill_stop_cursors', 'alt.test:60000');
        $this->settings('active', time() + 60, 0, 17);
        Settings::query()->updateOrCreate(['name' => 'orchestrator_bf_stop'], ['value' => '60000']);

        self::assertSame(17, (new BackfillPermitGate)->claimGeneration());
        self::assertSame(60_000, Settings::settingValue('orchestrator_bfc_stop'));
    }

    public function test_it_pins_the_provider_cursor_envelope_and_requires_completed_range_receipts(): void
    {
        config()->set('nntmux.orchestrator.require_backfill_permit', true);
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('first_record');
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
        DB::table('usenet_groups')->insert([
            'name' => 'alt.test',
            'first_record' => 250_000,
        ]);
        $this->settings('active', time() + 60, 0, 17);

        $gate = new BackfillPermitGate;
        self::assertSame(17, $gate->claimGeneration());
        self::assertSame(90_000, Settings::settingValue('orchestrator_bfc_first'));
        self::assertSame(249_999, Settings::settingValue('orchestrator_bfc_last'));
        self::assertFalse($gate->complete(17));

        DB::table('backfill_execution_ranges')->insert([
            'generation' => 17,
            'group_name' => 'alt.test',
            'first_article' => 90_000,
            'last_article' => 249_999,
            'status' => 'COMPLETED',
            'claimed_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertTrue($gate->complete(17));
        self::assertSame(17, Settings::settingValue('orchestrator_bf_completed'));
    }

    public function test_receipt_table_is_backward_compatible_while_strict_enforcement_is_off(): void
    {
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
        config()->set('nntmux.orchestrator.require_backfill_permit', false);
        $this->settings('active', time() + 60, 0, 17);

        $gate = new BackfillPermitGate;
        self::assertSame(17, $gate->claimGeneration());
        self::assertTrue($gate->complete(17));
    }

    public function test_failed_generation_is_fenced_for_prompt_orchestrator_recovery(): void
    {
        $this->settings('active', time() + 60, 0, 17);
        $gate = new BackfillPermitGate;
        self::assertSame(17, $gate->claimGeneration());

        self::assertTrue($gate->fail(17, 'provider failed'));
        self::assertSame(17, Settings::settingValue('orchestrator_bf_failed'));
    }

    public function test_it_denies_a_permit_when_the_pinned_stop_does_not_match_runtime_policy(): void
    {
        config()->set('nntmux.orchestrator.backfill_stop_cursors', 'alt.test:60000');
        $this->settings('active', time() + 60, 0, 17);
        Settings::query()->updateOrCreate(['name' => 'orchestrator_bf_stop'], ['value' => '50000']);

        self::assertNull((new BackfillPermitGate)->claimGeneration());
        self::assertSame(17, Settings::settingValue('orchestrator_bf_permit'));
    }

    public function test_it_denies_backfill_while_a_current_forward_window_is_unsettled(): void
    {
        $this->settings('active', time() + 60, 0, 17);
        DB::table('current_forward_windows')->insert([
            'generation' => 42,
            'state' => 'INGESTED',
        ]);

        self::assertNull((new BackfillPermitGate)->claimGeneration());
        self::assertSame(17, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame(0, Settings::settingValue('orchestrator_bf_claimed'));
    }

    public function test_it_denies_backfill_while_a_current_forward_permit_is_offered(): void
    {
        $this->settings('active', time() + 60, 0, 17);
        Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_permit'], ['value' => '42']);
        Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_claimed'], ['value' => '0']);
        Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_completed'], ['value' => '0']);

        self::assertNull((new BackfillPermitGate)->claimGeneration());
        self::assertSame(17, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame(0, Settings::settingValue('orchestrator_bf_claimed'));
    }

    #[DataProvider('deniedStates')]
    public function test_it_denies_unsafe_state(string $mode, int $leaseOffset, int $paused, int $permit): void
    {
        $this->settings($mode, time() + $leaseOffset, $paused, $permit);

        self::assertFalse((new BackfillPermitGate)->claim());
        self::assertSame($permit, Settings::settingValue('orchestrator_bf_permit'));
    }

    /** @return array<string, array{string, int, int, int}> */
    public static function deniedStates(): array
    {
        return [
            'shadow mode' => ['shadow', 60, 0, 1],
            'stale lease' => ['active', -1, 0, 1],
            'paused' => ['active', 60, 1, 1],
            'no permit' => ['active', 60, 0, 0],
        ];
    }

    private function settings(string $mode, int $lease, int $paused, int $permit): void
    {
        Settings::query()->insert([
            ['name' => 'orchestrator_mode', 'value' => $mode],
            ['name' => 'orchestrator_lease_until', 'value' => (string) $lease],
            ['name' => 'orchestrator_bf_paused', 'value' => (string) $paused],
            ['name' => 'orchestrator_bf_permit', 'value' => (string) $permit],
            ['name' => 'orchestrator_bf_claimed', 'value' => '0'],
            ['name' => 'orchestrator_bf_group', 'value' => 'alt.test'],
            ['name' => 'orchestrator_bf_qty', 'value' => '160000'],
        ]);
    }
}
