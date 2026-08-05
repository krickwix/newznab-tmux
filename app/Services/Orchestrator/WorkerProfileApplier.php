<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkerProfileApplier
{
    private const int SAFE_HIGH_PRESSURE_RECOVERY_TIMER_SECONDS = 115;

    private const int ACCELERATED_RECOVERY_TIMER_SECONDS = 20;

    public function apply(
        ControlDecision $decision,
        int $now,
        bool $grantPermit,
        ?string $backfillGroup = null,
        bool $preserveUnclaimedPermit = false,
        ?int $backfillQuantity = null,
    ): int {
        return DB::transaction(function () use ($decision, $now, $grantPermit, $backfillGroup, $preserveUnclaimedPermit, $backfillQuantity): int {
            $lockedSettings = Settings::query()
                ->whereIn('name', [
                    'orchestrator_generation',
                    'orchestrator_bf_permit',
                    'orchestrator_bf_claimed',
                    'orchestrator_bf_completed',
                    'orchestrator_bf_failed',
                    'orchestrator_bf_group',
                    'orchestrator_bf_qty',
                    'orchestrator_bf_stop',
                ])
                ->orderBy('name')
                ->lockForUpdate()
                ->get()
                ->mapWithKeys(fn (Settings $setting): array => [
                    $setting->name => $setting->getRawOriginal('value'),
                ]);
            $generation = (int) $lockedSettings->get('orchestrator_generation', 0) + 1;

            $profile = $decision->profile;
            $hardRecoveryCooldownSatisfied = in_array(
                $decision->nextState->failSafeCause,
                [FailSafeCause::Hard, FailSafeCause::Unknown],
                true,
            ) && $now >= $decision->nextState->cooldownUntil;
            $safeHighPressureRecovery = $profile->profile === ControlProfile::FailSafe
                && in_array('high_pressure_sample', $decision->reasons, true)
                && ($decision->nextState->failSafeCause === FailSafeCause::Telemetry
                    || $hardRecoveryCooldownSatisfied);
            $acceleratedRecovery = $safeHighPressureRecovery
                && in_array('core_pipeline_draining', $decision->reasons, true);
            $existingPermit = (int) $lockedSettings->get('orchestrator_bf_permit', 0);
            $existingClaimed = (int) $lockedSettings->get('orchestrator_bf_claimed', 0);
            $existingCompleted = (int) $lockedSettings->get('orchestrator_bf_completed', 0);
            $existingFailed = (int) $lockedSettings->get('orchestrator_bf_failed', 0);
            $existingGroup = (string) $lockedSettings->get('orchestrator_bf_group', '');
            $existingPinnedQuantity = (int) $lockedSettings->get('orchestrator_bf_qty', 0);
            $existingPinnedStop = (int) $lockedSettings->get('orchestrator_bf_stop', 0);
            $currentForwardUnsettled = Schema::hasTable('current_forward_windows')
                && DB::table('current_forward_windows')
                    ->whereIn('state', ['OFFERED', 'CLAIMED', 'INGESTED', 'ATTRIBUTING', 'CONTINUATION_PENDING'])
                    ->exists();
            // Free-run ignores the unsettled-window clamp too. It is the last
            // gate that can hold backfill off, and leaving it in place would
            // make free-run stall behind current-forward attribution -- the
            // exact "permitted but nothing happens" state it exists to remove.
            $freeRun = $profile->profile === ControlProfile::FreeRun;
            if ($currentForwardUnsettled && ! $freeRun) {
                $grantPermit = false;
                $preserveUnclaimedPermit = false;
            }
            $backfillAdmissionOpen = $decision->backfillPermitted && ($freeRun || ! $currentForwardUnsettled);
            $backfillStop = $grantPermit && $backfillGroup !== null
                ? ((new BackfillStopCursorPolicy)->stopCursor($backfillGroup) ?? 0)
                : $existingPinnedStop;
            $permit = ($backfillAdmissionOpen || $preserveUnclaimedPermit)
                ? ($grantPermit ? $generation : $existingPermit)
                : 0;
            $values = [
                'orchestrator_mode' => 'active',
                'orchestrator_profile' => $profile->profile->value,
                'orchestrator_recovery_ok' => ($profile->profile !== ControlProfile::FailSafe
                    || $safeHighPressureRecovery) ? '1' : '0',
                'orchestrator_lease_until' => (string) ($now + 600),
                'orchestrator_generation' => (string) $generation,
                // Published so any pod can answer "what happens if I reset the
                // pin". NNTMUX_ORCHESTRATOR_FREE_RUN is set on the orchestrator
                // deployment alone, so config() reads false everywhere else --
                // a mode CLI run from a worker pod would otherwise promise the
                // adaptive ladder and hand the fleet back to free-run.
                'orchestrator_free_run' => config('nntmux.orchestrator.free_run', false) ? '1' : '0',
                'orchestrator_bins_timer' => (string) ($safeHighPressureRecovery
                    ? ($acceleratedRecovery
                        ? self::ACCELERATED_RECOVERY_TIMER_SECONDS
                        : self::SAFE_HIGH_PRESSURE_RECOVERY_TIMER_SECONDS)
                    : $profile->binariesSleepSeconds),
                'orchestrator_back_timer' => (string) $profile->backfillSleepSeconds,
                'orchestrator_rel_timer' => (string) $profile->releasesSleepSeconds,
                'orchestrator_nzb_timer' => (string) $profile->nzbSleepSeconds,
                'orchestrator_nzb_limit' => (string) $profile->nzbBatchSize,
                'orchestrator_bf_paused' => $backfillAdmissionOpen ? '0' : '1',
                'orchestrator_bf_permit' => (string) $permit,
                'orchestrator_bf_claimed' => $grantPermit ? '0' : (string) $existingClaimed,
                'orchestrator_bf_completed' => $grantPermit ? '0' : (string) $existingCompleted,
                'orchestrator_bf_failed' => $grantPermit ? '0' : (string) $existingFailed,
                'orchestrator_bf_group' => $grantPermit ? (string) $backfillGroup : $existingGroup,
                'orchestrator_bf_qty' => (string) ($grantPermit
                    ? max(10000, $backfillQuantity ?? $profile->backfillQuantity)
                    : $existingPinnedQuantity),
                'orchestrator_bf_stop' => (string) $backfillStop,
                'backfill_groups' => (string) max(1, $profile->backfillGroups),
                'backfillthreads' => (string) max(1, $profile->backfillThreads),
                'backfill_qty' => (string) max(10000, $profile->backfillQuantity),
            ];
            if ($preserveUnclaimedPermit) {
                $values['orchestrator_bf_paused'] = '0';
                $values['orchestrator_bf_group'] = $existingGroup;
            }

            foreach ($values as $name => $value) {
                Settings::query()->updateOrCreate(['name' => $name], ['value' => $value]);
            }
            Settings::forgetCachedSettings();

            return $generation;
        }, 3);
    }

    public function failClosed(): void
    {
        DB::transaction(function (): void {
            foreach ([
                'orchestrator_mode' => 'failsafe',
                'orchestrator_profile' => 'fail_safe',
                'orchestrator_lease_until' => '0',
                'orchestrator_bf_paused' => '1',
                'orchestrator_bf_permit' => '0',
                'orchestrator_bf_group' => '',
                'orchestrator_bf_qty' => '0',
                'orchestrator_bf_stop' => '0',
                'orchestrator_cf_permit' => '0',
            ] as $name => $value) {
                Settings::query()->updateOrCreate(['name' => $name], ['value' => $value]);
            }
            Settings::forgetCachedSettings();
        }, 3);
    }

    public function revokePermit(): void
    {
        Settings::query()->updateOrCreate(['name' => 'orchestrator_bf_permit'], ['value' => '0']);
        Settings::forgetCachedSettings();
    }

    public function qualityLockBackfillTarget(string $group, string $reason = 'backfill_permit_wrong_category'): void
    {
        DB::transaction(function () use ($group, $reason): void {
            // Configured deep-backfill probe groups are intentionally backfilled
            // regardless of per-cohort category yield. Disabling `backfill` on
            // them (the default quality-lock penalty) drops them from
            // short_groups and permanently stalls the backfill candidate query,
            // so we skip the group-disable for probe groups and only pause the
            // current permit so the orchestrator advances past the bad cohort.
            $isProbeGroup = in_array(
                $group,
                (array) config('nntmux.orchestrator.backfill_probe_groups', []),
                true,
            );
            if (! $isProbeGroup) {
                DB::table('usenet_groups')
                    ->where('name', $group)
                    ->lockForUpdate()
                    ->update(['backfill' => 0]);
            }
            foreach ([
                'orchestrator_bf_permit' => '0',
                'orchestrator_bf_paused' => '1',
                'orchestrator_bf_group' => $group,
                'orchestrator_bf_quality' => $reason,
            ] as $name => $value) {
                Settings::query()->updateOrCreate(['name' => $name], ['value' => $value]);
            }
            Settings::forgetCachedSettings();
        }, 3);
    }
}
