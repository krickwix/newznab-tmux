<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Models\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Atomically consumes the one-shot permit immediately before backfill work.
 */
class BackfillPermitGate
{
    public function claim(): bool
    {
        return DB::transaction(function (): bool {
            $rows = Settings::query()
                ->whereIn('name', [
                    'orchestrator_mode',
                    'orchestrator_lease_until',
                    'orchestrator_bf_paused',
                    'orchestrator_bf_permit',
                    'orchestrator_bf_group',
                ])
                ->lockForUpdate()
                ->get()
                ->mapWithKeys(fn (Settings $setting): array => [
                    $setting->name => $setting->getRawOriginal('value'),
                ]);

            if ((string) $rows->get('orchestrator_mode', '') !== 'active'
                || (int) $rows->get('orchestrator_lease_until', 0) < time()
                || (int) $rows->get('orchestrator_bf_paused', 1) !== 0
                || (int) $rows->get('orchestrator_bf_permit', 0) <= 0
                || trim((string) $rows->get('orchestrator_bf_group', '')) === '') {
                return false;
            }

            Settings::query()
                ->where('name', 'orchestrator_bf_permit')
                ->update(['value' => '0']);
            Settings::forgetCachedSettings();

            return true;
        }, 3);
    }
}
