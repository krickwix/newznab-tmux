<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardStopCursorPolicy;
use Tests\TestCase;

final class CurrentForwardStopCursorPolicyTest extends TestCase
{
    public function test_it_pins_one_exact_audited_window_and_stop(): void
    {
        config()->set(
            'nntmux.orchestrator.current_forward_windows',
            'alt.binaries.hdtv.tv-episodes:99730786-99740785@99802459',
        );

        $policy = new CurrentForwardStopCursorPolicy;

        self::assertTrue($policy->isValid());
        self::assertSame(['alt.binaries.hdtv.tv-episodes'], $policy->groups());
        self::assertSame([
            'first' => 99_730_786,
            'last' => 99_740_785,
            'stop' => 99_802_459,
        ], $policy->window('alt.binaries.hdtv.tv-episodes'));
        self::assertTrue($policy->matches('alt.binaries.hdtv.tv-episodes', 99_730_786, 99_740_785, 99_802_459));
    }

    public function test_it_derives_each_exact_window_from_an_aligned_audited_corridor(): void
    {
        config()->set(
            'nntmux.orchestrator.current_forward_windows',
            'alt.test:101-30100@50100',
        );

        $policy = new CurrentForwardStopCursorPolicy;

        self::assertTrue($policy->isValid());
        self::assertSame([
            'first' => 101,
            'last' => 10_100,
            'stop' => 50_100,
        ], $policy->nextWindow('alt.test', 100));
        self::assertSame([
            'first' => 10_101,
            'last' => 20_100,
            'stop' => 50_100,
        ], $policy->nextWindow('alt.test', 10_100));
        self::assertSame([
            'first' => 20_101,
            'last' => 30_100,
            'stop' => 50_100,
        ], $policy->nextWindow('alt.test', 20_100));
        self::assertNull($policy->nextWindow('alt.test', 30_100));
        self::assertNull($policy->nextWindow('alt.test', 10_099));
        self::assertTrue($policy->matches('alt.test', 10_101, 20_100, 50_100));
        self::assertFalse($policy->matches('alt.test', 10_102, 20_101, 50_100));
    }

    public function test_malformed_duplicate_or_non_10k_windows_fail_closed(): void
    {
        foreach ([
            'broken',
            'alt.test:100-10099@20000,alt.test:100-10099@20000',
            'alt.test:100-200@300',
            'alt.test:100-20100@40100',
            'alt.test:100-10099@10098',
        ] as $configured) {
            config()->set('nntmux.orchestrator.current_forward_windows', $configured);
            $policy = new CurrentForwardStopCursorPolicy;

            self::assertFalse($policy->isValid(), $configured);
            self::assertSame([], $policy->groups(), $configured);
            self::assertNull($policy->window('alt.test'), $configured);
        }
    }
}
