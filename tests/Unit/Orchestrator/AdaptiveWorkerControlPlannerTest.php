<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\AdaptiveWorkerControlPlanner;
use App\Services\Orchestrator\ControlDecision;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\WorkerControlProfile;
use Tests\TestCase;

final class AdaptiveWorkerControlPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nntmux.orchestrator.pressure_projection_horizon_minutes' => 120,
            'nntmux.orchestrator.high_watermarks' => [
                'parts' => 300_000_000,
                'binaries' => 1_000_000,
                'collections' => 20_000,
                'collections_total' => 80_000,
                'releases' => 20_000,
                'nzbs' => 12_000,
            ],
            'nntmux.orchestrator.age_slo_seconds' => [
                'binaries' => 172_800,
                'collections' => 172_800,
                'releases' => 86_400,
                'nzbs' => 86_400,
            ],
        ]);
    }

    public function test_it_slows_header_input_while_draining_old_downstream_work_and_idles_empty_nzb_scans(): void
    {
        $decision = (new AdaptiveWorkerControlPlanner)->plan(
            $this->decision(ControlProfile::Fill, false),
            new PipelineSnapshot(
                partsBacklog: 192_755_815,
                binariesBacklog: 108_271,
                collectionsBacklog: 10_814,
                releasesBacklog: 12,
                nzbsBacklog: 6_797,
                oldestBinaryAgeSeconds: 2_781_923,
                oldestCollectionAgeSeconds: 2_559_463,
                oldestReleaseAgeSeconds: 2_441_735,
                eligibleNzbs: 0,
                collectionsTotalBacklog: 59_250,
                backlogEwmaPerMinute: [
                    'parts' => 0.0,
                    'binaries' => 0.0,
                    'collections_total' => 0.0,
                    'releases' => 0.0,
                    'nzbs' => 0.0,
                ],
            ),
        );

        self::assertSame(60, $decision->profile->binariesSleepSeconds);
        self::assertSame(20, $decision->profile->releasesSleepSeconds);
        self::assertSame(60, $decision->profile->nzbSleepSeconds);
        self::assertSame(5, $decision->profile->nzbBatchSize);
        self::assertSame(60, $decision->profile->backfillSleepSeconds);
        self::assertContains('adaptive_binaries_input_guard', $decision->reasons);
        self::assertContains('adaptive_releases_drain', $decision->reasons);
        self::assertContains('adaptive_nzb_idle', $decision->reasons);
    }

    public function test_it_accelerates_actionable_nzbs_and_keeps_a_safe_backfill_permit_pollable(): void
    {
        $decision = (new AdaptiveWorkerControlPlanner)->plan(
            $this->decision(ControlProfile::Fill, true),
            new PipelineSnapshot(
                partsBacklog: 80_000_000,
                binariesBacklog: 50_000,
                collectionsBacklog: 1_000,
                releasesBacklog: 8,
                nzbsBacklog: 100,
                readyCollections: 8,
                eligibleNzbs: 24,
                collectionsTotalBacklog: 20_000,
            ),
        );

        self::assertSame(20, $decision->profile->binariesSleepSeconds);
        self::assertSame(20, $decision->profile->releasesSleepSeconds);
        self::assertSame(20, $decision->profile->nzbSleepSeconds);
        self::assertSame(20, $decision->profile->nzbBatchSize);
        self::assertSame(20, $decision->profile->backfillSleepSeconds);
        self::assertContains('adaptive_nzb_drain', $decision->reasons);
        self::assertContains('adaptive_backfill_ready', $decision->reasons);
    }

    public function test_it_keeps_backfill_pollable_while_delayed_attribution_can_unlock_a_continuation(): void
    {
        $decision = (new AdaptiveWorkerControlPlanner)->plan(
            $this->decision(ControlProfile::Fill, false),
            new PipelineSnapshot(
                partsBacklog: 80_000_000,
                binariesBacklog: 50_000,
                collectionsBacklog: 1_000,
                releasesBacklog: 0,
                nzbsBacklog: 0,
                collectionsTotalBacklog: 20_000,
            ),
            backfillAttributionPending: true,
        );

        self::assertSame(20, $decision->profile->backfillSleepSeconds);
        self::assertContains('adaptive_backfill_attribution', $decision->reasons);
        self::assertNotContains('adaptive_backfill_idle', $decision->reasons);
    }

    public function test_projected_collection_growth_throttles_input_before_the_hard_limit(): void
    {
        $decision = (new AdaptiveWorkerControlPlanner)->plan(
            $this->decision(ControlProfile::Fill, false),
            new PipelineSnapshot(
                partsBacklog: 190_000_000,
                binariesBacklog: 50_000,
                collectionsBacklog: 8_000,
                releasesBacklog: 0,
                nzbsBacklog: 0,
                collectionsTotalBacklog: 50_000,
                backlogEwmaPerMinute: ['collections_total' => 100.0],
            ),
        );

        self::assertSame(60, $decision->profile->binariesSleepSeconds);
        self::assertContains('adaptive_binaries_input_guard', $decision->reasons);
    }

    public function test_parts_pressure_alone_throttles_header_ingestion_before_exhaustion(): void
    {
        $decision = (new AdaptiveWorkerControlPlanner)->plan(
            $this->decision(ControlProfile::Fill, false),
            new PipelineSnapshot(
                partsBacklog: 270_000_000,
                binariesBacklog: 10_000,
                collectionsBacklog: 1_000,
                releasesBacklog: 0,
                nzbsBacklog: 0,
                collectionsTotalBacklog: 5_000,
            ),
        );

        self::assertSame(160, $decision->profile->binariesSleepSeconds);
        self::assertContains('adaptive_binaries_input_guard', $decision->reasons);
    }

    public function test_header_ingestion_timer_is_monotonic_across_parts_pressure_boundaries(): void
    {
        $planner = new AdaptiveWorkerControlPlanner;
        $timers = [];
        foreach ([87_000_000, 90_000_000, 177_000_000, 180_000_000, 207_000_000, 210_000_000, 255_000_000] as $parts) {
            $decision = $planner->plan(
                $this->decision(ControlProfile::Fill, false),
                new PipelineSnapshot(
                    partsBacklog: $parts,
                    binariesBacklog: 10_000,
                    collectionsBacklog: 1_000,
                    releasesBacklog: 0,
                    nzbsBacklog: 0,
                    collectionsTotalBacklog: 5_000,
                ),
            );
            $timers[] = $decision->profile->binariesSleepSeconds;
        }

        self::assertSame([20, 40, 40, 40, 40, 60, 160], $timers);
        self::assertSame($timers, array_values($timers));
        for ($index = 1; $index < count($timers); $index++) {
            self::assertGreaterThanOrEqual($timers[$index - 1], $timers[$index]);
        }
    }

    public function test_fail_safe_profile_is_never_relaxed_by_adaptive_planning(): void
    {
        $decision = (new AdaptiveWorkerControlPlanner)->plan(
            $this->decision(ControlProfile::FailSafe, false),
            new PipelineSnapshot(1, 1, 1, 1, 1, eligibleNzbs: 100),
            backfillAttributionPending: true,
        );

        self::assertEquals(WorkerControlProfile::for(ControlProfile::FailSafe), $decision->profile);
        self::assertNotContains('adaptive_nzb_drain', $decision->reasons);
        self::assertNotContains('adaptive_backfill_attribution', $decision->reasons);
    }

    private function decision(ControlProfile $profile, bool $backfillPermitted): ControlDecision
    {
        return new ControlDecision(
            profile: WorkerControlProfile::for($profile),
            backfillPermitted: $backfillPermitted,
            reasons: ['test'],
            nextState: new ControlState(profile: $profile),
            transitioned: false,
        );
    }
}
