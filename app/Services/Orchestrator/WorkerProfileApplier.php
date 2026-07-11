<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;
use Illuminate\Support\Facades\DB;

class WorkerProfileApplier
{
    public function apply(ControlDecision $decision, int $now, bool $grantPermit, ?string $backfillGroup = null): int
    {
        return DB::transaction(function () use ($decision, $now, $grantPermit, $backfillGroup): int {
            $generation = (int) Settings::query()
                ->where('name', 'orchestrator_generation')
                ->lockForUpdate()
                ->value('value') + 1;

            $profile = $decision->profile;
            $existingPermit = (int) Settings::query()
                ->where('name', 'orchestrator_backfill_permit')
                ->lockForUpdate()
                ->value('value');
            $permit = $decision->backfillPermitted
                ? ($grantPermit ? $generation : $existingPermit)
                : 0;
            $values = [
                'orchestrator_mode' => 'active',
                'orchestrator_profile' => $profile->profile->value,
                'orchestrator_lease_until' => (string) ($now + 600),
                'orchestrator_generation' => (string) $generation,
                'orchestrator_bins_timer' => (string) $profile->binariesSleepSeconds,
                'orchestrator_back_timer' => (string) $profile->backfillSleepSeconds,
                'orchestrator_rel_timer' => (string) $profile->releasesSleepSeconds,
                'orchestrator_nzb_timer' => (string) $profile->nzbSleepSeconds,
                'orchestrator_nzb_limit' => (string) $profile->nzbBatchSize,
                'orchestrator_backfill_paused' => $decision->backfillPermitted ? '0' : '1',
                'orchestrator_backfill_permit' => (string) $permit,
                'orchestrator_backfill_group' => $decision->backfillPermitted ? (string) $backfillGroup : '',
                'backfill_groups' => (string) max(1, $profile->backfillGroups),
                'backfillthreads' => (string) max(1, $profile->backfillThreads),
                'backfill_qty' => (string) max(10000, $profile->backfillQuantity),
            ];

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
                'orchestrator_backfill_paused' => '1',
                'orchestrator_backfill_permit' => '0',
                'orchestrator_backfill_group' => '',
            ] as $name => $value) {
                Settings::query()->updateOrCreate(['name' => $name], ['value' => $value]);
            }
            Settings::forgetCachedSettings();
        }, 3);
    }

    public function revokePermit(): void
    {
        Settings::query()->updateOrCreate(['name' => 'orchestrator_backfill_permit'], ['value' => '0']);
        Settings::forgetCachedSettings();
    }
}
