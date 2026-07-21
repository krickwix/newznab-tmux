<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CurrentForwardRefreshPlanner
{
    private const int WINDOW_SIZE = 10_000;

    private const int PROVIDER_MAX_AGE_SECONDS = 600;

    /**
     * @return array{
     *     enabled:bool,
     *     reason:string,
     *     proposals:list<array{group:string,source_id:int,first:int,last:int,provider_first:int,provider_high:int,provider_observed_at:string,mode:string,window_id:int,retry_of_window_id:int,attempt_ordinal:int}>,
     *     rejections:array<string,string>
     * }
     */
    public function plan(?int $now = null): array
    {
        if (! config('nntmux.orchestrator.current_forward_refresh_enabled', false)) {
            return $this->result(false, 'refresh_disabled');
        }

        $policy = new CurrentForwardRefreshTrustPolicy;
        if (! $policy->isValid() || $policy->groups() === []) {
            return $this->result(true, 'invalid_window_policy');
        }

        $now ??= time();
        $proposals = [];
        $rejections = [];
        $sources = DB::table('current_forward_sources')->orderBy('id')->get();

        foreach ($sources as $source) {
            $groupName = trim((string) $source->group_name);
            $corridor = $policy->anchor($groupName);
            if ($corridor === null) {
                $rejections[$groupName] = 'untrusted_source';

                continue;
            }
            if (! in_array((string) $source->state, ['PROBATION', 'READY'], true)) {
                $rejections[$groupName] = 'source_not_ready';

                continue;
            }

            $group = DB::table('usenet_groups')
                ->where('id', (int) $source->groups_id)
                ->where('name', $groupName)
                ->first();
            if ($group === null || (int) $group->active !== 0 || (int) $group->backfill !== 1) {
                $rejections[$groupName] = 'group_not_inactive_backfill';

                continue;
            }

            $cursor = (int) $group->last_record;
            $anchorCursor = $corridor['first'] - 1;
            if ($cursor < $anchorCursor || ($cursor - $anchorCursor) % self::WINDOW_SIZE !== 0) {
                $rejections[$groupName] = 'cursor_drift';

                continue;
            }
            if ($cursor > PHP_INT_MAX - self::WINDOW_SIZE) {
                $rejections[$groupName] = 'cursor_overflow';

                continue;
            }
            $first = $cursor + 1;
            $last = $cursor + self::WINDOW_SIZE;

            $existingWindow = DB::table('current_forward_windows')
                ->where('source_id', (int) $source->id)
                ->where('first_article', $first)
                ->where('last_article', $last)
                ->when(
                    Schema::hasColumn('current_forward_windows', 'attempt_ordinal'),
                    static fn ($query) => $query->orderByDesc('attempt_ordinal'),
                )
                ->orderByDesc('id')
                ->first();
            $mode = 'NEW';
            $attemptOrdinal = 1;
            $retryOfWindowId = 0;
            if ($existingWindow !== null) {
                if ((string) $existingWindow->state === 'QUARANTINED') {
                    if (! (new CurrentForwardWindowRetryPolicy)->eligible($existingWindow, $source, $group)) {
                        $rejections[$groupName] = 'window_retry_unsafe';

                        continue;
                    }
                    $mode = 'RETRY';
                    $attemptOrdinal = (int) ($existingWindow->attempt_ordinal ?? 1) + 1;
                    $retryOfWindowId = (int) $existingWindow->id;
                } elseif ((string) $existingWindow->state !== 'AUDITED'
                    || $existingWindow->generation !== null
                ) {
                    $rejections[$groupName] = 'window_already_recorded';

                    continue;
                } elseif ($this->verificationIsFresh($existingWindow, $source, $now)) {
                    $rejections[$groupName] = 'audited_window_pending';

                    continue;
                } else {
                    $mode = 'REVERIFY';
                    $attemptOrdinal = (int) ($existingWindow->attempt_ordinal ?? 1);
                }
            } elseif ((int) $source->audited_last !== $cursor) {
                $rejections[$groupName] = 'ledger_cursor_drift';

                continue;
            }

            $provider = DB::table('short_groups')
                ->where('name', $groupName)
                ->orderByDesc('updated')
                ->first();
            if ($provider === null) {
                $rejections[$groupName] = 'provider_missing';

                continue;
            }
            $providerObservedAt = (string) $provider->updated;
            $providerObservedTimestamp = strtotime($providerObservedAt);
            if ($providerObservedTimestamp === false
                || $providerObservedTimestamp > $now + 60
                || $now - $providerObservedTimestamp > self::PROVIDER_MAX_AGE_SECONDS
            ) {
                $rejections[$groupName] = 'provider_stale';

                continue;
            }

            $providerFirst = (int) $provider->first_record;
            $providerHigh = (int) $provider->last_record;
            if (! CurrentForwardProviderCoverage::covers($providerFirst, $providerHigh, $first, $last)) {
                $rejections[$groupName] = 'provider_range_drift';

                continue;
            }

            $proposals[] = [
                'group' => $groupName,
                'source_id' => (int) $source->id,
                'first' => $first,
                'last' => $last,
                'provider_first' => $providerFirst,
                'provider_high' => $providerHigh,
                'provider_observed_at' => $providerObservedAt,
                'mode' => $mode,
                'window_id' => (int) ($existingWindow->id ?? 0),
                'retry_of_window_id' => $retryOfWindowId,
                'attempt_ordinal' => $attemptOrdinal,
            ];
        }

        return [
            'enabled' => true,
            'reason' => $proposals === [] ? 'no_safe_proposal' : 'proposal_available',
            'proposals' => $proposals,
            'rejections' => $rejections,
        ];
    }

    private function verificationIsFresh(object $window, object $source, int $now): bool
    {
        if (($window->failure_reason ?? null) === 'unclaimed_timeout_reaudit_required') {
            return false;
        }
        $maxAge = (int) config('nntmux.orchestrator.current_forward_audit_max_age_seconds', 900);
        if (Schema::hasTable('current_forward_window_verifications')) {
            $verification = DB::table('current_forward_window_verifications')
                ->where('window_id', $window->id)
                ->orderByDesc('verified_at')
                ->orderByDesc('id')
                ->first();
            if ($verification === null) {
                return false;
            }
            $verifiedAt = strtotime((string) $verification->verified_at);
            $providerAt = strtotime((string) $verification->provider_observed_at);

            return $verifiedAt !== false
                && $providerAt !== false
                && $verifiedAt <= $now + 60
                && $providerAt <= $now + 60
                && $now - $verifiedAt <= $maxAge
                && $now - $providerAt <= $maxAge;
        }

        $providerAt = strtotime((string) $window->provider_observed_at);
        $sourceAt = strtotime((string) $source->last_audited_at);

        return $providerAt !== false
            && $sourceAt !== false
            && $now - $providerAt <= $maxAge
            && $now - $sourceAt <= $maxAge;
    }

    /**
     * @return array{enabled:bool,reason:string,proposals:array<never>,rejections:array<never>}
     */
    private function result(bool $enabled, string $reason): array
    {
        return [
            'enabled' => $enabled,
            'reason' => $reason,
            'proposals' => [],
            'rejections' => [],
        ];
    }
}
