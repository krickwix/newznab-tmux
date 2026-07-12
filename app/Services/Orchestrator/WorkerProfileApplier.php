<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;
use Illuminate\Support\Facades\DB;

class WorkerProfileApplier
{
    private const int SAFE_HIGH_PRESSURE_RECOVERY_TIMER_SECONDS = 115;

    public function apply(
        ControlDecision $decision,
        int $now,
        bool $grantPermit,
        ?string $backfillGroup = null,
        bool $preserveUnclaimedPermit = false,
        ?int $backfillQuantity = null,
    ): int {
        return DB::transaction(function () use ($decision, $now, $grantPermit, $backfillGroup, $preserveUnclaimedPermit, $backfillQuantity): int {
            $generation = (int) Settings::query()
                ->where('name', 'orchestrator_generation')
                ->lockForUpdate()
                ->value('value') + 1;

            $profile = $decision->profile;
            $safeHighPressureRecovery = $profile->profile === ControlProfile::FailSafe
                && in_array('high_pressure_sample', $decision->reasons, true)
                && $decision->nextState->failSafeCause === FailSafeCause::Telemetry;
            $existingPermit = (int) Settings::query()
                ->where('name', 'orchestrator_bf_permit')
                ->lockForUpdate()
                ->value('value');
            $existingClaimed = (int) Settings::query()
                ->where('name', 'orchestrator_bf_claimed')
                ->lockForUpdate()
                ->value('value');
            $existingCompleted = (int) Settings::query()
                ->where('name', 'orchestrator_bf_completed')
                ->lockForUpdate()
                ->value('value');
            $existingGroup = (string) Settings::query()
                ->where('name', 'orchestrator_bf_group')
                ->lockForUpdate()
                ->value('value');
            $existingPinnedQuantity = (int) Settings::query()
                ->where('name', 'orchestrator_bf_qty')
                ->lockForUpdate()
                ->value('value');
            $permit = ($decision->backfillPermitted || $preserveUnclaimedPermit)
                ? ($grantPermit ? $generation : $existingPermit)
                : 0;
            $values = [
                'orchestrator_mode' => 'active',
                'orchestrator_profile' => $profile->profile->value,
                'orchestrator_recovery_ok' => ($profile->profile !== ControlProfile::FailSafe
                    || $safeHighPressureRecovery) ? '1' : '0',
                'orchestrator_lease_until' => (string) ($now + 600),
                'orchestrator_generation' => (string) $generation,
                'orchestrator_bins_timer' => (string) ($safeHighPressureRecovery
                    ? self::SAFE_HIGH_PRESSURE_RECOVERY_TIMER_SECONDS
                    : $profile->binariesSleepSeconds),
                'orchestrator_back_timer' => (string) $profile->backfillSleepSeconds,
                'orchestrator_rel_timer' => (string) $profile->releasesSleepSeconds,
                'orchestrator_nzb_timer' => (string) $profile->nzbSleepSeconds,
                'orchestrator_nzb_limit' => (string) $profile->nzbBatchSize,
                'orchestrator_bf_paused' => $decision->backfillPermitted ? '0' : '1',
                'orchestrator_bf_permit' => (string) $permit,
                'orchestrator_bf_claimed' => $grantPermit ? '0' : (string) $existingClaimed,
                'orchestrator_bf_completed' => $grantPermit ? '0' : (string) $existingCompleted,
                'orchestrator_bf_group' => $grantPermit ? (string) $backfillGroup : $existingGroup,
                'orchestrator_bf_qty' => (string) ($grantPermit
                    ? max(10000, $backfillQuantity ?? $profile->backfillQuantity)
                    : $existingPinnedQuantity),
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
}
