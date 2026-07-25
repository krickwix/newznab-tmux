<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CurrentForwardWindowRetryPolicy
{
    /**
     * A retry is a new immutable attempt for the exact same range. The caller
     * must repeat these checks while holding source, predecessor, and cursor
     * locks before inserting it.
     */
    public function eligible(object $window, object $source, object $group): bool
    {
        if (! $this->schemaReady()
            || (string) ($window->state ?? '') !== 'QUARANTINED'
            || (int) ($window->generation ?? 0) <= 0
            || (string) ($window->failure_reason ?? '') === ''
            || ($window->claimed_at ?? null) === null
            || ($window->settled_at ?? null) === null
            || ($window->ingested_at ?? null) !== null
            || (int) ($window->outcome_releases ?? 0) !== 0
            || (int) ($window->outcome_ready_nzbs ?? 0) !== 0
            || (string) ($source->state ?? '') !== 'READY'
            || (int) ($source->strikes ?? 0) < 1
            || (int) ($source->strikes ?? 0) >= 2
            || (int) ($source->audited_last ?? 0) !== (int) ($window->last_article ?? 0)
            || (int) ($group->id ?? 0) !== (int) ($source->groups_id ?? 0)
            || (int) ($group->last_record ?? 0) !== (int) ($window->first_article ?? 0) - 1
        ) {
            return false;
        }

        $windowId = (int) ($window->id ?? 0);
        $attemptOrdinal = (int) ($window->attempt_ordinal ?? 1);
        $maxRetries = max(0, (int) config('nntmux.orchestrator.current_forward_terminal_max_retries', 1));
        if ($windowId <= 0
            || (int) ($window->chain_root_id ?? $windowId) !== $windowId
            || $attemptOrdinal < 1
            || $attemptOrdinal > $maxRetries
            || ($attemptOrdinal === 1 && ($window->retry_of_window_id ?? null) !== null)
            || ($attemptOrdinal > 1 && (int) ($window->retry_of_window_id ?? 0) <= 0)
        ) {
            return false;
        }

        if (DB::table('current_forward_windows')
            ->where('source_id', $window->source_id)
            ->where('first_article', $window->first_article)
            ->where('last_article', $window->last_article)
            ->where('attempt_ordinal', '>', $attemptOrdinal)
            ->exists()
        ) {
            return false;
        }
        if (DB::table('current_forward_windows')
            ->where('chain_root_id', $windowId)
            ->where('id', '<>', $windowId)
            ->exists()
        ) {
            return false;
        }
        if (DB::table('current_forward_continuation_observations')
            ->where('chain_root_id', $windowId)
            ->exists()
        ) {
            return false;
        }

        return ! DB::table('current_forward_windows')
            ->whereIn('state', ['OFFERED', 'CLAIMED', 'INGESTED', 'ATTRIBUTING', 'CONTINUATION_PENDING'])
            ->exists();
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('current_forward_continuation_observations')
            && Schema::hasColumns('current_forward_sources', ['groups_id', 'audited_last', 'state', 'strikes'])
            && Schema::hasColumns('usenet_groups', ['id', 'last_record'])
            && Schema::hasColumns('current_forward_windows', [
                'attempt_ordinal',
                'retry_of_window_id',
                'chain_root_id',
                'claimed_at',
                'ingested_at',
                'settled_at',
                'outcome_releases',
                'outcome_ready_nzbs',
            ]);
    }
}
