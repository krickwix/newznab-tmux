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

    public const string DECISION_KEY = 'nntmux:orchestrator:last-decision';

    public function leaderLock(): Lock
    {
        /** @phpstan-ignore-next-line method.notFound */
        return Cache::store((string) config('nntmux.orchestrator.lock_store', 'redis'))
            ->lock('nntmux:worker-orchestrator:leader', 90);
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

    /** @return array<string, int>|null */
    public function permitObservation(): ?array
    {
        $value = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->get(self::PERMIT_OBSERVATION_KEY);

        return is_array($value) ? array_map('intval', $value) : null;
    }

    public function beginPermitObservation(PipelineSnapshot $snapshot, int $generation, int $now): void
    {
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forever(self::PERMIT_OBSERVATION_KEY, [
            'generation' => $generation,
            'issued_at' => $now,
            'parts' => $snapshot->partsBacklog,
            'binaries' => $snapshot->binariesBacklog,
            'ready_collections' => $snapshot->readyCollections,
            'release_total' => $snapshot->releaseTotal,
        ]);
    }

    public function clearPermitObservation(): void
    {
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forget(self::PERMIT_OBSERVATION_KEY);
    }

    /** @param array<string, mixed> $decision */
    public function storeDecision(array $decision): void
    {
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forever(self::DECISION_KEY, $decision);
    }
}
