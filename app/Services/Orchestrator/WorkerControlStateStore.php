<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

class WorkerControlStateStore
{
    private const string STATE_KEY = 'nntmux:orchestrator:state';

    private const string SNAPSHOT_KEY = 'nntmux:orchestrator:last-snapshot';

    private const string PERMIT_OBSERVATION_KEY = 'nntmux:orchestrator:permit-observation';

    private const string BACKFILL_YIELD_KEY = 'nntmux:orchestrator:backfill-yield';

    private const string BACKFILL_GROWTH_KEY = 'nntmux:orchestrator:backfill-growth';

    private const string INCOMPLETE_RELEASE_COHORT_KEY = 'nntmux:orchestrator:incomplete-release-cohort';

    public const string DECISION_KEY = 'nntmux:orchestrator:last-decision';

    public function leaderLock(): Lock
    {
        /** @var LockProvider $cache */
        $cache = Cache::store((string) config('nntmux.orchestrator.lock_store', 'redis'));

        return $cache->lock(
            'nntmux:worker-orchestrator:leader',
            (int) config('nntmux.orchestrator.leader_lock_seconds', 120),
        );
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
            ineffectiveBackfillPermitsByTarget: $this->targetIneffectivePermits($data['ineffective_permits_by_target'] ?? []),
            failSafeCause: $this->failSafeCause($data),
            failSafeRecoverySamples: max(0, (int) ($data['fail_safe_recovery_samples'] ?? 0)),
            failSafeLastObservedAt: max(0, (int) ($data['fail_safe_last_observed_at'] ?? 0)),
            recoveryDrainSamples: max(0, (int) ($data['recovery_drain_samples'] ?? 0)),
            recoveryDrainHoldSamples: max(0, (int) ($data['recovery_drain_hold_samples'] ?? 0)),
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
            'ineffective_permits_by_target' => $state->ineffectiveBackfillPermitsByTarget,
            'fail_safe_cause' => $state->failSafeCause?->value,
            'fail_safe_recovery_samples' => $state->failSafeRecoverySamples,
            'fail_safe_last_observed_at' => $state->failSafeLastObservedAt,
            'recovery_drain_samples' => $state->recoveryDrainSamples,
            'recovery_drain_hold_samples' => $state->recoveryDrainHoldSamples,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function failSafeCause(array $data): ?FailSafeCause
    {
        if (($data['profile'] ?? null) !== ControlProfile::FailSafe->value) {
            return null;
        }

        return FailSafeCause::tryFrom((string) ($data['fail_safe_cause'] ?? '')) ?? FailSafeCause::Unknown;
    }

    /** @return array<string, int> */
    private function targetIneffectivePermits(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $counts = [];
        foreach ($value as $group => $count) {
            if (is_string($group) && $group !== '') {
                $counts[$group] = min(WorkerControlPolicy::INEFFECTIVE_BACKFILL_LIMIT, max(0, (int) $count));
            }
        }

        return $counts;
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
            'schema_version' => 2,
            'parts' => $snapshot->partsBacklog,
            'binaries' => $snapshot->binariesBacklog,
            'collections' => $snapshot->collectionsBacklog,
            'collections_total' => $snapshot->physicalCollectionsBacklog(),
            'recovery_sources' => $snapshot->bodyRecoverySourceBacklog,
            'releases' => $snapshot->releasesBacklog,
            'nzbs' => $snapshot->nzbsBacklog,
            'database_deadlocks' => $snapshot->databaseDeadlocks,
            'database_current_waits' => $snapshot->databaseCurrentWaits,
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

    /** @param array{cursor: int, cursor_postdate: string, ready_collections: int, releases: int, release_high_watermark: int} $outcome */
    public function beginPermitObservation(PipelineSnapshot $snapshot, int $generation, int $now, array $outcome, int $quantity = 10000): void
    {
        $priorReleaseCohort = $this->takeIncompleteReleaseCohort($snapshot->backfillGroup, $now);
        $observation = [
            'schema_version' => 2,
            'generation' => $generation,
            'issued_at' => $now,
            'parts' => $snapshot->partsBacklog,
            'binaries' => $snapshot->binariesBacklog,
            'baseline_backlogs' => [
                'parts' => $snapshot->partsBacklog,
                'binaries' => $snapshot->binariesBacklog,
                'collections' => $snapshot->physicalCollectionsBacklog(),
            ],
            'peak_backlogs' => [
                'parts' => $snapshot->partsBacklog,
                'binaries' => $snapshot->binariesBacklog,
                'collections' => $snapshot->physicalCollectionsBacklog(),
            ],
            'ready_collections' => $outcome['ready_collections'],
            'release_total' => $outcome['releases'],
            'release_high_watermark' => $outcome['release_high_watermark'],
            'baseline_deadlocks' => $snapshot->databaseDeadlocks,
            'safety_clean' => $this->growthTelemetrySafe($snapshot, $snapshot->databaseDeadlocks),
            'backfill_group' => $snapshot->backfillGroup,
            'backfill_cursor' => $outcome['cursor'],
            'backfill_cursor_postdate' => $outcome['cursor_postdate'],
            'backfill_quantity' => max(10000, $quantity),
        ];
        if ($priorReleaseCohort !== null) {
            $observation['prior_release_cohort'] = $priorReleaseCohort;
        }
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forever(self::PERMIT_OBSERVATION_KEY, $observation);
    }

    /** @param array<string, mixed> $observation */
    public function rememberIncompleteReleaseCohort(
        array $observation,
        int $releaseIdHighInclusive,
        string $cursorEndPostdate,
        int $now,
    ): void {
        $group = (string) ($observation['backfill_group'] ?? '');
        $releaseIdLowExclusive = max(0, (int) ($observation['release_high_watermark'] ?? 0));
        $cursorStartPostdate = (string) ($observation['backfill_cursor_postdate'] ?? '');
        if ($group === '' || $releaseIdHighInclusive <= $releaseIdLowExclusive || $cursorStartPostdate === '' || $cursorEndPostdate === '') {
            return;
        }

        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forever(self::INCOMPLETE_RELEASE_COHORT_KEY, [
            'group' => $group,
            'id_low_exclusive' => $releaseIdLowExclusive,
            'id_high_inclusive' => $releaseIdHighInclusive,
            'cursor_start_postdate' => $cursorStartPostdate,
            'cursor_end_postdate' => $cursorEndPostdate,
            'expires_at' => $now + (int) config('nntmux.orchestrator.permit_observation_seconds', 1200),
        ]);
    }

    /** @return array{id_low_exclusive: int, id_high_inclusive: int, cursor_start_postdate: string, cursor_end_postdate: string}|null */
    private function takeIncompleteReleaseCohort(string $group, int $now): ?array
    {
        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        $cohort = $cache->get(self::INCOMPLETE_RELEASE_COHORT_KEY);
        if (! is_array($cohort)) {
            return null;
        }
        if ((int) ($cohort['expires_at'] ?? 0) < $now) {
            $cache->forget(self::INCOMPLETE_RELEASE_COHORT_KEY);

            return null;
        }
        if ((string) ($cohort['group'] ?? '') !== $group) {
            return null;
        }
        $cache->forget(self::INCOMPLETE_RELEASE_COHORT_KEY);

        return [
            'id_low_exclusive' => max(0, (int) ($cohort['id_low_exclusive'] ?? 0)),
            'id_high_inclusive' => max(0, (int) ($cohort['id_high_inclusive'] ?? 0)),
            'cursor_start_postdate' => (string) ($cohort['cursor_start_postdate'] ?? ''),
            'cursor_end_postdate' => (string) ($cohort['cursor_end_postdate'] ?? ''),
        ];
    }

    /** @return array<string, mixed>|null */
    public function updatePermitObservationPeaks(PipelineSnapshot $snapshot): ?array
    {
        $observation = $this->permitObservation();
        if ($observation === null
            || (int) ($observation['schema_version'] ?? 0) !== 2
            || ! is_array($observation['baseline_backlogs'] ?? null)
            || ! is_array($observation['peak_backlogs'] ?? null)
        ) {
            return $observation;
        }

        foreach (['parts', 'binaries', 'collections'] as $stage) {
            $observation['peak_backlogs'][$stage] = max(
                (int) ($observation['peak_backlogs'][$stage] ?? 0),
                match ($stage) {
                    'parts' => $snapshot->partsBacklog,
                    'binaries' => $snapshot->binariesBacklog,
                    'collections' => $snapshot->physicalCollectionsBacklog(),
                },
            );
        }
        $observation['safety_clean'] = (bool) ($observation['safety_clean'] ?? false)
            && $this->growthTelemetrySafe($snapshot, (int) ($observation['baseline_deadlocks'] ?? -1));
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->forever(self::PERMIT_OBSERVATION_KEY, $observation);

        return $observation;
    }

    /** @return array<string, mixed>|null */
    public function observePermitCompletion(int $generation, int $now): ?array
    {
        $observation = $this->permitObservation();
        if ($observation === null || (int) ($observation['generation'] ?? 0) !== $generation) {
            return $observation;
        }

        if (! isset($observation['completed_observed_at'])) {
            $observation['completed_observed_at'] = $now;
            Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
                ->forever(self::PERMIT_OBSERVATION_KEY, $observation);
        }

        return $observation;
    }

    public function clearPermitObservation(): void
    {
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forget(self::PERMIT_OBSERVATION_KEY);
    }

    /** @return array{parts: int, binaries: int, collections: int} */
    public function backfillGrowthFor(string $group): array
    {
        $configured = (array) config('nntmux.orchestrator.backfill_growth_per_10k', []);
        $growth = [
            'parts' => max(1, (int) ($configured['parts'] ?? 1)),
            'binaries' => max(1, (int) ($configured['binaries'] ?? 1)),
            'collections' => max(1, (int) ($configured['collections'] ?? 1)),
        ];
        $history = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->get(self::BACKFILL_GROWTH_KEY);
        if (! is_array($history)) {
            return $growth;
        }

        $ttl = (int) config('nntmux.orchestrator.backfill_yield_ttl_seconds', 86_400);
        $candidates = [];
        $global = $history['global'] ?? null;
        if ($group === ''
            && is_array($global)
            && (int) ($global['samples'] ?? 0) >= 2
        ) {
            $candidates[] = $global;
        }
        $target = $history['targets'][$group] ?? null;
        if ($group !== ''
            && is_array($target)
            && (int) ($target['samples'] ?? 0) >= 2
        ) {
            $candidates[] = $target;
        }
        if ($group !== '' && is_array($target)) {
            $learned = $this->learnedGrowthEnvelope($target, $growth, time());
            if ($learned !== null) {
                return $learned;
            }
        }
        foreach ($candidates as $candidate) {
            foreach (array_keys($growth) as $stage) {
                if (time() - (int) ($candidate['observed_at'][$stage] ?? 0) >= $ttl) {
                    continue;
                }
                $growth[$stage] = max(
                    $growth[$stage],
                    (int) ceil(max(0, (int) ($candidate['growth'][$stage] ?? 0)) * 1.25),
                );
            }
        }

        return $growth;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array{parts: int, binaries: int, collections: int}  $configured
     * @return array{parts: int, binaries: int, collections: int}|null
     */
    private function learnedGrowthEnvelope(array $target, array $configured, int $now): ?array
    {
        $samples = $target['recent_samples'] ?? null;
        if (! is_array($samples)) {
            return null;
        }
        $ttl = (int) config('nntmux.orchestrator.backfill_yield_ttl_seconds', 86_400);
        $recent = [];
        foreach ($samples as $sample) {
            if (! is_array($sample)
                || (int) ($sample['schema_version'] ?? 0) !== 1
                || (int) ($sample['generation'] ?? 0) <= 0
                || (int) ($sample['observed_at'] ?? 0) <= 0
                || (int) $sample['observed_at'] > $now
                || $now - (int) $sample['observed_at'] >= $ttl
                || (int) ($sample['requested_quantity'] ?? 0) < 10_000
                || (int) ($sample['cursor_delta'] ?? 0) !== (int) $sample['requested_quantity']
                || ($sample['safety_clean'] ?? false) !== true
            ) {
                continue;
            }
            $valid = true;
            foreach (array_keys($configured) as $stage) {
                $valid = $valid && isset($sample[$stage]) && is_numeric($sample[$stage]) && (int) $sample[$stage] >= 0;
            }
            if ($valid) {
                $recent[] = $sample;
            }
        }
        $recent = array_values(array_reduce($recent, static function (array $unique, array $sample): array {
            $unique[(int) $sample['generation']] = $sample;

            return $unique;
        }, []));
        $minimumSamples = (int) config('nntmux.orchestrator.backfill_growth_learning_min_samples', 12);
        if (count($recent) < $minimumSamples) {
            return null;
        }
        $latest = max(array_map(static fn (array $sample): int => (int) $sample['observed_at'], $recent));
        if ($now - $latest > (int) config('nntmux.orchestrator.backfill_growth_learning_latest_sample_seconds', 7200)) {
            return null;
        }

        $multiplier = (float) config('nntmux.orchestrator.backfill_growth_learning_safety_multiplier', 2.0);
        $floorFraction = (float) config('nntmux.orchestrator.backfill_growth_learning_prior_floor_fraction', 0.25);
        $envelope = [];
        foreach (array_keys($configured) as $stage) {
            $maximum = max(array_map(static fn (array $sample): int => (int) $sample[$stage], $recent));
            $envelope[$stage] = max(
                1,
                (int) ceil($configured[$stage] * $floorFraction),
                (int) ceil($maximum * $multiplier),
            );
        }

        return $envelope;
    }

    /** @param array<string, mixed> $observation */
    public function recordBackfillGrowth(
        string $group,
        array $observation,
        int $cursorDelta,
        int $requestedQuantity,
    ): bool {
        $baseline = $observation['baseline_backlogs'] ?? null;
        $peak = $observation['peak_backlogs'] ?? null;
        $generation = (int) ($observation['generation'] ?? 0);
        if ($group === ''
            || (int) ($observation['schema_version'] ?? 0) !== 2
            || ! is_array($baseline)
            || ! is_array($peak)
            || $generation <= 0
            || $cursorDelta < 10_000
            || $cursorDelta !== $requestedQuantity
            || (int) ($observation['backfill_quantity'] ?? 0) !== $requestedQuantity
            || ($observation['safety_clean'] ?? false) !== true
        ) {
            return false;
        }

        foreach (['parts', 'binaries', 'collections'] as $stage) {
            if (! isset($baseline[$stage], $peak[$stage])
                || ! is_numeric($baseline[$stage])
                || ! is_numeric($peak[$stage])
                || (int) $baseline[$stage] < 0
                || (int) $peak[$stage] < (int) $baseline[$stage]
            ) {
                return false;
            }
        }

        $configured = (array) config('nntmux.orchestrator.backfill_growth_per_10k', []);
        $configuredGrowth = [
            'parts' => max(1, (int) ($configured['parts'] ?? 1)),
            'binaries' => max(1, (int) ($configured['binaries'] ?? 1)),
            'collections' => max(1, (int) ($configured['collections'] ?? 1)),
        ];
        $sample = [];
        foreach (['parts', 'binaries', 'collections'] as $stage) {
            $delta = max(0, (int) ($peak[$stage] ?? 0) - (int) ($baseline[$stage] ?? 0));
            $measured = (int) ceil($delta * 10_000 / $cursorDelta);
            $sample[$stage] = $measured;
        }
        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        $history = $cache->get(self::BACKFILL_GROWTH_KEY);
        if (! is_array($history)) {
            $history = ['global' => ['samples' => 0, 'growth' => []], 'targets' => []];
        }
        $global = $history['global'] ?? ['samples' => 0, 'growth' => []];
        $target = $history['targets'][$group] ?? ['samples' => 0, 'growth' => []];
        if ((int) ($target['last_recorded_generation'] ?? 0) >= $generation) {
            return true;
        }
        $existingEnvelope = is_array($target)
            ? $this->learnedGrowthEnvelope($target, $configuredGrowth, time())
            : null;
        $recentSamples = is_array($target['recent_samples'] ?? null) ? $target['recent_samples'] : [];
        if ($existingEnvelope !== null) {
            foreach (array_keys($sample) as $stage) {
                if ($sample[$stage] > $existingEnvelope[$stage]) {
                    $recentSamples = [];
                    break;
                }
            }
        }
        $recentSamples[] = [
            'schema_version' => 1,
            'generation' => $generation,
            'observed_at' => time(),
            'requested_quantity' => $requestedQuantity,
            'cursor_delta' => $cursorDelta,
            'safety_clean' => true,
            ...$sample,
        ];
        $target['recent_samples'] = array_slice($recentSamples, -16);
        $target['last_recorded_generation'] = $generation;
        $globalGenerations = is_array($global['recent_generations'] ?? null) ? $global['recent_generations'] : [];
        $recordGlobal = ! in_array($generation, $globalGenerations, true);
        if ($recordGlobal) {
            $global['recent_generations'] = array_slice([...$globalGenerations, $generation], -64);
        }
        foreach (array_keys($sample) as $stage) {
            if ($recordGlobal && $sample[$stage] >= (int) ($global['growth'][$stage] ?? 0)) {
                $global['growth'][$stage] = $sample[$stage];
                $global['observed_at'][$stage] = time();
            }
            if ($sample[$stage] >= (int) ($target['growth'][$stage] ?? 0)) {
                $target['growth'][$stage] = $sample[$stage];
                $target['observed_at'][$stage] = time();
            }
        }
        $global['samples'] = max(0, (int) ($global['samples'] ?? 0)) + ($recordGlobal ? 1 : 0);
        $target['samples'] = max(0, (int) ($target['samples'] ?? 0)) + 1;
        $history['global'] = $global;
        $history['targets'][$group] = $target;
        $cache->forever(self::BACKFILL_GROWTH_KEY, $history);

        return true;
    }

    private function growthTelemetrySafe(PipelineSnapshot $snapshot, int $baselineDeadlocks): bool
    {
        return $snapshot->telemetryIsValid()
            && $snapshot->hardSafetyPassed()
            && ! $snapshot->highPressure
            && $snapshot->databaseCurrentWaits === 0
            && $snapshot->databaseDeadlocks === $baselineDeadlocks;
    }

    /** @return array<string, array{attempts: int, ewma_nzbs_per_10k: float, last_attempt_at: int, last_effective_at: int, last_cursor_delta: int}> */
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
                'last_cursor_delta' => max(0, (int) ($entry['last_cursor_delta'] ?? 0)),
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
            'last_cursor_delta' => 0,
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
            'last_cursor_delta' => max(0, $cursorDelta),
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
