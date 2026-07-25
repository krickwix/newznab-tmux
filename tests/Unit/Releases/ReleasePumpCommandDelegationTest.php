<?php

declare(strict_types=1);

namespace Tests\Unit\Releases;

use App\Console\Commands\ProcessReleasesCommand;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\ReleasePump;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class ReleasePumpCommandDelegationTest extends TestCase
{
    public function test_release_command_records_nonzero_created_result_in_worker_telemetry(): void
    {
        config([
            'nntmux.distributed_release_pump_deadline_seconds' => 25,
            'nntmux.distributed_release_pump_batch_size' => 200,
        ]);

        $pump = Mockery::mock(ReleasePump::class);
        $pump->shouldReceive('run')
            ->once()
            ->with('91', 25, 200)
            ->andReturn(['created' => 7, 'budget_exhausted' => false]);
        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('recordItem')
            ->once()
            ->with('releases', 'release', 'created', 7)
            ->andReturnTrue();
        $processing = (new ReflectionClass(ReleaseProcessingService::class))->newInstanceWithoutConstructor();
        $command = new ProcessReleasesCommand($processing, $pump, $telemetry);
        $method = new ReflectionMethod($command, 'processReleasesForGroup');

        $method->invoke($command, '91');
    }

    #[DataProvider('releaseCommandSources')]
    public function test_every_release_entry_point_delegates_to_the_shared_bounded_pump(string $relativePath): void
    {
        $source = file_get_contents(__DIR__.'/../../../'.$relativePath);

        self::assertIsString($source);
        self::assertStringContainsString('use App\\Services\\Releases\\ReleasePump;', $source);
        self::assertTrue(
            str_contains($source, '$this->releasePump->run(')
                || str_contains($source, 'app(ReleasePump::class)->run('),
            'Release entry point must invoke the shared bounded pump.',
        );
        self::assertStringNotContainsString('processIncompleteCollections(', $source);
    }

    /** @return array<string, array{string}> */
    public static function releaseCommandSources(): array
    {
        return [
            'release command' => ['app/Console/Commands/ProcessReleasesCommand.php'],
            'per-group command' => ['app/Console/Commands/UpdatePerGroup.php'],
        ];
    }
}
