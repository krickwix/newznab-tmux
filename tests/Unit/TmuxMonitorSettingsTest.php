<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Tmux\Tmux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TmuxMonitorSettingsTest extends TestCase
{
    public function test_monitor_settings_include_metadata_refresh_worker_knobs(): void
    {
        Schema::dropIfExists('settings');
        Schema::create('settings', static function ($table): void {
            $table->string('name', 25)->primary();
            $table->string('value', 1000);
        });

        DB::table('settings')->insert([
            ['name' => 'metadata_refresh', 'value' => '1'],
            ['name' => 'metadata_refresh_limit', 'value' => '7'],
            ['name' => 'metadata_refresh_sleep_ms', 'value' => '11'],
            ['name' => 'metadata_refresh_timer', 'value' => '30'],
        ]);

        $settings = (new Tmux)->getMonitorSettings();

        $this->assertArrayHasKey('metadata_refresh', $settings);
        $this->assertArrayHasKey('metadata_refresh_limit', $settings);
        $this->assertArrayHasKey('metadata_refresh_sleep_ms', $settings);
        $this->assertArrayHasKey('metadata_refresh_timer', $settings);
        $this->assertSame(1, $settings['metadata_refresh']);
        $this->assertSame(7, $settings['metadata_refresh_limit']);
        $this->assertSame(11, $settings['metadata_refresh_sleep_ms']);
        $this->assertSame(30, $settings['metadata_refresh_timer']);
    }
}
