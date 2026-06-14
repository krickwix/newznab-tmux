<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\DistributedJobWorker;
use ReflectionClass;
use Tests\TestCase;

class DistributedJobWorkerTest extends TestCase
{
    public function test_it_formats_array_valued_artisan_options(): void
    {
        $worker = (new ReflectionClass(DistributedJobWorker::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DistributedJobWorker::class, 'formatArguments');
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
}
