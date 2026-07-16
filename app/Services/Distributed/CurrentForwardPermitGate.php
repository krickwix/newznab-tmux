<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Models\Settings;
use App\Services\Orchestrator\CurrentForwardStopCursorPolicy;
use App\Services\Orchestrator\PipelineSnapshot;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Atomically issues and consumes exact windows from ranked immutable corridors. */
final class CurrentForwardPermitGate
{
    private const int PROVIDER_RESERVE = 20_000;

    /** @return array{granted:bool,reason:string,generation:int,group:string,first:int,last:int,stop:int} */
    public function issue(PipelineSnapshot $snapshot, int $generation): array
    {
        $denied = static fn (string $reason): array => [
            'granted' => false,
            'reason' => $reason,
            'generation' => 0,
            'group' => '',
            'first' => 0,
            'last' => 0,
            'stop' => 0,
        ];
        if ($generation <= 0) {
            return $denied('invalid_generation');
        }
        if (! $snapshot->telemetryIsValid()
            || ! $snapshot->hardSafetyPassed()
            || ! $snapshot->lowPressure
            || $snapshot->highPressure
            || $snapshot->databaseCurrentWaits > 0
            || $snapshot->releasesBacklog > 0
            || $snapshot->eligibleNzbs > 0
        ) {
            return $denied('pipeline_not_drained');
        }
        if (! $snapshot->databaseAdmissionSafe) {
            return $denied('database_admission_blocked');
        }

        $policy = new CurrentForwardStopCursorPolicy;
        $groups = $policy->groups();
        if (! $policy->isValid() || $groups === []) {
            return $denied('invalid_window_policy');
        }

        return DB::transaction(function () use ($denied, $generation, $groups, $policy): array {
            $this->ensureSettings();
            $settings = $this->lockedSettings();
            if ((string) $settings->get('orchestrator_profile') === 'fail_safe'
                || (int) $settings->get('orchestrator_recovery_ok') !== 1
            ) {
                return $denied('controller_not_recovered');
            }
            if ((string) $settings->get('orchestrator_mode') !== 'active'
                || (int) $settings->get('orchestrator_lease_until') < time()
                || (int) $settings->get('orchestrator_bf_permit') !== 0
            ) {
                return $denied('permit_conflict_or_stale_lease');
            }
            $activePermit = (int) $settings->get('orchestrator_cf_permit');
            if ($activePermit > 0) {
                $issuedAt = (int) $settings->get('orchestrator_cf_issued_at');
                $timeout = $this->claimTimeoutSeconds();
                if ($issuedAt <= 0
                    || time() - $issuedAt < $timeout
                    || $this->currentForwardWorkerIsActive()
                ) {
                    return $denied('permit_conflict_or_stale_lease');
                }
                Settings::query()->where('name', 'orchestrator_cf_permit')->update(['value' => '0']);
                Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_failed'], ['value' => (string) $activePermit]);
                Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_failure'], ['value' => 'unclaimed_timeout']);
                Settings::forgetCachedSettings();
            }
            if ((int) $settings->get('orchestrator_cf_halt') === 1) {
                return $denied('current_forward_quarantine_full');
            }
            $blocks = array_values(array_filter(explode(',', (string) $settings->get('orchestrator_cf_blocks'))));
            $claimed = (int) $settings->get('orchestrator_cf_claimed');
            if ($claimed > 0
                && $claimed !== (int) $settings->get('orchestrator_cf_completed')
                && $claimed !== (int) $settings->get('orchestrator_cf_failed')
            ) {
                $issuedAt = (int) $settings->get('orchestrator_cf_issued_at');
                $timeout = $this->claimTimeoutSeconds();
                if ($issuedAt <= 0
                    || time() - $issuedAt < $timeout
                    || $this->currentForwardWorkerIsActive()
                ) {
                    return $denied('current_forward_in_progress');
                }
                [$blocks, $overflow] = $this->appendBlock(
                    $blocks,
                    $this->windowIdentity(
                        (string) $settings->get('orchestrator_cf_group'),
                        (int) $settings->get('orchestrator_cf_first'),
                        (int) $settings->get('orchestrator_cf_last'),
                    ),
                );
                Settings::query()->where('name', 'orchestrator_cf_permit')->update(['value' => '0']);
                Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_failed'], ['value' => (string) $claimed]);
                Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_failure'], ['value' => 'claim_timeout']);
                Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_blocks'], ['value' => implode(',', $blocks)]);
                if ($overflow) {
                    Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_halt'], ['value' => '1']);
                    Settings::forgetCachedSettings();

                    return $denied('current_forward_quarantine_full');
                }
                Settings::forgetCachedSettings();
            }
            $group = '';
            $window = null;
            $lastReason = 'current_forward_corridors_exhausted';
            foreach ($groups as $candidateGroup) {
                $groupRow = DB::table('usenet_groups')->where('name', $candidateGroup)->lockForUpdate()->first();
                if ($groupRow === null || (int) $groupRow->active !== 0 || (int) $groupRow->backfill !== 1) {
                    $lastReason = 'group_not_inactive_backfill';

                    continue;
                }
                $corridor = $policy->window($candidateGroup);
                $cursor = (int) $groupRow->last_record;
                if ($corridor === null) {
                    return $denied('invalid_window_policy');
                }
                if ($cursor === $corridor['last']) {
                    $lastReason = 'current_forward_corridors_exhausted';

                    continue;
                }
                if ($cursor < $corridor['first'] - 1
                    || $cursor > $corridor['last']
                    || ($cursor - ($corridor['first'] - 1)) % 10_000 !== 0
                ) {
                    return $denied('cursor_drift');
                }
                $candidateWindow = $policy->nextWindow($candidateGroup, $cursor);
                if ($candidateWindow === null) {
                    return $denied('cursor_drift');
                }
                $block = $this->windowIdentity($candidateGroup, $candidateWindow['first'], $candidateWindow['last']);
                if (in_array($block, $blocks, true)) {
                    $lastReason = 'current_forward_window_quarantined';

                    continue;
                }
                $provider = DB::table('short_groups')->where('name', $candidateGroup)->lockForUpdate()->first();
                if (! $this->providerCoversWindow($provider, $candidateWindow['first'], $candidateWindow['last'])) {
                    $lastReason = 'provider_range_drift';

                    continue;
                }
                $group = $candidateGroup;
                $window = $candidateWindow;

                break;
            }
            if ($group === '' || $window === null) {
                return $denied($lastReason);
            }

            foreach ([
                'orchestrator_cf_gen' => $generation,
                'orchestrator_cf_permit' => $generation,
                'orchestrator_cf_group' => $group,
                'orchestrator_cf_first' => $window['first'],
                'orchestrator_cf_last' => $window['last'],
                'orchestrator_cf_stop' => $window['stop'],
                'orchestrator_cf_issued_at' => time(),
                'orchestrator_cf_failure' => '',
            ] as $name => $value) {
                Settings::query()->updateOrCreate(['name' => $name], ['value' => (string) $value]);
            }
            Settings::forgetCachedSettings();

            return [
                'granted' => true,
                'reason' => 'current_forward_permit_granted',
                'generation' => $generation,
                'group' => $group,
                ...$window,
            ];
        }, 3);
    }

