<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionCleanupService;
use App\Services\ReleaseProcessingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReleaseProcessingCleanupScopeTest extends TestCase
{
    public function test_group_scoped_release_cleanup_does_not_run_global_cbp_cleanup(): void
    {
        $cleanup = new RecordingCollectionCleanupService;

        $this->makeService($cleanup)->deleteCollections(123);

        $this->assertSame(0, $cleanup->calls);
    }

    public function test_site_wide_release_cleanup_runs_global_cbp_cleanup(): void
    {
        $cleanup = new RecordingCollectionCleanupService;

        $this->makeService($cleanup)->deleteCollections('');

        $this->assertSame(1, $cleanup->calls);
        $this->assertFalse($cleanup->lastEchoCli);
    }

    private function makeService(CollectionCleanupService $cleanup): ReleaseProcessingService
    {
        $reflection = new ReflectionClass(ReleaseProcessingService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $echoCli = $reflection->getProperty('echoCLI');
        $echoCli->setValue($service, false);

        $collectionCleanupService = $reflection->getProperty('collectionCleanupService');
        $collectionCleanupService->setValue($service, $cleanup);

        return $service;
    }
}

final class RecordingCollectionCleanupService extends CollectionCleanupService
{
    public int $calls = 0;

    public ?bool $lastEchoCli = null;

    public function deleteFinishedAndOrphans(bool $echoCLI): int
    {
        $this->calls++;
        $this->lastEchoCli = $echoCLI;

        return 0;
    }
}
