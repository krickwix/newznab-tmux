<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\DistributedJobWorker;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DistributedJobWorkerResponsiveSleepTest extends TestCase
{
    public function test_fresh_active_release_controls_can_shorten_an_existing_sleep(): void
    {
        self::assertSame(10, $this->responsiveSleepSeconds(180, [
            'release_controls_fresh' => true,
            'settings' => [
                'orchestrator_mode' => 'active',
                'orchestrator_lease_until' => time() + 60,
                'orchestrator_rel_timer' => 10,
            ],
        ]));
    }

    public function test_stale_release_controls_preserve_the_original_conservative_sleep(): void
    {
        self::assertSame(180, $this->responsiveSleepSeconds(180, [
            'release_controls_fresh' => true,
            'settings' => [
                'orchestrator_mode' => 'active',
                'orchestrator_lease_until' => time() - 1,
                'orchestrator_rel_timer' => 10,
            ],
        ]));
    }

    public function test_refreshed_controls_never_lengthen_the_sleep_already_selected_for_this_pass(): void
    {
        self::assertSame(20, $this->responsiveSleepSeconds(20, [
            'release_controls_fresh' => true,
            'settings' => [
                'orchestrator_mode' => 'active',
                'orchestrator_lease_until' => time() + 60,
                'orchestrator_rel_timer' => 180,
            ],
        ]));
    }

    /** @param array<string, mixed> $runVar */
    private function responsiveSleepSeconds(int $original, array $runVar): int
    {
        $worker = (new ReflectionClass(DistributedJobWorker::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DistributedJobWorker::class, 'responsiveSleepSeconds');

        return $method->invoke($worker, 'releases', $original, $runVar);
    }
}
