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

    private function evaluateAt(FreeAdaptiveState $prev, PipelineSnapshot $snapshot, int $now, ?FreeAdaptiveConfig $config = null, bool $entering = false): FreeAdaptiveState
    {
        return (new FreeAdaptiveBackfillGovernor)->evaluate($prev, $snapshot, $now, $entering, $config ?? $this->config());
    }

    /**
     * Decide on $snapshot as the closing tick of a window that already holds two
     * samples of the same utilisation (minSamples is 3), so single-sample cases
     * read as "a steady window at this p".
     */
    private function evaluate(FreeAdaptiveState $prev, PipelineSnapshot $snapshot, ?FreeAdaptiveConfig $config = null, bool $entering = false): FreeAdaptiveState
    {
        $probe = $this->evaluateAt(new FreeAdaptiveState($prev->delay, $prev->updatedAt, $prev->checkedAt, $prev->lastDoneAt, windowStart: self::NOW - 10), $snapshot, self::NOW, $config);
        $seeded = new FreeAdaptiveState(
            $prev->delay, $prev->updatedAt, $prev->checkedAt, $prev->lastDoneAt,
            windowSum: 2 * $probe->projected, windowSamples: 2, windowStart: self::NOW - 400,
        );

        return $this->evaluateAt($entering ? $prev : $seeded, $snapshot, self::NOW, $config, $entering);
    }

    /**
     * Feed one sample per minute starting right after $state's window opened.
     *
     * @param  list<float>  $collectionsBacklogs
     */
    private function feed(FreeAdaptiveState $state, array $collectionsBacklogs, int $startAt, array $snapshotOverrides = []): FreeAdaptiveState
    {
        foreach ($collectionsBacklogs as $i => $backlog) {
            $now = $startAt + $i * 60;
            $state = $this->evaluateAt($state, $this->snapshot((int) $backlog, 0.0, ['observedAt' => $now] + $snapshotOverrides), $now);
        }

        return $state;
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
        // (649k - 12k * 10) / 600k = 0.88
        $next = $this->evaluate($this->stored(120), $this->snapshot(649_000, -12_000.0));

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

    public function test_high_watermarks_come_from_the_same_config_the_pressure_stages_report(): void
    {
        config()->set('nntmux.orchestrator.high_watermarks', ['parts' => 7, 'binaries' => 8, 'collections' => 600_000]);

        $config = FreeAdaptiveConfig::fromConfig();

        self::assertSame(['parts' => 7, 'binaries' => 8, 'collections' => 600_000], $config->highWatermarks);
    }

    public function test_a_zero_increase_step_is_rejected(): void
    {
        config()->set('nntmux.orchestrator.free_adaptive.increase_step_seconds', 0);

        self::assertSame(30, FreeAdaptiveConfig::fromConfig()->increaseStep);
    }

    public function test_alternating_samples_with_a_mean_in_the_deadband_never_change_the_delay(): void
    {
        // The utilisation swings 0.75 / 1.15 (450k / 690k of 600k) every minute, as the
        // live EWMA flood did. Each 5-sample window averages 0.91 or 0.99, inside the
        // deadband, whichever phase it starts on; the old single-sample rule flipped
        // the delay on every swing.
        $state = new FreeAdaptiveState(120, self::NOW, self::NOW, 0, windowStart: self::NOW);
        $backlogs = [];
        for ($i = 0; $i < 30; $i++) {
            $backlogs[] = $i % 2 === 0 ? 450_000 : 690_000;
        }
        $state = $this->feed($state, $backlogs, self::NOW + 60);

        self::assertSame(120, $state->delay);
    }

    public function test_a_sustained_high_projection_still_climbs(): void
    {
        $state = new FreeAdaptiveState(120, self::NOW, self::NOW, 0, windowStart: self::NOW);
        $state = $this->feed($state, array_fill(0, 12, 700_000), self::NOW + 60);

        self::assertGreaterThan(120, $state->delay);
    }

    public function test_a_sustained_low_projection_still_decays(): void
    {
        $state = new FreeAdaptiveState(120, self::NOW, self::NOW, 0, windowStart: self::NOW);
        $state = $this->feed($state, array_fill(0, 12, 100_000), self::NOW + 60);

        self::assertLessThan(120, $state->delay);
    }

    public function test_fewer_than_min_samples_hold_and_keep_collecting(): void
    {
        $prev = new FreeAdaptiveState(120, self::NOW - 1000, self::NOW - 60, 0, windowSum: 1.4, windowSamples: 1, windowStart: self::NOW - 400);

        $next = $this->evaluateAt($prev, $this->snapshot(840_000, 0.0), self::NOW);

        self::assertSame(120, $next->delay);
        self::assertSame('insufficient_samples', $next->reason);
        self::assertSame(2, $next->windowSamples);
    }

    public function test_the_window_resets_on_a_change_and_on_entering(): void
    {
        $changed = $this->evaluate($this->stored(120), $this->snapshot(700_000, 0.0));
        self::assertSame(180, $changed->delay);
        self::assertSame(0, $changed->windowSamples);
        self::assertSame(0.0, $changed->windowSum);
        self::assertSame(self::NOW, $changed->windowStart);

        $held = $this->evaluate($this->stored(120), $this->snapshot(570_000, 0.0));
        self::assertSame(0, $held->windowSamples);

        $prev = new FreeAdaptiveState(900, self::NOW - 1000, self::NOW - 60, 0, windowSum: 5.0, windowSamples: 5, windowStart: self::NOW - 400);
        $entered = $this->evaluateAt($prev, $this->snapshot(700_000, 0.0), self::NOW, null, true);
        self::assertSame(0, $entered->windowSamples);
        self::assertSame(0.0, $entered->windowSum);
        self::assertSame(120, $entered->delay);
    }

    public function test_stale_ticks_are_excluded_from_the_mean_and_the_window_still_grows(): void
    {
        $prev = new FreeAdaptiveState(120, self::NOW - 1000, self::NOW - 60, 0, windowSum: 0.4, windowSamples: 2, windowStart: self::NOW - 100);

        $stale = $this->evaluateAt($prev, $this->snapshot(0, 0.0, ['telemetryFresh' => false]), self::NOW - 30);
        self::assertSame(2, $stale->windowSamples);
        self::assertSame(0.4, $stale->windowSum);

        $closing = new FreeAdaptiveState(120, self::NOW - 1000, self::NOW - 60, 0, windowSum: 0.4, windowSamples: 2, windowStart: self::NOW - 400);
        $next = $this->evaluateAt($closing, $this->snapshot(0, 0.0, ['telemetryFresh' => false]), self::NOW);
        self::assertSame(180, $next->delay);
        self::assertSame('metrics_stale', $next->reason);
    }

    public function test_a_mostly_stale_window_with_a_fresh_boundary_tick_still_grows(): void
    {
        // 4 stale ticks against 2 fresh ones (one of them the boundary), and a mean of 0.2.
        $prev = new FreeAdaptiveState(120, self::NOW - 1000, self::NOW - 60, 0, windowSum: 0.2, windowSamples: 1, windowStart: self::NOW - 400, windowBad: 4);

        $next = $this->evaluateAt($prev, $this->snapshot(120_000, 0.0), self::NOW);

        self::assertSame(180, $next->delay);
        self::assertSame('metrics_stale', $next->reason);
        self::assertSame(0, $next->windowBad);
    }

    public function test_stale_ticks_are_counted_in_the_window(): void
    {
        $prev = new FreeAdaptiveState(120, self::NOW - 1000, self::NOW - 60, 0, windowStart: self::NOW - 100);

        $next = $this->evaluateAt($prev, $this->snapshot(0, 0.0, ['telemetryFresh' => false]), self::NOW - 30);

        self::assertSame(1, $next->windowBad);
    }

    public function test_a_gap_resets_the_window_but_keeps_the_delay(): void
    {
        $prev = new FreeAdaptiveState(900, self::NOW - 5000, self::NOW - 600, 0, windowSum: 9.0, windowSamples: 9, windowStart: self::NOW - 5000, windowBad: 3);

        $next = $this->evaluateAt($prev, $this->snapshot(570_000, 0.0), self::NOW);

        self::assertSame(900, $next->delay);
        self::assertSame(1, $next->windowSamples);
        self::assertSame(0, $next->windowBad);
        self::assertSame(self::NOW, $next->windowStart);
        self::assertEqualsWithDelta(0.95, $next->windowSum, 0.0001);
    }

    public function test_the_window_mean_is_reported(): void
    {
        $state = $this->feed(new FreeAdaptiveState(120, self::NOW, self::NOW, 0, windowStart: self::NOW), [300_000, 330_000], self::NOW + 60);

        self::assertEqualsWithDelta(0.525, $state->windowMean(), 0.0001);
        self::assertSame(2, $state->toArray(self::NOW, $this->config())['window_samples']);
        self::assertEqualsWithDelta(0.525, $state->toArray(self::NOW, $this->config())['window_mean_utilisation'], 0.0001);
    }

    public function test_the_horizon_is_clamped(): void
    {
        config()->set('nntmux.orchestrator.free_adaptive.horizon_minutes', 9999);
        self::assertSame(120, FreeAdaptiveConfig::fromConfig()->horizonMinutes);

        config()->set('nntmux.orchestrator.free_adaptive.horizon_minutes', 0);
        self::assertSame(1, FreeAdaptiveConfig::fromConfig()->horizonMinutes);
    }
}
