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

    /**
     * Free-run must not stall between permits.
     *
     * WorkerOrchestrator gates a re-grant on there being no open permit
     * observation and no pending delayed attribution. Those windows are
     * measurement -- 1200s and 600s by default -- and under the ladder they are
     * why backfill ran at ~10 permits/hour with 94% of cycles spent waiting.
     * In free-run the worker would finish a permit and then sit on "adaptive
     * orchestrator has not granted a fresh permit" until the observation aged
     * out, which is the same "permitted but nothing happens" state free-run
     * exists to remove.
     *
     * Asserted on the shape rather than by driving WorkerOrchestrator, which
     * needs the whole snapshot/telemetry stack: the profile is what the
     * orchestrator branches on.
     */
    public function test_free_run_is_the_profile_the_orchestrator_can_branch_on_for_regrants(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        $decision = (new WorkerControlPolicy)->decide($this->snapshot(), new ControlState, time());

        self::assertSame(ControlProfile::FreeRun, $decision->profile->profile);
        self::assertTrue($decision->backfillPermitted);

        $source = (string) file_get_contents(
            \dirname(__DIR__, 3).'/app/Services/Orchestrator/WorkerOrchestrator.php'
        );
        // The re-grant must skip BOTH measurement waits, and must still refuse
        // to overwrite a permit that has been granted and not yet claimed.
        self::assertStringContainsString('$freeRun || $permitObservation === null', $source);
        self::assertStringContainsString('$freeRun || $delayedAttributionSettled === null', $source);
        self::assertStringContainsString("Settings::settingValue('orchestrator_bf_permit') === 0", $source);
        // And it must never issue against an empty target. Such a permit is
        // unclaimable (claimGeneration() refuses `$group === ''`) AND
        // un-reissuable (the check above needs the permit back at 0), so it
        // wedges backfill permanently. Granting every cycle turns a rare race
        // into a certainty, which is exactly what happened on the first
        // free-run re-grant build.
        self::assertStringContainsString(
            "trim((string) \$snapshot->backfillGroup) !== ''",
            $source,
        );
        // And it must open no permit observation. An observation completes,
        // the completion path defers attribution, that records a pending group,
        // and selectBackfillTarget() then returns NULL for as long as any group
        // is pending without a continuation -- so free-run granted a permit,
        // ran it, and had no target at all for the next ~600s. Cutting the
        // chain at its source is what keeps backfill moving rather than merely
        // permitted.
        self::assertStringContainsString('if ($backfillPermitGranted && ! $freeRun) {', $source);
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
