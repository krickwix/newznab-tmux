<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Tmux\TmuxMonitorService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DistributedJobWorkerSignalHandlerTest extends TestCase
{
    public function test_signal_termination_message_uses_signal_job_and_lock_in_order(): void
    {
        $worker = new DistributedJobWorker(
            new DistributedJobCatalog,
            $this->createStub(TmuxMonitorService::class),
            $this->createStub(DistributedWorkerTelemetry::class),
        );

        $method = new ReflectionMethod($worker, 'formatTerminationSignalMessage');

        $message = $method->invoke(
            $worker,
            15,
            'hashed-fixnames',
            'nntmux:distributed-worker:hashed-fixnames'
        );

        $this->assertStringContainsString('received signal 15 while running hashed-fixnames', $message);
        $this->assertStringContainsString('releasing nntmux:distributed-worker:hashed-fixnames before exit', $message);
    }
}
