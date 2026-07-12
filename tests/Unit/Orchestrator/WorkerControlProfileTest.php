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
}
