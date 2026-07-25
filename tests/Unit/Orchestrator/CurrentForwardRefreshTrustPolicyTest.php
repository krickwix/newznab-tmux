<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardRefreshTrustPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CurrentForwardRefreshTrustPolicyTest extends TestCase
{
    public function test_refresh_only_anchor_is_trusted_without_becoming_a_static_corridor(): void
    {
        config([
            'nntmux.orchestrator.current_forward_windows' => 'alt.static:101-10100@10100',
            'nntmux.orchestrator.current_forward_refresh_sources' => 'alt.refresh:201-10200',
        ]);

        $policy = new CurrentForwardRefreshTrustPolicy;

        self::assertTrue($policy->isValid());
        self::assertSame(['alt.static', 'alt.refresh'], $policy->groups());
        self::assertSame(['first' => 201, 'last' => 10_200], $policy->anchor('alt.refresh'));
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_refresh_policy_fails_closed(string $raw): void
    {
        config([
            'nntmux.orchestrator.current_forward_windows' => '',
            'nntmux.orchestrator.current_forward_refresh_sources' => $raw,
        ]);

        $policy = new CurrentForwardRefreshTrustPolicy;

        self::assertFalse($policy->isValid());
        self::assertSame([], $policy->groups());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPolicies(): iterable
    {
        yield 'invalid syntax' => ['alt.refresh'];
        yield 'not ten thousand' => ['alt.refresh:1-9999'];
        yield 'conflicting duplicate' => ['alt.refresh:1-10000,alt.refresh:2-10001'];
    }
}
