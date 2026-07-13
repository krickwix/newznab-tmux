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

    /** @param list<string> $probeGroups */
    public function __construct(
        ?array $probeGroups = null,
        ?int $historyTtlSeconds = null,
        ?int $exploitAttemptsBeforeExplore = null,
        ?float $aggressiveExploreBelowYield = null,
        ?int $lockRetrySeconds = null,
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
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $candidates
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @param  array<string, int>  $ineffectivePermitsByTarget
     * @param  array{group: string, marked_at: int}|null  $contextRepeat
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

                return $candidate['cursor'] > 0
                    && $candidate['remaining_articles'] >= 20_000
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

            return $candidate;
        }, $candidates);

        $byName = [];
        foreach ($candidates as $candidate) {
            $byName[$candidate['name']] = $candidate;
        }

        $repeatGroup = trim((string) ($contextRepeat['group'] ?? ''));
        $repeatMarkedAt = (int) ($contextRepeat['marked_at'] ?? 0);
        if ($repeatGroup !== ''
            && $repeatMarkedAt > 0
            && $repeatMarkedAt <= $now
            && $now - $repeatMarkedAt < $this->historyTtlSeconds
            && isset($byName[$repeatGroup])
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

        usort($candidates, static function (array $left, array $right) use ($history): int {
            $attempt = (int) $history[$left['name']]['last_attempt_at']
                <=> (int) $history[$right['name']]['last_attempt_at'];

            return $attempt !== 0 ? $attempt : $left['name'] <=> $right['name'];
        });

        return $candidates[0];
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
