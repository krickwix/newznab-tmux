<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Models\Settings;
use App\Services\Orchestrator\BackfillDateEligibilityPolicy;
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
                    'orchestrator_profile',
                    'orchestrator_lease_until',
                    'orchestrator_bf_paused',
                    'orchestrator_bf_permit',
                    'orchestrator_bf_budget',
                    'orchestrator_bf_claimed',
                    'orchestrator_bf_completed',
                    'orchestrator_bf_failed',
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

            $backfillClaimed = (int) $rows->get('orchestrator_bf_claimed', 0);
            if ($backfillClaimed > 0
                && $backfillClaimed !== (int) $rows->get('orchestrator_bf_completed', 0)
                && $backfillClaimed !== (int) $rows->get('orchestrator_bf_failed', 0)
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
                ['name' => 'orchestrator_bfc_profile'],
                ['value' => (string) $rows->get('orchestrator_profile', '')],
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

    public function queueFreeRunSuccessor(int $generation): ?int
    {
        return DB::transaction(function () use ($generation): ?int {
            $rows = Settings::query()
                ->whereIn('name', [
                    'orchestrator_mode',
                    'orchestrator_profile',
                    'orchestrator_free_run',
                    'orchestrator_lease_until',
                    'orchestrator_generation',
                    'orchestrator_bf_paused',
                    'orchestrator_bf_permit',
                    'orchestrator_bf_budget',
                    'orchestrator_bf_claimed',
                    'orchestrator_bf_completed',
                    'orchestrator_bf_failed',
                    'orchestrator_bfc_group',
                    'orchestrator_bfc_profile',
                    'orchestrator_bfc_qty',
                    'orchestrator_bfc_stop',
                    'orchestrator_bfc_first',
                    'orchestrator_bfc_last',
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

            $currentForwardClaimed = (int) $rows->get('orchestrator_cf_claimed', 0);
            if ($generation <= 0
                || (string) $rows->get('orchestrator_mode', '') !== 'active'
                || (string) $rows->get('orchestrator_profile', '') !== 'free_run'
                || (int) $rows->get('orchestrator_free_run', 0) !== 1
                || (int) $rows->get('orchestrator_lease_until', 0) < time() + 510
                || (int) $rows->get('orchestrator_bf_paused', 1) !== 0
                || (int) $rows->get('orchestrator_bf_permit', 0) !== 0
                || (int) $rows->get('orchestrator_bf_budget', 0) < 10_000
                || (int) $rows->get('orchestrator_bf_claimed', 0) !== $generation
                || (int) $rows->get('orchestrator_bf_completed', 0) !== $generation
                || (int) $rows->get('orchestrator_bf_failed', 0) === $generation
                || (int) $rows->get('orchestrator_cf_permit', 0) !== 0
                || ($currentForwardClaimed > 0
                    && $currentForwardClaimed !== (int) $rows->get('orchestrator_cf_completed', 0))
                || (Schema::hasTable('current_forward_windows')
                    && DB::table('current_forward_windows')
                        ->whereIn('state', ['OFFERED', 'CLAIMED', 'INGESTED', 'ATTRIBUTING', 'CONTINUATION_PENDING'])
                        ->exists())
                || ! Schema::hasTable('usenet_groups')
                || ! Schema::hasTable('short_groups')
                || ! Schema::hasColumns('usenet_groups', [
                    'name', 'active', 'backfill', 'backfill_target', 'first_record', 'first_record_postdate',
                    'last_record', 'last_record_postdate',
                ])
                || ! Schema::hasColumns('short_groups', ['name', 'first_record', 'last_record', 'updated'])
            ) {
                return null;
            }

            $group = trim((string) $rows->get('orchestrator_bfc_group', ''));
            $quantity = (int) $rows->get('orchestrator_bfc_qty', 0);
            $pinnedStop = (int) $rows->get('orchestrator_bfc_stop', 0);
            $claimedFirst = (int) $rows->get('orchestrator_bfc_first', 0);
            $claimedLast = (int) $rows->get('orchestrator_bfc_last', 0);
            $stopPolicy = new BackfillStopCursorPolicy;
            if ($group === ''
                || (string) $rows->get('orchestrator_bfc_profile', '') !== 'free_run'
                || $quantity < 10_000
                || $claimedFirst <= 0
                || $claimedLast < $claimedFirst
                || ! $stopPolicy->isValid()
                || ($stopPolicy->stopCursor($group) ?? 0) !== $pinnedStop
            ) {
                return null;
            }

            $candidate = DB::table('usenet_groups as g')
                ->join('short_groups as s', 's.name', '=', 'g.name')
                ->where('g.name', $group)
                ->where(static fn ($query) => $query->where('g.active', 1)->orWhere('g.backfill', 1))
                ->lockForUpdate()
                ->first([
                    'g.active',
                    'g.backfill_target',
                    'g.first_record',
                    'g.first_record_postdate',
                    'g.last_record',
                    'g.last_record_postdate',
                    's.first_record as provider_first',
                    's.last_record as provider_last',
                    's.updated as provider_updated',
                ]);
            if ($candidate === null
                || ((int) $candidate->active !== 1 && ! $this->groupIsAllowed($group))
                || ! BackfillDateEligibilityPolicy::fromRuntime()->isPending(
                    (string) $candidate->first_record_postdate,
                    (int) $candidate->backfill_target,
                )
            ) {
                return null;
            }

            $cursor = (int) $candidate->first_record;
            $providerFirst = (int) $candidate->provider_first;
            $providerLast = (int) $candidate->provider_last;
            $groupLast = (int) $candidate->last_record;
            $providerFresh = strtotime((string) $candidate->provider_updated);
            $groupRangeAligned = $groupLast >= $providerFirst && $groupLast <= $providerLast;
            $activeRangeAligned = (int) $candidate->active === 1
                && $providerLast - $groupLast <= 10_000;
            $inactiveRangeAligned = (int) $candidate->active !== 1
                && strtotime((string) $candidate->last_record_postdate) >= strtotime('2000-01-01')
                && $groupLast < PHP_INT_MAX
                && $cursor >= $providerFirst
                && $cursor <= $groupLast + 1;
            if ($providerFresh === false
                || $providerFresh < time() - 600
                || $providerFirst <= 0
                || $providerLast < $providerFirst
                || ! $groupRangeAligned
                || (! $activeRangeAligned && ! $inactiveRangeAligned)
                || $cursor < $claimedFirst
                || $cursor > $claimedLast
            ) {
                return null;
            }

            $remainingArticles = $stopPolicy->remainingArticles(
                $group,
                $cursor,
                max(0, $cursor - $providerFirst),
            );
            $sourceQuantity = $remainingArticles > 10_000 && $remainingArticles < 20_000
                ? 10_000
                : min($quantity, intdiv(max(0, $remainingArticles - 10_000), 10_000) * 10_000);
            $quantity = min(
                (int) $rows->get('orchestrator_bf_budget', 0),
                $sourceQuantity,
            );
            if ($quantity < 10_000) {
                return null;
            }

            $nextGeneration = max($generation, (int) $rows->get('orchestrator_generation', 0)) + 1;
            foreach ([
                'orchestrator_generation' => (string) $nextGeneration,
                'orchestrator_bf_permit' => (string) $nextGeneration,
                'orchestrator_bf_group' => $group,
                'orchestrator_bf_qty' => (string) $quantity,
                'orchestrator_bf_stop' => (string) $pinnedStop,
                'orchestrator_bf_budget' => (string) max(
                    0,
                    (int) $rows->get('orchestrator_bf_budget', 0) - $quantity,
                ),
            ] as $name => $value) {
                Settings::query()->updateOrCreate(['name' => $name], ['value' => $value]);
            }
            Settings::forgetCachedSettings();

            return $nextGeneration;
        }, 3);
    }

    private function groupIsAllowed(string $group): bool
    {
        $allowed = array_values(array_unique(array_filter(array_map(
            static fn (mixed $candidate): string => trim((string) $candidate),
            (array) config('nntmux.orchestrator.backfill_probe_groups', []),
        ), static fn (string $candidate): bool => $candidate !== '')));
        if ($allowed === []) {
            return false;
        }
        foreach ($allowed as $candidate) {
            if (strcasecmp($candidate, 'all') === 0) {
                return true;
            }
        }

        return count($allowed) <= 16 && in_array($group, $allowed, true);
    }
}
