<?php

declare(strict_types=1);

namespace App\Services\Movies;

use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MovieLookupState
{
    public const string STATUS_CLAIMED = 'claimed';

    public const string STATUS_RETRY = 'retry';

    public const string STATUS_QUARANTINED = 'quarantined';

    public function claim(int $releaseId): ?string
    {
        return DB::transaction(function () use ($releaseId): ?string {
            $release = Release::query()->whereKey($releaseId)->lockForUpdate()->first([
                'id', 'imdbid', 'searchname', 'categories_id',
            ]);
            if ($release === null) {
                return null;
            }

            $state = DB::table('movie_lookup_states')->where('release_id', $releaseId)->lockForUpdate()->first();
            if ($state !== null && ! $this->snapshotMatches($state, $release)) {
                DB::table('movie_lookup_states')->where('release_id', $releaseId)->delete();
                $state = null;
            }

            $now = now();
            if ($state !== null) {
                if ($state->status === self::STATUS_QUARANTINED) {
                    return null;
                }
                if ($state->status === self::STATUS_RETRY && $state->next_attempt_at !== null && Carbon::parse($state->next_attempt_at)->isFuture()) {
                    return null;
                }
                if ($state->status === self::STATUS_CLAIMED && $state->claim_expires_at !== null && Carbon::parse($state->claim_expires_at)->isFuture()) {
                    return null;
                }
            }

            $token = (string) Str::uuid();
            DB::table('movie_lookup_states')->updateOrInsert(
                ['release_id' => $releaseId],
                [
                    ...$this->snapshot($release),
                    'attempted_imdbid' => $release->imdbid === null ? null : (string) $release->imdbid,
                    'status' => self::STATUS_CLAIMED,
                    'reason_code' => null,
                    'claim_token' => $token,
                    'claim_expires_at' => $now->copy()->addMinutes(15),
                    'next_attempt_at' => null,
                    'quarantined_at' => null,
                    'created_at' => $state->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );

            return $token;
        }, 3);
    }

    /**
     * @return array{status: string, attempts: int}|null
     */
    public function fail(int $releaseId, string $claimToken, string $reason, bool $terminal): ?array
    {
        return DB::transaction(function () use ($releaseId, $claimToken, $reason, $terminal): ?array {
            $release = Release::query()->whereKey($releaseId)->lockForUpdate()->first([
                'id', 'imdbid', 'searchname', 'categories_id',
            ]);
            $state = DB::table('movie_lookup_states')->where('release_id', $releaseId)->lockForUpdate()->first();
            if ($release === null || ! $this->hasCurrentClaim($state, $release, $claimToken)) {
                return null;
            }

            $attempts = (int) $state->attempts + ($terminal ? 1 : 0);
            $retryCount = (int) $state->retry_count + 1;
            $maxAttempts = max(1, (int) config('nntmux_api.movie_lookup_max_attempts', 3));
            $quarantined = $terminal && $attempts >= $maxAttempts;
            $retryMinutes = max(1, (int) config('nntmux_api.movie_lookup_retry_minutes', 30));
            $delayMultiplier = 2 ** min(6, max(0, $retryCount - 1));
            $baseDelay = min(1440, $retryMinutes * $delayMultiplier);
            $jitter = (int) (abs(crc32($releaseId.':'.$retryCount)) % max(1, (int) ceil($baseDelay * 0.2)));
            $now = now();

            if ($quarantined) {
                Release::query()
                    ->whereKey($releaseId)
                    ->where('imdbid', $release->imdbid)
                    ->where(function (Builder $query): void {
                        $query->whereNull('movieinfo_id')->orWhere('movieinfo_id', 0);
                    })
                    ->update(['imdbid' => null, 'movieinfo_id' => null]);
            }

            DB::table('movie_lookup_states')->where('release_id', $releaseId)->where('claim_token', $claimToken)->update([
                'observed_imdbid' => $quarantined ? null : $state->observed_imdbid,
                'status' => $quarantined ? self::STATUS_QUARANTINED : self::STATUS_RETRY,
                'reason_code' => Str::limit(preg_replace('/[^a-z0-9_]+/i', '_', strtolower($reason)) ?: 'unknown_failure', 64, ''),
                'attempts' => $attempts,
                'retry_count' => $retryCount,
                'claim_token' => null,
                'claim_expires_at' => null,
                'next_attempt_at' => $quarantined ? null : $now->copy()->addMinutes($baseDelay + $jitter),
                'quarantined_at' => $quarantined ? $now : null,
                'updated_at' => $now,
            ]);

            return ['status' => $quarantined ? self::STATUS_QUARANTINED : self::STATUS_RETRY, 'attempts' => $attempts];
        }, 3);
    }

    public function complete(int $releaseId, string $claimToken): bool
    {
        return DB::transaction(function () use ($releaseId, $claimToken): bool {
            $release = Release::query()->whereKey($releaseId)->lockForUpdate()->first([
                'id', 'imdbid', 'searchname', 'categories_id',
            ]);
            $state = DB::table('movie_lookup_states')->where('release_id', $releaseId)->lockForUpdate()->first();

            return $release !== null
                && $this->hasCurrentClaim($state, $release, $claimToken)
                && DB::table('movie_lookup_states')->where('release_id', $releaseId)->where('claim_token', $claimToken)->delete() === 1;
        }, 3);
    }

    public function markNoMatch(int $releaseId, string $claimToken): bool
    {
        return DB::transaction(function () use ($releaseId, $claimToken): bool {
            $release = Release::query()->whereKey($releaseId)->lockForUpdate()->first([
                'id', 'imdbid', 'searchname', 'categories_id', 'movieinfo_id',
            ]);
            $state = DB::table('movie_lookup_states')->where('release_id', $releaseId)->lockForUpdate()->first();
            if ($release === null
                || ! $this->hasCurrentClaim($state, $release, $claimToken)
                || ($release->movieinfo_id !== null && (int) $release->movieinfo_id !== 0)) {
                return false;
            }

            Release::query()->whereKey($releaseId)->update(['imdbid' => '']);
            DB::table('movie_lookup_states')->where('release_id', $releaseId)->where('claim_token', $claimToken)->delete();

            return true;
        }, 3);
    }

    public function link(int $releaseId, string $claimToken, string $imdbId, int $movieInfoId): bool
    {
        return DB::transaction(function () use ($releaseId, $claimToken, $imdbId, $movieInfoId): bool {
            $release = Release::query()->whereKey($releaseId)->lockForUpdate()->first([
                'id', 'imdbid', 'searchname', 'categories_id',
            ]);
            $state = DB::table('movie_lookup_states')->where('release_id', $releaseId)->lockForUpdate()->first();
            if ($release === null || ! $this->hasCurrentClaim($state, $release, $claimToken)) {
                return false;
            }

            Release::query()->whereKey($releaseId)->update([
                'imdbid' => $imdbId,
                'movieinfo_id' => $movieInfoId,
            ]);
            DB::table('movie_lookup_states')->where('release_id', $releaseId)->where('claim_token', $claimToken)->delete();

            return true;
        }, 3);
    }

    /**
     * @param  Builder<Release>  $query
     * @return Builder<Release>
     */
    public function applyEligibility(Builder $query, string $releaseAlias = 'releases'): Builder
    {
        return $query->whereRaw($this->eligibilitySql($releaseAlias));
    }

    public function eligibilitySql(string $releaseAlias = 'releases'): string
    {
        $alias = preg_replace('/[^a-z0-9_]+/i', '', $releaseAlias) ?: 'releases';

        return "NOT EXISTS (
            SELECT 1 FROM movie_lookup_states mls
            WHERE mls.release_id = {$alias}.id
              AND COALESCE(mls.observed_imdbid, '') = COALESCE({$alias}.imdbid, '')
              AND mls.observed_searchname = {$alias}.searchname
              AND mls.observed_category_id = {$alias}.categories_id
              AND (
                mls.status = 'quarantined'
                OR (mls.status = 'retry' AND mls.next_attempt_at > CURRENT_TIMESTAMP)
                OR (mls.status = 'claimed' AND mls.claim_expires_at > CURRENT_TIMESTAMP)
              )
        )";
    }

    /**
     * @return array{observed_imdbid: string|null, observed_searchname: string, observed_category_id: int}
     */
    private function snapshot(Release $release): array
    {
        return [
            'observed_imdbid' => $release->imdbid === null ? null : (string) $release->imdbid,
            'observed_searchname' => (string) $release->searchname,
            'observed_category_id' => (int) $release->categories_id,
        ];
    }

    private function snapshotMatches(object $state, Release $release): bool
    {
        return ($state->observed_imdbid === null ? null : (string) $state->observed_imdbid) === ($release->imdbid === null ? null : (string) $release->imdbid)
            && (string) $state->observed_searchname === (string) $release->searchname
            && (int) $state->observed_category_id === (int) $release->categories_id;
    }

    private function hasCurrentClaim(?object $state, Release $release, string $claimToken): bool
    {
        return $state !== null
            && $state->status === self::STATUS_CLAIMED
            && $state->claim_token === $claimToken
            && $state->claim_expires_at !== null
            && Carbon::parse($state->claim_expires_at)->isFuture()
            && $this->snapshotMatches($state, $release);
    }
}
