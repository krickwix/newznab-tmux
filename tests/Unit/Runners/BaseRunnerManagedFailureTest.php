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

    public function test_a_child_error_written_to_stdout_reaches_the_failure_message(): void
    {
        $runner = new class extends BaseRunner
        {
            public function managed(array $commands): array
            {
                return $this->runParallelCommands($commands, 1, failOnError: true);
            }
        };
        $script = 'echo "Getting 10,000 articles\n"; echo "\033[31mUnable to read XOVER response\033[0m\n"; exit(1);';

        try {
            $runner->managed(['alt.binaries.moovee#6' => PHP_BINARY.' -r '.escapeshellarg($script)]);
            $this->fail('The child failure did not propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Managed child process failure: alt.binaries.moovee#6 (exit 1): Getting 10,000 articles | Unable to read XOVER response',
                $e->getMessage(),
            );
        }
    }
}
