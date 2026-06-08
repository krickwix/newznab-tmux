<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ForkingService;
use App\Services\Runners\BackfillRunner;
use PHPUnit\Framework\TestCase;

final class ForkingServiceReleaseEndWorkTest extends TestCase
{
    public function test_release_end_work_does_not_launch_non_numeric_all_group_release_process(): void
    {
        $service = new class extends ForkingService
        {
            /** @var list<string> */
            public array $commands = [];

            public function __construct() {}

            public function runEndWork(): void
            {
                $this->backfillRunner = new BackfillRunner;
                $this->processReleasesEndWork();
            }

            protected function getReleaseWorkCount(): int
            {
                return 4;
            }

            protected function executeCommand(string $command): string
            {
                $this->commands[] = $command;

                return '';
            }
        };

        $service->runEndWork();

        self::assertSame([], $service->commands);
    }
}
