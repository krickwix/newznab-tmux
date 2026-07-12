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

    /** @param list<string> $probeGroups */
    public function __construct(
        ?array $probeGroups = null,
        ?int $historyTtlSeconds = null,
        ?int $exploitAttemptsBeforeExplore = null,
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
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $candidates
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @param  array<string, int>  $ineffectivePermitsByTarget
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}|null
     */
    public function select(array $candidates, array $history, int $now, array $ineffectivePermitsByTarget = []): ?array
    {
        $candidates = array_values(array_filter(
            $candidates,
            static function (array $candidate) use ($now, $ineffectivePermitsByTarget): bool {
                $timestamp = strtotime($candidate['cursor_postdate']);

                return $candidate['cursor'] > 0
                    && $candidate['remaining_articles'] >= 20_000
                    && $timestamp !== false
                    && (int) substr($candidate['cursor_postdate'], 0, 4) >= 2000
                    && $timestamp <= $now
                    && (int) ($ineffectivePermitsByTarget[$candidate['name']] ?? 0) < WorkerControlPolicy::INEFFECTIVE_BACKFILL_LIMIT;
            },
        ));
        if ($candidates === []) {
            return null;
        }

        $byName = [];
        foreach ($candidates as $candidate) {
            $byName[$candidate['name']] = $candidate;
        }

        $positive = array_values(array_filter(
            $candidates,
            function (array $candidate) use ($history, $now): bool {
                $entry = $history[$candidate['name']] ?? null;

                return is_array($entry)
                    && $now - (int) ($entry['last_attempt_at'] ?? 0) < $this->historyTtlSeconds
                    && (float) ($entry['ewma_nzbs_per_10k'] ?? 0.0) > 0.0;
            },
        ));
        if ($positive !== []) {
            usort($positive, static function (array $left, array $right) use ($history): int {
                $score = (float) $history[$right['name']]['ewma_nzbs_per_10k']
                    <=> (float) $history[$left['name']]['ewma_nzbs_per_10k'];

                return $score !== 0 ? $score : $left['name'] <=> $right['name'];
            });

            $best = $positive[0];
            $attempts = (int) ($history[$best['name']]['attempts'] ?? 0);
            if ($attempts > 0
                && $attempts % $this->exploitAttemptsBeforeExplore === 0
                && $this->wasMostRecentlyAttempted($best['name'], $history)
            ) {
                $probe = $this->selectConfiguredProbe($byName, $history, $now);
                if ($probe !== null) {
                    return $probe;
                }
                $untried = $this->selectUntried($candidates, $history, $now);
                if ($untried !== null) {
                    return $untried;
                }
            }

            return $best;
        }

        $probe = $this->selectConfiguredProbe($byName, $history, $now);
        if ($probe !== null) {
            return $probe;
        }

        $untried = $this->selectUntried($candidates, $history, $now);
        if ($untried !== null) {
            return $untried;
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
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}|null
     */
    private function selectUntried(array $candidates, array $history, int $now): ?array
    {
        $untried = array_values(array_filter(
            $candidates,
            function (array $candidate) use ($history, $now): bool {
                $entry = $history[$candidate['name']] ?? null;

                return ! is_array($entry)
                    || $now - (int) ($entry['last_attempt_at'] ?? 0) >= $this->historyTtlSeconds;
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
     * @param  array<string, array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $byName
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}|null
     */
    private function selectConfiguredProbe(array $byName, array $history, int $now): ?array
    {
        foreach ($this->probeGroups as $group) {
            $entry = $history[$group] ?? null;
            $isRecent = is_array($entry)
                && $now - (int) ($entry['last_attempt_at'] ?? 0) < $this->historyTtlSeconds;
            if (isset($byName[$group]) && ! $isRecent) {
                return $byName[$group];
            }
            if (isset($byName[$group])
                && $isRecent
                && (int) ($entry['attempts'] ?? 0) === 1
                && (int) ($entry['last_cursor_delta'] ?? 0) > 0
                && (float) ($entry['ewma_nzbs_per_10k'] ?? 0.0) <= 0.0
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
