<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

final class CurrentForwardProviderCoverage
{
    private const int DEFAULT_RESERVE = 20_000;

    private const int MINIMUM_RESERVE = 19_000;

    private const int MAXIMUM_RESERVE = 20_000;

    public static function reserve(): int
    {
        $reserve = (int) config(
            'nntmux.orchestrator.current_forward_provider_reserve',
            self::DEFAULT_RESERVE,
        );

        return $reserve >= self::MINIMUM_RESERVE && $reserve <= self::MAXIMUM_RESERVE
            ? $reserve
            : self::DEFAULT_RESERVE;
    }

    public static function covers(int $providerFirst, int $providerHigh, int $first, int $last): bool
    {
        $reserve = self::reserve();

        return $providerFirst > 0
            && $providerFirst <= $first
            && $last <= PHP_INT_MAX - $reserve
            && $providerHigh >= $last + $reserve;
    }
}
