<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Distributed\DistributedJobWorker;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class NativeWorkerPlanCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        foreach ([
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'innerfileblacklist' => '',
        ] as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }
    }

    public function test_it_prints_native_shadow_plan_without_running_worker_loop(): void
    {
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once()->andReturn([]);
        $monitor->shouldReceive('collectStatistics')->once()->andReturn([
            'constants' => [
                'run_ircscraper' => 0,
                'sequential' => 0,
            ],
            'settings' => [
                'metadata_refresh' => 1,
                'metadata_refresh_limit' => 25,
                'metadata_refresh_sleep_ms' => 250,
                'metadata_refresh_timer' => 900,
            ],
            'counts' => [
                'now' => [
                    'other_hashed' => 150,
                ],
            ],
            'killswitch' => [
                'pp' => false,
                'coll' => false,
            ],
        ]);
        $this->app->instance(TmuxMonitorService::class, $monitor);

        $worker = Mockery::mock(DistributedJobWorker::class);
        $worker->shouldNotReceive('run');
        $this->app->instance(DistributedJobWorker::class, $worker);

        $exitCode = Artisan::call('nntmux:worker', [
            'job' => 'metadata-refresh',
            '--lock-seconds' => '42',
            '--sleep' => '123',
            '--native-plan' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $plan = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $plan['version']);
        $this->assertSame('shadow', $plan['mode']);
        $this->assertSame('metadata-refresh', $plan['job']['name']);
        $this->assertSame(123, $plan['job']['sleep']);
        $this->assertStringEndsWith($plan['lock']['name'], $plan['lock']['redis_key']);
        $this->assertSame(42, $plan['lock']['seconds']);
        $this->assertCount(3, $plan['commands']);

        $encoded = json_encode($plan, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('settings', $encoded);
        $this->assertStringNotContainsString('DB_PASSWORD', $encoded);
    }

    public function test_native_plan_rejects_unknown_jobs_before_collecting_monitor_state(): void
    {
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldNotReceive('initializeMonitor');
        $monitor->shouldNotReceive('collectStatistics');
        $this->app->instance(TmuxMonitorService::class, $monitor);

        $exitCode = Artisan::call('nntmux:worker', [
            'job' => 'not-a-job',
            '--native-plan' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown distributed job [not-a-job]', Artisan::output());
    }
}
