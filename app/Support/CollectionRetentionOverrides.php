<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CollectionFileCheckStatus;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shorter retention for the incomplete collections of listed groups.
 *
 * Some groups (teevee, boneless, vintage-tv) post multi-part sets whose
 * missing parts never arrive, so their incompletes sit until the global
 * partretentionhours cleanup and alone keep the collections backlog over
 * the orchestrator's pressure gate. An override expires only collections
 * that never completed and never became a release; complete or released
 * collections keep the global retention.
 */
final class CollectionRetentionOverrides
{
    /**
     * Cutoff per groups_id, for overrides shorter than $globalHours.
     *
     * @return array<int, DateTimeInterface>
     */
    public static function cutoffs(int $globalHours, ?int $groupId = null): array
    {
        $hoursByName = array_filter(
            (array) config('nntmux.collection_retention_hours_overrides', []),
            static fn (mixed $hours): bool => (int) $hours > 0 && (int) $hours < $globalHours,
        );
        if ($hoursByName === []) {
            return [];
        }

        $cutoffs = [];
        $ids = DB::table('usenet_groups')
            ->whereIn('name', array_map('strval', array_keys($hoursByName)))
            ->when($groupId !== null, static fn ($q) => $q->where('id', $groupId))
            ->pluck('id', 'name');
        foreach ($ids as $name => $id) {
            $cutoffs[(int) $id] = now()->subHours((int) $hoursByName[$name]);
        }

        return $cutoffs;
    }

    /**
     * OR each override's expiry test into $query, on collections aliased `c`.
     *
     * @param  array<int, DateTimeInterface>  $cutoffs
     */
    public static function orWhereExpired(Builder $query, array $cutoffs): void
    {
        foreach ($cutoffs as $groupId => $cutoff) {
            $query->orWhere(static fn (Builder $expired) => self::whereExpired($expired, $groupId, $cutoff));
        }
    }

    /**
     * Restrict $query (collections aliased `c`) to one group's expired incompletes.
     */
    public static function whereExpired(Builder $query, int $groupId, DateTimeInterface $cutoff): Builder
    {
        return $query
            ->where('c.groups_id', $groupId)
            ->where('c.dateadded', '<', $cutoff)
            ->whereNull('c.releases_id')
            ->whereIn('c.filecheck', [
                CollectionFileCheckStatus::Default->value,
                CollectionFileCheckStatus::CompleteCollection->value,
            ]);
    }
}
