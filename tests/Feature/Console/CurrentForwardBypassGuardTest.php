<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Binaries\BinariesService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurrentForwardBypassGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ]);
        config()->set('nntmux.orchestrator.current_forward_windows', 'alt.protected:101-10100@20000');
    }

    public function test_generic_range_update_and_part_repair_are_rejected_before_nntp_work(): void
    {
        $this->artisan('articles:get-range', [
            'mode' => 'binaries',
            'group' => 'alt.protected',
            'first' => 101,
            'last' => 10_100,
        ])->expectsOutputToContain('requires an exact current-forward permit')->assertFailed();

        $this->artisan('update:binaries', ['group' => 'alt.protected', 'max' => 10_000])
            ->expectsOutputToContain('requires an exact current-forward permit')
            ->assertFailed();

        $this->artisan('binaries:part-repair', ['group' => 'alt.protected'])
            ->expectsOutputToContain('protected from generic part repair')
            ->assertFailed();
    }

    public function test_refresh_only_source_is_rejected_by_every_generic_entry_point(): void
    {
        config([
            'nntmux.orchestrator.current_forward_windows' => '',
            'nntmux.orchestrator.current_forward_refresh_sources' => 'alt.refresh:101-10100',
        ]);

        $this->artisan('articles:get-range', [
            'mode' => 'binaries',
            'group' => 'alt.refresh',
            'first' => 10_101,
            'last' => 20_100,
        ])->expectsOutputToContain('requires an exact current-forward permit')->assertFailed();

        $this->artisan('update:binaries', ['group' => 'alt.refresh', 'max' => 10_000])
            ->expectsOutputToContain('requires an exact current-forward permit')
            ->assertFailed();

        $this->artisan('binaries:part-repair', ['group' => 'alt.refresh'])
            ->expectsOutputToContain('protected from generic part repair')
            ->assertFailed();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires an exact current-forward permit');
        (new BinariesService)->scan(
            ['id' => 1, 'name' => 'alt.refresh'],
            10_101,
            20_100,
        );
    }
}
