<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Container\Container;

final readonly class BackfillTargetSelector
{
    /** @var list<string> */
    private array $probeGroups;

    private int $historyTtlSeconds;

    private int $exploitAttemptsBeforeExplore;

    private float $aggressiveExploreBelowYield;

    private int $lockRetrySeconds;

    private int $terminalMinAttempts;

    private float $terminalMinYield;

    private bool $fairShareNewestCursor;

    /** @param list<string> $probeGroups */
    public function __construct(
        ?array $probeGroups = null,
        ?int $historyTtlSeconds = null,
        ?int $exploitAttemptsBeforeExplore = null,
        ?float $aggressiveExploreBelowYield = null,
        ?int $lockRetrySeconds = null,
        ?int $terminalMinAttempts = null,
        ?float $terminalMinYield = null,
        ?bool $fairShareNewestCursor = null,
    ) {
        $configuredGroups = $probeGroups ?? config('nntmux.orchestrator.backfill_probe_groups', []);
        $this->probeGroups = array_values(array_filter(array_map(
            static fn (mixed $group): string => trim((string) $group),
            is_array($configuredGroups) ? $configuredGroups : [],
        )));
        $this->historyTtlSeconds = max(
            1,
            $historyTtlSeconds ?? (int) config('nntmux.orchestrator.backfill_yield_ttl_seconds', 86_400),
        );
        $container = Container::getInstance();
        $this->exploitAttemptsBeforeExplore = max(
            1,
            $exploitAttemptsBeforeExplore ?? ($container->bound('config')
                ? (int) config('nntmux.orchestrator.backfill_exploit_attempts_before_explore', 3)
                : 3),
        );
        $this->aggressiveExploreBelowYield = max(
            0.0,
            $aggressiveExploreBelowYield ?? ($container->bound('config')
                ? (float) config('nntmux.orchestrator.backfill_aggressive_explore_below_yield', 0.0)
                : 0.0),
        );
        $this->lockRetrySeconds = max(
            300,
            $lockRetrySeconds ?? ($container->bound('config')
                ? (int) config('nntmux.orchestrator.backfill_target_lock_retry_seconds', 21_600)
                : 21_600),
        );
        $this->terminalMinAttempts = max(
            3,
            $terminalMinAttempts ?? ($container->bound('config')
                ? (int) config('nntmux.orchestrator.backfill_terminal_min_attempts', 3)
                : 3),
        );
        $this->terminalMinYield = max(
            1.0,
            $terminalMinYield ?? ($container->bound('config')
                ? (float) config('nntmux.orchestrator.backfill_terminal_min_yield', 1.0)
                : 1.0),
        );
        $this->fairShareNewestCursor = $fairShareNewestCursor ?? ($container->bound('config')
            ? (bool) config('nntmux.orchestrator.backfill_fair_share_newest_cursor', false)
            : false);
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $candidates
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @param  array<string, int>  $ineffectivePermitsByTarget
     * @param  array{group: string, marked_at: int, generation?: int, expected_cursor_postdate?: string|null}|null  $contextRepeat
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int, safe_quantity?: int}|null
     */
    public function select(
        array $candidates,
        array $history,
        int $now,
        array $ineffectivePermitsByTarget = [],
        ?array $contextRepeat = null,
    ): ?array {
        $candidates = array_values(array_filter(
            $candidates,
            function (array $candidate) use ($history, $now, $ineffectivePermitsByTarget): bool {
                $timestamp = strtotime($candidate['cursor_postdate']);
                $entry = $history[$candidate['name']] ?? null;
                $lockRetryDue = is_array($entry)
                    && (int) ($entry['last_attempt_at'] ?? 0) > 0
                    && $now - (int) $entry['last_attempt_at'] >= $this->lockRetrySeconds;
                $remainingArticles = (int) $candidate['remaining_articles'];
                $rangeEligible = $remainingArticles >= 20_000
                    || $this->isTerminalPositiveCandidate(
                        $candidate,
                        $entry,
                        $now,
                        (int) ($ineffectivePermitsByTarget[$candidate['name']] ?? 0),
                    );

                return $candidate['cursor'] > 0
                    && $rangeEligible
                    && (int) ($candidate['safe_quantity'] ?? 10_000) >= 10_000
                    && $timestamp !== false
                    && (int) substr($candidate['cursor_postdate'], 0, 4) >= 2000
                    && $timestamp <= $now
                    && ($lockRetryDue
                        || (int) ($ineffectivePermitsByTarget[$candidate['name']] ?? 0) < WorkerControlPolicy::INEFFECTIVE_BACKFILL_LIMIT);
            },
        ));
        if ($candidates === []) {
            return null;
        }

        $candidates = array_map(function (array $candidate) use ($history, $now, $ineffectivePermitsByTarget): array {
            $entry = $history[$candidate['name']] ?? null;
            $candidate['lock_retry_due'] = is_array($entry)
                && (int) ($entry['last_attempt_at'] ?? 0) > 0
                && $now - (int) $entry['last_attempt_at'] >= $this->lockRetrySeconds
                && (int) ($ineffectivePermitsByTarget[$candidate['name']] ?? 0) >= WorkerControlPolicy::INEFFECTIVE_BACKFILL_LIMIT;
            if ((int) $candidate['remaining_articles'] < 20_000) {
                $candidate['safe_quantity'] = 10_000;
                $candidate['terminal_positive'] = true;
            }

            return $candidate;
        }, $candidates);

        $byName = [];
        foreach ($candidates as $candidate) {
            $byName[$candidate['name']] = $candidate;
        }

        // Fair-share mode: keep EVERY configured group's cursor moving backward
        // together instead of letting one high-yield group monopolize the single
        // per-cycle backfill slot. We round-robin by least-recently-attempted so
        // each group gets a turn, and break ties by newest cursor so the group
        // that is furthest behind in its backward walk (highest priority per the
        // "prioritize the most recent cursor date" rule) is served first.
        if ($this->fairShareNewestCursor && count($candidates) > 1) {
            $ranked = $candidates;
            usort($ranked, static function (array $left, array $right) use ($history): int {
                $leftAttempt = (int) ($history[$left['name']]['last_attempt_at'] ?? 0);
                $rightAttempt = (int) ($history[$right['name']]['last_attempt_at'] ?? 0);
                // Least-recently attempted first (round-robin across all groups).
                if ($leftAttempt !== $rightAttempt) {
                    return $leftAttempt <=> $rightAttempt;
                }
                // Tie-break: newest cursor first (largest timestamp = least
                // progressed backward = furthest from its 20y target).
                $leftTs = strtotime($left['cursor_postdate']) ?: 0;
                $rightTs = strtotime($right['cursor_postdate']) ?: 0;
                $score = $rightTs <=> $leftTs;

                return $score !== 0 ? $score : $left['name'] <=> $right['name'];
            });

            return $ranked[0];
        }

        $repeatGroup = trim((string) ($contextRepeat['group'] ?? ''));
        $repeatMarkedAt = (int) ($contextRepeat['marked_at'] ?? 0);
        $repeatExpectedPostdate = (string) ($contextRepeat['expected_cursor_postdate'] ?? '');
        if ($repeatGroup !== ''
            && $repeatMarkedAt > 0
            && $repeatMarkedAt <= $now
            && $now - $repeatMarkedAt < $this->historyTtlSeconds
            && isset($byName[$repeatGroup])
            && ($repeatExpectedPostdate === ''
                || strtotime($repeatExpectedPostdate) === strtotime($byName[$repeatGroup]['cursor_postdate']))
        ) {
            return [...$byName[$repeatGroup], 'safe_quantity' => 10_000];
        }

        $positive = array_values(array_filter(
            $candidates,
            function (array $candidate) use ($history, $now, $ineffectivePermitsByTarget): bool {
                $entry = $history[$candidate['name']] ?? null;

                return is_array($entry)
                    && $now - (int) ($entry['last_attempt_at'] ?? 0) < $this->historyTtlSeconds
                    && (float) ($entry['ewma_nzbs_per_10k'] ?? 0.0) > 0.0
                    && (int) ($ineffectivePermitsByTarget[$candidate['name']] ?? 0) === 0;
            },
        ));
        if ($positive !== []) {
            usort($positive, static function (array $left, array $right) use ($history): int {
                $score = (float) $history[$right['name']]['ewma_nzbs_per_10k']
                    <=> (float) $history[$left['name']]['ewma_nzbs_per_10k'];

                return $score !== 0 ? $score : $left['name'] <=> $right['name'];
            });

            $best = $positive[0];
            $bestYield = (float) ($history[$best['name']]['ewma_nzbs_per_10k'] ?? 0.0);
            $pendingRepeat = ($this->aggressiveExploreBelowYield <= 0.0
                || $bestYield < $this->aggressiveExploreBelowYield)
                ? $this->selectRecentPendingRepeat($candidates, $history, $now, $best['name'])
                : null;
            if ($pendingRepeat !== null) {
                $untriedProbe = $this->selectConfiguredUntried($byName, $history, $now, $ineffectivePermitsByTarget);
                if ($untriedProbe !== null) {
                    return $untriedProbe;
                }
                $untried = $this->selectUntried($candidates, $history, $now, $ineffectivePermitsByTarget);
                if ($untried !== null) {
                    return $untried;
                }

                return $pendingRepeat;
            }
            $attempts = (int) ($history[$best['name']]['attempts'] ?? 0);
            $exploreEvery = $this->aggressiveExploreBelowYield > 0.0
                && $bestYield < $this->aggressiveExploreBelowYield
                ? 1
                : $this->exploitAttemptsBeforeExplore;
            if ($attempts > 0
                && $attempts % $exploreEvery === 0
                && $this->wasMostRecentlyAttempted($best['name'], $history)
            ) {
                $probe = $this->selectConfiguredProbe($byName, $history, $now, $ineffectivePermitsByTarget);
                if ($probe !== null) {
                    return $probe;
                }
                $untried = $this->selectUntried($candidates, $history, $now, $ineffectivePermitsByTarget);
                if ($untried !== null) {
                    return $untried;
                }
            }

            return $best;
        }

        $probe = $this->selectConfiguredProbe($byName, $history, $now, $ineffectivePermitsByTarget);
        if ($probe !== null) {
            return $probe;
        }

        $untried = $this->selectUntried($candidates, $history, $now, $ineffectivePermitsByTarget);
        if ($untried !== null) {
            return $untried;
        }

        $dueRetries = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => (bool) ($candidate['lock_retry_due'] ?? false),
        ));
        if ($dueRetries !== []) {
            usort($dueRetries, static function (array $left, array $right) use ($history): int {
                $effective = (int) ($history[$right['name']]['last_effective_at'] ?? 0)
                    <=> (int) ($history[$left['name']]['last_effective_at'] ?? 0);
                if ($effective !== 0) {
                    return $effective;
                }
                $cursorBand = $right['cursor_postdate'] <=> $left['cursor_postdate'];
                if ($cursorBand !== 0) {
                    return $cursorBand;
                }

                return (int) ($history[$left['name']]['last_attempt_at'] ?? 0)
                    <=> (int) ($history[$right['name']]['last_attempt_at'] ?? 0);
            });

            return $dueRetries[0];
        }

        // Last-resort round-robin. Reached only when every earlier branch
        // declined, which happens precisely when no candidate has a clean
        // ineffective-permit count -- and a candidate with no history at all is
        // the likeliest one to be in that pool. Reading its entry unguarded
        // raised an ErrorException that propagated to WorkerOrchestrator's
        // catch-all and called failClosed(), so one historyless candidate
        // pinned the whole fleet into fail_safe every cycle until an operator
        // noticed. A missing entry means never attempted: sort it first.
        usort($candidates, static function (array $left, array $right) use ($history): int {
            $attempt = (int) ($history[$left['name']]['last_attempt_at'] ?? 0)
                <=> (int) ($history[$right['name']]['last_attempt_at'] ?? 0);

            return $attempt !== 0 ? $attempt : $left['name'] <=> $right['name'];
        });

        return $candidates[0];
    }

    /**
     * @param  array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int, safe_quantity?: int}  $candidate
     * @param  array{attempts?: int, ewma_nzbs_per_10k?: float, last_attempt_at?: int, last_effective_at?: int, last_cursor_delta?: int}|null  $entry
     */
    private function isTerminalPositiveCandidate(
        array $candidate,
        ?array $entry,
        int $now,
        int $ineffectivePermits,
    ): bool {
        $remainingArticles = (int) $candidate['remaining_articles'];
        $lastAttemptAt = (int) ($entry['last_attempt_at'] ?? 0);
        $lastEffectiveAt = (int) ($entry['last_effective_at'] ?? 0);

        return $remainingArticles > 10_000
            && $remainingArticles < 20_000
            && is_array($entry)
            && (int) ($entry['attempts'] ?? 0) >= $this->terminalMinAttempts
            && (float) ($entry['ewma_nzbs_per_10k'] ?? 0.0) >= $this->terminalMinYield
            && $lastAttemptAt > 0
            && $lastAttemptAt <= $now
            && $now - $lastAttemptAt < $this->historyTtlSeconds
            && $lastEffectiveAt > 0
            && $lastEffectiveAt <= $now
            && $ineffectivePermits === 0;
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $candidates
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @param  array<string, int>  $ineffectivePermitsByTarget
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}|null
     */
    private function selectUntried(
        array $candidates,
        array $history,
        int $now,
        array $ineffectivePermitsByTarget,
    ): ?array {
        $untried = array_values(array_filter(
            $candidates,
            function (array $candidate) use ($history, $now, $ineffectivePermitsByTarget): bool {
                $entry = $history[$candidate['name']] ?? null;

                return (int) ($ineffectivePermitsByTarget[$candidate['name']] ?? 0) === 0
                    && (! is_array($entry)
                        || $now - (int) ($entry['last_attempt_at'] ?? 0) >= $this->historyTtlSeconds);
            },
        ));
        if ($untried !== []) {
            usort($untried, static fn (array $left, array $right): int => $right['cursor_postdate'] <=> $left['cursor_postdate']
                ?: $left['name'] <=> $right['name']);

            return $untried[0];
        }

        return null;
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $candidates
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}|null
     */
    private function selectRecentPendingRepeat(array $candidates, array $history, int $now, string $bestGroup): ?array
    {
        $bestAttemptAt = (int) ($history[$bestGroup]['last_attempt_at'] ?? 0);
        $mostRecent = null;
        $mostRecentAttemptAt = 0;
        $timestampTied = false;
        foreach ($candidates as $candidate) {
            if ($candidate['name'] === $bestGroup) {
                continue;
            }
            $attemptAt = (int) ($history[$candidate['name']]['last_attempt_at'] ?? 0);
            if ($attemptAt > $mostRecentAttemptAt) {
                $mostRecent = $candidate;
                $mostRecentAttemptAt = $attemptAt;
                $timestampTied = false;
            } elseif ($attemptAt > 0 && $attemptAt === $mostRecentAttemptAt) {
                $timestampTied = true;
            }
        }
        if ($mostRecent === null || $timestampTied || $mostRecentAttemptAt <= $bestAttemptAt) {
            return null;
        }

        $entry = $history[$mostRecent['name']] ?? null;
        if (! is_array($entry)
            || $now - $mostRecentAttemptAt >= $this->historyTtlSeconds
            || (int) ($entry['attempts'] ?? 0) !== 1
            || (int) ($entry['last_cursor_delta'] ?? 0) <= 0
            || (float) ($entry['ewma_nzbs_per_10k'] ?? 0.0) > 0.0
        ) {
            return null;
        }

        return $mostRecent;
    }

    /**
     * @param  array<string, array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $byName
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @param  array<string, int>  $ineffectivePermitsByTarget
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}|null
     */
    private function selectConfiguredProbe(
        array $byName,
        array $history,
        int $now,
        array $ineffectivePermitsByTarget,
    ): ?array {
        $untried = $this->selectConfiguredUntried($byName, $history, $now, $ineffectivePermitsByTarget);
        if ($untried !== null) {
            return $untried;
        }

        foreach ($this->probeGroups as $group) {
            $entry = $history[$group] ?? null;
            if (isset($byName[$group])
                && is_array($entry)
                && (int) ($entry['attempts'] ?? 0) >= 1
                && (int) ($entry['last_cursor_delta'] ?? 0) > 0
                && (int) ($ineffectivePermitsByTarget[$group] ?? 0) === 1
            ) {
                return $byName[$group];
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $byName
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @param  array<string, int>  $ineffectivePermitsByTarget
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}|null
     */
    private function selectConfiguredUntried(
        array $byName,
        array $history,
        int $now,
        array $ineffectivePermitsByTarget,
    ): ?array {
        foreach ($this->probeGroups as $group) {
            $entry = $history[$group] ?? null;
            $isRecent = is_array($entry)
                && $now - (int) ($entry['last_attempt_at'] ?? 0) < $this->historyTtlSeconds;
            if (isset($byName[$group])
                && ! $isRecent
                && (int) ($ineffectivePermitsByTarget[$group] ?? 0) === 0
            ) {
                return $byName[$group];
            }
        }

        return null;
    }

    /** @param array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}> $history */
    private function wasMostRecentlyAttempted(string $group, array $history): bool
    {
        $lastAttemptAt = (int) ($history[$group]['last_attempt_at'] ?? 0);
        foreach ($history as $otherGroup => $entry) {
            if ($otherGroup !== $group && (int) ($entry['last_attempt_at'] ?? 0) >= $lastAttemptAt) {
                return false;
            }
        }

        return $lastAttemptAt > 0;
    }
}
