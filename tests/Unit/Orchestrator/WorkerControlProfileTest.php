<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\WorkerControlProfile;
use Tests\TestCase;

final class WorkerControlProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'nntmux.orchestrator.backfill_scale_min_yield' => 1.0,
            'nntmux.orchestrator.backfill_target_nzbs_per_permit' => 60,
            'nntmux.orchestrator.backfill_max_quantity' => 200_000,
        ]);
    }

    public function test_fill_scales_proven_yield_to_the_target_and_hard_cap(): void
    {
        $profile = WorkerControlProfile::for(ControlProfile::Fill);

        self::assertSame(200_000, $profile->quantityForYield(3.0, 1_000_000));
        self::assertSame(100_000, $profile->quantityForYield(6.1, 1_000_000));
        self::assertSame(40_000, $profile->quantityForYield(3.0, 1_000_000, 40_000));
    }

    public function test_unknown_invalid_and_balanced_targets_remain_bounded_to_probe_quantity(): void
    {
        self::assertSame(10_000, WorkerControlProfile::for(ControlProfile::Fill)->quantityForYield(0.0, 1_000_000));
        self::assertSame(10_000, WorkerControlProfile::for(ControlProfile::Fill)->quantityForYield(INF, 1_000_000));
        self::assertSame(10_000, WorkerControlProfile::for(ControlProfile::Balanced)->quantityForYield(10.0, 1_000_000));
    }

    public function test_scaled_quantity_preserves_a_ten_thousand_article_source_reserve(): void
    {
        $profile = WorkerControlProfile::for(ControlProfile::Fill);

        self::assertSame(20_000, $profile->quantityForYield(10.0, 30_000));
        self::assertSame(10_000, $profile->quantityForYield(10.0, 20_000));
    }

    public function test_one_input_bearing_zero_yield_probe_remains_at_probe_quantity(): void
    {
        $balanced = WorkerControlProfile::for(ControlProfile::Balanced);
        $fill = WorkerControlProfile::for(ControlProfile::Fill);

        self::assertSame(10_000, $balanced->quantityForYield(0.0, 1_000_000, 150_000, 1, 10_000, 0, true, 1));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 150_000, 1, 10_000, 0, true, 1));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 150_000, 0, 0, 0, true, 1));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 150_000, 1, 0, 0, true, 1));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 150_000, 1, 10_000, 1, true, 1));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 150_000, 1, 10_000, 0, false, 1));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 150_000, 1, 10_000, 0, true, 0));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 150_000, 2, 10_000, 0, true, 1));
    }

    public function test_zero_yield_retry_does_not_consume_extra_headroom_or_source_reserve(): void
    {
        $profile = WorkerControlProfile::for(ControlProfile::Fill);

        self::assertSame(10_000, $profile->quantityForYield(0.0, 1_000_000, 40_000, 1, 10_000, 0, true, 1));
        self::assertSame(10_000, $profile->quantityForYield(0.0, 30_000, 150_000, 1, 10_000, 0, true, 1));
        self::assertSame(10_000, $profile->quantityForYield(0.0, 20_000, 150_000, 1, 10_000, 0, true, 1));

        config(['nntmux.orchestrator.backfill_max_quantity' => 30_000]);
        self::assertSame(10_000, $profile->quantityForYield(0.0, 1_000_000, 150_000, 1, 10_000, 0, true, 1));
    }

    public function test_cooldown_due_zero_yield_target_remains_at_probe_quantity(): void
    {
        $fill = WorkerControlProfile::for(ControlProfile::Fill);
        $balanced = WorkerControlProfile::for(ControlProfile::Balanced);

        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 150_000, 2, 10_000, 0, true, 2, true));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 40_000, 2, 10_000, 0, true, 2, true));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 30_000, 150_000, 2, 10_000, 0, true, 2, true));
        self::assertSame(10_000, $fill->quantityForYield(0.0, 1_000_000, 150_000, 1, 10_000, 0, true, 1, true));
        self::assertSame(10_000, $balanced->quantityForYield(0.0, 1_000_000, 150_000, 2, 10_000, 0, true, 2, true));

        self::assertSame(20_000, $fill->quantityForYield(3.0, 1_000_000, 150_000, 2, 10_000, time(), true, 2, true));
    }

    public function test_proven_target_quantity_ramps_by_at_most_twice_the_last_exact_cohort(): void
    {
        $profile = WorkerControlProfile::for(ControlProfile::Fill);

        self::assertSame(40_000, $profile->quantityForYield(3.0, 1_000_000, 600_000, 2, 20_000, time(), true));
        self::assertSame(200_000, $profile->quantityForYield(3.0, 1_000_000, 600_000, 2, 100_000, time(), true));
    }

    public function test_no_quantity_is_returned_when_live_headroom_cannot_fit_one_quantum(): void
    {
        $profile = WorkerControlProfile::for(ControlProfile::Fill);

        self::assertSame(0, $profile->quantityForYield(3.0, 1_000_000, 0));
        self::assertSame(0, $profile->quantityForYield(0.0, 1_000_000, 0, 1, 10_000, 0, true, 1));
    }

    public function test_active_profiles_keep_nzb_capacity_above_the_doubled_rate_target(): void
    {
        self::assertSame(40, WorkerControlProfile::for(ControlProfile::Drain)->nzbBatchSize);
        self::assertSame(40, WorkerControlProfile::for(ControlProfile::Balanced)->nzbBatchSize);
        self::assertSame(40, WorkerControlProfile::for(ControlProfile::Fill)->nzbBatchSize);
    }

    public function test_fill_accelerates_release_feedback_without_changing_pressure_profiles(): void
    {
        self::assertSame(20, WorkerControlProfile::for(ControlProfile::Fill)->releasesSleepSeconds);
        self::assertSame(60, WorkerControlProfile::for(ControlProfile::Balanced)->releasesSleepSeconds);
        self::assertSame(45, WorkerControlProfile::for(ControlProfile::Drain)->releasesSleepSeconds);
        self::assertSame(180, WorkerControlProfile::for(ControlProfile::FailSafe)->releasesSleepSeconds);
    }

    public function test_fill_polls_for_immutable_backfill_permits_without_changing_pressure_profiles(): void
    {
        self::assertSame(20, WorkerControlProfile::for(ControlProfile::Fill)->backfillSleepSeconds);
        self::assertSame(900, WorkerControlProfile::for(ControlProfile::Balanced)->backfillSleepSeconds);
        self::assertSame(1800, WorkerControlProfile::for(ControlProfile::Drain)->backfillSleepSeconds);
        self::assertSame(1800, WorkerControlProfile::for(ControlProfile::FailSafe)->backfillSleepSeconds);
    }

    public function test_fail_safe_keeps_all_input_conservative_while_backfill_is_disabled(): void
    {
        $profile = WorkerControlProfile::for(ControlProfile::FailSafe);

        self::assertSame(300, $profile->binariesSleepSeconds);
        self::assertFalse($profile->backfillEnabled);
    }
}