    /** @return array{generation:int,group:string,first:int,last:int,stop:int}|null */
    public function claim(int $generation, string $group, int $first, int $last): ?array
    {
        return DB::transaction(function () use ($generation, $group, $first, $last): ?array {
            $this->ensureSettings();
            $settings = $this->lockedSettings();
            $stop = (int) $settings->get('orchestrator_cf_stop');
            $policy = new CurrentForwardStopCursorPolicy;
            if ($generation <= 0
                || ! $policy->isValid()
                || ! $policy->matches($group, $first, $last, $stop)
                || (string) $settings->get('orchestrator_mode') !== 'active'
                || (string) $settings->get('orchestrator_profile') === 'fail_safe'
                || (int) $settings->get('orchestrator_recovery_ok') !== 1
                || (int) $settings->get('orchestrator_lease_until') < time()
                || (int) $settings->get('orchestrator_bf_permit') !== 0
                || (int) $settings->get('orchestrator_cf_permit') !== $generation
                || (string) $settings->get('orchestrator_cf_group') !== $group
                || (int) $settings->get('orchestrator_cf_first') !== $first
                || (int) $settings->get('orchestrator_cf_last') !== $last
            ) {
                return null;
            }
            $groupRow = DB::table('usenet_groups')->where('name', $group)->lockForUpdate()->first();
            $provider = DB::table('short_groups')->where('name', $group)->lockForUpdate()->first();
            if ($groupRow === null
                || (int) $groupRow->active !== 0
                || (int) $groupRow->backfill !== 1
                || (int) $groupRow->last_record !== $first - 1
                || ! $this->providerCoversWindow($provider, $first, $last)
            ) {
                return null;
            }

            Settings::query()->where('name', 'orchestrator_cf_permit')->update(['value' => '0']);
            Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_claimed'], ['value' => (string) $generation]);
            Settings::forgetCachedSettings();

            return compact('generation', 'group', 'first', 'last', 'stop');
        }, 3);
    }

