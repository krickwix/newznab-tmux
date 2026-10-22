<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\FreeAdaptiveBackfillGovernor;
use App\Services\Orchestrator\FreeAdaptiveConfig;
use App\Services\Orchestrator\FreeAdaptiveState;
use App\Services\Orchestrator\PipelineSnapshot;
use Tests\TestCase;

/**
 * The control law of the free_adaptive backfill delay. Pure: no DB, no clock.
 */
final class FreeAdaptiveBackfillGovernorTest extends TestCase
{
    private const int NOW = 1_000_000;

    private function config(array $overrides = []): FreeAdaptiveConfig
    {
        return new FreeAdaptiveConfig(...array_replace([
            'highWatermarks' => ['parts' => 1_000_000, 'binaries' => 1_000_000, 'collections' => 600_000],
        ], $overrides));
    }

    private function snapshot(int $collections, float $ewma, array $overrides = []): PipelineSnapshot
    {
        return new PipelineSnapshot(...array_replace([
            'partsBacklog' => 0,
            'binariesBacklog' => 0,
            'collectionsBacklog' => $collections,
            'releasesBacklog' => 0,
            'nzbsBacklog' => 0,
            'observedAt' => self::NOW,
            'backlogEwmaPerMinute' => ['parts' => 0.0, 'binaries' => 0.0, 'collections' => $ewma],
        ], $overrides));
    }

    /** A stored state whose last change is old enough to be outside the settle window. */
    private function stored(int $delay, int $changedAgo = 1000): FreeAdaptiveState
    {
        return new FreeAdaptiveState($delay, self::NOW - $changedAgo, self::NOW - 60, self::NOW - 10);
    }

    private function evaluate(FreeAdaptiveState $prev, PipelineSnapshot $snapshot, ?FreeAdaptiveConfig $config = null, bool $entering = false): FreeAdaptiveState
    {
        return (new FreeAdaptiveBackfillGovernor)->evaluate($prev, $snapshot, self::NOW, $entering, $config ?? $this->config());
    }

    public function test_over_watermark_and_growing_increases_by_the_factor(): void
    {
        $next = $this->evaluate($this->stored(120), $this->snapshot(649_000, 3_400.0));

        self::assertSame(180, $next->delay);
        self::assertSame('increase', $next->reason);
        self::assertSame('collections', $next->bindingStage);
        self::assertSame(self::NOW, $next->updatedAt);
    }

    public function test_increase_uses_the_additive_step_when_the_delay_is_zero(): void
    {
        self::assertSame(30, $this->evaluate($this->stored(0), $this->snapshot(700_000, 0.0))->delay);
    }

    public function test_increase_clamps_at_the_maximum_without_touching_the_settle_anchor(): void
    {
        $prev = $this->stored(3_000);
        $next = $this->evaluate($this->stored(3_000), $this->snapshot(700_000, 0.0));
        self::assertSame(3_600, $next->delay);

        $atMax = $this->evaluate($this->stored(3_600), $this->snapshot(700_000, 0.0));
        self::assertSame(3_600, $atMax->delay);
        self::assertSame($this->stored(3_600)->updatedAt, $atMax->updatedAt);
        self::assertNotSame($prev->updatedAt, $next->updatedAt);
    }

    public function test_low_projection_decreases_gently_and_snaps_to_the_minimum(): void
    {
        self::assertSame(96, $this->evaluate($this->stored(120), $this->snapshot(100_000, 0.0))->delay);
        self::assertSame(0, $this->evaluate($this->stored(12), $this->snapshot(100_000, 0.0))->delay);
        self::assertSame(0, $this->evaluate($this->stored(0), $this->snapshot(100_000, 0.0))->delay);
    }

    public function test_decrease_clamps_at_a_nonzero_minimum(): void
    {
        $config = $this->config(['minDelay' => 15, 'initialDelay' => 120]);

        self::assertSame(15, $this->evaluate($this->stored(16), $this->snapshot(0, 0.0), $config)->delay);
    }

    public function test_the_deadband_between_the_thresholds_holds(): void
    {
        // 570k / 600k = 0.95
        $next = $this->evaluate($this->stored(120), $this->snapshot(570_000, 0.0));

        self::assertSame(120, $next->delay);
        self::assertSame('hold', $next->reason);
    }

    public function test_over_the_watermark_but_shrinking_fast_decreases(): void
    {
        // (649k - 4k * 30) / 600k = 0.88
        $next = $this->evaluate($this->stored(120), $this->snapshot(649_000, -4_000.0));

        self::assertSame(96, $next->delay);
        self::assertSame('decrease', $next->reason);
    }

