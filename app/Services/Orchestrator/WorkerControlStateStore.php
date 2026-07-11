<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

class WorkerControlStateStore
{
    private const string STATE_KEY = 'nntmux:orchestrator:state';

    private const string SNAPSHOT_KEY = 'nntmux:orchestrator:last-snapshot';

    private const string PERMIT_OBSERVATION_KEY = 'nntmux:orchestrator:permit-observation';

    private const string BACKFILL_YIELD_KEY = 'nntmux:orchestrator:backfill-yield';

    public const string DECISION_KEY = 'nntmux:orchestrator:last-decision';

    public function leaderLock(): Lock
    {
        return Cache::store((string) config('nntmux.orchestrator.lock_store', 'redis'))
            ->lock('nntmux:worker-orchestrator:leader', 600);
    }

    public function loadState(): ControlState
    {
        $data = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->get(self::STATE_KEY);
        if (! is_array($data)) {
            return ControlState::initial();
        }

        return new ControlState(
            profile: ControlProfile::tryFrom((string) ($data['profile'] ?? '')) ?? ControlProfile::Drain,
            consecutiveHigh: max(0, (int) ($data['consecutive_high'] ?? 0)),
            consecutiveLow: max(0, (int) ($data['consecutive_low'] ?? 0)),
            lastTransitionAt: max(0, (int) ($data['last_transition_at'] ?? 0)),
            cooldownUntil: max(0, (int) ($data['cooldown_until'] ?? 0)),
            consecutiveIneffectiveBackfillPermits: max(0, (int) ($data['ineffective_permits'] ?? 0)),
            backfillLocked: (bool) ($data['backfill_locked'] ?? false),
        );
    }

    public function storeState(ControlState $state): void
    {
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forever(self::STATE_KEY, [
            'profile' => $state->profile->value,
            'consecutive_high' => $state->consecutiveHigh,
            'consecutive_low' => $state->consecutiveLow,
            'last_transition_at' => $state->lastTransitionAt,
            'cooldown_until' => $state->cooldownUntil,
            'ineffective_permits' => $state->consecutiveIneffectiveBackfillPermits,
            'backfill_locked' => $state->backfillLocked,
        ]);
    }

    /** @return array<string, int|float>|null */
    public function previousSnapshot(): ?array
    {
        $value = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->get(self::SNAPSHOT_KEY);

        return is_array($value) ? $value : null;
    }

    public function storeSnapshot(PipelineSnapshot $snapshot): void
    {
        $value = [
            'parts' => $snapshot->partsBacklog,
            'binaries' => $snapshot->binariesBacklog,
            'collections' => $snapshot->collectionsBacklog,
            'releases' => $snapshot->releasesBacklog,
            'nzbs' => $snapshot->nzbsBacklog,
            'database_deadlocks' => $snapshot->databaseDeadlocks,
            'observed_at' => $snapshot->observedAt,
        ];
        foreach ($snapshot->backlogEwmaPerMinute as $stage => $rate) {
            $value['ewma_'.$stage] = $rate;
        }
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forever(self::SNAPSHOT_KEY, $value);
    }

    /** @return array<string, int|string>|null */
    public function permitObservation(): ?array
    {
        $value = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->get(self::PERMIT_OBSERVATION_KEY);

        return is_array($value) ? $value : null;
    }

    /** @param array{cursor: int, ready_collections: int, releases: int, nzb_created: int} $outcome */
    public function beginPermitObservation(PipelineSnapshot $snapshot, int $generation, int $now, array $outcome): void
    {
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forever(self::PERMIT_OBSERVATION_KEY, [
            'generation' => $generation,
            'issued_at' => $now,
            'parts' => $snapshot->partsBacklog,
            'binaries' => $snapshot->binariesBacklog,
            'ready_collections' => $outcome['ready_collections'],
            'release_total' => $outcome['releases'],
            'nzb_created' => $outcome['nzb_created'],
            'backfill_group' => $snapshot->backfillGroup,
            'backfill_cursor' => $outcome['cursor'],
        ]);
    }

    public function clearPermitObservation(): void
    {
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forget(self::PERMIT_OBSERVATION_KEY);
    }

    /** @return array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int}> */
    public function backfillYieldHistory(): array
    {
        $value = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->get(self::BACKFILL_YIELD_KEY);
        if (! is_array($value)) {
            return [];
        }

        $history = [];
        foreach ($value as $group => $entry) {
            if (! is_string($group) || ! is_array($entry)) {
                continue;
            }
            $history[$group] = [
                'attempts' => max(0, (int) ($entry['attempts'] ?? 0)),
                'ewma_nzbs_per_10k' => max(0.0, (float) ($entry['ewma_nzbs_per_10k'] ?? 0.0)),
                'last_attempt_at' => max(0, (int) ($entry['last_attempt_at'] ?? 0)),
                'last_effective_at' => max(0, (int) ($entry['last_effective_at'] ?? 0)),
            ];
        }

        return $history;
    }

    public function recordBackfillYield(string $group, int $cursorDelta, int $nzbCreatedDelta, int $now): void
    {
        $history = $this->backfillYieldHistory();
        $existing = $history[$group] ?? [
            'attempts' => 0,
            'ewma_nzbs_per_10k' => 0.0,
            'last_attempt_at' => 0,
            'last_effective_at' => 0,
        ];
        $yield = $cursorDelta > 0
            ? max(0, $nzbCreatedDelta) * 10_000 / $cursorDelta
            : 0.0;
        $score = $existing['attempts'] === 0
            ? $yield
            : ($existing['ewma_nzbs_per_10k'] + $yield) / 2;
        $history[$group] = [
            'attempts' => $existing['attempts'] + 1,
            'ewma_nzbs_per_10k' => round($score, 6),
            'last_attempt_at' => max(0, $now),
            'last_effective_at' => $cursorDelta > 0 && $nzbCreatedDelta > 0
                ? max(0, $now)
                : $existing['last_effective_at'],
        ];
        uasort($history, static fn (array $left, array $right): int => $right['last_attempt_at'] <=> $left['last_attempt_at']);
        $history = array_slice($history, 0, 16, preserve_keys: true);

        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->forever(self::BACKFILL_YIELD_KEY, $history);
    }

    /** @param array<string, mixed> $decision */
    public function storeDecision(array $decision): void
    {
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forever(self::DECISION_KEY, $decision);
    }
}