    public function complete(int $generation): bool
    {
        return DB::transaction(function () use ($generation): bool {
            $this->ensureSettings();
            $settings = $this->lockedSettings();
            $group = (string) $settings->get('orchestrator_cf_group');
            $last = (int) $settings->get('orchestrator_cf_last');
            $groupRow = DB::table('usenet_groups')->where('name', $group)->lockForUpdate()->first();
            if ($generation <= 0
                || (int) $settings->get('orchestrator_cf_gen') !== $generation
                || (int) $settings->get('orchestrator_cf_permit') !== 0
                || (int) $settings->get('orchestrator_cf_claimed') !== $generation
                || (int) $settings->get('orchestrator_cf_failed') === $generation
                || $groupRow === null
                || (int) $groupRow->last_record !== $last
                || (int) $groupRow->active !== 0
                || (int) $groupRow->backfill !== 1
            ) {
                return false;
            }

            Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_completed'], ['value' => (string) $generation]);
            Settings::forgetCachedSettings();

            return true;
        }, 3);
    }

    public function fail(int $generation, string $reason): bool
    {
        return DB::transaction(function () use ($generation, $reason): bool {
            $this->ensureSettings();
            $settings = $this->lockedSettings();
            if ($generation <= 0
                || (int) $settings->get('orchestrator_cf_gen') !== $generation
                || (int) $settings->get('orchestrator_cf_claimed') !== $generation
            ) {
                return false;
            }
            $block = $this->windowIdentity(
                (string) $settings->get('orchestrator_cf_group'),
                (int) $settings->get('orchestrator_cf_first'),
                (int) $settings->get('orchestrator_cf_last'),
            );
            $blocks = array_values(array_filter(explode(',', (string) $settings->get('orchestrator_cf_blocks'))));
            [$blocks, $overflow] = $this->appendBlock($blocks, $block);
            Settings::query()->where('name', 'orchestrator_cf_permit')->update(['value' => '0']);
            Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_failed'], ['value' => (string) $generation]);
            Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_failure'], ['value' => substr($reason, 0, 120)]);
            Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_blocks'], ['value' => implode(',', $blocks)]);
            if ($overflow) {
                Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_halt'], ['value' => '1']);
            }
            Settings::forgetCachedSettings();

            return true;
        }, 3);
    }

