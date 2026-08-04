<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\AdaptiveWorkerControlPlanner;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\WorkerControlPolicy;
use App\Services\Orchestrator\WorkerControlProfile;
use Tests\TestCase;

/**
 * Free-run: every governor off, by operator override only.
 *
 * The gates this bypasses are not theoretical. This fleet has twice been taken
 * down by them doing their job -- a 177k-collection ingest burst that pushed
 * past the collections watermark and self-locked the orchestrator into
 * fail_safe with no permits, and a MariaDB working set that held it there for
 * hours. Free-run removes exactly those brakes, so the tests that matter are
 * the ones proving it cannot be entered by accident and that it really does
 * release everything when it is.
 */
final class FreeRunProfileTest extends TestCase
{
    private function snapshot(array $overrides = []): PipelineSnapshot
    {
        return new PipelineSnapshot(...array_merge([
            'partsBacklog' => 0,
            'binariesBacklog' => 0,
            'collectionsBacklog' => 0,
            'releasesBacklog' => 0,
            'nzbsBacklog' => 0,
            'telemetryFresh' => true,
            'telemetryComplete' => true,
            'telemetryConsistent' => true,
            'databaseMemorySafe' => true,
            'databaseCpuSafe' => true,
            'databaseWaitsSafe' => true,
            'storageSafe' => true,
            'highPressure' => false,
            'lowPressure' => true,
            'providerAvailable' => true,
            'cursorAvailable' => true,
        ], $overrides));
    }

    public function test_free_run_is_off_unless_configured(): void
    {
        config()->set('nntmux.orchestrator.free_run', false);

        $decision = (new WorkerControlPolicy)->decide($this->snapshot(), new ControlState, time());

        self::assertNotSame(ControlProfile::FreeRun, $decision->profile->profile);
    }

    public function test_free_run_zeroes_every_worker_timer(): void
    {
        $profile = WorkerControlProfile::for(ControlProfile::FreeRun);

        self::assertSame(0, $profile->binariesSleepSeconds);
        self::assertSame(0, $profile->backfillSleepSeconds);
        self::assertSame(0, $profile->releasesSleepSeconds);
        self::assertSame(0, $profile->nzbSleepSeconds);
        self::assertTrue($profile->backfillEnabled);
    }

    /**
     * The load-bearing one. Free-run is checked before telemetry validity and
     * hard safety, so a stale sample or a busy database must not drop it back
     * to fail_safe -- otherwise free-run silently is not free.
     */
    public function test_free_run_overrides_the_conditions_that_force_fail_safe(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        foreach ([
            'stale telemetry' => ['telemetryFresh' => false],
            'incomplete telemetry' => ['telemetryComplete' => false],
            'database memory unsafe' => ['databaseMemorySafe' => false],
            'database waits unsafe' => ['databaseWaitsSafe' => false],
            'storage unsafe' => ['storageSafe' => false],
            'high pressure' => ['highPressure' => true, 'lowPressure' => false],
        ] as $label => $override) {
            $decision = (new WorkerControlPolicy)->decide(
                $this->snapshot($override),
                new ControlState,
                time(),
            );

            self::assertSame(ControlProfile::FreeRun, $decision->profile->profile, $label);
            self::assertTrue($decision->backfillPermitted, $label);
            self::assertSame(['free_run_operator_override'], $decision->reasons, $label);
        }
    }

    /**
     * The bug the first deployment shipped.
     *
     * WorkerControlPolicy handed back a profile with every timer at zero, and
     * AdaptiveWorkerControlPlanner -- which only skipped FailSafe -- floored
     * them straight back up: releases and nzb to 60s, backfill to 10s. The
     * orchestrator reported profile=free_run while the workers kept sleeping,
     * which is the worst of both (no brakes, no speed). Caught in production by
     * reading worker_controls rather than trusting the profile name.
     */
    public function test_the_adaptive_planner_does_not_re_floor_free_run_timers(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        $decision = (new WorkerControlPolicy)->decide($this->snapshot(), new ControlState, time());
        $planned = (new AdaptiveWorkerControlPlanner)->plan($decision, $this->snapshot());

        self::assertSame(ControlProfile::FreeRun, $planned->profile->profile);
        self::assertSame(0, $planned->profile->binariesSleepSeconds);
        self::assertSame(0, $planned->profile->backfillSleepSeconds);
        self::assertSame(0, $planned->profile->releasesSleepSeconds);
        self::assertSame(0, $planned->profile->nzbSleepSeconds);
    }

    public function test_free_run_releases_the_backfill_lock(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        $locked = new ControlState(
            profile: ControlProfile::Fill,
            backfillLocked: true,
            consecutiveIneffectiveBackfillPermits: 9,
            ineffectiveBackfillPermitsByTarget: ['alt.binaries.movies' => 9],
        );

        $decision = (new WorkerControlPolicy)->decide($this->snapshot(), $locked, time());

        self::assertFalse($decision->nextState->backfillLocked);
        self::assertSame(0, $decision->nextState->consecutiveIneffectiveBackfillPermits);
        self::assertSame([], $decision->nextState->ineffectiveBackfillPermitsByTarget);
    }

    /**
     * Pressure must never promote INTO free-run. If it could, an ordinary busy
     * period would silently disable every brake in the system.
     */
    public function test_the_adaptive_ladder_cannot_reach_free_run(): void
    {
        self::assertSame(ControlProfile::Fill, ControlProfile::Fill->stepUp());
        self::assertSame(ControlProfile::Fill, ControlProfile::Balanced->stepUp());
        self::assertNotSame(ControlProfile::FreeRun, ControlProfile::Fill->stepUp());
    }

    public function test_leaving_free_run_resumes_the_ladder_at_balanced(): void
    {
        self::assertSame(ControlProfile::Balanced, ControlProfile::FreeRun->stepDown());
    }

    public function test_other_profiles_keep_their_timers(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        // The override changes which profile is SELECTED, never what the other
        // profiles mean.
        $failSafe = WorkerControlProfile::for(ControlProfile::FailSafe);
        self::assertSame(300, $failSafe->binariesSleepSeconds);
        self::assertFalse($failSafe->backfillEnabled);
    }
}
