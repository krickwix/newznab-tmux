<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

final readonly class BackfillTargetSelector
{
    /** @var list<string> */
    private array $probeGroups;

    private int $historyTtlSeconds;

    /** @param list<string> $probeGroups */
    public function __construct(
        ?array $probeGroups = null,
        ?int $historyTtlSeconds = null,
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
    }

    /**
     * @param  list<array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}>  $candidates
     * @param  array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}>  $history
     * @return array{name: string, cursor: int, cursor_postdate: string, remaining_articles: int}|null
     */
    public function select(array $candidates, array $history, int $now): ?array
    {
        $candidates = array_values(array_filter(
            $candidates,
            static function (array $candidate) use ($now): bool {
                $timestamp = strtotime($candidate['cursor_postdate']);

                return $candidate['cursor'] > 0
                    && $candidate['remaining_articles'] >= 20_000
                    && $timestamp !== false
                    && (int) substr($candidate['cursor_postdate'], 0, 4) >= 2000
                    && $timestamp <= $now;
            },
        ));
        if ($candidates === []) {
            return null;
        }

        $byName = [];
        foreach ($candidates as $candidate) {
            $byName[$candidate['name']] = $candidate;
        }
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

            return $positive[0];
        }

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

        usort($candidates, static function (array $left, array $right) use ($history): int {
            $attempt = (int) $history[$left['name']]['last_attempt_at']
                <=> (int) $history[$right['name']]['last_attempt_at'];

            return $attempt !== 0 ? $attempt : $left['name'] <=> $right['name'];
        });

        return $candidates[0];
    }
}
