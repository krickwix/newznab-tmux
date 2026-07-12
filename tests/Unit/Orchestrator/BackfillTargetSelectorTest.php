<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\BackfillTargetSelector;
use PHPUnit\Framework\TestCase;

final class BackfillTargetSelectorTest extends TestCase
{
    public function test_it_selects_the_first_configured_untried_probe(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.metal', 'alt.probe.criterion'],
            historyTtlSeconds: 86_400,
        );

        $target = $selector->select([
            $this->candidate('alt.newest', '2026-03-03 11:37:48'),
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.metal', '2008-08-15 09:08:32'),
        ], history: [], now: 2_000_000_000);

        self::assertSame('alt.probe.metal', $target['name'] ?? null);
    }

    public function test_it_explores_the_next_probe_after_a_zero_yield_attempt(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.metal', 'alt.probe.criterion'],
            historyTtlSeconds: 86_400,
        );
        $history = [
            'alt.probe.metal' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 0,
                'last_cursor_delta' => 0,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.metal', '2008-08-15 09:08:32'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.probe.criterion', $target['name'] ?? null);
    }

    public function test_it_repeats_one_input_bearing_probe_before_advancing(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.probe.criterion', $target['name'] ?? null);
    }

    public function test_it_prefers_the_highest_recent_positive_yield_after_probes(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.metal', 'alt.probe.criterion'],
            historyTtlSeconds: 86_400,
        );
        $history = [
            'alt.probe.metal' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 4.0,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
            ],
            'alt.probe.criterion' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 2.0,
                'last_attempt_at' => 1_999_999_100,
                'last_effective_at' => 1_999_999_100,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.metal', '2008-08-15 09:08:32'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.probe.metal', $target['name'] ?? null);
    }

    public function test_it_excludes_invalid_dates_and_ranges_below_the_safe_probe_floor(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.metal', 'alt.probe.criterion'],
            historyTtlSeconds: 86_400,
        );
        $tooSmall = $this->candidate('alt.probe.metal', '2008-08-15 09:08:32');
        $tooSmall['remaining_articles'] = 19_999;
        $invalidDate = $this->candidate('alt.invalid', '0000-12-12 15:09:20');

        $target = $selector->select([
            $tooSmall,
            $invalidDate,
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
        ], history: [], now: 2_000_000_000);

        self::assertSame('alt.probe.criterion', $target['name'] ?? null);
    }

    public function test_it_prefers_an_untried_candidate_to_known_zero_yield(): void
    {
        $selector = new BackfillTargetSelector(probeGroups: [], historyTtlSeconds: 86_400);
        $history = [
            'alt.zero' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_900,
                'last_effective_at' => 0,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.zero', '2026-03-03 11:37:48'),
            $this->candidate('alt.untried', '2019-04-14 12:49:16'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.untried', $target['name'] ?? null);
    }

    public function test_zero_yield_candidates_rotate_by_oldest_attempt(): void
    {
        $selector = new BackfillTargetSelector(probeGroups: [], historyTtlSeconds: 86_400);
        $history = [
            'alt.recent' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_900,
                'last_effective_at' => 0,
            ],
            'alt.oldest' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 0,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.recent', '2026-03-03 11:37:48'),
            $this->candidate('alt.oldest', '2019-04-14 12:49:16'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.oldest', $target['name'] ?? null);
    }

    /** @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int} */
    private function candidate(string $name, string $postdate): array
    {
        return [
            'name' => $name,
            'cursor' => 100_000,
            'cursor_postdate' => $postdate,
            'remaining_articles' => 90_000,
        ];
    }
}
