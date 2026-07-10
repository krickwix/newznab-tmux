<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class DistributedJobWorkerTest extends TestCase
{
    public function test_it_formats_array_valued_artisan_options(): void
    {
        $worker = (new ReflectionClass(DistributedJobWorker::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DistributedJobWorker::class, 'formatArguments');
        $method->setAccessible(true);

        $this->assertSame(
            '--source=all --source=srrdb --limit=25 --update method',
            $method->invoke($worker, [
                '--source' => ['all', 'srrdb'],
                '--limit' => 25,
                '--update' => true,
                'method' => 'method',
            ]),
        );
    }

    public function test_it_records_successful_locked_worker_run(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        Artisan::shouldReceive('call')->once()->with('test:work', [], Mockery::type(BufferedOutput::class))->andReturn(0);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('startRun')->once()->with('releases')->andReturn(100.0);
        $telemetry->shouldReceive('finishRun')->once()->with('releases', 'success', 100.0);

        $worker = new DistributedJobWorker(
            new DistributedJobCatalog,
            Mockery::mock(TmuxMonitorService::class),
            $telemetry,
        );
        $method = new ReflectionMethod($worker, 'runLockedPlan');

        $exitCode = $method->invoke($worker, [
            'name' => 'releases',
            'description' => 'test release processing',
            'enabled' => true,
            'disabled_reason' => null,
            'commands' => [['command' => 'test:work', 'arguments' => []]],
            'sleep' => 1,
        ], 60, new BufferedOutput);

        self::assertSame(0, $exitCode);
    }
}
