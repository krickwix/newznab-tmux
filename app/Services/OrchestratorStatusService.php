<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only projection of the adaptive worker orchestrator's published state.
 *
 * Every value here comes from something the orchestrator already publishes: the
 * durable `settings` rows written by WorkerProfileApplier and the Redis keys
 * owned by WorkerControlStateStore. PipelineSnapshotRepository::capture() is
 * deliberately never called -- it runs the heavy pipeline counts and reading it
 * from a web request would perturb the pipeline this page reports on. The page
 * therefore reports the orchestrator's own last observation and how old it is,
 * which is the honest answer anyway: a stale observation is exactly why backfill
 * stops.
 *
 * Nothing in this class writes. It must stay that way; permit granting, profile
 * changes and fail-safe recovery are CLI-only by design.
 */
class OrchestratorStatusService
{
    /**
     * The five regulated pipeline stages, in flow order.
     */
    public const PRESSURE_STAGES = ['parts', 'binaries', 'collections', 'releases', 'nzbs'];

    private const SETTING_NAMES = [
        'orchestrator_mode',
        'orchestrator_profile',
        'orchestrator_recovery_ok',
        'orchestrator_free_run',
        'orchestrator_lease_until',
        'orchestrator_generation',
        'orchestrator_bins_timer',
        'orchestrator_back_timer',
        'orchestrator_rel_timer',
        'orchestrator_nzb_timer',
        'orchestrator_nzb_limit',
        'orchestrator_bf_paused',
        'orchestrator_bf_permit',
        'orchestrator_bf_claimed',
        'orchestrator_bf_completed',
        'orchestrator_bf_failed',
        'orchestrator_bf_group',
        'orchestrator_bf_qty',
        'orchestrator_bf_stop',
        'orchestrator_bf_quality',
        'orchestrator_cf_permit',
        'orchestrator_cf_claimed',
        'orchestrator_cf_completed',
        'orchestrator_cf_failed',
        'orchestrator_cf_issued_at',
        'orchestrator_cf_blocks',
        'orchestrator_cf_halt',
        'orchestrator_cf_group',
    ];

    private const CURRENT_FORWARD_WINDOW_STATES = [
        'AUDITED',
        'OFFERED',
        'CLAIMED',
        'INGESTED',
        'ATTRIBUTING',
        'CONTINUATION_PENDING',
        'CHAINED',
        'PRODUCTIVE',
        'QUARANTINED',
    ];

    /**
     * Window states that hold a backfill permit off until they settle.
     *
     * Mirrors the clamp in WorkerProfileApplier::apply().
     */
    private const CURRENT_FORWARD_UNSETTLED_STATES = [
        'OFFERED',
        'CLAIMED',
        'INGESTED',
        'ATTRIBUTING',
        'CONTINUATION_PENDING',
    ];

