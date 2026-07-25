<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardProviderCoverage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CurrentForwardProviderCoverageTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function reserveCases(): iterable
    {
        yield 'lower bound' => [19_000, 19_000];
        yield 'upper bound' => [20_000, 20_000];
        yield 'below bound fails closed' => [18_999, 20_000];
        yield 'above bound fails closed' => [20_001, 20_000];
        yield 'negative fails closed' => [-1, 20_000];
    }

    #[DataProvider('reserveCases')]
    public function test_reserve_accepts_only_the_narrow_evidence_backed_range(int $configured, int $expected): void
    {
        config()->set('nntmux.orchestrator.current_forward_provider_reserve', $configured);

        self::assertSame($expected, CurrentForwardProviderCoverage::reserve());
    }

    public function test_coverage_accepts_the_exact_boundary_and_rejects_one_article_less(): void
    {
        config()->set('nntmux.orchestrator.current_forward_provider_reserve', 19_000);

        self::assertTrue(CurrentForwardProviderCoverage::covers(1, 39_100, 10_101, 20_100));
        self::assertFalse(CurrentForwardProviderCoverage::covers(1, 39_099, 10_101, 20_100));
    }
}
