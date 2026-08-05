<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\FailSafeCause;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\WorkerControlPolicy;
use Tests\TestCase;

/**
 * Pinning a control mode from the CLI.
 *
 * The pin replaces the adaptive ladder's SELECTION and nothing else. That
 * distinction is the whole safety story: free-run bypasses the hard gates
 * because that is what it is for, and every other pinned mode must still yield
 * to them. A pinned `fill` that ignored a database in trouble would be free-run
 * wearing a safer-looking name, which is exactly how an operator gets surprised.
 */
final class ControlProfileOverrideTest extends TestCase
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

    private function decide(PipelineSnapshot $snapshot, ?ControlState $state = null): object
    {
        return (new WorkerControlPolicy)->decide($snapshot, $state ?? new ControlState, time());
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('nntmux.orchestrator.free_run', false);
    }

    /**
     * @return array<string, array{0: ControlProfile}>
     */
    public static function pinnableModes(): array
    {
        return [
            'fail_safe' => [ControlProfile::FailSafe],
            'drain' => [ControlProfile::Drain],
            'balanced' => [ControlProfile::Balanced],
            'fill' => [ControlProfile::Fill],
            'free_run' => [ControlProfile::FreeRun],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pinnableModes')]
    public function test_every_mode_can_be_pinned(ControlProfile $mode): void
    {
        $decision = $this->decide($this->snapshot(['profileOverride' => $mode]));

        self::assertSame($mode, $decision->profile->profile);
    }

    /**
     * The load-bearing one.
     *
     * A pin must not become a second free-run. Reaching a pinned profile
     * requires passing the same hard-safety block the ladder does, so an
     * operator who pinned `fill` still gets dropped to fail_safe when the
     * database is in trouble.
     */
    public function test_a_pin_other_than_free_run_still_yields_to_hard_safety(): void
    {
        foreach ([
            'database memory unsafe' => ['databaseMemorySafe' => false],
            'database waits unsafe' => ['databaseWaitsSafe' => false],
            'storage unsafe' => ['storageSafe' => false],
            'stale telemetry' => ['telemetryFresh' => false],
        ] as $label => $unsafe) {
            $decision = $this->decide($this->snapshot([
                'profileOverride' => ControlProfile::Fill,
                ...$unsafe,
            ]));

            self::assertSame(ControlProfile::FailSafe, $decision->profile->profile, $label);
            self::assertFalse($decision->backfillPermitted, $label);
        }
    }

    /**
     * The mirror image: free-run is still the one mode that overrides safety.
     * If a refactor ever made the pin uniform, this fails and the free-run
     * contract is gone.
     */
    public function test_free_run_remains_the_only_mode_that_overrides_safety(): void
    {
        $decision = $this->decide($this->snapshot([
            'profileOverride' => ControlProfile::FreeRun,
            'databaseMemorySafe' => false,
            'telemetryFresh' => false,
        ]));

        self::assertSame(ControlProfile::FreeRun, $decision->profile->profile);
        self::assertTrue($decision->backfillPermitted);
        self::assertSame(['free_run_operator_override'], $decision->reasons);

        foreach (ControlProfile::cases() as $mode) {
            self::assertSame($mode === ControlProfile::FreeRun, $mode->bypassesSafety(), $mode->value);
        }
    }

    /**
     * A pin outranks the fail-safe recovery climb.
     *
     * Recovery walks up one rung per qualifying sample with dwell and cooldown
     * between them -- tens of minutes. An operator pinning `fill` on a healthy
     * fleet that happens to be sitting in fail_safe means "go now", and safety
     * has already been re-checked above this point.
     */
    public function test_a_pin_lifts_the_fleet_out_of_fail_safe_without_the_recovery_climb(): void
    {
        $parked = new ControlState(
            profile: ControlProfile::FailSafe,
            failSafeCause: FailSafeCause::Telemetry,
            cooldownUntil: time() + 3600,
        );

        $decision = $this->decide(
            $this->snapshot(['profileOverride' => ControlProfile::Fill]),
            $parked,
        );

        self::assertSame(ControlProfile::Fill, $decision->profile->profile);
        self::assertContains('profile_pinned_by_operator', $decision->reasons);
    }

    /**
     * Pinning fail_safe is a legitimate operator brake, and it must not be
     * reported as a telemetry fault -- that sends an incident responder looking
     * for a broken Prometheus scrape that does not exist.
     */
    public function test_a_pinned_fail_safe_is_labelled_as_pinned_not_as_a_fault(): void
    {
        $decision = $this->decide($this->snapshot(['profileOverride' => ControlProfile::FailSafe]));

        self::assertSame(ControlProfile::FailSafe, $decision->profile->profile);
        self::assertSame(FailSafeCause::Pinned, $decision->nextState->failSafeCause);
    }

    /**
     * With nothing pinned the ladder is untouched. This is what makes `reset`
     * meaningful rather than "pin whatever it happened to be".
     */
    public function test_no_pin_leaves_the_adaptive_ladder_in_control(): void
    {
        $decision = $this->decide($this->snapshot(), new ControlState(profile: ControlProfile::Balanced));

        self::assertSame(ControlProfile::Balanced, $decision->profile->profile);
        self::assertNotContains('profile_pinned_by_operator', $decision->reasons);
    }

    /**
     * A stored pin outranks the deployed FREE_RUN default. Without this the CLI
     * cannot turn free-run off, which is the main reason it exists -- the env
     * var alone needs a manifest edit, a rebuild and a rollout.
     */
    public function test_a_pin_overrides_the_deployed_free_run_default(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        $decision = $this->decide($this->snapshot(['profileOverride' => ControlProfile::Balanced]));

        self::assertSame(ControlProfile::Balanced, $decision->profile->profile);
    }

    /**
     * And with no pin, the env default still applies unchanged -- the original
     * free-run path must keep working for snapshots built without the field.
     */
    public function test_the_env_default_still_applies_when_nothing_is_pinned(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        self::assertSame(ControlProfile::FreeRun, $this->decide($this->snapshot())->profile->profile);
    }

    /**
     * The trap this design sets for itself.
     *
     * withPermitOutcome() rebuilds the snapshot field by field and runs on
     * every completed backfill permit. Forgetting to carry the pin there would
     * un-pin the fleet silently, minutes after the operator set it, with
     * nothing in the logs to connect the two.
     */
    public function test_a_permit_outcome_does_not_drop_the_pin(): void
    {
        $rebuilt = $this->snapshot(['profileOverride' => ControlProfile::Fill])
            ->withPermitOutcome(completed: true, effective: true, group: 'alt.binaries.test', generation: 7);

        self::assertSame(ControlProfile::Fill, $rebuilt->profileOverride);
        self::assertSame(ControlProfile::Fill, $this->decide($rebuilt)->profile->profile);
    }

    /**
     * Pressure must still never promote INTO free-run. The pin is the only door
     * and it is opened by hand.
     */
    public function test_the_ladder_still_cannot_reach_free_run(): void
    {
        self::assertNotSame(ControlProfile::FreeRun, ControlProfile::Fill->stepUp());
        self::assertNotSame(ControlProfile::FreeRun, ControlProfile::Balanced->stepUp());
    }
}