    /** @return array{generation:int,group:string,first:int,last:int}|null */
    public function pending(): ?array
    {
        $generation = (int) Settings::settingValue('orchestrator_cf_permit');
        $group = trim((string) Settings::settingValue('orchestrator_cf_group'));
        $first = (int) Settings::settingValue('orchestrator_cf_first');
        $last = (int) Settings::settingValue('orchestrator_cf_last');

        return $generation > 0 && $group !== '' && $first > 0 && $last >= $first
            ? compact('generation', 'group', 'first', 'last')
            : null;
    }

    private function providerCoversWindow(?object $provider, int $first, int $last): bool
    {
        return $provider !== null
            && strtotime((string) $provider->updated) >= time() - 600
            && (int) $provider->first_record <= $first
            && (int) $provider->last_record >= $last + self::PROVIDER_RESERVE;
    }

    private function ensureSettings(): void
    {
        foreach ([
            'orchestrator_mode' => '',
            'orchestrator_profile' => 'fail_safe',
            'orchestrator_recovery_ok' => 0,
            'orchestrator_lease_until' => 0,
            'orchestrator_bf_permit' => 0,
            'orchestrator_cf_gen' => 0,
            'orchestrator_cf_permit' => 0,
            'orchestrator_cf_claimed' => 0,
            'orchestrator_cf_completed' => 0,
            'orchestrator_cf_failed' => 0,
            'orchestrator_cf_issued_at' => 0,
            'orchestrator_cf_blocks' => '',
            'orchestrator_cf_halt' => 0,
            'orchestrator_cf_group' => '',
            'orchestrator_cf_first' => 0,
            'orchestrator_cf_last' => 0,
            'orchestrator_cf_stop' => 0,
        ] as $name => $value) {
            Settings::query()->firstOrCreate(['name' => $name], ['value' => (string) $value]);
        }
    }

    private function lockedSettings(): mixed
    {
        return Settings::query()
            ->whereIn('name', [
                'orchestrator_mode', 'orchestrator_profile', 'orchestrator_recovery_ok',
                'orchestrator_lease_until', 'orchestrator_bf_permit',
                'orchestrator_cf_gen', 'orchestrator_cf_permit', 'orchestrator_cf_claimed',
                'orchestrator_cf_completed', 'orchestrator_cf_failed', 'orchestrator_cf_group',
                'orchestrator_cf_first', 'orchestrator_cf_last', 'orchestrator_cf_stop',
                'orchestrator_cf_issued_at', 'orchestrator_cf_blocks',
                'orchestrator_cf_halt',
            ])
            ->lockForUpdate()
            ->get()
            ->mapWithKeys(fn (Settings $setting): array => [$setting->name => $setting->getRawOriginal('value')]);
    }

    private function windowIdentity(string $group, int $first, int $last): string
    {
        return $group.':'.$first.'-'.$last;
    }

    /**
     * @param  list<string>  $blocks
     * @return array{0:list<string>,1:bool}
     */
    private function appendBlock(array $blocks, string $block): array
    {
        if ($block === ':0-0' || in_array($block, $blocks, true)) {
            return [$blocks, false];
        }
        $candidate = [...$blocks, $block];
        if (strlen(implode(',', $candidate)) > 900) {
            return [$blocks, true];
        }

        return [$candidate, false];
    }

    private function currentForwardWorkerIsActive(): bool
    {
        try {
            $store = Cache::store((string) config('nntmux.distributed_lock_store', 'redis'))->getStore();
            if (! $store instanceof LockProvider) {
                return true;
            }
            $lock = $store->lock('nntmux:distributed-worker:current-forward', 5);
            if (! $lock->get()) {
                return true;
            }
            $lock->release();

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    private function claimTimeoutSeconds(): int
    {
        return max(
            (int) config('nntmux.orchestrator.current_forward_claim_timeout_seconds', 900),
            (int) config('nntmux.distributed_current_forward_max_run_seconds', 600) + 60,
        );
    }
}
