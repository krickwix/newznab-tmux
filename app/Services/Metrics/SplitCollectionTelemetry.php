<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Best-effort, low-cardinality evidence for split-pair Xref decisions.
 *
 * Group labels are limited to the configured reconciliation allowlist and
 * decision labels come from the fixed vocabulary below. Redis loss may reset
 * these counters, but telemetry failure must never stop release processing.
 */
final class SplitCollectionTelemetry
{
    /** @var list<string> */
    public const DECISIONS = [
        'static_accept',
        'dynamic_eligible_shadow',
        'dynamic_accept',
        'reject_direction',
        'reject_missing_or_malformed',
        'reject_parts_cap',
        'reject_span',
        'reject_gap_cap',
        'reject_residual',
    ];

    private const string KEY_PREFIX = 'metrics:split_collection:pair_xref_decisions:';

    /**
     * @param  array<string, array<string, int>>  $counts
     */
    public function record(array $counts): bool
    {
        try {
            $store = $this->store();
            foreach ($counts as $group => $decisions) {
                if (! $this->validGroup($group)) {
                    continue;
                }
                foreach ($decisions as $decision => $count) {
                    if (! in_array($decision, self::DECISIONS, true) || $count < 1) {
                        continue;
                    }
                    $key = $this->key($group, $decision);
                    if ($store->increment($key, $count) === false) {
                        $store->forever($key, $count);
                    }
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $groups
     * @return array{available:bool,groups:array<string,array<string,int>>}
     */
    public function snapshot(array $groups): array
    {
        $snapshot = ['available' => true, 'groups' => []];

        try {
            $store = $this->store();
            foreach (array_values(array_unique($groups)) as $group) {
                if (! $this->validGroup($group)) {
                    continue;
                }
                foreach (self::DECISIONS as $decision) {
                    $snapshot['groups'][$group][$decision] = (int) ($store->get($this->key($group, $decision)) ?? 0);
                }
            }
        } catch (Throwable) {
            return ['available' => false, 'groups' => []];
        }

        return $snapshot;
    }

    private function store(): Repository
    {
        return Cache::store((string) config('nntmux.distributed_lock_store', 'redis'));
    }

    private function key(string $group, string $decision): string
    {
        return self::KEY_PREFIX.rawurlencode(strtolower($group)).':'.$decision;
    }

    private function validGroup(string $group): bool
    {
        return strlen($group) <= 255
            && preg_match('/^[a-z0-9][a-z0-9.-]*$/i', $group) === 1;
    }
}
