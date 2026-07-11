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
    }

    public function test_it_atomically_consumes_one_fresh_active_permit(): void
    {
        $this->settings('active', time() + 60, 0, 17);

        self::assertTrue((new BackfillPermitGate)->claim());
        self::assertSame(0, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame(17, Settings::settingValue('orchestrator_bf_claimed'));
        self::assertFalse((new BackfillPermitGate)->claim());
    }

    public function test_it_denies_a_permit_without_a_pinned_target_group(): void
    {
        $this->settings('active', time() + 60, 0, 17);
        Settings::query()->where('name', 'orchestrator_bf_group')->update(['value' => '']);

        self::assertFalse((new BackfillPermitGate)->claim());
        self::assertSame(17, Settings::settingValue('orchestrator_bf_permit'));
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
        ]);
    }
}
