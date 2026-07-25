<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Models\Settings;
use App\Services\Orchestrator\CurrentForwardProviderCoverage;
use App\Services\Orchestrator\CurrentForwardRefreshTrustPolicy;
use App\Services\Orchestrator\CurrentForwardStopCursorPolicy;
use App\Services\Orchestrator\CurrentForwardWindowLineage;
use App\Services\Orchestrator\PipelineSnapshot;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Atomically issues and consumes exact windows from ranked immutable corridors. */
final class CurrentForwardPermitGate
{
    /** @return array{granted:bool,reason:string,generation:int,group:string,first:int,last:int,stop:int} */
    public function issue(PipelineSnapshot $snapshot, int $generation): array
    {
        $denied = fn (string $reason): array => $this->denied($reason);
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
        $ledgerMode = $this->ledgerIssuanceEnabled();
        $refreshPolicy = new CurrentForwardRefreshTrustPolicy;
        $groups = $ledgerMode ? $refreshPolicy->groups() : $policy->groups();
        if (($ledgerMode && ! $refreshPolicy->isValid())
            || (! $ledgerMode && ! $policy->isValid())
            || $groups === []
        ) {
            return $denied('invalid_window_policy');
        }

        return DB::transaction(function () use ($denied, $generation, $groups, $policy, $ledgerMode, $snapshot): array {
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
                || $this->backfillClaimIsUnsettled($settings)
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
                $ledgerOffer = $this->lockedLedgerWindowForGeneration($activePermit);
                if ($ledgerOffer !== null && (string) $ledgerOffer->state === 'OFFERED') {
                    $updates = [
                        'generation' => null,
                        'state' => 'AUDITED',
                        'release_baseline' => null,
                        'cursor_postdate' => null,
                        'failure_reason' => 'unclaimed_timeout_reaudit_required',
                        'offered_at' => null,
                        'updated_at' => now(),
                    ];
                    if (Schema::hasColumn('current_forward_windows', 'issued_verification_id')) {
                        $updates['issued_verification_id'] = null;
                    }
                    if ((int) ($ledgerOffer->chain_root_id ?? $ledgerOffer->id) !== (int) $ledgerOffer->id) {
                        $updates['chain_root_id'] = $ledgerOffer->id;
                        $updates['parent_window_id'] = null;
                        $updates['chain_ordinal'] = 1;
                        $updates['continuation_deadline_at'] = null;
                    }
                    DB::table('current_forward_windows')
                        ->where('id', $ledgerOffer->id)
                        ->where('state', 'OFFERED')
                        ->update($updates);
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
                $ledgerClaim = $this->lockedLedgerWindowForGeneration($claimed);
                if ($ledgerClaim !== null && (string) $ledgerClaim->state === 'CLAIMED') {
                    $groupRow = DB::table('usenet_groups')
                        ->where('name', (string) $settings->get('orchestrator_cf_group'))
                        ->lockForUpdate()
                        ->first();
                    $cursor = (int) ($groupRow->last_record ?? 0);
                    $first = (int) $ledgerClaim->first_article;
                    $last = (int) $ledgerClaim->last_article;
                    if ($cursor === $last) {
                        $updates = [
                            'state' => 'INGESTED',
                            'ingested_at' => now(),
                            'updated_at' => now(),
                        ];
                        if ($groupRow !== null
                            && Schema::hasColumn('usenet_groups', 'last_record_postdate')
                            && Schema::hasColumn('current_forward_windows', 'cursor_end_postdate')
                        ) {
                            $updates['cursor_end_postdate'] = $groupRow->last_record_postdate;
                        }
                        DB::table('current_forward_windows')
                            ->where('id', $ledgerClaim->id)
                            ->where('state', 'CLAIMED')
                            ->update($updates);
                        Settings::query()->updateOrCreate([
                            'name' => 'orchestrator_cf_completed',
                        ], ['value' => (string) $claimed]);
                        Settings::forgetCachedSettings();

                        return $denied('current_forward_refresh_in_progress');
                    }
                    $partialCursor = $cursor !== $first - 1;
                    $source = DB::table('current_forward_sources')
                        ->where('id', $ledgerClaim->source_id)
                        ->lockForUpdate()
                        ->first();
                    DB::table('current_forward_windows')
                        ->where('id', $ledgerClaim->id)
                        ->where('state', 'CLAIMED')
                        ->update([
                            'state' => 'QUARANTINED',
                            'failure_reason' => $partialCursor ? 'partial_cursor_timeout' : 'claim_timeout',
                            'settled_at' => now(),
                            'updated_at' => now(),
                        ]);
                    $rootId = (int) ($ledgerClaim->chain_root_id ?? $ledgerClaim->id);
                    if ($rootId !== (int) $ledgerClaim->id) {
                        DB::table('current_forward_windows')
                            ->where('id', $rootId)
                            ->where('state', 'CONTINUATION_PENDING')
                            ->update([
                                'state' => 'QUARANTINED',
                                'failure_reason' => $partialCursor ? 'partial_cursor_timeout' : 'claim_timeout',
                                'settled_at' => now(),
                                'updated_at' => now(),
                            ]);
                    }
                    if ($source !== null) {
                        $strikes = $partialCursor ? 2 : min(2, (int) $source->strikes + 1);
                        $sourceUpdates = [
                            'state' => $partialCursor ? 'HALTED' : ($strikes >= 2 ? 'QUALITY_LOCKED' : 'READY'),
                            'strikes' => $strikes,
                            'updated_at' => now(),
                        ];
                        if (Schema::hasColumn('current_forward_sources', 'last_reason')) {
                            $sourceUpdates['last_reason'] = $partialCursor ? 'partial_cursor_timeout' : 'claim_timeout';
                        }
                        DB::table('current_forward_sources')->where('id', $source->id)->update($sourceUpdates);
                    }
                    if ($partialCursor) {
                        Settings::query()->updateOrCreate(['name' => 'orchestrator_cf_halt'], ['value' => '1']);
                    }
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
            if ($this->hasUnsettledLedgerWindow()) {
                return $denied('current_forward_refresh_in_progress');
            }
            if ($ledgerMode) {
                $continuationRoot = $this->lockedContinuationRoot();
                if ($continuationRoot !== null) {
                    return $this->offerContinuationWindow(
                        $snapshot,
                        $generation,
                        $groups,
                        $continuationRoot,
                    );
                }

                return $this->offerAuditedWindow($generation, $groups);
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

            $this->writePermitSettings($generation, $group, $window['first'], $window['last'], $window['stop']);

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
            $refreshPolicy = new CurrentForwardRefreshTrustPolicy;
            $ledgerCandidate = $this->ledgerWindowForGeneration($generation);
            $lockedSource = $ledgerCandidate === null
                ? null
                : DB::table('current_forward_sources')
                    ->where('id', $ledgerCandidate->source_id)
                    ->lockForUpdate()
                    ->first();
            $rootId = (int) ($ledgerCandidate->chain_root_id ?? $ledgerCandidate->id ?? 0);
            $lockedRoot = $ledgerCandidate !== null && $rootId > 0
                ? DB::table('current_forward_windows')->where('id', $rootId)->lockForUpdate()->first()
                : null;
            $ledgerWindow = $ledgerCandidate === null
                ? null
                : ($rootId === (int) $ledgerCandidate->id
                    ? $lockedRoot
                    : $this->lockedLedgerWindowForGeneration($generation));
            $ledgerClaim = $ledgerWindow !== null;
            $continuationClaim = $ledgerClaim && $rootId !== (int) $ledgerWindow->id;
            if ($generation <= 0
                || ($ledgerClaim
                    ? (! $this->ledgerIssuanceEnabled()
                        || ! $refreshPolicy->isValid()
                        || ! $refreshPolicy->protects($group))
                    : (! $policy->isValid() || ! $policy->matches($group, $first, $last, $stop)))
                || (string) $settings->get('orchestrator_mode') !== 'active'
                || (string) $settings->get('orchestrator_profile') === 'fail_safe'
                || (int) $settings->get('orchestrator_recovery_ok') !== 1
                || (int) $settings->get('orchestrator_lease_until') < time()
                || (int) $settings->get('orchestrator_bf_permit') !== 0
                || $this->backfillClaimIsUnsettled($settings)
                || (int) $settings->get('orchestrator_cf_permit') !== $generation
                || (string) $settings->get('orchestrator_cf_group') !== $group
                || (int) $settings->get('orchestrator_cf_first') !== $first
                || (int) $settings->get('orchestrator_cf_last') !== $last
                || ($continuationClaim
                    && (! (new CurrentForwardWindowLineage)->enabled()
                        || $lockedRoot === null
                        || (string) $lockedRoot->state !== 'CONTINUATION_PENDING'
                        || strtotime((string) $lockedRoot->continuation_deadline_at) === false
                        || time() >= strtotime((string) $lockedRoot->continuation_deadline_at)))
            ) {
                return null;
            }
            $groupRow = DB::table('usenet_groups')->where('name', $group)->lockForUpdate()->first();
            $provider = $this->lockedLatestProvider($group);
            if ($groupRow === null
                || (int) $groupRow->active !== 0
                || (int) $groupRow->backfill !== 1
                || (int) $groupRow->last_record !== $first - 1
                || ! $this->providerCoversWindow($provider, $first, $last)
            ) {
                return null;
            }
            if ($ledgerClaim && ! $this->ledgerWindowMatchesClaim(
                $ledgerWindow,
                $lockedSource,
                $groupRow,
                $group,
                $first,
                $last,
            )) {
                return null;
            }

            if ($ledgerClaim) {
                $updated = DB::table('current_forward_windows')
                    ->where('id', $ledgerWindow->id)
                    ->where('state', 'OFFERED')
                    ->where('generation', $generation)
                    ->update([
                        'state' => 'CLAIMED',
                        'claimed_at' => now(),
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    return null;
                }
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
            $ledgerWindow = $this->lockedLedgerWindowForGeneration($generation);
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

            if ($ledgerWindow !== null) {
                if ((string) $ledgerWindow->state !== 'CLAIMED'
                    || (int) $ledgerWindow->last_article !== $last
                ) {
                    return false;
                }
                $updates = [
                    'state' => 'INGESTED',
                    'ingested_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('usenet_groups', 'last_record_postdate')
                    && Schema::hasColumn('current_forward_windows', 'cursor_end_postdate')
                ) {
                    $updates['cursor_end_postdate'] = $groupRow->last_record_postdate;
                }
                if (DB::table('current_forward_windows')
                    ->where('id', $ledgerWindow->id)
                    ->where('state', 'CLAIMED')
                    ->update($updates) !== 1
                ) {
                    return false;
                }
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
            $ledgerCandidate = $this->ledgerWindowForGeneration($generation);
            $source = $ledgerCandidate === null
                ? null
                : DB::table('current_forward_sources')
                    ->where('id', $ledgerCandidate->source_id)
                    ->lockForUpdate()
                    ->first();
            $ledgerWindow = $ledgerCandidate === null
                ? null
                : $this->lockedLedgerWindowForGeneration($generation);
            if ($ledgerWindow !== null) {
                if ((string) $ledgerWindow->state !== 'CLAIMED') {
                    return false;
                }
                $groupRow = DB::table('usenet_groups')
                    ->where('name', (string) $settings->get('orchestrator_cf_group'))
                    ->lockForUpdate()
                    ->first();
                if ($groupRow !== null
                    && (int) $groupRow->last_record === (int) $ledgerWindow->last_article
                ) {
                    $updates = [
                        'state' => 'INGESTED',
                        'ingested_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (Schema::hasColumn('usenet_groups', 'last_record_postdate')
                        && Schema::hasColumn('current_forward_windows', 'cursor_end_postdate')
                    ) {
                        $updates['cursor_end_postdate'] = $groupRow->last_record_postdate;
                    }
                    if (DB::table('current_forward_windows')
                        ->where('id', $ledgerWindow->id)
                        ->where('state', 'CLAIMED')
                        ->update($updates) !== 1
                    ) {
                        return false;
                    }
                    Settings::query()->updateOrCreate(
                        ['name' => 'orchestrator_cf_completed'],
                        ['value' => (string) $generation],
                    );
                    Settings::forgetCachedSettings();

                    return true;
                }
                if ($source === null) {
                    return false;
                }
                $strikes = min(2, (int) $source->strikes + 1);
                $sourceUpdates = [
                    'strikes' => $strikes,
                    'state' => $strikes >= 2 ? 'QUALITY_LOCKED' : 'READY',
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('current_forward_sources', 'last_reason')) {
                    $sourceUpdates['last_reason'] = substr($reason, 0, 120);
                }
                if (DB::table('current_forward_windows')
                    ->where('id', $ledgerWindow->id)
                    ->where('state', 'CLAIMED')
                    ->update([
                        'state' => 'QUARANTINED',
                        'failure_reason' => substr($reason, 0, 120),
                        'settled_at' => now(),
                        'updated_at' => now(),
                    ]) !== 1
                ) {
                    return false;
                }
                $rootId = (int) ($ledgerWindow->chain_root_id ?? $ledgerWindow->id);
                if ($rootId !== (int) $ledgerWindow->id) {
                    DB::table('current_forward_windows')
                        ->where('id', $rootId)
                        ->where('state', 'CONTINUATION_PENDING')
                        ->update([
                            'state' => 'QUARANTINED',
                            'failure_reason' => substr($reason, 0, 120),
                            'settled_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
                DB::table('current_forward_sources')->where('id', $source->id)->update($sourceUpdates);
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

    /**
     * @param  list<string>  $trustedGroups
     * @return array{granted:bool,reason:string,generation:int,group:string,first:int,last:int,stop:int}
     */
    private function offerAuditedWindow(int $generation, array $trustedGroups): array
    {
        $windowsQuery = DB::table('current_forward_windows as candidate')
            ->join('current_forward_sources as ranked_source', 'ranked_source.id', '=', 'candidate.source_id')
            ->join('usenet_groups as ranked_group', function ($join): void {
                $join->on('ranked_group.id', '=', 'ranked_source.groups_id')
                    ->on('ranked_group.name', '=', 'ranked_source.group_name');
            })
            ->select('candidate.*')
            ->where('candidate.state', 'AUDITED')
            ->whereNull('candidate.generation')
            ->where('ranked_source.state', 'READY')
            ->where('ranked_source.strikes', '<', 2)
            ->whereIn('ranked_source.group_name', $trustedGroups)
            ->where('ranked_group.active', 0)
            ->where('ranked_group.backfill', 1)
            ->whereRaw('ranked_group.last_record = candidate.first_article - 1')
            ->whereColumn('ranked_source.audited_last', 'candidate.last_article');
        if (Schema::hasColumns('current_forward_windows', ['attempt_ordinal', 'retry_of_window_id'])) {
            $windowsQuery->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('current_forward_windows as newer')
                    ->whereColumn('newer.source_id', 'candidate.source_id')
                    ->whereColumn('newer.first_article', 'candidate.first_article')
                    ->whereColumn('newer.last_article', 'candidate.last_article')
                    ->whereColumn('newer.attempt_ordinal', '>', 'candidate.attempt_ordinal');
            });
        }
        $windows = $this->rankAuditedWindows($windowsQuery->get());
        if ($windows->isEmpty()) {
            return $this->denied('audited_window_unavailable');
        }

        $lastReason = 'audited_window_unavailable';
        $trustPolicy = new CurrentForwardRefreshTrustPolicy;
        foreach ($windows as $candidate) {
            $source = DB::table('current_forward_sources')
                ->where('id', $candidate->source_id)
                ->lockForUpdate()
                ->first();
            $trustAnchor = $source === null
                ? null
                : $trustPolicy->anchor((string) $source->group_name);
            if ($source === null
                || (string) $source->state !== 'READY'
                || (int) $source->strikes >= 2
                || ! in_array((string) $source->group_name, $trustedGroups, true)
                || $trustAnchor === null
                || (int) $source->anchor_first !== $trustAnchor['first']
            ) {
                $lastReason = 'audited_source_unavailable';

                continue;
            }
            $window = DB::table('current_forward_windows')
                ->where('id', $candidate->id)
                ->lockForUpdate()
                ->first();
            if ($window === null
                || (string) $window->state !== 'AUDITED'
                || $window->generation !== null
                || (int) $window->source_id !== (int) $source->id
            ) {
                $lastReason = 'audited_window_race_lost';

                continue;
            }
            if (! $this->retryAttemptIsExecutable($window, $source)) {
                $lastReason = 'audited_window_retry_invalid';

                continue;
            }
            $group = (string) $source->group_name;
            $first = (int) $window->first_article;
            $last = (int) $window->last_article;
            $groupRow = DB::table('usenet_groups')
                ->where('id', $source->groups_id)
                ->where('name', $group)
                ->lockForUpdate()
                ->first();
            if ($groupRow === null
                || (int) $groupRow->active !== 0
                || (int) $groupRow->backfill !== 1
                || (int) $groupRow->last_record !== $first - 1
            ) {
                $lastReason = 'audited_window_cursor_drift';

                continue;
            }
            $verification = $this->latestVerification((int) $window->id);
            if (! $this->normalAuditIsExecutable($verification)) {
                $lastReason = 'audited_window_partial_only';

                continue;
            }
            if (! $this->auditIsFresh($window, $verification)
                || ! $this->timestampIsFresh($source->last_audited_at, $this->auditMaxAgeSeconds())
            ) {
                $lastReason = 'audited_window_stale';

                continue;
            }
            $provider = $this->lockedLatestProvider($group);
            if (! $this->providerCoversWindow($provider, $first, $last)) {
                $lastReason = 'provider_range_drift';

                continue;
            }

            $releaseBaseline = Schema::hasTable('releases')
                ? (int) DB::table('releases')->max('id')
                : 0;
            $windowUpdates = [
                'generation' => $generation,
                'state' => 'OFFERED',
                'release_baseline' => $releaseBaseline,
                'offered_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('current_forward_windows', 'issued_verification_id')) {
                $windowUpdates['issued_verification_id'] = $verification?->id;
            }
            if (Schema::hasColumn('usenet_groups', 'last_record_postdate')
                && Schema::hasColumn('current_forward_windows', 'cursor_postdate')
            ) {
                $windowUpdates['cursor_postdate'] = $groupRow->last_record_postdate;
            }
            $updated = DB::table('current_forward_windows')
                ->where('id', $window->id)
                ->where('state', 'AUDITED')
                ->whereNull('generation')
                ->update($windowUpdates);
            if ($updated !== 1) {
                $lastReason = 'audited_window_race_lost';

                continue;
            }

            $this->writePermitSettings($generation, $group, $first, $last, $last);

            return [
                'granted' => true,
                'reason' => 'current_forward_audited_permit_granted',
                'generation' => $generation,
                'group' => $group,
                'first' => $first,
                'last' => $last,
                'stop' => $last,
            ];
        }

        return $this->denied($lastReason);
    }

    /**
     * Prefer audited work backed by proven productive output and stronger exact
     * evidence. Hard safety gates are repeated under locks before issuance.
     *
     * @param  Collection<int, \stdClass>  $windows
     * @return Collection<int, \stdClass>
     */
    private function rankAuditedWindows(Collection $windows): Collection
    {
        $sourceIds = $windows->pluck('source_id')->map(static fn (mixed $id): int => (int) $id)->unique()->all();
        $sources = DB::table('current_forward_sources')
            ->whereIn('id', $sourceIds)
            ->get()
            ->keyBy('id');
        $verifications = $windows->mapWithKeys(fn (object $window): array => [
            (int) $window->id => $this->latestVerification((int) $window->id),
        ]);
        $productive = collect();
        if ($sourceIds !== []
            && Schema::hasColumns('current_forward_windows', ['settled_at', 'outcome_ready_nzbs'])
        ) {
            $productive = DB::table('current_forward_windows')
                ->select('source_id')
                ->selectRaw('COUNT(*) AS productive_attempts')
                ->selectRaw('COALESCE(SUM(outcome_ready_nzbs), 0) AS ready_nzbs')
                ->whereIn('source_id', $sourceIds)
                ->where('state', 'PRODUCTIVE')
                ->whereNotNull('settled_at')
                ->whereNotNull('outcome_ready_nzbs')
                ->groupBy('source_id')
                ->get()
                ->keyBy('source_id');
        }

        return $windows->sort(function (object $left, object $right) use ($sources, $productive, $verifications): int {
            $leftSource = $sources->get($left->source_id);
            $rightSource = $sources->get($right->source_id);
            $leftHistory = $productive->get($left->source_id);
            $rightHistory = $productive->get($right->source_id);
            $leftVerification = $verifications->get((int) $left->id);
            $rightVerification = $verifications->get((int) $right->id);
            $leftHeaders = max(1, (int) ($leftVerification->headers ?? $left->headers ?? 0));
            $rightHeaders = max(1, (int) ($rightVerification->headers ?? $right->headers ?? 0));
            $leftYenc = (int) ($leftVerification->yenc_headers ?? $left->yenc_headers ?? 0);
            $rightYenc = (int) ($rightVerification->yenc_headers ?? $right->yenc_headers ?? 0);
            $comparisons = [
                [(int) ($leftSource->strikes ?? 2), (int) ($rightSource->strikes ?? 2)],
                [-((int) ($leftHistory->ready_nzbs ?? 0)), -((int) ($rightHistory->ready_nzbs ?? 0))],
                [-((int) ($leftHistory->productive_attempts ?? 0)), -((int) ($rightHistory->productive_attempts ?? 0))],
                [-((int) ($leftVerification->complete_binary_files ?? $left->complete_binary_files ?? 0)), -((int) ($rightVerification->complete_binary_files ?? $right->complete_binary_files ?? 0))],
                [-((int) ($leftVerification->multipart_headers ?? $left->multipart_headers ?? 0)), -((int) ($rightVerification->multipart_headers ?? $right->multipart_headers ?? 0))],
                [-($leftYenc / $leftHeaders), -($rightYenc / $rightHeaders)],
                [-((int) strtotime((string) ($leftVerification->verified_at ?? $left->provider_observed_at ?? ''))), -((int) strtotime((string) ($rightVerification->verified_at ?? $right->provider_observed_at ?? '')))],
                [-((int) ($leftVerification->provider_high ?? $left->provider_high ?? 0) - (int) $left->last_article), -((int) ($rightVerification->provider_high ?? $right->provider_high ?? 0) - (int) $right->last_article)],
                [(int) $left->id, (int) $right->id],
            ];
            foreach ($comparisons as [$leftValue, $rightValue]) {
                $comparison = $leftValue <=> $rightValue;
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        })->values();
    }

    private function retryAttemptIsExecutable(object $window, object $source): bool
    {
        if (! Schema::hasColumns('current_forward_windows', ['attempt_ordinal', 'retry_of_window_id'])) {
            return true;
        }
        $attempt = (int) ($window->attempt_ordinal ?? 0);
        $retryOf = (int) ($window->retry_of_window_id ?? 0);
        if ($attempt === 1) {
            return $retryOf === 0;
        }
        $maxRetries = max(0, min(1, (int) config(
            'nntmux.orchestrator.current_forward_terminal_max_retries',
            1,
        )));
        if ($attempt < 2
            || $attempt > 1 + $maxRetries
            || $retryOf <= 0
            || (int) ($source->strikes ?? 0) !== 1
        ) {
            return false;
        }
        $predecessor = DB::table('current_forward_windows')
            ->where('id', $retryOf)
            ->lockForUpdate()
            ->first();
        if ($predecessor === null
            || (string) $predecessor->state !== 'QUARANTINED'
            || $predecessor->generation === null
            || trim((string) ($predecessor->failure_reason ?? '')) === ''
            || $predecessor->claimed_at === null
            || $predecessor->settled_at === null
            || $predecessor->ingested_at !== null
            || (int) $predecessor->source_id !== (int) $window->source_id
            || (int) $predecessor->first_article !== (int) $window->first_article
            || (int) $predecessor->last_article !== (int) $window->last_article
            || (int) ($predecessor->attempt_ordinal ?? 0) + 1 !== $attempt
            || (int) ($predecessor->chain_root_id ?? 0) !== (int) $predecessor->id
            || (int) ($predecessor->outcome_releases ?? 0) !== 0
            || (int) ($predecessor->outcome_ready_nzbs ?? 0) !== 0
            || (int) ($window->chain_root_id ?? 0) !== (int) $window->id
        ) {
            return false;
        }

        return ! DB::table('current_forward_windows')
            ->where('source_id', $window->source_id)
            ->where('first_article', $window->first_article)
            ->where('last_article', $window->last_article)
            ->where('attempt_ordinal', '>', $attempt)
            ->exists();
    }

    /**
     * @param  list<string>  $trustedGroups
     * @return array{granted:bool,reason:string,generation:int,group:string,first:int,last:int,stop:int}
     */
    private function offerContinuationWindow(
        PipelineSnapshot $snapshot,
        int $generation,
        array $trustedGroups,
        object $root,
    ): array {
        $lineage = new CurrentForwardWindowLineage;
        if (! $lineage->enabled() || ! $lineage->schemaReady()) {
            return $this->denied('current_forward_continuation_disabled');
        }
        $deadline = strtotime((string) ($root->continuation_deadline_at ?? ''));
        if ($deadline === false || time() >= $deadline) {
            return $this->denied('current_forward_continuation_deadline');
        }

        $source = DB::table('current_forward_sources')
            ->where('id', $root->source_id)
            ->lockForUpdate()
            ->first();
        $root = DB::table('current_forward_windows')
            ->where('id', $root->id)
            ->lockForUpdate()
            ->first();
        if ($root === null || (string) $root->state !== 'CONTINUATION_PENDING') {
            return $this->denied('current_forward_continuation_race_lost');
        }
        $lockedDeadline = strtotime((string) ($root->continuation_deadline_at ?? ''));
        if ($lockedDeadline === false || time() >= $lockedDeadline) {
            return $this->denied('current_forward_continuation_deadline');
        }
        $members = DB::table('current_forward_windows')
            ->where('chain_root_id', $root->id)
            ->whereIn('state', ['CONTINUATION_PENDING', 'CHAINED'])
            ->orderBy('chain_ordinal')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $latest = $members->last();
        if ($latest === null) {
            return $this->denied('current_forward_continuation_chain_missing');
        }
        foreach ($members as $index => $member) {
            $ordinal = $index + 1;
            if ((int) $member->chain_ordinal !== $ordinal
                || ($ordinal === 1 && (int) $member->id !== (int) $root->id)
                || ($ordinal === 1 && (string) $member->state !== 'CONTINUATION_PENDING')
                || ($ordinal > 1 && (string) $member->state !== 'CHAINED')
            ) {
                return $this->denied('current_forward_continuation_chain_invalid');
            }
        }
        $nextOrdinal = (int) ($latest->chain_ordinal ?? 0) + 1;
        if ($nextOrdinal > $lineage->maxWindows()) {
            return $this->denied('current_forward_continuation_chain_exhausted');
        }

        $exact = $lineage->observe((int) $root->id);
        $growth = (array) config('nntmux.orchestrator.backfill_growth_per_10k', []);
        $projectedParts = max(10_000, (int) ($growth['parts'] ?? 10_000));
        $projectedBinaries = min(
            $lineage->maxBinaries(),
            max(1, (int) config('nntmux.orchestrator.current_forward_continuation_projected_binaries', 500)),
        );
        $projectedCollections = min(
            $lineage->maxCollections(),
            max(1, (int) config('nntmux.orchestrator.current_forward_continuation_projected_collections', 100)),
        );
        $high = (array) config('nntmux.orchestrator.high_watermarks', []);
        if ($lineage->maxParts() < $exact['parts'] + $projectedParts
            || $lineage->maxBinaries() < $exact['binaries'] + $projectedBinaries
            || $lineage->maxCollections() < $exact['collections'] + $projectedCollections
            || $snapshot->partsBacklog + $projectedParts >= (int) ($high['parts'] ?? PHP_INT_MAX)
            || $snapshot->binariesBacklog + $projectedBinaries >= (int) ($high['binaries'] ?? PHP_INT_MAX)
            || $snapshot->physicalCollectionsBacklog() + $projectedCollections >= (int) ($high['collections_total'] ?? ($high['collections'] ?? PHP_INT_MAX))
        ) {
            return $this->denied('current_forward_continuation_budget_blocked');
        }

        if ($source === null
            || (string) $source->state !== 'READY'
            || (int) $source->strikes >= 2
            || ! in_array((string) $source->group_name, $trustedGroups, true)
        ) {
            return $this->denied('audited_source_unavailable');
        }
        $first = (int) $latest->last_article + 1;
        $last = $first + 9_999;
        $candidate = DB::table('current_forward_windows')
            ->where('source_id', $source->id)
            ->where('first_article', $first)
            ->where('last_article', $last)
            ->where('state', 'AUDITED')
            ->whereNull('generation')
            ->lockForUpdate()
            ->first();
        if ($candidate === null) {
            return $this->denied('current_forward_continuation_audit_pending');
        }
        $group = (string) $source->group_name;
        $groupRow = DB::table('usenet_groups')
            ->where('id', $source->groups_id)
            ->where('name', $group)
            ->lockForUpdate()
            ->first();
        if ($groupRow === null
            || (int) $groupRow->active !== 0
            || (int) $groupRow->backfill !== 1
            || (int) $groupRow->last_record !== $first - 1
        ) {
            return $this->denied('audited_window_cursor_drift');
        }
        $verification = $this->latestVerification((int) $candidate->id);
        if (! $this->continuationAuditIsExecutable($candidate, $verification, $root)) {
            return $this->denied('audited_window_stale');
        }
        $provider = $this->lockedLatestProvider($group);
        if (! $this->providerCoversWindow($provider, $first, $last)) {
            return $this->denied('provider_range_drift');
        }

        $releaseBaseline = Schema::hasTable('releases') ? (int) DB::table('releases')->max('id') : 0;
        $updated = DB::table('current_forward_windows')
            ->where('id', $candidate->id)
            ->where('state', 'AUDITED')
            ->whereNull('generation')
            ->update([
                'generation' => $generation,
                'state' => 'OFFERED',
                'release_baseline' => $releaseBaseline,
                'issued_verification_id' => $verification?->id,
                'cursor_postdate' => $groupRow->last_record_postdate,
                'chain_root_id' => $root->id,
                'parent_window_id' => $latest->id,
                'chain_ordinal' => $nextOrdinal,
                'continuation_deadline_at' => $root->continuation_deadline_at,
                'offered_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return $this->denied('audited_window_race_lost');
        }

        $this->writePermitSettings($generation, $group, $first, $last, $last);

        return [
            'granted' => true,
            'reason' => 'current_forward_continuation_permit_granted',
            'generation' => $generation,
            'group' => $group,
            'first' => $first,
            'last' => $last,
            'stop' => $last,
        ];
    }

    private function ledgerWindowMatchesClaim(
        object $window,
        ?object $source,
        object $groupRow,
        string $group,
        int $first,
        int $last,
    ): bool {
        $rootId = (int) ($window->chain_root_id ?? $window->id ?? 0);
        $continuation = $rootId > 0 && $rootId !== (int) ($window->id ?? 0);
        $verification = Schema::hasTable('current_forward_window_verifications')
            ? DB::table('current_forward_window_verifications')
                ->where('id', $window->issued_verification_id)
                ->where('window_id', $window->id)
                ->first()
            : null;
        if ((string) $window->state !== 'OFFERED'
            || (int) $window->first_article !== $first
            || (int) $window->last_article !== $last
            || ($continuation
                ? ! $this->continuationAuditIsExecutable(
                    $window,
                    $verification,
                    DB::table('current_forward_windows')->where('id', $rootId)->first(),
                )
                : ! $this->auditIsFresh($window, $verification))
        ) {
            return false;
        }

        return $source !== null
            && (string) $source->state === 'READY'
            && (int) $source->strikes < 2
            && (int) $source->groups_id === (int) $groupRow->id
            && (string) $source->group_name === $group
            && ($continuation
                || $this->timestampIsFresh($source->last_audited_at, $this->auditMaxAgeSeconds()));
    }

    private function normalAuditIsExecutable(?object $verification): bool
    {
        if (! Schema::hasTable('current_forward_window_verifications')
            || ! Schema::hasColumns('current_forward_window_verifications', [
                'window_id',
                'provider_first',
                'provider_high',
                'provider_observed_at',
                'headers',
                'yenc_headers',
                'multipart_headers',
                'policy_version',
                'complete_binary_files',
                'verified_at',
            ])
        ) {
            return false;
        }

        return $verification !== null
            && (string) ($verification->policy_version ?? '') === 'exact-xover-v1'
            && (int) ($verification->complete_binary_files ?? 0) >= 1;
    }

    private function continuationAuditIsExecutable(
        object $window,
        ?object $verification,
        ?object $root,
    ): bool {
        if (! Schema::hasTable('current_forward_window_verifications')
            || ! Schema::hasTable('current_forward_continuation_observations')
            || ! Schema::hasColumns('current_forward_window_verifications', [
                'policy_version',
                'complete_binary_files',
            ])
        ) {
            return $this->auditIsFresh($window, $verification);
        }
        if ($verification === null || $root === null
            || (string) ($root->state ?? '') !== 'CONTINUATION_PENDING'
        ) {
            return false;
        }
        $deadline = strtotime((string) ($root->continuation_deadline_at ?? ''));
        $verifiedAt = strtotime((string) ($verification->verified_at ?? ''));
        $providerObservedAt = strtotime((string) ($verification->provider_observed_at ?? ''));
        $chainStartedAt = DB::table('current_forward_continuation_observations')
            ->where('chain_root_id', $root->id)
            ->min('observed_at');
        $chainStarted = strtotime((string) $chainStartedAt);
        $policy = (string) ($verification->policy_version ?? '');
        $qualityValid = ($policy === 'exact-xover-v1'
                && (int) ($verification->complete_binary_files ?? 0) >= 1)
            || $policy === 'exact-xover-continuation-v1';

        return $deadline !== false
            && time() < $deadline
            && $verifiedAt !== false
            && $providerObservedAt !== false
            && $chainStarted !== false
            && $verifiedAt >= $chainStarted
            && $providerObservedAt >= $chainStarted
            && $verifiedAt <= $deadline
            && $providerObservedAt <= $deadline
            && $qualityValid
            && CurrentForwardProviderCoverage::covers(
                (int) $verification->provider_first,
                (int) $verification->provider_high,
                (int) $window->first_article,
                (int) $window->last_article,
            );
    }

    private function auditIsFresh(object $window, ?object $verification = null): bool
    {
        if (($window->failure_reason ?? null) === 'unclaimed_timeout_reaudit_required') {
            return false;
        }
        if (Schema::hasTable('current_forward_window_verifications')) {
            $verification ??= isset($window->issued_verification_id)
                ? DB::table('current_forward_window_verifications')
                    ->where('id', $window->issued_verification_id)
                    ->where('window_id', $window->id)
                    ->first()
                : null;
            if ($verification === null) {
                return false;
            }

            return $this->timestampIsFresh($verification->verified_at, $this->auditMaxAgeSeconds())
                && $this->timestampIsFresh($verification->provider_observed_at, $this->auditMaxAgeSeconds())
                && CurrentForwardProviderCoverage::covers(
                    (int) $verification->provider_first,
                    (int) $verification->provider_high,
                    (int) $window->first_article,
                    (int) $window->last_article,
                );
        }
        $sourceAuditFresh = ! property_exists($window, 'last_audited_at')
            || $this->timestampIsFresh($window->last_audited_at, $this->auditMaxAgeSeconds());

        return $this->timestampIsFresh($window->provider_observed_at, $this->auditMaxAgeSeconds())
            && $sourceAuditFresh;
    }

    private function latestVerification(int $windowId): ?object
    {
        if (! Schema::hasTable('current_forward_window_verifications')) {
            return null;
        }

        return DB::table('current_forward_window_verifications')
            ->where('window_id', $windowId)
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->first();
    }

    private function auditMaxAgeSeconds(): int
    {
        return max(
            600,
            (int) config('nntmux.orchestrator.current_forward_audit_max_age_seconds', 900),
        );
    }

    private function ledgerIssuanceEnabled(): bool
    {
        return (bool) config('nntmux.orchestrator.current_forward_ledger_issuance_enabled', false)
            && Schema::hasTable('current_forward_sources')
            && Schema::hasTable('current_forward_windows');
    }

    private function hasUnsettledLedgerWindow(): bool
    {
        if (! Schema::hasTable('current_forward_windows')) {
            return false;
        }

        return DB::table('current_forward_windows')
            ->whereIn('state', ['OFFERED', 'CLAIMED', 'INGESTED', 'ATTRIBUTING'])
            ->exists();
    }

    private function lockedContinuationRoot(): ?object
    {
        if (! Schema::hasTable('current_forward_windows')
            || ! Schema::hasColumn('current_forward_windows', 'chain_root_id')
        ) {
            return null;
        }

        return DB::table('current_forward_windows')
            ->where('state', 'CONTINUATION_PENDING')
            ->whereColumn('id', 'chain_root_id')
            ->orderBy('id')
            ->first();
    }

    private function lockedLedgerWindowForGeneration(int $generation): ?object
    {
        if ($generation <= 0 || ! Schema::hasTable('current_forward_windows')) {
            return null;
        }

        return DB::table('current_forward_windows')
            ->where('generation', $generation)
            ->lockForUpdate()
            ->first();
    }

    private function ledgerWindowForGeneration(int $generation): ?object
    {
        if ($generation <= 0 || ! Schema::hasTable('current_forward_windows')) {
            return null;
        }

        return DB::table('current_forward_windows')
            ->where('generation', $generation)
            ->first();
    }

    private function lockedLatestProvider(string $group): ?object
    {
        return DB::table('short_groups')
            ->where('name', $group)
            ->orderByDesc('updated')
            ->lockForUpdate()
            ->first();
    }

    private function backfillClaimIsUnsettled(mixed $settings): bool
    {
        $claimed = (int) $settings->get('orchestrator_bf_claimed');

        return $claimed > 0
            && $claimed !== (int) $settings->get('orchestrator_bf_completed')
            && $claimed !== (int) $settings->get('orchestrator_bf_failed');
    }

    private function writePermitSettings(int $generation, string $group, int $first, int $last, int $stop): void
    {
        foreach ([
            'orchestrator_cf_gen' => $generation,
            'orchestrator_cf_permit' => $generation,
            'orchestrator_cf_group' => $group,
            'orchestrator_cf_first' => $first,
            'orchestrator_cf_last' => $last,
            'orchestrator_cf_stop' => $stop,
            'orchestrator_cf_issued_at' => time(),
            'orchestrator_cf_failure' => '',
        ] as $name => $value) {
            Settings::query()->updateOrCreate(['name' => $name], ['value' => (string) $value]);
        }
        Settings::forgetCachedSettings();
    }

    /** @return array{granted:false,reason:string,generation:0,group:string,first:0,last:0,stop:0} */
    private function denied(string $reason): array
    {
        return [
            'granted' => false,
            'reason' => $reason,
            'generation' => 0,
            'group' => '',
            'first' => 0,
            'last' => 0,
            'stop' => 0,
        ];
    }

    private function providerCoversWindow(?object $provider, int $first, int $last): bool
    {
        return $provider !== null
            && $this->timestampIsFresh($provider->updated, 600)
            && CurrentForwardProviderCoverage::covers(
                (int) $provider->first_record,
                (int) $provider->last_record,
                $first,
                $last,
            );
    }

    private function timestampIsFresh(mixed $value, int $maxAgeSeconds): bool
    {
        $timestamp = strtotime((string) $value);
        $now = time();

        return $timestamp !== false
            && $timestamp <= $now + 60
            && $timestamp >= $now - $maxAgeSeconds;
    }

    private function ensureSettings(): void
    {
        foreach ([
            'orchestrator_mode' => '',
            'orchestrator_profile' => 'fail_safe',
            'orchestrator_recovery_ok' => 0,
            'orchestrator_lease_until' => 0,
            'orchestrator_bf_permit' => 0,
            'orchestrator_bf_claimed' => 0,
            'orchestrator_bf_completed' => 0,
            'orchestrator_bf_failed' => 0,
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
                'orchestrator_bf_claimed', 'orchestrator_bf_completed', 'orchestrator_bf_failed',
                'orchestrator_cf_gen', 'orchestrator_cf_permit', 'orchestrator_cf_claimed',
                'orchestrator_cf_completed', 'orchestrator_cf_failed', 'orchestrator_cf_group',
                'orchestrator_cf_first', 'orchestrator_cf_last', 'orchestrator_cf_stop',
                'orchestrator_cf_issued_at', 'orchestrator_cf_blocks',
                'orchestrator_cf_halt',
            ])
            ->orderBy('name')
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
