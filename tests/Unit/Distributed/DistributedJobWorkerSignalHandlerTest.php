<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Distributed\NativeHashedFixNameRenamePrepassRunner;
use App\Services\Distributed\NativeWorkerCommitRunner;
use App\Services\Distributed\NativeWorkerLaneRunner;
use App\Services\Distributed\NativeWorkerPlanExporter;
use App\Services\Distributed\NativeWorkerShadowRunner;
use App\Services\NameFixing\NativeSearchSideEffectOutboxSync;
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
            new NativeWorkerPlanExporter,
            new NativeWorkerShadowRunner,
            new NativeWorkerCommitRunner,
            new NativeSearchSideEffectOutboxSync,
            $this->createStub(NativeHashedFixNameRenamePrepassRunner::class),
            $this->createStub(NativeWorkerLaneRunner::class),
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
