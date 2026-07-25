<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\BackfillTargetSelector;
use App\Services\Orchestrator\WorkerControlPolicy;
use PHPUnit\Framework\TestCase;

final class BackfillTargetSelectorTest extends TestCase
{
    public function test_fresh_context_repeat_has_priority_over_every_lane_and_is_probe_clamped(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.untried', 'alt.repeat', 'alt.positive'],
            historyTtlSeconds: 86_400,
        );
        $repeat = [...$this->candidate('alt.repeat', '2026-01-02 00:00:00'), 'safe_quantity' => 80_000];

        $target = $selector->select([
            $this->candidate('alt.untried', '2026-01-03 00:00:00'),
            $repeat,
            $this->candidate('alt.positive', '2026-01-01 00:00:00'),
        ], [
            'alt.repeat' => $this->history(attempts: 1, yield: 0.0),
            'alt.positive' => $this->history(attempts: 4, yield: 5.0),
        ], now: 2_000_000_000, contextRepeat: [
            'group' => 'alt.repeat',
            'marked_at' => 1_999_999_900,
        ]);

        self::assertSame('alt.repeat', $target['name'] ?? null);
        self::assertSame(10_000, $target['safe_quantity'] ?? null);
    }

    public function test_context_repeat_never_bypasses_candidate_safety_or_exact_strike_gates(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.repeat', 'alt.safe'],
            historyTtlSeconds: 86_400,
        );
        $unsafe = [...$this->candidate('alt.repeat', '2026-01-02 00:00:00'), 'safe_quantity' => 0];

        $safetyGated = $selector->select([
            $unsafe,
            $this->candidate('alt.safe', '2026-01-01 00:00:00'),
        ], [
            'alt.repeat' => $this->history(attempts: 1, yield: 0.0),
        ], now: 2_000_000_000, contextRepeat: [
            'group' => 'alt.repeat',
            'marked_at' => 1_999_999_900,
        ]);
        $strikeGated = $selector->select([
            $this->candidate('alt.repeat', '2026-01-02 00:00:00'),
            $this->candidate('alt.safe', '2026-01-01 00:00:00'),
        ], [
            'alt.repeat' => $this->history(attempts: 1, yield: 0.0),
        ], now: 2_000_000_000, ineffectivePermitsByTarget: [
            'alt.repeat' => WorkerControlPolicy::INEFFECTIVE_BACKFILL_LIMIT,
        ], contextRepeat: [
            'group' => 'alt.repeat',
            'marked_at' => 1_999_999_900,
        ]);

        self::assertSame('alt.safe', $safetyGated['name'] ?? null);
        self::assertSame('alt.safe', $strikeGated['name'] ?? null);
    }

    public function test_stale_or_wrong_group_context_repeat_does_not_change_normal_selection(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.untried', 'alt.repeat'],
            historyTtlSeconds: 600,
        );
        $candidates = [
            $this->candidate('alt.untried', '2026-01-03 00:00:00'),
            $this->candidate('alt.repeat', '2026-01-02 00:00:00'),
        ];

        $stale = $selector->select($candidates, [], 2_000_000_000, contextRepeat: [
            'group' => 'alt.repeat',
            'marked_at' => 1_999_999_400,
        ]);
        $wrong = $selector->select($candidates, [], 2_000_000_000, contextRepeat: [
            'group' => 'alt.missing',
            'marked_at' => 1_999_999_999,
        ]);

        self::assertSame('alt.untried', $stale['name'] ?? null);
        self::assertSame('alt.untried', $wrong['name'] ?? null);
    }

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

    public function test_it_tries_later_untried_probe_before_confirming_input_bearing_zero_yield(): void
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

        self::assertSame('alt.probe.freak', $target['name'] ?? null);
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

    public function test_a_positive_target_stops_lower_confidence_probe_exploration(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 0.5,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.probe.criterion', $target['name'] ?? null);
    }

    public function test_it_explores_an_untried_probe_after_three_positive_target_attempts(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 3,
                'ewma_nzbs_per_10k' => 0.75,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.probe.freak', $target['name'] ?? null);
    }

    public function test_it_explores_after_each_low_yield_exploit_until_a_better_target_is_found(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.dvd-r'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
            aggressiveExploreBelowYield: 1.0,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 7,
                'ewma_nzbs_per_10k' => 0.34375,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.dvd-r', '2019-04-14 12:49:16'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.probe.dvd-r', $target['name'] ?? null);
    }

    public function test_satisfactory_yield_retains_the_configured_exploit_interval(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.dvd-r'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
            aggressiveExploreBelowYield: 1.0,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 7,
                'ewma_nzbs_per_10k' => 1.0,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.dvd-r', '2019-04-14 12:49:16'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.probe.criterion', $target['name'] ?? null);
    }

    public function test_forced_exploration_repeats_one_input_bearing_zero_yield_probe(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 6,
                'ewma_nzbs_per_10k' => 0.75,
                'last_attempt_at' => 1_999_999_200,
                'last_effective_at' => 1_999_999_200,
                'last_cursor_delta' => 10_000,
            ],
            'alt.probe.freak' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_100,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: ['alt.probe.freak' => 1]);

        self::assertSame('alt.probe.freak', $target['name'] ?? null);
    }

    public function test_productive_target_skips_zero_yield_context_repeat_above_exploration_threshold(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
            aggressiveExploreBelowYield: 0.15,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 8,
                'ewma_nzbs_per_10k' => 0.23,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
            'alt.probe.freak' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_100,
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

    public function test_it_immediately_completes_an_input_bearing_exploration_probe(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 3,
                'ewma_nzbs_per_10k' => 0.75,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
            'alt.probe.freak' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_100,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.probe.freak', $target['name'] ?? null);
    }

    public function test_it_resumes_the_positive_target_after_the_exploration_repeat(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 6,
                'ewma_nzbs_per_10k' => 0.6875,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
            'alt.probe.freak' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_200,
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

    public function test_it_does_not_resurrect_an_older_probe_after_a_newer_intervening_attempt(): void
    {
        $selector = new BackfillTargetSelector(probeGroups: [], historyTtlSeconds: 86_400);
        $history = [
            'alt.best' => [
                'attempts' => 3,
                'ewma_nzbs_per_10k' => 0.75,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
            'alt.older-probe' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_100,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
            'alt.intervening' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_200,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.best', '2009-05-15 06:49:20'),
            $this->candidate('alt.older-probe', '2016-08-27 18:02:03'),
            $this->candidate('alt.intervening', '2017-08-27 18:02:03'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.best', $target['name'] ?? null);
    }

    public function test_tied_recent_probe_timestamps_conservatively_prevent_a_repeat(): void
    {
        $selector = new BackfillTargetSelector(probeGroups: [], historyTtlSeconds: 86_400);
        $history = [
            'alt.best' => [
                'attempts' => 3,
                'ewma_nzbs_per_10k' => 0.75,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
            'alt.probe-a' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_200,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
            'alt.probe-b' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_200,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.best', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe-a', '2016-08-27 18:02:03'),
            $this->candidate('alt.probe-b', '2017-08-27 18:02:03'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.best', $target['name'] ?? null);
    }

    public function test_forced_exploration_can_select_an_unconfigured_untried_candidate(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 3,
                'ewma_nzbs_per_10k' => 0.75,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.unconfigured', '2026-03-03 11:37:48'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.unconfigured', $target['name'] ?? null);
    }

    public function test_it_exploits_between_forced_exploration_intervals(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
        );

        foreach ([4, 5] as $attempts) {
            $history = [
                'alt.probe.criterion' => [
                    'attempts' => $attempts,
                    'ewma_nzbs_per_10k' => 0.75,
                    'last_attempt_at' => 1_999_999_000,
                    'last_effective_at' => 1_999_999_000,
                    'last_cursor_delta' => 10_000,
                ],
            ];

            $target = $selector->select([
                $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
                $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
            ], $history, now: 2_000_000_000);

            self::assertSame('alt.probe.criterion', $target['name'] ?? null);
        }
    }

    public function test_attempt_timestamp_ties_conservatively_keep_the_positive_target(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 3,
                'ewma_nzbs_per_10k' => 0.75,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
            'alt.previous' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 0,
                'last_cursor_delta' => 0,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
        ], $history, now: 2_000_000_000);

        self::assertSame('alt.probe.criterion', $target['name'] ?? null);
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

    public function test_it_skips_a_target_after_two_consecutive_ineffective_permits(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 5,
                'ewma_nzbs_per_10k' => 0.375,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_998_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: ['alt.probe.criterion' => 2]);

        self::assertSame('alt.probe.freak', $target['name'] ?? null);
    }

    public function test_recent_productive_target_cannot_override_exact_ineffective_lock(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
            aggressiveExploreBelowYield: 0.15,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 8,
                'ewma_nzbs_per_10k' => 0.23,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_998_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: ['alt.probe.criterion' => 2]);

        self::assertSame('alt.probe.freak', $target['name'] ?? null);
    }

    public function test_positive_target_with_one_exact_strike_yields_exploitation_priority_to_untried_source(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.productive', 'alt.probe.untried'],
            historyTtlSeconds: 86_400,
            aggressiveExploreBelowYield: 0.15,
        );
        $history = [
            'alt.probe.productive' => [
                'attempts' => 8,
                'ewma_nzbs_per_10k' => 1.5,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_998_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.productive', '2026-06-01 00:00:00'),
            $this->candidate('alt.probe.untried', '2026-07-01 00:00:00'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: [
            'alt.probe.productive' => 1,
        ]);

        self::assertSame('alt.probe.untried', $target['name'] ?? null);
    }

    public function test_it_returns_null_when_every_candidate_has_two_ineffective_permits(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion', 'alt.probe.freak'],
            historyTtlSeconds: 86_400,
        );

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
            $this->candidate('alt.probe.freak', '2016-08-27 18:02:03'),
        ], history: [], now: 2_000_000_000, ineffectivePermitsByTarget: [
            'alt.probe.criterion' => 2,
            'alt.probe.freak' => 2,
        ]);

        self::assertNull($target);
    }

    public function test_it_retries_a_locked_target_after_the_bounded_cooldown(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion'],
            historyTtlSeconds: 86_400,
            lockRetrySeconds: 21_600,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_970_000,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: ['alt.probe.criterion' => 2]);

        self::assertSame('alt.probe.criterion', $target['name'] ?? null);
        self::assertTrue($target['lock_retry_due'] ?? false);
    }

    public function test_it_keeps_a_locked_target_excluded_during_the_cooldown(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.probe.criterion'],
            historyTtlSeconds: 86_400,
            lockRetrySeconds: 21_600,
        );
        $history = [
            'alt.probe.criterion' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_990_000,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.probe.criterion', '2009-05-15 06:49:20'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: ['alt.probe.criterion' => 2]);

        self::assertNull($target);
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

    public function test_due_retries_prefer_recent_effectiveness_then_newer_cursor_band(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: [],
            historyTtlSeconds: 86_400,
            lockRetrySeconds: 21_600,
        );
        $history = [
            'alt.old-effective' => [
                'attempts' => 4,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_970_000,
                'last_effective_at' => 1_999_000_000,
                'last_cursor_delta' => 10_000,
            ],
            'alt.recent-effective-older-band' => [
                'attempts' => 4,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_960_000,
                'last_effective_at' => 1_999_500_000,
                'last_cursor_delta' => 10_000,
            ],
            'alt.recent-effective-newer-band' => [
                'attempts' => 4,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_970_500,
                'last_effective_at' => 1_999_500_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.old-effective', '2026-06-01 00:00:00'),
            $this->candidate('alt.recent-effective-older-band', '2026-06-15 00:00:00'),
            $this->candidate('alt.recent-effective-newer-band', '2026-07-01 00:00:00'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: [
            'alt.old-effective' => 2,
            'alt.recent-effective-older-band' => 2,
            'alt.recent-effective-newer-band' => 2,
        ]);

        self::assertSame('alt.recent-effective-newer-band', $target['name'] ?? null);
        self::assertTrue($target['lock_retry_due'] ?? false);
    }

    public function test_due_retry_is_a_distinct_lane_ahead_of_non_due_zero_yield_history(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: [],
            historyTtlSeconds: 86_400,
            lockRetrySeconds: 21_600,
        );
        $history = [
            'alt.non-due' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_990_000,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
            'alt.due' => [
                'attempts' => 4,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_970_000,
                'last_effective_at' => 1_999_500_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.non-due', '2026-07-01 00:00:00'),
            $this->candidate('alt.due', '2026-06-01 00:00:00'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: [
            'alt.non-due' => 1,
            'alt.due' => 2,
        ]);

        self::assertSame('alt.due', $target['name'] ?? null);
    }

    public function test_stale_struck_target_is_not_reclassified_as_untried(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.stale-struck', 'alt.never-tried'],
            historyTtlSeconds: 86_400,
            lockRetrySeconds: 21_600,
        );
        $history = [
            'alt.stale-struck' => [
                'attempts' => 5,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_900_000,
                'last_effective_at' => 1_999_000_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.stale-struck', '2026-07-01 00:00:00'),
            $this->candidate('alt.never-tried', '2026-06-01 00:00:00'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: [
            'alt.stale-struck' => 2,
        ]);

        self::assertSame('alt.never-tried', $target['name'] ?? null);
    }

    public function test_stale_one_strike_probe_remains_confirmable_during_forced_exploration(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: ['alt.stale-one-strike', 'alt.clean-positive'],
            historyTtlSeconds: 86_400,
            exploitAttemptsBeforeExplore: 3,
        );
        $history = [
            'alt.stale-one-strike' => [
                'attempts' => 1,
                'ewma_nzbs_per_10k' => 0.0,
                'last_attempt_at' => 1_999_900_000,
                'last_effective_at' => 0,
                'last_cursor_delta' => 10_000,
            ],
            'alt.clean-positive' => [
                'attempts' => 3,
                'ewma_nzbs_per_10k' => 1.5,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_999_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        $target = $selector->select([
            $this->candidate('alt.stale-one-strike', '2026-06-01 00:00:00'),
            $this->candidate('alt.clean-positive', '2026-07-01 00:00:00'),
        ], $history, now: 2_000_000_000, ineffectivePermitsByTarget: [
            'alt.stale-one-strike' => 1,
        ]);

        self::assertSame('alt.stale-one-strike', $target['name'] ?? null);
    }

    public function test_recent_proven_zero_strike_terminal_range_is_selected_and_clamped_to_exactly_10k(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: [],
            historyTtlSeconds: 86_400,
            terminalMinAttempts: 3,
            terminalMinYield: 1.0,
        );
        $terminal = [...$this->candidate('alt.terminal', '2008-10-24 01:12:31'),
            'remaining_articles' => 16_387,
            'safe_quantity' => 80_000,
        ];

        $target = $selector->select([$terminal], [
            'alt.terminal' => [
                'attempts' => 54,
                'ewma_nzbs_per_10k' => 1.442194,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_998_000,
                'last_cursor_delta' => 80_000,
            ],
        ], now: 2_000_000_000);

        self::assertSame('alt.terminal', $target['name'] ?? null);
        self::assertSame(10_000, $target['safe_quantity'] ?? null);
        self::assertTrue($target['terminal_positive'] ?? false);
    }

    public function test_terminal_lane_includes_both_10_001_and_19_999_boundaries(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: [],
            historyTtlSeconds: 86_400,
            terminalMinAttempts: 3,
            terminalMinYield: 1.0,
        );
        $history = [
            'alt.terminal' => [
                'attempts' => 3,
                'ewma_nzbs_per_10k' => 1.0,
                'last_attempt_at' => 1_999_999_000,
                'last_effective_at' => 1_999_998_000,
                'last_cursor_delta' => 10_000,
            ],
        ];

        foreach ([10_001, 19_999] as $remainingArticles) {
            $candidate = [...$this->candidate('alt.terminal', '2008-10-24 01:12:31'),
                'remaining_articles' => $remainingArticles,
                'safe_quantity' => 50_000,
            ];
            $target = $selector->select([$candidate], $history, now: 2_000_000_000);

            self::assertSame(10_000, $target['safe_quantity'] ?? null);
            self::assertTrue($target['terminal_positive'] ?? false);
        }
    }

    public function test_ordinary_20k_range_does_not_require_terminal_history(): void
    {
        $selector = new BackfillTargetSelector(probeGroups: [], historyTtlSeconds: 86_400);
        $candidate = [...$this->candidate('alt.ordinary', '2008-10-24 01:12:31'),
            'remaining_articles' => 20_000,
            'safe_quantity' => 10_000,
        ];

        $target = $selector->select([$candidate], history: [], now: 2_000_000_000);

        self::assertSame('alt.ordinary', $target['name'] ?? null);
        self::assertArrayNotHasKey('terminal_positive', $target ?? []);
    }

    public function test_context_repeat_cannot_bypass_terminal_productivity_guards(): void
    {
        $selector = new BackfillTargetSelector(probeGroups: [], historyTtlSeconds: 86_400);
        $candidate = [...$this->candidate('alt.terminal', '2008-10-24 01:12:31'),
            'remaining_articles' => 16_387,
            'safe_quantity' => 10_000,
        ];

        $target = $selector->select(
            [$candidate],
            history: [],
            now: 2_000_000_000,
            contextRepeat: ['group' => 'alt.terminal', 'marked_at' => 1_999_999_900],
        );

        self::assertNull($target);
    }

    public function test_terminal_range_fails_closed_without_every_productivity_guard(): void
    {
        $selector = new BackfillTargetSelector(
            probeGroups: [],
            historyTtlSeconds: 86_400,
            terminalMinAttempts: 3,
            terminalMinYield: 1.0,
        );
        $candidate = [...$this->candidate('alt.terminal', '2008-10-24 01:12:31'),
            'remaining_articles' => 16_387,
            'safe_quantity' => 80_000,
        ];
        $history = [
            'attempts' => 3,
            'ewma_nzbs_per_10k' => 1.0,
            'last_attempt_at' => 1_999_999_000,
            'last_effective_at' => 1_999_998_000,
            'last_cursor_delta' => 10_000,
        ];

        $cases = [
            'fewer than 10k articles' => [[...$candidate, 'remaining_articles' => 9_999], $history, 0],
            'exact provider reserve' => [[...$candidate, 'remaining_articles' => 10_000], $history, 0],
            'insufficient attempts' => [$candidate, [...$history, 'attempts' => 2], 0],
            'insufficient yield' => [$candidate, [...$history, 'ewma_nzbs_per_10k' => 0.999], 0],
            'no effective result' => [$candidate, [...$history, 'last_effective_at' => 0], 0],
            'stale history' => [$candidate, [...$history, 'last_attempt_at' => 1_999_913_600], 0],
            'future history' => [$candidate, [...$history, 'last_attempt_at' => 2_000_000_001], 0],
            'future effectiveness' => [$candidate, [...$history, 'last_effective_at' => 2_000_000_001], 0],
            'one ineffective strike' => [$candidate, $history, 1],
            'insufficient safe capacity' => [[...$candidate, 'safe_quantity' => 9_999], $history, 0],
        ];

        foreach ($cases as $message => [$terminal, $terminalHistory, $strikes]) {
            $target = $selector->select(
                [$terminal],
                ['alt.terminal' => $terminalHistory],
                now: 2_000_000_000,
                ineffectivePermitsByTarget: ['alt.terminal' => $strikes],
            );

            self::assertNull($target, $message);
        }
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

    /** @return array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int} */
    private function history(int $attempts, float $yield): array
    {
        return [
            'attempts' => $attempts,
            'ewma_nzbs_per_10k' => $yield,
            'last_attempt_at' => 1_999_999_000,
            'last_effective_at' => $yield > 0 ? 1_999_999_000 : 0,
            'last_cursor_delta' => 10_000,
        ];
    }
}
