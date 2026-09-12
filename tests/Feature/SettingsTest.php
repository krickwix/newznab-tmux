<?php

namespace Tests\Feature;

use App\Models\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SettingsTest extends TestCase
{
    public function test_setting_value(): void
    {
        $name = config('app.name');

        $this->assertEquals('NNTmux', $name);
    }

    public function test_forget_memoized_settings_refreshes_database_without_evicting_shared_cache(): void
    {
        config([
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '7']);
        Settings::forgetCachedSettings();

        $sharedCacheKeys = [
            'site_settings',
            'site_settings_array',
            'site_settings_converted',
            'api_v1_server_menu',
            'api_v2_capabilities',
        ];
        foreach ($sharedCacheKeys as $key) {
            Cache::put($key, 'sentinel');
        }

        self::assertSame(7, Settings::settingValue('orchestrator_bf_permit'));
        DB::table('settings')->where('name', 'orchestrator_bf_permit')->update(['value' => '0']);

        Settings::forgetMemoizedSettings();

        self::assertSame(0, Settings::settingValue('orchestrator_bf_permit'));
        foreach ($sharedCacheKeys as $key) {
            self::assertSame('sentinel', Cache::get($key));
        }
    }
}
