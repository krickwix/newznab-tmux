<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Models\Settings;
use App\Services\Distributed\CurrentForwardPermitGate;
use App\Services\Orchestrator\PipelineSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurrentForwardPermitGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.current_forward_windows' => 'alt.test:101-10100@20000',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->integer('active');
            $table->integer('backfill');
            $table->unsignedBigInteger('last_record');
        });
        Schema::create('short_groups', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('last_record');
            $table->dateTime('updated');
        });
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.test',
            'active' => 0,
            'backfill' => 1,
            'last_record' => 100,
        ]);
        DB::table('short_groups')->insert([
            'name' => 'alt.test',
            'first_record' => 1,
            'last_record' => 40_100,
            'updated' => now(),
        ]);
        Settings::query()->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_lease_until', 'value' => (string) (time() + 600)],
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
        ]);
    }

    public function test_one_exact_generation_is_issued_claimed_and_completed(): void
    {
        $gate = new CurrentForwardPermitGate;
        $issued = $gate->issue($this->safeSnapshot(), 17);

        self::assertTrue($issued['granted']);
        self::assertSame(17, Settings::settingValue('orchestrator_cf_permit'));
        self::assertSame([
            'generation' => 17,
            'group' => 'alt.test',
            'first' => 101,
            'last' => 10_100,
            'stop' => 20_000,
        ], $gate->claim(17, 'alt.test', 101, 10_100));
        self::assertNull($gate->claim(17, 'alt.test', 101, 10_100));

        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        self::assertTrue($gate->complete(17));
        self::assertSame(17, Settings::settingValue('orchestrator_cf_completed'));
        self::assertFalse($gate->complete(18));
    }

    public function test_issue_fails_closed_for_unsafe_pipeline_or_group_state(): void
    {
        $gate = new CurrentForwardPermitGate;
        self::assertSame('pipeline_not_drained', $gate->issue($this->safeSnapshot(highPressure: true), 17)['reason']);

        DB::table('usenet_groups')->where('name', 'alt.test')->update(['active' => 1]);
        self::assertSame('group_not_inactive_backfill', $gate->issue($this->safeSnapshot(), 18)['reason']);
        self::assertSame(0, Settings::settingValue('orchestrator_cf_permit'));
    }

    public function test_claim_rejects_drift_and_failure_is_generation_fenced(): void
    {
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 17)['granted']);
        DB::table('short_groups')->where('name', 'alt.test')->update(['last_record' => 30_000]);

        self::assertNull($gate->claim(17, 'alt.test', 101, 10_100));
        self::assertFalse($gate->fail(18, 'wrong_generation'));
        self::assertTrue($gate->fail(17, 'provider_range_drift'));
        self::assertSame(17, Settings::settingValue('orchestrator_cf_failed'));
        self::assertSame('provider_range_drift', Settings::settingValue('orchestrator_cf_failure'));
    }

    private function safeSnapshot(bool $highPressure = false): PipelineSnapshot
    {
        return new PipelineSnapshot(
            100,
            10,
            2,
            0,
            0,
            highPressure: $highPressure,
            lowPressure: ! $highPressure,
            databaseCurrentWaits: 0,
            eligibleNzbs: 0,
        );
    }
}
