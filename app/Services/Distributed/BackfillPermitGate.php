<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Models\Settings;
use App\Services\Orchestrator\BackfillStopCursorPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
                    'orchestrator_cf_permit',
                    'orchestrator_cf_claimed',
                    'orchestrator_cf_completed',
                ])
                ->orderBy('name')
                ->lockForUpdate()
                ->get()
                ->mapWithKeys(fn (Settings $setting): array => [
                    $setting->name => $setting->getRawOriginal('value'),
                ]);

            if (Schema::hasTable('current_forward_windows')
                && DB::table('current_forward_windows')
                    ->whereIn('state', ['OFFERED', 'CLAIMED', 'INGESTED', 'ATTRIBUTING', 'CONTINUATION_PENDING'])
                    ->exists()
            ) {
                return null;
            }
            $currentForwardClaimed = (int) $rows->get('orchestrator_cf_claimed', 0);
            if ((int) $rows->get('orchestrator_cf_permit', 0) > 0
                || ($currentForwardClaimed > 0
                    && $currentForwardClaimed !== (int) $rows->get('orchestrator_cf_completed', 0))
            ) {
                return null;
            }

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
            $quantity = (int) $rows->get('orchestrator_bf_qty');
            $envelopeFirst = max(1, $pinnedStop);
            $envelopeLast = $envelopeFirst + $quantity - 1;
            if (Schema::hasTable('usenet_groups')) {
                $cursor = (int) DB::table('usenet_groups')
                    ->where('name', $group)
                    ->lockForUpdate()
                    ->value('first_record');
                if ($cursor <= 1 || $cursor <= $pinnedStop) {
                    return null;
                }
                $envelopeLast = $cursor - 1;
                $envelopeFirst = max(1, $pinnedStop, $cursor - $quantity);
                if ($envelopeLast < $envelopeFirst) {
                    return null;
                }
            }
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
                ['value' => (string) $quantity],
            );
            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bfc_stop'],
                ['value' => (string) $pinnedStop],
            );
            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bfc_first'],
                ['value' => (string) $envelopeFirst],
            );
            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bfc_last'],
                ['value' => (string) $envelopeLast],
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
            if ((bool) config('nntmux.orchestrator.require_backfill_permit', false)
                && Schema::hasTable('backfill_execution_ranges')
            ) {
                $ranges = DB::table('backfill_execution_ranges')
                    ->where('generation', $generation)
                    ->lockForUpdate()
                    ->get();
                $quantity = (int) Settings::query()
                    ->where('name', 'orchestrator_bfc_qty')
                    ->lockForUpdate()
                    ->value('value');
                $articles = $ranges->sum(static fn (object $range): int => (int) $range->last_article - (int) $range->first_article + 1
                );
                if ($ranges->isEmpty()
                    || $ranges->contains(static fn (object $range): bool => $range->status !== 'COMPLETED')
                    || $articles <= 0
                    || $articles > $quantity
                ) {
                    return false;
                }
            }

            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bf_completed'],
                ['value' => (string) $generation],
            );
            Settings::forgetCachedSettings();

            return true;
        }, 3);
    }

    public function fail(int $generation, string $error): bool
    {
        return DB::transaction(function () use ($generation, $error): bool {
            $claimed = (int) Settings::query()
                ->where('name', 'orchestrator_bf_claimed')
                ->lockForUpdate()
                ->value('value');
            if ($generation <= 0 || $claimed !== $generation) {
                return false;
            }

            if (Schema::hasTable('backfill_execution_ranges')) {
                DB::table('backfill_execution_ranges')
                    ->where('generation', $generation)
                    ->where('status', 'CLAIMED')
                    ->update([
                        'status' => 'FAILED',
                        'error' => mb_substr($error, 0, 1000),
                        'updated_at' => now(),
                    ]);
            }
            Settings::query()->updateOrCreate(
                ['name' => 'orchestrator_bf_failed'],
                ['value' => (string) $generation],
            );
            Settings::forgetCachedSettings();

            return true;
        }, 3);
    }
}
