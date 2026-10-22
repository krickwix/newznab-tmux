<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Models\Settings;
use App\Services\Orchestrator\AdaptiveWorkerControlPlanner;
use App\Services\Orchestrator\ControlDecision;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlProfileOverride;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\WorkerControlPolicy;
use App\Services\Orchestrator\WorkerControlProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * free_adaptive as a profile: free_run in every respect the policy, the planner
 * and the ladder can see. The one difference (the permit delay) lives in the
 * orchestrator grant and is covered by FreeAdaptiveOrchestratorTest.
 */
final class FreeAdaptiveProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('nntmux.orchestrator.free_run', false);
    }

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
            'profileOverride' => ControlProfile::FreeAdaptive,
        ], $overrides));
    }

    public function test_the_enum_value_and_ladder_position_match_free_run(): void
    {
        self::assertSame(ControlProfile::FreeAdaptive, ControlProfile::from('free_adaptive'));
        self::assertSame(4, ControlProfile::FreeAdaptive->rung());
        self::assertSame(ControlProfile::FreeRun->rung(), ControlProfile::FreeAdaptive->rung());
        self::assertTrue(ControlProfile::FreeAdaptive->bypassesSafety());
        self::assertNotSame('', ControlProfile::FreeAdaptive->description());
        // Leaving it resumes at Balanced, like free_run; it never steps itself up.
        self::assertSame(ControlProfile::Balanced, ControlProfile::FreeAdaptive->stepDown());
        self::assertSame(ControlProfile::FreeAdaptive, ControlProfile::FreeAdaptive->stepUp());
    }

    public function test_the_adaptive_ladder_cannot_reach_free_adaptive(): void
    {
        foreach (ControlProfile::cases() as $mode) {
            if ($mode->isFreeRunFamily()) {
                continue;
            }
            self::assertFalse($mode->stepUp()->isFreeRunFamily(), $mode->value.' stepUp');
            self::assertFalse($mode->stepDown()->isFreeRunFamily(), $mode->value.' stepDown');
        }
    }

    public function test_the_free_run_family_is_exactly_free_run_and_free_adaptive(): void
    {
        foreach (ControlProfile::cases() as $mode) {
            $expected = \in_array($mode, [ControlProfile::FreeRun, ControlProfile::FreeAdaptive], true);
            self::assertSame($expected, $mode->isFreeRunFamily(), $mode->value);
            self::assertSame($expected, ControlProfile::isFreeRunFamilyValue($mode->value), $mode->value);
        }
        self::assertFalse(ControlProfile::isFreeRunFamilyValue(''));
        self::assertFalse(ControlProfile::isFreeRunFamilyValue('FREE_RUN'));
        self::assertFalse(ControlProfile::isFreeRunFamilyValue('free_adaptive '));
    }

    public function test_worker_timers_are_identical_to_free_run(): void
    {
        $freeRun = WorkerControlProfile::for(ControlProfile::FreeRun);
        $adaptive = WorkerControlProfile::for(ControlProfile::FreeAdaptive);

        self::assertSame(ControlProfile::FreeAdaptive, $adaptive->profile);
        self::assertSame(0, $adaptive->binariesSleepSeconds);
        self::assertSame(0, $adaptive->backfillSleepSeconds);
        self::assertSame(0, $adaptive->releasesSleepSeconds);
        self::assertSame(0, $adaptive->nzbSleepSeconds);
        self::assertTrue($adaptive->backfillEnabled);
        self::assertSame($freeRun->nzbBatchSize, $adaptive->nzbBatchSize);
        self::assertSame($freeRun->backfillGroups, $adaptive->backfillGroups);
        self::assertSame($freeRun->backfillThreads, $adaptive->backfillThreads);
        self::assertSame($freeRun->backfillQuantity, $adaptive->backfillQuantity);
    }

    /**
     * Pinned FA is checked before telemetry validity and hard safety, exactly
     * like free_run: the governor is its only brake, by contract.
     */
    public function test_a_pinned_free_adaptive_short_circuits_the_conditions_that_force_fail_safe(): void
    {
        foreach ([
            'healthy' => [],
            'stale telemetry' => ['telemetryFresh' => false],
            'incomplete telemetry' => ['telemetryComplete' => false],
            'database memory unsafe' => ['databaseMemorySafe' => false],
            'database waits unsafe' => ['databaseWaitsSafe' => false],
            'storage unsafe' => ['storageSafe' => false],
            'high pressure' => ['highPressure' => true, 'lowPressure' => false],
        ] as $label => $override) {
            $decision = (new WorkerControlPolicy)->decide($this->snapshot($override), new ControlState, time());

            self::assertSame(ControlProfile::FreeAdaptive, $decision->profile->profile, $label);
            self::assertSame(ControlProfile::FreeAdaptive, $decision->nextState->profile, $label);
            self::assertTrue($decision->backfillPermitted, $label);
            self::assertSame(['free_adaptive_operator_override'], $decision->reasons, $label);
            self::assertFalse($decision->nextState->backfillLocked, $label);
        }
    }

    public function test_the_deployed_free_run_default_does_not_turn_into_free_adaptive(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        $decision = (new WorkerControlPolicy)->decide($this->snapshot(['profileOverride' => null]), new ControlState, time());

        self::assertSame(ControlProfile::FreeRun, $decision->profile->profile);
    }

    /**
     * transitioned is what tells the governor to reset its delay, so it must be
     * true on every entry and exit across the family, and false while staying.
     */
    public function test_transitioned_flags_every_switch_inside_the_free_run_family(): void
    {
        $now = 1_000_000;
        $policy = new WorkerControlPolicy;

        $enter = $policy->decide($this->snapshot(), new ControlState(profile: ControlProfile::FreeRun, lastTransitionAt: $now - 500), $now);
        self::assertTrue($enter->transitioned);
        self::assertSame($now, $enter->nextState->lastTransitionAt);

        $stay = $policy->decide($this->snapshot(), $enter->nextState, $now + 60);
        self::assertFalse($stay->transitioned);
        self::assertSame($now, $stay->nextState->lastTransitionAt);

        $back = $policy->decide($this->snapshot(['profileOverride' => ControlProfile::FreeRun]), $stay->nextState, $now + 120);
        self::assertTrue($back->transitioned);
        self::assertSame(ControlProfile::FreeRun, $back->profile->profile);
        self::assertSame(['free_run_operator_override'], $back->reasons);

        $fromLadder = $policy->decide($this->snapshot(), new ControlState(profile: ControlProfile::Balanced), $now);
        self::assertTrue($fromLadder->transitioned);
    }

    public function test_the_adaptive_planner_returns_a_free_adaptive_decision_untouched(): void
    {
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
        ]);
        $decision = new ControlDecision(
            profile: WorkerControlProfile::for(ControlProfile::FreeAdaptive),
            backfillPermitted: true,
            reasons: ['free_adaptive_operator_override'],
            nextState: new ControlState(profile: ControlProfile::FreeAdaptive),
            transitioned: false,
        );
        // A snapshot that would make the planner floor every timer on a ladder profile.
        $snapshot = new PipelineSnapshot(
            partsBacklog: 290_000_000,
            binariesBacklog: 990_000,
            collectionsBacklog: 19_000,
            releasesBacklog: 19_000,
            nzbsBacklog: 0,
            eligibleNzbs: 0,
            collectionsTotalBacklog: 79_000,
            backlogEwmaPerMinute: ['parts' => 1e6, 'binaries' => 1e4, 'collections_total' => 1e3, 'releases' => 0.0, 'nzbs' => 0.0],
        );

        $planned = (new AdaptiveWorkerControlPlanner)->plan($decision, $snapshot, backfillAttributionPending: true);

        self::assertSame($decision, $planned);
        self::assertSame(0, $planned->profile->binariesSleepSeconds);
        self::assertSame(0, $planned->profile->backfillSleepSeconds);
        self::assertSame(0, $planned->profile->releasesSleepSeconds);
        self::assertSame(0, $planned->profile->nzbSleepSeconds);
    }

    public function test_a_stored_free_adaptive_pin_round_trips(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        Settings::forgetCachedSettings();

        $override = new ControlProfileOverride;
        $override->set(ControlProfile::FreeAdaptive);
        Settings::forgetCachedSettings();
        self::assertSame(ControlProfile::FreeAdaptive, $override->stored());
        self::assertSame(ControlProfile::FreeAdaptive, $override->effective());

        Settings::query()->where('name', ControlProfileOverride::SETTING)->update(['value' => 'free_adaptiv']);
        Settings::forgetCachedSettings();
        self::assertNull($override->stored());
    }
}
