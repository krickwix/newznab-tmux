<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Models\Settings;
use App\Services\Orchestrator\BackfillStopCursorPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Atomically consumes the one-shot permit immediately before backfill work.
 */
class BackfillPermitGate
{
    public function claim(): bool
    {
        return $this->claimGeneration() !== null;
    }

    public function claimGeneration(): ?int
    {
        return DB::transaction(function (): ?int {
            $rows = Settings::query()
                ->whereIn('name', [
                    'orchestrator_mode',
                    'orchestrator_lease_until',
                    'orchestrator_bf_paused',
                    'orchestrator_bf_permit',
                    'orchestrator_bf_group',
                    'orchestrator_bf_qty',
                    'orchestrator_bf_stop',
                ])
                ->lockForUpdate()
                ->get()
                ->mapWithKeys(fn (Settings $setting): array => [
                    $setting->name => $setting->getRawOriginal('value'),
                ]);

            $group = trim((string) $rows->get('orchestrator_bf_group', ''));
            $policy = new BackfillStopCursorPolicy;
            $configuredStop = $policy->stopCursor($group) ?? 0;
            $pinnedStop = (int) $rows->get('orchestrator_bf_stop', 0);
            if (! $policy->isValid()
                || $configuredStop !== $pinnedStop
                || (string) $rows->get('orchestrator_mode', '') !== 'active'
                || (int) $rows->get('orchestrator_lease_until', 0) < time()
                || (int) $rows->get('orchestrator_bf_paused', 1) !== 0
                || (int) $rows->get('orchestrator_bf_permit', 0) <= 0
                || $group === ''
                || (int) $rows->get('orchestrator_bf_qty', 0) < 10000) {
                return null;
            }

            $generation = (int) $rows->get('orchestrator_bf_permit');
            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bf_claimed'],
                ['value' => (string) $generation],
            );
            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bfc_group'],
                ['value' => $group],
            );
            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bfc_qty'],
                ['value' => (string) (int) $rows->get('orchestrator_bf_qty')],
            );
            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bfc_stop'],
                ['value' => (string) $pinnedStop],
            );
            Settings::query()->where('name', 'orchestrator_bf_permit')->update(['value' => '0']);
            Settings::forgetCachedSettings();

            return $generation;
        }, 3);
    }

    public function complete(int $generation): bool
    {
        return DB::transaction(function () use ($generation): bool {
            $claimed = (int) Settings::query()
                ->where('name', 'orchestrator_bf_claimed')
                ->lockForUpdate()
                ->value('value');
            if ($generation <= 0 || $claimed !== $generation) {
                return false;
            }

            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bf_completed'],
                ['value' => (string) $generation],
            );
            Settings::forgetCachedSettings();

            return true;
        }, 3);
    }
}