    public function __construct(
        private readonly WorkerControlStateStore $store = new WorkerControlStateStore,
        private readonly DistributedWorkerTelemetry $workerTelemetry = new DistributedWorkerTelemetry,
        private readonly DistributedJobCatalog $jobCatalog = new DistributedJobCatalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(?int $now = null): array
    {
        $now ??= time();

        $settings = $this->read(fn (): array => DB::table('settings')
            ->whereIn('name', self::SETTING_NAMES)
            ->pluck('value', 'name')
            ->all());
        $state = $this->read(fn (): ControlState => $this->store->loadState());
        $decision = $this->read(fn (): array => $this->store->lastDecision());
        $observation = $this->read(fn (): ?array => $this->store->permitObservation());
        $yieldHistory = $this->read(fn (): array => $this->store->backfillYieldHistory());
        $windows = $this->currentForwardWindows();

        $freshness = $this->freshness($decision, $now);

        return [
            'generated_at' => $now,
            'sources' => [
                'settings' => $settings['available'],
                'state_store' => $state['available'],
                'decision' => $decision['available'],
                'permit_observation' => $observation['available'],
                'yield_history' => $yieldHistory['available'],
                'current_forward_windows' => $windows['available'],
            ],
            'freshness' => $freshness,
            'profile' => $this->profile($settings, $state, $decision, $freshness),
            'lease' => $this->lease($settings, $now),
            'fail_safe' => $this->failSafe($settings, $state, $now),
            'pressure' => $this->pressure($decision, $freshness),
            'backfill' => $this->backfill($settings, $observation, $now),
            'denial' => $this->denial($settings, $state, $decision, $windows, $freshness),
            'current_forward' => $this->currentForward($settings, $decision, $windows, $freshness, $now),
            'yield' => $this->yield($yieldHistory, $now),
            'safety' => $this->safety($decision, $freshness),
            'lanes' => $this->lanes($now),
        ];
    }

    /**
     * @param  array{available: bool, value: mixed}  $decision
     * @return array<string, mixed>
     */
    private function freshness(array $decision, int $now): array
    {
        $maxAge = max(1, (int) config('nntmux.orchestrator.snapshot_max_age_seconds', 180));
        $payload = is_array($decision['value']) ? $decision['value'] : [];
        $observedAt = (int) ($payload['observed_at'] ?? 0);

        if (! $decision['available'] || $payload === [] || $observedAt <= 0) {
            return [
                'available' => false,
                'observed_at' => 0,
                'age_seconds' => null,
                'max_age_seconds' => $maxAge,
                'fresh' => false,
            ];
        }

        $age = max(0, $now - $observedAt);

        return [
            'available' => true,
            'observed_at' => $observedAt,
            'age_seconds' => $age,
            'max_age_seconds' => $maxAge,
            'fresh' => $age <= $maxAge,
        ];
    }

    /**
     * @param  array{available: bool, value: mixed}  $settings
     * @param  array{available: bool, value: mixed}  $state
     * @param  array{available: bool, value: mixed}  $decision
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function profile(array $settings, array $state, array $decision, array $freshness): array
    {
        $durableMode = $this->setting($settings, 'orchestrator_mode', 'legacy');
        $durableProfile = $this->setting($settings, 'orchestrator_profile', '');
        $payload = is_array($decision['value']) ? $decision['value'] : [];

        // Same precedence the Prometheus scrape uses: a fresh controller
        // decision wins over the durable rows, except while the applier has
        // parked the fleet in fail-safe.
        $effectiveMode = $durableMode;
        $effectiveProfile = $durableProfile;
        if ($freshness['fresh'] && $durableMode !== 'failsafe') {
            $effectiveMode = (string) ($payload['mode'] ?? $effectiveMode);
            $effectiveProfile = (string) ($payload['profile'] ?? $effectiveProfile);
        }

        $enum = ControlProfile::tryFrom($effectiveProfile);
        $storedState = $state['value'] instanceof ControlState ? $state['value'] : null;

        return [
            'available' => $settings['available'],
            'mode' => $effectiveMode,
            'profile' => $effectiveProfile,
            'description' => $enum?->description(),
            'rung' => $enum?->rung(),
            'bypasses_safety' => $enum?->bypassesSafety(),
            'durable_profile' => $durableProfile,
            'redis_profile' => $storedState?->profile->value,
            'redis_available' => $state['available'],
            'generation' => $this->intSetting($settings, 'orchestrator_generation'),
            'free_run_published' => $this->setting($settings, 'orchestrator_free_run', '') === '1',
            'free_run_configured' => (bool) config('nntmux.orchestrator.free_run', false),
            'recovery_ok' => $this->setting($settings, 'orchestrator_recovery_ok', '') === '1',
            'consecutive_high' => $storedState?->consecutiveHigh,
            'consecutive_low' => $storedState?->consecutiveLow,
            'last_transition_at' => $storedState?->lastTransitionAt,
            'cooldown_until' => $storedState?->cooldownUntil,
            'timers' => [
                'binaries' => $this->intSetting($settings, 'orchestrator_bins_timer'),
                'backfill' => $this->intSetting($settings, 'orchestrator_back_timer'),
                'releases' => $this->intSetting($settings, 'orchestrator_rel_timer'),
                'nzbs' => $this->intSetting($settings, 'orchestrator_nzb_timer'),
            ],
            'nzb_batch_size' => $this->intSetting($settings, 'orchestrator_nzb_limit'),
        ];
    }

    /**
     * @param  array{available: bool, value: mixed}  $settings
     * @return array<string, mixed>
     */
    private function lease(array $settings, int $now): array
    {
        $leaseUntil = $this->intSetting($settings, 'orchestrator_lease_until');
        $leaseSeconds = 600;

        return [
            'available' => $settings['available'],
            'lease_until' => $leaseUntil,
            'remaining_seconds' => $leaseUntil > 0 ? max(0, $leaseUntil - $now) : 0,
            'age_seconds' => $leaseUntil > 0 ? max(0, $now - ($leaseUntil - $leaseSeconds)) : null,
            'expired' => $leaseUntil <= $now,
            'lock' => $this->leaderLock(),
            'lock_ttl_seconds' => max(1, (int) config('nntmux.orchestrator.leader_lock_seconds', 120)),
        ];
    }

    /**
     * The leader lock's owner token is a random per-run string, so it names no
     * pod and is not worth surfacing. Whether the lock is currently held, next
     * to the durable lease, is the part an operator can act on.
     *
     * @return array{available: bool, held: bool|null}
     */
    private function leaderLock(): array
    {
        try {
            return ['available' => true, 'held' => $this->store->leaderLock()->isLocked()];
        } catch (Throwable) {
            return ['available' => false, 'held' => null];
        }
    }

    /**
     * @param  array{available: bool, value: mixed}  $settings
     * @param  array{available: bool, value: mixed}  $state
     * @return array<string, mixed>
     */
    private function failSafe(array $settings, array $state, int $now): array
    {
        $storedState = $state['value'] instanceof ControlState ? $state['value'] : null;
        $cause = $storedState?->failSafeCause;

        return [
            'available' => $state['available'],
            'mode_failsafe' => $this->setting($settings, 'orchestrator_mode', '') === 'failsafe',
            'cause' => $cause?->value,
            'recovery_samples' => $storedState?->failSafeRecoverySamples,
            'last_observed_at' => $storedState?->failSafeLastObservedAt,
            'last_observed_age_seconds' => ($storedState !== null && $storedState->failSafeLastObservedAt > 0)
                ? max(0, $now - $storedState->failSafeLastObservedAt)
                : null,
            'recovery_drain_samples' => $storedState?->recoveryDrainSamples,
            'recovery_drain_hold_samples' => $storedState?->recoveryDrainHoldSamples,
            'cooldown_remaining_seconds' => $storedState !== null
                ? max(0, $storedState->cooldownUntil - $now)
                : null,
        ];
    }

    /**
     * @param  array{available: bool, value: mixed}  $decision
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function pressure(array $decision, array $freshness): array
    {
        $payload = is_array($decision['value']) ? $decision['value'] : [];
        if (! $freshness['available']) {
            return ['available' => false, 'fresh' => false, 'classification' => null, 'stages' => []];
        }

        $schedulable = is_array($payload['schedulable_backlogs'] ?? null) ? $payload['schedulable_backlogs'] : [];
        $physical = is_array($payload['backlogs'] ?? null) ? $payload['backlogs'] : [];
        $ewma = is_array($payload['ewma_per_minute'] ?? null) ? $payload['ewma_per_minute'] : [];
        $rates = is_array($payload['rates_per_minute'] ?? null) ? $payload['rates_per_minute'] : [];
        $ages = is_array($payload['oldest_age_seconds'] ?? null) ? $payload['oldest_age_seconds'] : [];

        $stages = [];
        foreach (self::PRESSURE_STAGES as $stage) {
            $high = (int) config('nntmux.orchestrator.high_watermarks.'.$stage, 0);
            $stages[$stage] = [
                'schedulable' => array_key_exists($stage, $schedulable) ? (int) $schedulable[$stage] : null,
                'physical' => array_key_exists($stage, $physical) ? (int) $physical[$stage] : null,
                'high_watermark' => $high > 0 ? $high : null,
                'utilisation' => ($high > 0 && array_key_exists($stage, $schedulable))
                    ? min(1.0, max(0.0, (int) $schedulable[$stage] / $high))
                    : null,
                'ewma_per_minute' => array_key_exists($stage, $ewma) ? (float) $ewma[$stage] : null,
                'rate_per_minute' => array_key_exists($stage, $rates) ? (float) $rates[$stage] : null,
                'oldest_age_seconds' => array_key_exists($stage, $ages) ? (int) $ages[$stage] : null,
                'age_slo_seconds' => ((int) config('nntmux.orchestrator.age_slo_seconds.'.$stage, 0)) ?: null,
            ];
        }

        return [
            'available' => true,
            'fresh' => (bool) $freshness['fresh'],
            'classification' => (string) ($payload['pressure'] ?? 'unknown'),
            'stages' => $stages,
            'collections_total' => array_key_exists('collections_total', $physical) ? (int) $physical['collections_total'] : null,
            'recovery_sources' => array_key_exists('recovery_sources', $physical) ? (int) $physical['recovery_sources'] : null,
        ];
    }

    /**
     * @param  array{available: bool, value: mixed}  $settings
     * @param  array{available: bool, value: mixed}  $observation
     * @return array<string, mixed>
     */
    private function backfill(array $settings, array $observation, int $now): array
    {
        $permit = $this->intSetting($settings, 'orchestrator_bf_permit');
        $claimed = $this->intSetting($settings, 'orchestrator_bf_claimed');
        $completed = $this->intSetting($settings, 'orchestrator_bf_completed');
        $failed = $this->intSetting($settings, 'orchestrator_bf_failed');
        $entry = is_array($observation['value']) ? $observation['value'] : null;

        $requested = $entry !== null ? (int) ($entry['backfill_quantity'] ?? 0) : null;
        $expectedDelta = $entry !== null ? (int) ($entry['backfill_expected_cursor_delta'] ?? 0) : null;
        $issuedAt = $entry !== null ? (int) ($entry['issued_at'] ?? 0) : null;

        return [
            'available' => $settings['available'],
            'permit_generation' => $permit,
            'granted' => $permit > 0,
            'paused' => $this->setting($settings, 'orchestrator_bf_paused', '1') === '1',
            'group' => $this->setting($settings, 'orchestrator_bf_group', ''),
            'pinned_quantity' => $this->intSetting($settings, 'orchestrator_bf_qty'),
            'stop_cursor' => $this->intSetting($settings, 'orchestrator_bf_stop'),
            'quality_lock' => $this->setting($settings, 'orchestrator_bf_quality', ''),
            'claimed_generation' => $claimed,
            'completed_generation' => $completed,
            'failed_generation' => $failed,
            'claim_state' => match (true) {
                $permit <= 0 => 'none',
                $failed === $permit => 'failed',
                $completed === $permit => 'completed',
                $claimed === $permit => 'claimed',
                default => 'unclaimed',
            },
            'observation' => [
                'available' => $observation['available'],
                'present' => $entry !== null,
                'generation' => $entry !== null ? (int) ($entry['generation'] ?? 0) : null,
                'group' => $entry !== null ? (string) ($entry['backfill_group'] ?? '') : null,
                'issued_at' => $issuedAt,
                'age_seconds' => ($issuedAt !== null && $issuedAt > 0) ? max(0, $now - $issuedAt) : null,
                'observation_window_seconds' => max(300, (int) config('nntmux.orchestrator.permit_observation_seconds', 1200)),
                'requested_articles' => $requested,
                'expected_cursor_delta' => $expectedDelta,
                'cursor' => $entry !== null ? (int) ($entry['backfill_cursor'] ?? 0) : null,
                'cursor_postdate' => $entry !== null ? (string) ($entry['backfill_cursor_postdate'] ?? '') : null,
                'ready_collections' => $entry !== null ? (int) ($entry['ready_collections'] ?? 0) : null,
                'release_high_watermark' => $entry !== null ? (int) ($entry['release_high_watermark'] ?? 0) : null,
                'baseline_backlogs' => $entry !== null && is_array($entry['baseline_backlogs'] ?? null)
                    ? $entry['baseline_backlogs']
                    : null,
                'peak_backlogs' => $entry !== null && is_array($entry['peak_backlogs'] ?? null)
                    ? $entry['peak_backlogs']
                    : null,
                'group_active' => $entry !== null ? (int) ($entry['backfill_group_active'] ?? 1) === 1 : null,
                'safety_clean' => $entry !== null ? (bool) ($entry['safety_clean'] ?? false) : null,
            ],
        ];
    }

    /**
     * @param  array{available: bool, value: mixed}  $settings
     * @param  array{available: bool, value: mixed}  $state
     * @param  array{available: bool, value: mixed}  $decision
     * @param  array{available: bool, value: mixed}  $windows
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function denial(array $settings, array $state, array $decision, array $windows, array $freshness): array
    {
        $payload = is_array($decision['value']) ? $decision['value'] : [];
        $storedState = $state['value'] instanceof ControlState ? $state['value'] : null;
        $counts = is_array($windows['value']) ? $windows['value'] : [];
        $unsettled = 0;
        foreach (self::CURRENT_FORWARD_UNSETTLED_STATES as $windowState) {
            $unsettled += (int) ($counts[$windowState] ?? 0);
        }
        $qualifiedSupply = is_array($payload['qualified_supply'] ?? null) ? $payload['qualified_supply'] : [];

        return [
            'available' => $freshness['available'] || $state['available'] || $settings['available'],
            'fresh' => (bool) $freshness['fresh'],
            'policy_permitted' => $freshness['available'] ? (bool) ($payload['backfill_permitted'] ?? false) : null,
            'permit_granted_last_cycle' => $freshness['available'] ? (bool) ($payload['permit_granted'] ?? false) : null,
            'paused_setting' => $this->setting($settings, 'orchestrator_bf_paused', '1') === '1',
            'reasons' => $freshness['available'] && is_array($payload['reasons'] ?? null)
                ? array_values(array_filter(array_map('strval', $payload['reasons'])))
                : [],
            'backfill_locked' => $storedState?->backfillLocked,
            'ineffective_permits' => $storedState?->consecutiveIneffectiveBackfillPermits,
            'ineffective_by_target' => $storedState?->ineffectiveBackfillPermitsByTarget ?? [],
            'qualified_supply_starved' => $storedState?->qualifiedSupplyStarved,
            'qualified_supply_starved_since' => $storedState?->qualifiedSupplyStarvedSince,
            'qualified_supply_recovery_samples' => $storedState?->qualifiedSupplyRecoverySamples,
            'release_yield_per_minute' => array_key_exists('release_yield_per_minute', $qualifiedSupply)
                ? $qualifiedSupply['release_yield_per_minute']
                : null,
            'current_forward_unsettled' => $windows['available'] ? $unsettled : null,
            'current_forward_windows_available' => $windows['available'],
            'require_permit' => (bool) config('nntmux.orchestrator.require_backfill_permit', false),
            'auto_backfill' => (bool) config('nntmux.orchestrator.auto_backfill', false),
        ];
    }

    /**
     * @param  array{available: bool, value: mixed}  $settings
     * @param  array{available: bool, value: mixed}  $decision
     * @param  array{available: bool, value: mixed}  $windows
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function currentForward(array $settings, array $decision, array $windows, array $freshness, int $now): array
    {
        $permit = $this->intSetting($settings, 'orchestrator_cf_permit');
        $claimed = $this->intSetting($settings, 'orchestrator_cf_claimed');
        $completed = $this->intSetting($settings, 'orchestrator_cf_completed');
        $failed = $this->intSetting($settings, 'orchestrator_cf_failed');
        $issuedAt = $this->intSetting($settings, 'orchestrator_cf_issued_at');
        $claimInProgress = $claimed > 0 && $claimed !== $completed && $claimed !== $failed;
        $counts = is_array($windows['value']) ? $windows['value'] : [];
        $payload = is_array($decision['value']) ? $decision['value'] : [];

        $blocks = array_values(array_filter(array_map(
            'trim',
            explode(',', $this->setting($settings, 'orchestrator_cf_blocks', '')),
        )));

        return [
            'available' => $settings['available'],
            'permit_generation' => $permit,
            'group' => $this->setting($settings, 'orchestrator_cf_group', ''),
            'claimed_generation' => $claimed,
            'completed_generation' => $completed,
            'failed_generation' => $failed,
            'claim_in_progress' => $claimInProgress,
            'claim_age_seconds' => ($claimInProgress && $issuedAt > 0) ? max(0, $now - $issuedAt) : null,
            'claim_timeout_seconds' => max(300, (int) config('nntmux.orchestrator.current_forward_claim_timeout_seconds', 900)),
            'halted' => $this->intSetting($settings, 'orchestrator_cf_halt') === 1,
            'quarantined_windows' => count($blocks),
            'refresh_enabled' => (bool) config('nntmux.orchestrator.current_forward_refresh_enabled', false),
            'ledger_issuance_enabled' => (bool) config('nntmux.orchestrator.current_forward_ledger_issuance_enabled', false),
            'continuation_enabled' => (bool) config('nntmux.orchestrator.current_forward_continuation_enabled', false),
            'windows_available' => $windows['available'],
            'windows' => $windows['available']
                ? array_combine(
                    self::CURRENT_FORWARD_WINDOW_STATES,
                    array_map(
                        static fn (string $windowState): int => (int) ($counts[$windowState] ?? 0),
                        self::CURRENT_FORWARD_WINDOW_STATES,
                    ),
                )
                : [],
            'last_decision_state' => ($freshness['available'] && is_array($payload['current_forward'] ?? null))
                ? $payload['current_forward']
                : null,
        ];
    }

    /**
     * @param  array{available: bool, value: mixed}  $yieldHistory
     * @return array<string, mixed>
     */
    private function yield(array $yieldHistory, int $now): array
    {
        $history = is_array($yieldHistory['value']) ? $yieldHistory['value'] : [];
        $groups = [];
        foreach ($history as $group => $entry) {
            if (! is_string($group) || ! is_array($entry)) {
                continue;
            }
            $lastAttemptAt = (int) ($entry['last_attempt_at'] ?? 0);
            $lastEffectiveAt = (int) ($entry['last_effective_at'] ?? 0);
            $groups[] = [
                'group' => $group,
                'attempts' => (int) ($entry['attempts'] ?? 0),
                'ewma_nzbs_per_10k' => (float) ($entry['ewma_nzbs_per_10k'] ?? 0.0),
                'last_cursor_delta' => (int) ($entry['last_cursor_delta'] ?? 0),
                'last_attempt_at' => $lastAttemptAt,
                'last_attempt_age_seconds' => $lastAttemptAt > 0 ? max(0, $now - $lastAttemptAt) : null,
                'last_effective_at' => $lastEffectiveAt,
                'last_effective_age_seconds' => $lastEffectiveAt > 0 ? max(0, $now - $lastEffectiveAt) : null,
            ];
        }

        return [
            'available' => $yieldHistory['available'],
            'groups' => $groups,
            'scale_min_yield' => (float) config('nntmux.orchestrator.backfill_scale_min_yield', 1.0),
            'terminal_min_yield' => (float) config('nntmux.orchestrator.backfill_terminal_min_yield', 1.0),
            'terminal_min_attempts' => (int) config('nntmux.orchestrator.backfill_terminal_min_attempts', 3),
        ];
    }

    /**
     * @param  array{available: bool, value: mixed}  $decision
     * @param  array<string, mixed>  $freshness
     * @return array<string, mixed>
     */
    private function safety(array $decision, array $freshness): array
    {
        if (! $freshness['available']) {
            return ['available' => false, 'fresh' => false];
        }

        $payload = is_array($decision['value']) ? $decision['value'] : [];
        $contention = is_array($payload['database_contention'] ?? null) ? $payload['database_contention'] : [];

        return [
            'available' => true,
            'fresh' => (bool) $freshness['fresh'],
            'admission_safe' => (bool) ($contention['admission_safe'] ?? false),
            'admission_blocked' => (bool) ($contention['admission_blocked'] ?? false),
            'row_lock_waits' => (int) ($contention['row_lock_waits'] ?? 0),
            'row_lock_delta' => (int) ($contention['row_lock_delta'] ?? 0),
            'row_lock_rate_per_minute' => (float) ($contention['window_rate_per_minute'] ?? 0.0),
            'hard_breach_at' => (int) ($contention['hard_breach_at'] ?? 0),
            'storage_available_bytes' => array_key_exists('storage_available_bytes', $payload)
                ? $payload['storage_available_bytes']
                : null,
            'eligible_nzbs' => (int) ($payload['eligible_nzbs'] ?? 0),
            'body_recovery_queue' => (int) ($payload['body_recovery_queue'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lanes(int $now): array
    {
        $jobs = $this->jobCatalog->jobs();
        $snapshot = $this->read(fn (): array => $this->workerTelemetry->snapshot(array_keys($jobs), (float) $now));
        $value = is_array($snapshot['value']) ? $snapshot['value'] : [];
        $available = $snapshot['available'] && (bool) ($value['available'] ?? false);

        if (! $available) {
            return ['available' => false, 'lanes' => []];
        }

        $lanes = [];
        foreach ($jobs as $worker => $description) {
            $entry = is_array($value['workers'][$worker] ?? null) ? $value['workers'][$worker] : [];
            // Same suppression rule the Prometheus scrape applies: an
            // in-progress marker older than the lane's own lock lifetime is a
            // leaked marker from a lost process, not a running cycle.
            $staleAfter = $worker === 'nzb-backlog'
                ? max(1, (int) config('nntmux.distributed_nzb_lock_seconds', 7200))
                : max(
                    1,
                    (int) config('nntmux.distributed_lock_seconds', 900),
                    (int) config('nntmux.distributed_long_lock_seconds', 3600),
                );
            $inProgress = (bool) ($entry['in_progress'] ?? false);
            $inProgressAge = (float) ($entry['in_progress_age_seconds'] ?? 0.0);
            $stale = $inProgress && $inProgressAge > $staleAfter;
            $lastCompleted = (float) ($entry['last_completed_timestamp_seconds'] ?? 0.0);
            $lastSuccess = (float) ($entry['last_success_timestamp_seconds'] ?? 0.0);
            $runs = is_array($entry['runs'] ?? null) ? $entry['runs'] : [];

            $lanes[] = [
                'worker' => $worker,
                'description' => $description,
                'in_progress' => $inProgress && ! $stale,
                'in_progress_age_seconds' => $stale ? null : $inProgressAge,
                'stale_marker' => $stale,
                'stale_after_seconds' => $staleAfter,
                'last_duration_seconds' => (float) ($entry['last_duration_seconds'] ?? 0.0),
                'last_completed_at' => $lastCompleted > 0 ? (int) $lastCompleted : null,
                'last_completed_age_seconds' => $lastCompleted > 0 ? max(0, $now - (int) $lastCompleted) : null,
                'last_success_at' => $lastSuccess > 0 ? (int) $lastSuccess : null,
                'last_success_age_seconds' => $lastSuccess > 0 ? max(0, $now - (int) $lastSuccess) : null,
                'runs' => array_map('intval', $runs),
                'observed' => $entry !== [],
            ];
        }

        return ['available' => true, 'lanes' => $lanes];
    }

    /**
     * Lifecycle counts for the additive current-forward ledger.
     *
     * This is the one relational read beyond the keyed `settings` lookup. It is
     * the same grouped count the Prometheus scrape already runs every interval
     * against a small orchestrator-owned ledger -- never against a bulk-loading
     * ingest table.
     *
     * @return array{available: bool, value: array<string, int>|null}
     */
    private function currentForwardWindows(): array
    {
        try {
            if (! Schema::hasTable('current_forward_windows')) {
                return ['available' => false, 'value' => null];
            }

            $counts = DB::table('current_forward_windows')
                ->selectRaw('state, COUNT(*) AS aggregate')
                ->groupBy('state')
                ->pluck('aggregate', 'state')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();

            return ['available' => true, 'value' => $counts];
        } catch (Throwable) {
            return ['available' => false, 'value' => null];
        }
    }

    /**
     * @param  array{available: bool, value: mixed}  $settings
     */
    private function setting(array $settings, string $name, string $default): string
    {
        if (! $settings['available'] || ! is_array($settings['value'])) {
            return $default;
        }

        $value = $settings['value'][$name] ?? null;

        return $value === null ? $default : trim((string) $value);
    }

    /**
     * @param  array{available: bool, value: mixed}  $settings
     */
    private function intSetting(array $settings, string $name): int
    {
        return (int) $this->setting($settings, $name, '0');
    }

    /**
     * @return array{available: bool, value: mixed}
     */
    private function read(callable $reader): array
    {
        try {
            return ['available' => true, 'value' => $reader()];
        } catch (Throwable) {
            return ['available' => false, 'value' => null];
        }
    }
}