    public function test_a_change_inside_the_settle_window_holds(): void
    {
        $prev = $this->stored(120, 60);

        $next = $this->evaluate($prev, $this->snapshot(700_000, 0.0));
        self::assertSame(120, $next->delay);
        self::assertSame('settling', $next->reason);
        self::assertSame($prev->updatedAt, $next->updatedAt);

        self::assertSame(180, $this->evaluate($this->stored(120, 300), $this->snapshot(700_000, 0.0))->delay);
    }

    public function test_stale_telemetry_increases_and_never_decreases(): void
    {
        $quiet = $this->snapshot(0, 0.0, ['telemetryFresh' => false]);
        $next = $this->evaluate($this->stored(120), $quiet);
        self::assertSame(180, $next->delay);
        self::assertSame('metrics_stale', $next->reason);

        $old = $this->snapshot(0, 0.0, ['observedAt' => self::NOW - 600]);
        self::assertSame(180, $this->evaluate($this->stored(120), $old)->delay);
    }

    public function test_missing_ewma_for_every_stage_increases(): void
    {
        $next = $this->evaluate($this->stored(120), $this->snapshot(0, 0.0, ['backlogEwmaPerMinute' => []]));

        self::assertSame(180, $next->delay);
        self::assertSame('metrics_missing', $next->reason);
    }

    public function test_one_missing_stage_is_skipped_not_read_as_zero(): void
    {
        $snapshot = $this->snapshot(700_000, 0.0, ['backlogEwmaPerMinute' => ['collections' => 0.0]]);
        $next = $this->evaluate($this->stored(120), $snapshot);

        self::assertSame(180, $next->delay);
        self::assertStringContainsString('stage_missing:parts,binaries', $next->reason);
    }

    public function test_the_busiest_stage_binds(): void
    {
        $snapshot = $this->snapshot(0, 0.0, ['binariesBacklog' => 1_200_000]);

        self::assertSame('binaries', $this->evaluate($this->stored(120), $snapshot)->bindingStage);
    }

    public function test_entering_the_profile_or_having_nothing_stored_starts_at_the_initial_delay(): void
    {
        $entered = $this->evaluate($this->stored(900), $this->snapshot(700_000, 0.0), null, true);
        self::assertSame(120, $entered->delay);
        self::assertSame('entered', $entered->reason);

        $fresh = $this->evaluate(new FreeAdaptiveState(-1), $this->snapshot(0, 0.0));
        self::assertSame(120, $fresh->delay);

        $abandoned = new FreeAdaptiveState(900, 1, self::NOW - 2 * 3_600 - 1);
        self::assertSame(120, $this->evaluate($abandoned, $this->snapshot(0, 0.0))->delay);
    }

    public function test_a_restart_resumes_the_stored_delay(): void
    {
        self::assertSame(900, $this->evaluate($this->stored(900), $this->snapshot(570_000, 0.0))->delay);
    }

    public function test_noise_around_zero_at_low_utilisation_never_changes_a_zero_delay(): void
    {
        $state = $this->stored(0);
        foreach ([40.0, -40.0, 15.0, -15.0, 0.0] as $ewma) {
            $state = $this->evaluate($state, $this->snapshot(50_000, $ewma));
            self::assertSame(0, $state->delay);
        }
    }

    public function test_the_gate_opens_once_the_current_delay_has_elapsed_since_the_last_permit(): void
    {
        $state = new FreeAdaptiveState(120, 0, 0, self::NOW - 100);

        self::assertFalse($state->gateOpen(self::NOW));
        self::assertTrue($state->gateOpen(self::NOW + 20));
        self::assertTrue((new FreeAdaptiveState(0, 0, 0, self::NOW))->gateOpen(self::NOW));
    }

    public function test_an_inconsistent_config_falls_back_to_the_defaults(): void
    {
        config()->set('nntmux.orchestrator.free_adaptive', [
            'min_delay_seconds' => 500,
            'max_delay_seconds' => 100,
            'increase_factor' => 0.5,
        ]);

        $config = FreeAdaptiveConfig::fromConfig();

        self::assertSame(120, $config->initialDelay);
        self::assertSame(0, $config->minDelay);
        self::assertSame(3_600, $config->maxDelay);
        self::assertSame(1.5, $config->increaseFactor);
    }

    public function test_config_stages_are_filtered_to_known_ones(): void
    {
        config()->set('nntmux.orchestrator.free_adaptive.stages', 'collections, nzbs ,bogus');

        self::assertSame(['collections'], FreeAdaptiveConfig::fromConfig()->stages);
    }
}
