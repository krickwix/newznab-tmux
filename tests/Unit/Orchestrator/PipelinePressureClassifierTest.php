<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\PipelinePressureClassifier;
use PHPUnit\Framework\TestCase;

final class PipelinePressureClassifierTest extends TestCase
{
    private PipelinePressureClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new PipelinePressureClassifier(
            highWatermarks: [
                'parts' => 300_000_000,
                'binaries' => 1_000_000,
                'collections' => 20_000,
                'releases' => 20_000,
                'nzbs' => 12_000,
            ],
            ageSloSeconds: [
                'binaries' => 172_800,
                'collections' => 172_800,
                'releases' => 86_400,
                'nzbs' => 86_400,
            ],
            projectionHorizonMinutes: 120,
        );
    }

    public function test_growth_with_many_hours_of_headroom_is_not_high_and_can_be_low(): void
    {
        $backlogs = $this->backlogs(collections: 4_070);
        $ewma = $this->rates(collections: 29.0);

        self::assertFalse($this->classifier->isHigh($backlogs, $this->ages(), $ewma));
        self::assertTrue($this->classifier->isLow($backlogs, $ewma));
    }

    public function test_projected_breach_at_or_inside_horizon_is_high_and_not_low(): void
    {
        $atBoundary = $this->backlogs(collections: 14_000);
        $inside = $this->backlogs(collections: 14_001);
        $ewma = $this->rates(collections: 50.0);

        self::assertTrue($this->classifier->isHigh($atBoundary, $this->ages(), $ewma));
        self::assertTrue($this->classifier->isHigh($inside, $this->ages(), $ewma));
        self::assertFalse($this->classifier->isHigh(
            $this->backlogs(collections: 13_999),
            $this->ages(),
            $ewma,
        ));
        self::assertFalse($this->classifier->isLow($atBoundary, $ewma));
    }

    public function test_low_projection_is_strict_at_the_low_watermark_and_horizon(): void
    {
        self::assertFalse($this->classifier->isLow($this->backlogs(collections: 12_000), $this->rates()));
        self::assertFalse($this->classifier->isLow(
            $this->backlogs(collections: 10_800),
            $this->rates(collections: 10.0),
        ));
        self::assertTrue($this->classifier->isLow(
            $this->backlogs(collections: 10_799),
            $this->rates(collections: 10.0),
        ));
    }

    public function test_zero_and_negative_growth_do_not_project_a_breach_but_non_finite_growth_fails_closed(): void
    {
        $nearHigh = $this->backlogs(collections: 19_999);

        self::assertFalse($this->classifier->isHigh($nearHigh, $this->ages(), $this->rates()));
        self::assertFalse($this->classifier->isHigh($nearHigh, $this->ages(), $this->rates(collections: -1.0)));
        self::assertTrue($this->classifier->isHigh($nearHigh, $this->ages(), $this->rates(collections: INF)));
        self::assertFalse($this->classifier->isLow($this->backlogs(), $this->rates(collections: INF)));
    }

    public function test_parts_and_binaries_project_to_the_hard_limit_not_the_sixty_percent_mark(): void
    {
        self::assertTrue($this->classifier->isLow(
            $this->backlogs(parts: 186_000_000, binaries: 700_000),
            ['parts' => 500.0, 'binaries' => 1_000.0, 'collections' => 0.0, 'releases' => 0.0, 'nzbs' => 0.0],
        ));
        self::assertFalse($this->classifier->isLow(
            $this->backlogs(parts: 186_000_000, binaries: 880_000),
            ['parts' => 500.0, 'binaries' => 1_000.0, 'collections' => 0.0, 'releases' => 0.0, 'nzbs' => 0.0],
        ));
    }

    public function test_absolute_watermark_and_aged_backlog_still_force_high_pressure(): void
    {
        self::assertTrue($this->classifier->isHigh(
            $this->backlogs(parts: 300_000_000),
            $this->ages(),
            $this->rates(),
        ));
        self::assertTrue($this->classifier->isHigh(
            $this->backlogs(collections: 12_001),
            $this->ages(collections: 172_800),
            $this->rates(),
        ));
    }

    public function test_recovery_sources_and_physical_total_have_independent_capacity_gates(): void
    {
        $classifier = new PipelinePressureClassifier(
            highWatermarks: [
                'parts' => 300_000_000,
                'binaries' => 1_000_000,
                'collections' => 20_000,
                'collections_total' => 80_000,
                'recovery_sources' => 60_000,
                'releases' => 20_000,
                'nzbs' => 12_000,
            ],
            ageSloSeconds: ['recovery_sources' => 86_400],
            projectionHorizonMinutes: 120,
        );
        $safe = $this->backlogs() + ['collections_total' => 48_000, 'recovery_sources' => 40_000];
        $rates = $this->rates() + ['collections_total' => 0.0, 'recovery_sources' => 0.0];

        self::assertFalse($classifier->isHigh($safe, ['recovery_sources' => 0], $rates));
        self::assertTrue($classifier->isHigh(
            array_replace($safe, ['recovery_sources' => 60_000]),
            ['recovery_sources' => 0],
            $rates,
        ));
        self::assertTrue($classifier->isHigh(
            array_replace($safe, ['collections_total' => 80_000]),
            ['recovery_sources' => 0],
            $rates,
        ));
        self::assertFalse($classifier->isLow($safe, $rates));
        self::assertTrue($classifier->isLow(
            array_replace($safe, ['collections_total' => 47_999, 'recovery_sources' => 35_999]),
            $rates,
        ));
    }

    /** @return array<string, int> */
    private function backlogs(int $parts = 186_000_000, int $binaries = 40_000, int $collections = 4_000): array
    {
        return ['parts' => $parts, 'binaries' => $binaries, 'collections' => $collections, 'releases' => 12, 'nzbs' => 6_792];
    }

    /** @return array<string, int> */
    private function ages(int $collections = 0): array
    {
        return ['binaries' => 0, 'collections' => $collections, 'releases' => 0, 'nzbs' => 0];
    }

    /** @return array<string, float> */
    private function rates(float $collections = 0.0): array
    {
        return ['parts' => 0.0, 'binaries' => 0.0, 'collections' => $collections, 'releases' => 0.0, 'nzbs' => 0.0];
    }
}
