<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Facades\DB;

final class CurrentForwardRefreshPlanner
{
    private const int WINDOW_SIZE = 10_000;

    private const int PROVIDER_RESERVE = 20_000;

    private const int PROVIDER_MAX_AGE_SECONDS = 600;

    /**
     * @return array{
     *     enabled:bool,
     *     reason:string,
     *     proposals:list<array{group:string,source_id:int,first:int,last:int,provider_first:int,provider_high:int,provider_observed_at:string}>,
     *     rejections:array<string,string>
     * }
     */
    public function plan(?int $now = null): array
    {
        if (! config('nntmux.orchestrator.current_forward_refresh_enabled', false)) {
            return $this->result(false, 'refresh_disabled');
        }

        $policy = new CurrentForwardStopCursorPolicy;
        if (! $policy->isValid() || $policy->groups() === []) {
            return $this->result(true, 'invalid_window_policy');
        }

        $now ??= time();
        $proposals = [];
        $rejections = [];
        $sources = DB::table('current_forward_sources')->orderBy('id')->get();

        foreach ($sources as $source) {
            $groupName = trim((string) $source->group_name);
            $corridor = $policy->window($groupName);
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
            if ((int) $source->audited_last !== $cursor) {
                $rejections[$groupName] = 'ledger_cursor_drift';

                continue;
            }
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
                ->first();
            if ($existingWindow !== null) {
                $rejections[$groupName] = (string) $existingWindow->state === 'AUDITED'
                    ? 'audited_window_pending'
                    : 'window_already_recorded';

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
            if ($providerFirst <= 0
                || $providerFirst > $first
                || $last > PHP_INT_MAX - self::PROVIDER_RESERVE
                || $providerHigh < $last + self::PROVIDER_RESERVE
            ) {
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
            ];
        }

        return [
            'enabled' => true,
            'reason' => $proposals === [] ? 'no_safe_proposal' : 'proposal_available',
            'proposals' => $proposals,
            'rejections' => $rejections,
        ];
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
