<?php

declare(strict_types=1);

namespace Tests\Unit\Runners;

use App\Services\Runners\BaseRunner;
use RuntimeException;
use Tests\TestCase;

final class BaseRunnerManagedFailureTest extends TestCase
{
    public function test_managed_parallel_child_failure_propagates_to_the_parent(): void
    {
        $runner = new class extends BaseRunner
        {
            public function managed(array $commands): array
            {
                return $this->runParallelCommands($commands, 1, failOnError: true);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Managed child process failure');

        $runner->managed(['range-1' => PHP_BINARY.' -r "exit(7);"']);
    }
}
