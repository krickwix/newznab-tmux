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

    private const string BACKFILL_CONTEXT_REPEAT_KEY = 'nntmux:orchestrator:backfill-context-repeat';

    private const string BACKFILL_DELAYED_ATTRIBUTION_KEY = 'nntmux:orchestrator:backfill-delayed-attribution';

    private const int BACKFILL_DELAYED_ATTRIBUTION_LIMIT = 16;

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
            processedBackfillPermitGenerations: $this->processedPermitGenerations($data['processed_permit_generations'] ?? []),
            qualifiedSupplyStarved: (bool) ($data['qualified_supply_starved'] ?? false),
            qualifiedSupplyCandidateSince: max(0, (int) ($data['qualified_supply_candidate_since'] ?? 0)),
            qualifiedSupplyStarvedSince: max(0, (int) ($data['qualified_supply_starved_since'] ?? 0)),
            qualifiedSupplyLastObservedAt: max(0, (int) ($data['qualified_supply_last_observed_at'] ?? 0)),
            qualifiedSupplyRecoverySamples: max(0, (int) ($data['qualified_supply_recovery_samples'] ?? 0)),
            qualifiedSupplyColdStartAt: max(0, (int) ($data['qualified_supply_cold_start_at'] ?? 0)),
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
            'processed_permit_generations' => array_slice($state->processedBackfillPermitGenerations, -64),
            'qualified_supply_starved' => $state->qualifiedSupplyStarved,
            'qualified_supply_candidate_since' => $state->qualifiedSupplyCandidateSince,
            'qualified_supply_starved_since' => $state->qualifiedSupplyStarvedSince,
            'qualified_supply_last_observed_at' => $state->qualifiedSupplyLastObservedAt,
            'qualified_supply_recovery_samples' => $state->qualifiedSupplyRecoverySamples,
            'qualified_supply_cold_start_at' => $state->qualifiedSupplyColdStartAt,
        ]);
    }

    /** @return list<int> */
    private function processedPermitGenerations(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter(
            array_map('intval', $value),
            static fn (int $generation): bool => $generation > 0,
        ))), -64);
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
            'schema_version' => 5,
            'parts' => $snapshot->schedulablePartsBacklog(),
            'binaries' => $snapshot->schedulableBinariesBacklog(),
            'collections' => $snapshot->schedulableCollectionsBacklog(),
            'collections_total' => $snapshot->physicalCollectionsBacklog(),
            'recovery_sources' => $snapshot->bodyRecoverySourceBacklog,
            'releases' => $snapshot->releasesBacklog,
            'nzbs' => $snapshot->nzbsBacklog,
            'physical_parts' => $snapshot->partsBacklog,
            'physical_binaries' => $snapshot->binariesBacklog,
            'physical_collections' => $snapshot->physicalCollectionsBacklog(),
            'release_total' => $snapshot->releaseTotal,
            'release_created_total' => $snapshot->releaseCreatedTotal,
            'release_yield_per_minute' => $snapshot->releaseYieldPerMinute,
            'database_deadlocks' => $snapshot->databaseDeadlocks,
            'database_current_waits' => $snapshot->databaseCurrentWaits,
            'database_row_lock_waits' => $snapshot->databaseRowLockWaits,
            'database_row_lock_delta' => $snapshot->databaseRowLockDelta,
            'database_row_lock_instant_rate' => $snapshot->databaseRowLockInstantRate,
            'database_row_lock_window_started_at' => $snapshot->databaseRowLockWindowStartedAt,
            'database_row_lock_window_start_count' => $snapshot->databaseRowLockWindowStartCount,
            'database_row_lock_window_rate' => $snapshot->databaseRowLockWindowRate,
            'database_row_lock_admission_blocked' => $snapshot->databaseRowLockAdmissionBlocked,
            'database_row_lock_hard_breach_at' => $snapshot->databaseRowLockHardBreachAt,
            'database_current_wait_started_at' => $snapshot->databaseCurrentWaitStartedAt,
            'database_admission_safe' => $snapshot->databaseAdmissionSafe,
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

    /** @param array{cursor: int, cursor_postdate: string, ready_collections: int, releases: int, release_high_watermark: int, group_active?: int, raw_collections?: int, raw_binaries?: int, partial_collections?: int, complete_binaries?: int} $outcome */
    public function beginPermitObservation(PipelineSnapshot $snapshot, int $generation, int $now, array $outcome, int $quantity = 10000): void
    {
        $priorReleaseCohort = $this->takeIncompleteReleaseCohort($snapshot->backfillGroup, $now);
        $requestedQuantity = max(10_000, $quantity);
        $expectedCursorDelta = $snapshot->backfillRemainingArticles > 0
            ? min($requestedQuantity, max(0, $snapshot->backfillRemainingArticles - 10_000))
            : $requestedQuantity;
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
            'backfill_group_active' => (int) ($outcome['group_active'] ?? 1),
            'raw_collections' => (int) ($outcome['raw_collections'] ?? 0),
            'raw_binaries' => (int) ($outcome['raw_binaries'] ?? 0),
            'partial_collections' => (int) ($outcome['partial_collections'] ?? 0),
            'complete_binaries' => (int) ($outcome['complete_binaries'] ?? 0),
            'baseline_deadlocks' => $snapshot->databaseDeadlocks,
            'safety_clean' => $this->growthTelemetrySafe($snapshot, $snapshot->databaseDeadlocks),
            'backfill_group' => $snapshot->backfillGroup,
            'backfill_cursor' => $outcome['cursor'],
            'backfill_cursor_postdate' => $outcome['cursor_postdate'],
            'backfill_quantity' => $requestedQuantity,
            'backfill_expected_cursor_delta' => $expectedCursorDelta,
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

    /**
     * @param  array<string, mixed>  $observation
     * @param  array<string, mixed>  $outcome
     */
    public function queueBackfillDelayedAttribution(
        array $observation,
        array $outcome,
        int $cursorDelta,
        int $now,
        bool $contextContinuation = false,
    ): bool {
        $generation = (int) ($observation['generation'] ?? 0);
        $group = trim((string) ($observation['backfill_group'] ?? ''));
        $quantity = (int) ($observation['backfill_quantity'] ?? 0);
        $expectedCursorDelta = array_key_exists('backfill_expected_cursor_delta', $observation)
            ? (int) $observation['backfill_expected_cursor_delta']
            : $quantity;
        $startPostdate = (string) ($observation['backfill_cursor_postdate'] ?? '');
        $endPostdate = (string) ($outcome['cursor_postdate'] ?? '');
        if ($generation <= 0
            || $group === ''
            || $now <= 0
            || $quantity < 10_000
            || $expectedCursorDelta <= 0
            || $cursorDelta !== $expectedCursorDelta
            || strtotime($startPostdate) === false
            || strtotime($endPostdate) === false
        ) {
            return false;
        }
        if ($contextContinuation
            && ($quantity !== 10_000 || $expectedCursorDelta !== 10_000 || $cursorDelta !== 10_000)
        ) {
            return false;
        }

        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        if (in_array($generation, $this->settledBackfillGenerations(), true)) {
            return false;
        }
        $ledger = $cache->get(self::BACKFILL_DELAYED_ATTRIBUTION_KEY);
        if (! is_array($ledger)) {
            $ledger = [];
        }
        $key = (string) $generation;
        if (isset($ledger[$key]) && is_array($ledger[$key])) {
            $existing = $ledger[$key];

            return trim((string) ($existing['group'] ?? '')) === $group
                && (int) ($existing['generation'] ?? 0) === $generation
                && (int) ($existing['release_high_watermark'] ?? -1) === max(0, (int) ($observation['release_high_watermark'] ?? 0))
                && strtotime((string) ($existing['cursor_start_postdate'] ?? '')) === strtotime($startPostdate)
                && strtotime((string) ($existing['cursor_end_postdate'] ?? '')) === strtotime($endPostdate)
                && (int) ($existing['cursor_delta'] ?? 0) === $cursorDelta;
        }
        foreach ($ledger as $entryKey => $entry) {
            if (! is_array($entry) || trim((string) ($entry['group'] ?? '')) !== $group) {
                continue;
            }

            $continuations = $this->backfillContinuationWindows($entry);
            if ($continuations === null) {
                return false;
            }
            $existingContinuation = null;
            $existingContinuationIndex = null;
            foreach ($continuations as $index => $continuation) {
                if ((int) $continuation['generation'] === $generation) {
                    $existingContinuation = $continuation;
                    $existingContinuationIndex = $index;
                    break;
                }
            }
            if (is_array($existingContinuation)) {
                $samePayload = strtotime((string) $existingContinuation['start_postdate']) === strtotime($startPostdate)
                    && strtotime((string) $existingContinuation['end_postdate']) === strtotime($endPostdate)
                    && (int) $existingContinuation['cursor_delta'] === $cursorDelta;
                $marker = $samePayload ? $this->backfillContextRepeat($now) : null;
                $predecessorGeneration = $existingContinuationIndex === 0
                    ? (int) ($entry['generation'] ?? 0)
                    : (int) ($continuations[$existingContinuationIndex - 1]['generation'] ?? 0);
                if ($samePayload && (int) ($marker['generation'] ?? 0) === $predecessorGeneration) {
                    $this->clearBackfillContextRepeat($group);
                }

                return $samePayload;
            }
            $marker = $contextContinuation ? $this->backfillContextRepeat($now) : null;
            $chainCount = 1 + count($continuations);
            $lastExtendedAt = $continuations === []
                ? (int) ($entry['queued_at'] ?? 0)
                : (int) ($continuations[array_key_last($continuations)]['queued_at'] ?? 0);
            $latestGeneration = $continuations === []
                ? (int) ($entry['generation'] ?? 0)
                : (int) $continuations[array_key_last($continuations)]['generation'];
            $rootCursorDelta = (int) ($entry['cursor_delta'] ?? 0)
                - array_sum(array_column($continuations, 'cursor_delta'));
            if ((string) ($marker['group'] ?? '') !== $group
                || (int) ($marker['generation'] ?? 0) !== $latestGeneration
                || $chainCount >= $this->backfillContextMaxChainWindows()
                || $rootCursorDelta !== 10_000
                || (int) ($marker['marked_at'] ?? 0) < $lastExtendedAt
                || strtotime((string) ($entry['cursor_end_postdate'] ?? '')) !== strtotime($startPostdate)
            ) {
                return false;
            }

            $continuations[] = [
                'generation' => $generation,
                'start_postdate' => $startPostdate,
                'end_postdate' => $endPostdate,
                'cursor_delta' => $cursorDelta,
                'queued_at' => $now,
            ];
            $extended = [
                ...$entry,
                'schema_version' => 2,
                'settle_after' => max(
                    (int) ($entry['settle_after'] ?? 0),
                    $now + (int) config('nntmux.orchestrator.backfill_delayed_attribution_seconds', 9_000),
                ),
                'quality_grace_started_at' => $now,
                'cursor_end_postdate' => $endPostdate,
                'cursor_delta' => (int) ($entry['cursor_delta'] ?? 0) + $cursorDelta,
                'chain_count' => $chainCount + 1,
                'continuations' => $continuations,
            ];
            unset(
                $extended['continuation_generation'],
                $extended['continuation_start_postdate'],
                $extended['continuation_cursor_delta'],
                $extended['productive_drain_started_at'],
                $extended['productive_drain_signature'],
            );
            $ledger[$entryKey] = $extended;
            $cache->forever(self::BACKFILL_DELAYED_ATTRIBUTION_KEY, $ledger);
            $this->clearBackfillContextRepeat($group);

            return true;
        }
        if (count($ledger) >= self::BACKFILL_DELAYED_ATTRIBUTION_LIMIT) {
            return false;
        }

        $ledger[$key] = [
            'schema_version' => 1,
            'generation' => $generation,
            'group' => $group,
            'queued_at' => $now,
            'settle_after' => $now + (int) config('nntmux.orchestrator.backfill_delayed_attribution_seconds', 9_000),
            'quality_grace_started_at' => $now,
            'release_high_watermark' => max(0, (int) ($observation['release_high_watermark'] ?? 0)),
            'cursor_start_postdate' => $startPostdate,
            'cursor_end_postdate' => $endPostdate,
            'cursor_delta' => $cursorDelta,
        ];
        $cache->forever(self::BACKFILL_DELAYED_ATTRIBUTION_KEY, $ledger);

        return true;
    }

    public function observeBackfillProductiveDrain(
        int $generation,
        int $targetNzbs,
        int $createdReleases,
        int $now,
    ): int {
        if ($generation <= 0 || $targetNzbs <= 0 || $createdReleases < $targetNzbs || $now <= 0) {
            return 0;
        }

        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        $ledger = $cache->get(self::BACKFILL_DELAYED_ATTRIBUTION_KEY);
        $key = (string) $generation;
        if (! is_array($ledger) || ! isset($ledger[$key]) || ! is_array($ledger[$key])) {
            return 0;
        }

        $signature = $targetNzbs.':'.$createdReleases;
        $entry = $ledger[$key];
        $startedAt = (int) ($entry['productive_drain_started_at'] ?? 0);
        if ((string) ($entry['productive_drain_signature'] ?? '') === $signature
            && $startedAt > 0
            && $startedAt <= $now
        ) {
            return $startedAt;
        }

        $ledger[$key] = [
            ...$entry,
            'productive_drain_started_at' => $now,
            'productive_drain_signature' => $signature,
        ];
        $cache->forever(self::BACKFILL_DELAYED_ATTRIBUTION_KEY, $ledger);

        return $now;
    }

    public function clearBackfillProductiveDrain(int $generation): void
    {
        if ($generation <= 0) {
            return;
        }

        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        $ledger = $cache->get(self::BACKFILL_DELAYED_ATTRIBUTION_KEY);
        $key = (string) $generation;
        if (! is_array($ledger)
            || ! isset($ledger[$key])
            || ! is_array($ledger[$key])
            || (! array_key_exists('productive_drain_started_at', $ledger[$key])
                && ! array_key_exists('productive_drain_signature', $ledger[$key]))
        ) {
            return;
        }

        unset($ledger[$key]['productive_drain_started_at'], $ledger[$key]['productive_drain_signature']);
        $cache->forever(self::BACKFILL_DELAYED_ATTRIBUTION_KEY, $ledger);
    }

    /** @return list<string> */
    public function pendingBackfillDelayedAttributionGroups(): array
    {
        $ledger = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->get(self::BACKFILL_DELAYED_ATTRIBUTION_KEY);
        if (! is_array($ledger)) {
            return [];
        }

        $groups = [];
        foreach ($ledger as $entry) {
            if (is_array($entry)
                && in_array((int) ($entry['schema_version'] ?? 0), [1, 2], true)
                && (int) ($entry['generation'] ?? 0) > 0
                && trim((string) ($entry['group'] ?? '')) !== ''
            ) {
                $groups[] = trim((string) $entry['group']);
            }
        }

        return array_values(array_unique($groups));
    }

    public function backfillDelayedAttributionGenerationRole(string $group, int $generation): ?string
    {
        $group = trim($group);
        if ($group === '' || $generation <= 0) {
            return null;
        }
        $ledger = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->get(self::BACKFILL_DELAYED_ATTRIBUTION_KEY);
        if (! is_array($ledger)) {
            return null;
        }

        foreach ($ledger as $entry) {
            if (! is_array($entry)
                || ! in_array((int) ($entry['schema_version'] ?? 0), [1, 2], true)
                || trim((string) ($entry['group'] ?? '')) !== $group
            ) {
                continue;
            }
            $continuations = $this->backfillContinuationWindows($entry);
            if ($continuations === null) {
                continue;
            }
            if ((int) ($entry['generation'] ?? 0) === $generation) {
                return 'root';
            }
            foreach ($continuations as $continuation) {
                if ((int) $continuation['generation'] === $generation) {
                    return 'continuation';
                }
            }
        }

        return null;
    }

    public function backfillDelayedAttributionCanContinue(string $group, int $now): bool
    {
        return $this->continuableBackfillDelayedAttribution($group, $now) !== null;
    }

    public function backfillDelayedAttributionExpectedCursorPostdate(string $group, int $now): ?string
    {
        $entry = $this->continuableBackfillDelayedAttribution($group, $now);

        return $entry === null ? null : (string) $entry['cursor_end_postdate'];
    }

    /** @return array<string, mixed>|null */
    private function continuableBackfillDelayedAttribution(string $group, int $now): ?array
    {
        $group = trim($group);
        $marker = $this->backfillContextRepeat($now);
        if ($group === '' || (string) ($marker['group'] ?? '') !== $group) {
            return null;
        }

        $ledger = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->get(self::BACKFILL_DELAYED_ATTRIBUTION_KEY);
        if (! is_array($ledger)) {
            return null;
        }

        foreach ($ledger as $entry) {
            if (! is_array($entry)
                || ! in_array((int) ($entry['schema_version'] ?? 0), [1, 2], true)
                || trim((string) ($entry['group'] ?? '')) !== $group
                || (int) ($entry['generation'] ?? 0) <= 0
                || (int) ($entry['settle_after'] ?? 0) <= $now
                || (int) ($marker['marked_at'] ?? 0) < (int) ($entry['queued_at'] ?? 0)
            ) {
                continue;
            }

            $continuations = $this->backfillContinuationWindows($entry);
            if ($continuations === null) {
                continue;
            }
            $lastExtendedAt = $continuations === []
                ? (int) ($entry['queued_at'] ?? 0)
                : (int) ($continuations[array_key_last($continuations)]['queued_at'] ?? 0);
            $latestGeneration = $continuations === []
                ? (int) ($entry['generation'] ?? 0)
                : (int) $continuations[array_key_last($continuations)]['generation'];
            $rootCursorDelta = (int) ($entry['cursor_delta'] ?? 0)
                - array_sum(array_column($continuations, 'cursor_delta'));
            if (1 + count($continuations) >= $this->backfillContextMaxChainWindows()
                || (int) ($marker['generation'] ?? 0) !== $latestGeneration
                || (int) ($marker['marked_at'] ?? 0) < $lastExtendedAt
                || $rootCursorDelta !== 10_000
            ) {
                continue;
            }

            return $entry;
        }

        return null;
    }

    private function backfillContextMaxChainWindows(): int
    {
        return min(4, max(2, (int) config('nntmux.orchestrator.backfill_context_max_chain_windows', 3)));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array{generation: int, start_postdate: string, end_postdate: string, cursor_delta: int, queued_at: int}>|null
     */
    private function backfillContinuationWindows(array $entry): ?array
    {
        $schemaVersion = (int) ($entry['schema_version'] ?? 0);
        if ($schemaVersion === 2) {
            if (! is_array($entry['continuations'] ?? null)) {
                return null;
            }
            $windows = [];
            $previousEnd = null;
            $generations = [(int) ($entry['generation'] ?? 0)];
            foreach ($entry['continuations'] as $continuation) {
                if (! is_array($continuation)
                    || (int) ($continuation['generation'] ?? 0) <= 0
                    || strtotime((string) ($continuation['start_postdate'] ?? '')) === false
                    || strtotime((string) ($continuation['end_postdate'] ?? '')) === false
                    || (int) ($continuation['cursor_delta'] ?? 0) !== 10_000
                    || (int) ($continuation['queued_at'] ?? 0) <= 0
                    || in_array((int) $continuation['generation'], $generations, true)
                    || ($previousEnd !== null
                        && strtotime($previousEnd) !== strtotime((string) $continuation['start_postdate']))
                ) {
                    return null;
                }
                $windows[] = [
                    'generation' => (int) $continuation['generation'],
                    'start_postdate' => (string) $continuation['start_postdate'],
                    'end_postdate' => (string) $continuation['end_postdate'],
                    'cursor_delta' => (int) $continuation['cursor_delta'],
                    'queued_at' => max(0, (int) ($continuation['queued_at'] ?? 0)),
                ];
                $generations[] = (int) $continuation['generation'];
                $previousEnd = (string) $continuation['end_postdate'];
            }
            if ($windows === []
                || count($windows) >= $this->backfillContextMaxChainWindows()
                || (int) ($entry['chain_count'] ?? 0) !== 1 + count($windows)
                || (int) ($entry['cursor_delta'] ?? 0) !== 10_000 + 10_000 * count($windows)
                || strtotime((string) ($entry['cursor_end_postdate'] ?? '')) !== strtotime((string) $windows[array_key_last($windows)]['end_postdate'])
            ) {
                return null;
            }

            return $windows;
        }

        $continuationGeneration = (int) ($entry['continuation_generation'] ?? 0);
        if ($continuationGeneration <= 0) {
            return (int) ($entry['chain_count'] ?? 1) === 1 ? [] : null;
        }
        $startPostdate = (string) ($entry['continuation_start_postdate'] ?? '');
        $endPostdate = (string) ($entry['cursor_end_postdate'] ?? '');
        $cursorDelta = (int) ($entry['continuation_cursor_delta'] ?? 0);
        if ($schemaVersion !== 1
            || $continuationGeneration === (int) ($entry['generation'] ?? 0)
            || (int) ($entry['chain_count'] ?? 0) !== 2
            || strtotime($startPostdate) === false
            || strtotime($endPostdate) === false
            || $cursorDelta !== 10_000
            || (int) ($entry['cursor_delta'] ?? 0) !== 20_000
        ) {
            return null;
        }

        return [[
            'generation' => $continuationGeneration,
            'start_postdate' => $startPostdate,
            'end_postdate' => $endPostdate,
            'cursor_delta' => $cursorDelta,
            'queued_at' => max(0, (int) ($entry['quality_grace_started_at'] ?? $entry['queued_at'] ?? 0)),
        ]];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<int>
     */
    public function backfillDelayedAttributionGenerations(array $entry): array
    {
        $root = (int) ($entry['generation'] ?? 0);
        $continuations = $this->backfillContinuationWindows($entry);
        if ($root <= 0 || $continuations === null) {
            return [];
        }

        return $this->processedPermitGenerations([
            $root,
            ...array_column($continuations, 'generation'),
        ]);
    }

    /** @return array<string, int|string>|null */
    public function matureBackfillDelayedAttribution(int $now): ?array
    {
        $ledger = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->get(self::BACKFILL_DELAYED_ATTRIBUTION_KEY);
        if (! is_array($ledger)) {
            return null;
        }

        $mature = array_values(array_filter($ledger, fn (mixed $entry): bool => is_array($entry)
            && in_array((int) ($entry['schema_version'] ?? 0), [1, 2], true)
            && (int) ($entry['generation'] ?? 0) > 0
            && (int) ($entry['settle_after'] ?? 0) > 0
            && (int) $entry['settle_after'] <= $now
            && $this->backfillDelayedAttributionGenerations($entry) !== []));
        usort($mature, static fn (array $left, array $right): int => (int) $left['settle_after'] <=> (int) $right['settle_after']);

        return $mature[0] ?? null;
    }

    /** @return list<array<string, int|string>> */
    public function immatureBackfillDelayedAttributions(int $now): array
    {
        $ledger = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->get(self::BACKFILL_DELAYED_ATTRIBUTION_KEY);
        if (! is_array($ledger)) {
            return [];
        }

        $immature = array_values(array_filter($ledger, fn (mixed $entry): bool => is_array($entry)
            && in_array((int) ($entry['schema_version'] ?? 0), [1, 2], true)
            && (int) ($entry['generation'] ?? 0) > 0
            && (int) ($entry['settle_after'] ?? 0) > $now
            && $this->backfillDelayedAttributionGenerations($entry) !== []));
        usort($immature, static fn (array $left, array $right): int => [
            (int) ($left['queued_at'] ?? 0),
            (int) ($left['generation'] ?? 0),
        ] <=> [
            (int) ($right['queued_at'] ?? 0),
            (int) ($right['generation'] ?? 0),
        ]);

        return $immature;
    }

    public function completeBackfillDelayedAttribution(int $generation): bool
    {
        if ($generation <= 0) {
            return false;
        }
        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        $ledger = $cache->get(self::BACKFILL_DELAYED_ATTRIBUTION_KEY);
        $key = (string) $generation;
        if (! is_array($ledger) || ! isset($ledger[$key])) {
            return false;
        }
        $completedEntry = $ledger[$key];
        $group = is_array($completedEntry) ? trim((string) ($completedEntry['group'] ?? '')) : '';
        unset($ledger[$key]);
        $ledger === []
            ? $cache->forget(self::BACKFILL_DELAYED_ATTRIBUTION_KEY)
            : $cache->forever(self::BACKFILL_DELAYED_ATTRIBUTION_KEY, $ledger);
        if ($group !== '') {
            $this->clearBackfillContextRepeat($group);
        }

        return true;
    }

    public function markBackfillContextRepeat(string $group, int $now, int $generation = 0): void
    {
        $group = trim($group);
        if ($group === '' || $now <= 0) {
            return;
        }

        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->forever(self::BACKFILL_CONTEXT_REPEAT_KEY, [
                'group' => $group,
                'marked_at' => $now,
                'generation' => max(0, $generation),
            ]);
    }

    /** @return array{group: string, marked_at: int, generation: int}|null */
    public function backfillContextRepeat(int $now): ?array
    {
        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        $marker = $cache->get(self::BACKFILL_CONTEXT_REPEAT_KEY);
        $group = is_array($marker) ? trim((string) ($marker['group'] ?? '')) : '';
        $markedAt = is_array($marker) ? (int) ($marker['marked_at'] ?? 0) : 0;
        $generation = is_array($marker) ? max(0, (int) ($marker['generation'] ?? 0)) : 0;
        $ttl = max(1, (int) config('nntmux.orchestrator.backfill_yield_ttl_seconds', 86_400));
        if ($group === '' || $markedAt <= 0 || $markedAt > $now || $now - $markedAt >= $ttl) {
            if ($marker !== null) {
                $cache->forget(self::BACKFILL_CONTEXT_REPEAT_KEY);
            }

            return null;
        }

        return ['group' => $group, 'marked_at' => $markedAt, 'generation' => $generation];
    }

    public function clearBackfillContextRepeat(string $group): bool
    {
        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        $marker = $cache->get(self::BACKFILL_CONTEXT_REPEAT_KEY);
        if (! is_array($marker) || trim((string) ($marker['group'] ?? '')) !== trim($group)) {
            return false;
        }

        return $cache->forget(self::BACKFILL_CONTEXT_REPEAT_KEY);
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
            && $snapshot->databaseAdmissionSafe
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
            if (! is_string($group) || str_starts_with($group, '_') || ! is_array($entry)) {
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

    /** @param list<int> $relatedGenerations */
    public function recordBackfillYield(
        string $group,
        int $cursorDelta,
        int $nzbCreatedDelta,
        int $now,
        int $generation = 0,
        array $relatedGenerations = [],
    ): void {
        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        $settledGenerations = $this->settledBackfillGenerations();
        if ($generation > 0 && in_array($generation, $settledGenerations, true)) {
            return;
        }
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
        if ($generation > 0) {
            $history['_settled_generations'] = $this->processedPermitGenerations([
                ...$settledGenerations,
                $generation,
                ...$relatedGenerations,
            ]);
        } elseif ($settledGenerations !== []) {
            $history['_settled_generations'] = $settledGenerations;
        }

        $cache->forever(self::BACKFILL_YIELD_KEY, $history);
    }

    /** @return list<int> */
    private function settledBackfillGenerations(): array
    {
        $raw = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))
            ->get(self::BACKFILL_YIELD_KEY);

        return is_array($raw) && is_array($raw['_settled_generations'] ?? null)
            ? $this->processedPermitGenerations($raw['_settled_generations'])
            : [];
    }

    public function markBackfillTargetAttempted(string $group, int $now): void
    {
        if ($group === '') {
            return;
        }

        $cache = Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'));
        $raw = $cache->get(self::BACKFILL_YIELD_KEY);
        $settledGenerations = is_array($raw) && is_array($raw['_settled_generations'] ?? null)
            ? $this->processedPermitGenerations($raw['_settled_generations'])
            : [];
        $history = $this->backfillYieldHistory();
        $entry = $history[$group] ?? [
            'attempts' => 0,
            'ewma_nzbs_per_10k' => 0.0,
            'last_attempt_at' => 0,
            'last_effective_at' => 0,
            'last_cursor_delta' => 0,
        ];
        $entry['last_attempt_at'] = max(0, $now);
        $history[$group] = $entry;
        uasort($history, static fn (array $left, array $right): int => $right['last_attempt_at'] <=> $left['last_attempt_at']);

        $history = array_slice($history, 0, 16, preserve_keys: true);
        if ($settledGenerations !== []) {
            $history['_settled_generations'] = $settledGenerations;
        }
        $cache->forever(self::BACKFILL_YIELD_KEY, $history);
    }

    /** @param array<string, mixed> $decision */
    public function storeDecision(array $decision): void
    {
        Cache::store((string) config('nntmux.orchestrator.state_store', 'redis'))->forever(self::DECISION_KEY, $decision);
    }
}
