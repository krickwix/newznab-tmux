<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Metrics;

use App\Services\Metrics\DistributedWorkerTelemetry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class DistributedWorkerTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_it_persists_bounded_worker_run_and_item_telemetry(): void
    {
        $telemetry = new DistributedWorkerTelemetry;

        $startedAt = $telemetry->startRun('releases', 1_000.25);
        $telemetry->recordItem('nzb-backlog', 'nzb', 'created', 2);
        $telemetry->recordItem('nzb-backlog', 'nzb', 'failed');
        $telemetry->recordItem('releases', 'release', 'created', 4);
        $telemetry->recordSelectorDuration(0.125);
        $telemetry->finishRun('releases', 'success', $startedAt, 1_003.75);

        $snapshot = $telemetry->snapshot(['releases', 'nzb-backlog'], 1_010.0);

        self::assertTrue($snapshot['available']);
        self::assertSame(1, $snapshot['workers']['releases']['runs']['success']);
        self::assertSame(0, $snapshot['workers']['releases']['runs']['failure']);
        self::assertArrayNotHasKey('nzb', $snapshot['workers']['releases']['items']);
        self::assertSame(3.5, $snapshot['workers']['releases']['last_duration_seconds']);
        self::assertSame(4, $snapshot['workers']['releases']['items']['release']['created']);
        self::assertSame(1_000.25, $snapshot['workers']['releases']['last_started_timestamp_seconds']);
        self::assertSame(1_003.75, $snapshot['workers']['releases']['last_completed_timestamp_seconds']);
        self::assertSame(1_003.75, $snapshot['workers']['releases']['last_success_timestamp_seconds']);
        self::assertFalse($snapshot['workers']['releases']['in_progress']);
        self::assertSame(0.0, $snapshot['workers']['releases']['in_progress_age_seconds']);

        self::assertSame(0, $snapshot['workers']['nzb-backlog']['runs']['success']);
        self::assertSame(2, $snapshot['workers']['nzb-backlog']['items']['nzb']['created']);
        self::assertSame(1, $snapshot['workers']['nzb-backlog']['items']['nzb']['failed']);
        self::assertSame(0.125, $snapshot['nzb_selector_last_duration_seconds']);
    }

    public function test_it_exposes_in_progress_age_without_creating_dynamic_labels(): void
    {
        $telemetry = new DistributedWorkerTelemetry;

        $telemetry->startRun('nzb-backlog', 2_000.0);
        $telemetry->recordItem('nzb-backlog', 'nzb', 'unexpected-outcome');
        self::assertFalse($telemetry->recordItem('releases', 'nzb', 'created'));

        $snapshot = $telemetry->snapshot(['nzb-backlog'], 2_045.5);

        self::assertTrue($snapshot['workers']['nzb-backlog']['in_progress']);
        self::assertSame(45.5, $snapshot['workers']['nzb-backlog']['in_progress_age_seconds']);
        self::assertArrayNotHasKey('unexpected-outcome', $snapshot['workers']['nzb-backlog']['items']['nzb']);
    }

    public function test_non_executing_outcomes_do_not_clear_an_active_worker_cycle(): void
    {
        $telemetry = new DistributedWorkerTelemetry;
        $telemetry->startRun('releases', 3_000.0);

        $telemetry->recordRunOutcome('releases', 'lock_contended');
        $snapshot = $telemetry->snapshot(['releases'], 3_010.0);

        self::assertSame(1, $snapshot['workers']['releases']['runs']['lock_contended']);
        self::assertTrue($snapshot['workers']['releases']['in_progress']);
        self::assertSame(10.0, $snapshot['workers']['releases']['in_progress_age_seconds']);
    }

    public function test_in_progress_marker_expires_with_the_distributed_lock_lifetime(): void
    {
        CarbonImmutable::setTestNow('2026-07-21 00:00:00');
        try {
            $telemetry = new DistributedWorkerTelemetry;
            $telemetry->startRun('releases', 2_000.0, 30);

            self::assertTrue($telemetry->snapshot(['releases'], 2_010.0)['workers']['releases']['in_progress']);

            CarbonImmutable::setTestNow('2026-07-21 00:00:31');
            self::assertFalse($telemetry->snapshot(['releases'], 2_031.0)['workers']['releases']['in_progress']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
