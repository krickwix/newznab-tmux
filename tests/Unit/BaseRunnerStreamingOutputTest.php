<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Runners\BaseRunner;
use Tests\TestCase;

final class BaseRunnerStreamingOutputTest extends TestCase
{
    public function test_streaming_command_output_is_not_replayed_after_process_exit(): void
    {
        config([
            'nntmux.echocli' => false,
            'nntmux.multiprocessing_max_child_time' => 30,
        ]);

        $runner = new ExposedStreamingRunner;

        ob_start();
        $runner->stream([PHP_BINARY.' -r '.escapeshellarg('echo "stream-line\n"; fwrite(STDERR, "error-line\n");')]);
        $output = (string) ob_get_clean();

        $this->assertSame(1, substr_count($output, 'stream-line'));
        $this->assertSame(1, substr_count($output, 'error-line'));
    }
}

final class ExposedStreamingRunner extends BaseRunner
{
    /**
     * @param  list<string>  $commands
     */
    public function stream(array $commands): void
    {
        $this->runStreamingCommands($commands, 1, 'test');
    }
}
