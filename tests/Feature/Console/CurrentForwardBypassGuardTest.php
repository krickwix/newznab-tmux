<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

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
}
