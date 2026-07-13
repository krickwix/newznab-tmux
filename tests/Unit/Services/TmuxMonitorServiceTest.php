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
}
