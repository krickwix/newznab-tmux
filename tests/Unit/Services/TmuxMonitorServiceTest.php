<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TmuxMonitorServiceTest extends TestCase
{
    public function test_it_refreshes_only_the_current_forward_permit_control_view(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_lease_until', 'value' => '2000'],
            ['name' => 'orchestrator_cf_permit', 'value' => '41'],
            ['name' => 'orchestrator_cf_group', 'value' => 'alt.test'],
            ['name' => 'orchestrator_cf_first', 'value' => '101'],
            ['name' => 'orchestrator_cf_last', 'value' => '10100'],
            ['name' => 'unrelated_setting', 'value' => 'changed-in-db'],
        ]);

        $monitor = new class extends TmuxMonitorService
        {
            /** @param array<string, mixed> $runVar */
            public function seedRunVar(array $runVar): void
            {
                $this->runVar = $runVar;
            }
        };
        $monitor->seedRunVar(['settings' => [
            'unrelated_setting' => 'cached-value',
            'orchestrator_mode' => 'failsafe',
            'orchestrator_lease_until' => 0,
            'orchestrator_cf_permit' => 0,
        ]]);

        $settings = $monitor->refreshCurrentForwardControlSettings()['settings'];

        self::assertSame('active', $settings['orchestrator_mode']);
        self::assertSame(2000, $settings['orchestrator_lease_until']);
        self::assertSame(41, $settings['orchestrator_cf_permit']);
        self::assertSame('alt.test', $settings['orchestrator_cf_group']);
        self::assertSame(101, $settings['orchestrator_cf_first']);
        self::assertSame(10_100, $settings['orchestrator_cf_last']);
        self::assertSame('cached-value', $settings['unrelated_setting']);
    }

    public function test_it_refreshes_only_the_backfill_permit_control_view(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_lease_until', 'value' => '2000'],
            ['name' => 'orchestrator_back_timer', 'value' => '20'],
            ['name' => 'orchestrator_bf_paused', 'value' => '0'],
            ['name' => 'orchestrator_bf_permit', 'value' => '41'],
            ['name' => 'unrelated_setting', 'value' => 'changed-in-db'],
        ]);

        $monitor = new class extends TmuxMonitorService
        {
            /** @param array<string, mixed> $runVar */
            public function seedRunVar(array $runVar): void
            {
                $this->runVar = $runVar;
            }
        };
        $monitor->seedRunVar(['settings' => [
            'backfill' => 1,
            'unrelated_setting' => 'cached-value',
            'orchestrator_mode' => 'failsafe',
            'orchestrator_lease_until' => 0,
            'orchestrator_back_timer' => 60,
            'orchestrator_bf_paused' => 1,
            'orchestrator_bf_permit' => 0,
        ]]);

        $settings = $monitor->refreshBackfillControlSettings()['settings'];

        self::assertSame('active', $settings['orchestrator_mode']);
        self::assertSame(2000, $settings['orchestrator_lease_until']);
        self::assertSame(20, $settings['orchestrator_back_timer']);
        self::assertSame(0, $settings['orchestrator_bf_paused']);
        self::assertSame(41, $settings['orchestrator_bf_permit']);
        self::assertSame(1, $settings['backfill']);
        self::assertSame('cached-value', $settings['unrelated_setting']);
    }

    public function test_it_refreshes_only_the_nzb_adaptive_control_view(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_lease_until', 'value' => '2000'],
            ['name' => 'orchestrator_nzb_timer', 'value' => '20'],
            ['name' => 'orchestrator_nzb_limit', 'value' => '20'],
            ['name' => 'unrelated_setting', 'value' => 'changed-in-db'],
        ]);

        $monitor = new class extends TmuxMonitorService
        {
            /** @param array<string, mixed> $runVar */
            public function seedRunVar(array $runVar): void
            {
                $this->runVar = $runVar;
            }
        };
        $monitor->seedRunVar(['settings' => [
            'unrelated_setting' => 'cached-value',
            'orchestrator_mode' => 'failsafe',
            'orchestrator_lease_until' => 0,
            'orchestrator_nzb_timer' => 60,
            'orchestrator_nzb_limit' => 5,
        ]]);

        $settings = $monitor->refreshNzbControlSettings()['settings'];

        self::assertSame('active', $settings['orchestrator_mode']);
        self::assertSame(2000, $settings['orchestrator_lease_until']);
        self::assertSame(20, $settings['orchestrator_nzb_timer']);
        self::assertSame(20, $settings['orchestrator_nzb_limit']);
        self::assertSame('cached-value', $settings['unrelated_setting']);
        self::assertTrue($monitor->refreshNzbControlSettings()['nzb_controls_fresh']);
    }

    public function test_missing_nzb_control_row_never_reuses_a_cached_fresh_lease(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_lease_until', 'value' => '2000'],
            ['name' => 'orchestrator_nzb_timer', 'value' => '20'],
        ]);

        $monitor = new class extends TmuxMonitorService
        {
            /** @param array<string, mixed> $runVar */
            public function seedRunVar(array $runVar): void
            {
                $this->runVar = $runVar;
            }
        };
        $monitor->seedRunVar(['settings' => [
            'orchestrator_mode' => 'active',
            'orchestrator_lease_until' => time() + 600,
            'orchestrator_nzb_timer' => 20,
            'orchestrator_nzb_limit' => 20,
        ]]);

        self::assertFalse($monitor->refreshNzbControlSettings()['nzb_controls_fresh']);
    }
}
