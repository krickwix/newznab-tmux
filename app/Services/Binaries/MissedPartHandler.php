<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Models\MissedPart;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Handles missed parts tracking and repair during header processing.
 */
final class MissedPartHandler
{
    private int $partRepairLimit;

    private int $partRepairMaxTries;

    private int $chunkSize;

    private ?bool $claimColumnsAvailable = null;

    public function __construct(int $partRepairLimit = 15000, int $partRepairMaxTries = 3, int $chunkSize = 500)
    {
        $this->partRepairLimit = $partRepairLimit;
        $this->partRepairMaxTries = $partRepairMaxTries;
        $this->chunkSize = max(50, min(1000, $chunkSize));
    }

    /**
     * Add missing article numbers to the repair queue.
     *
     * @param  list<int>  $numbers
     */
    public function addMissingParts(array $numbers, int $groupId): void
    {
        if (empty($numbers)) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->addMissingPartsSqlite($numbers, $groupId);

            return;
        }

        $this->addMissingPartsMysql($numbers, $groupId);
    }

    /**
     * @param  list<int>  $numbers
     */
    private function addMissingPartsSqlite(array $numbers, int $groupId): void
    {
        foreach (array_chunk(array_unique($numbers), $this->chunkSize) as $chunk) {
            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $number) {
                $placeholders[] = '(?, ?, 1)';
                $bindings[] = $number;
                $bindings[] = $groupId;
            }

            DB::statement(
                'INSERT INTO missed_parts (numberid, groups_id, attempts) VALUES '.implode(',', $placeholders).' ON CONFLICT(numberid, groups_id) DO UPDATE SET attempts = attempts + 1',
                $bindings
            );
        }
    }

    /**
     * @param  list<int>  $numbers
     */
    private function addMissingPartsMysql(array $numbers, int $groupId): void
    {
        foreach (array_chunk(array_unique($numbers), $this->chunkSize) as $chunk) {
            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $number) {
                $placeholders[] = '(?, ?, 1)';
                $bindings[] = $number;
                $bindings[] = $groupId;
            }

            DB::insert(
                'INSERT INTO missed_parts (numberid, groups_id, attempts) VALUES '.implode(',', $placeholders).' ON DUPLICATE KEY UPDATE attempts = attempts + 1',
                $bindings
            );
        }
    }

    /**
     * Remove successfully repaired parts from the queue.
     *
     * @param  list<int>  $numbers
     */
    public function removeRepairedParts(array $numbers, int $groupId): void
    {
        if (empty($numbers)) {
            return;
        }

        try {
            // Single DELETE — InnoDB autocommits one statement atomically, so
            // an explicit transaction here would just add round-trips.
            DB::table('missed_parts')
                ->where('groups_id', $groupId)
                ->whereIn('numberid', $numbers)
                ->delete();
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                Log::warning('removeRepairedParts failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Get parts that need repair for a group.
     *
     * @return array<int, \stdClass> Array of missed parts
     */
    public function getMissingParts(int $groupId): array
    {
        try {
            $query = DB::table('missed_parts')
                ->where('groups_id', $groupId)
                ->where('attempts', '<', $this->partRepairMaxTries);

            if ($this->supportsClaims()) {
                // Explicit BODY-recovery rows belong exclusively to the
                // lease-based worker lane. Letting the legacy lane select an
                // unclaimed/expired tagged row would permit its tokenless
                // acknowledgement path to race a dedicated worker.
                $query->where(static function (Builder $query): void {
                    $query->whereNull('recovery_kind')
                        ->orWhere('recovery_kind', '!=', 'body_preamble');
                });
            }

            return $query
                ->orderBy('numberid')
                ->limit($this->partRepairLimit)
                ->get()
                ->all();
        } catch (\PDOException $e) {
            if ($e->getMessage() === 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction') {
                Log::notice('Deadlock occurred while fetching missed parts');
            }

            return [];
        }
    }

    /**
     * Atomically lease BODY-preamble recovery rows for one worker pass.
     *
     * MariaDB workers skip rows locked by concurrent claimers. SQLite has no
     * row-level locking, so its write transaction provides the safe fallback.
     * Expired claims are eligible for reclaim.
     *
     * @return array<int, object>
     */
    public function claimBodyRecoveryParts(
        int $groupId,
        string $token,
        string $owner,
        int $limit,
        CarbonInterface|string $leaseUntil
    ): array {
        if ($token === '' || $owner === '' || $limit <= 0) {
            return [];
        }

        $limit = min($limit, $this->partRepairLimit);
        $expiresAt = $leaseUntil instanceof CarbonInterface
            ? CarbonImmutable::instance($leaseUntil)
            : CarbonImmutable::parse($leaseUntil);

        return DB::transaction(function () use ($groupId, $token, $owner, $limit, $expiresAt): array {
            $query = DB::table('missed_parts')
                ->select(['id', 'numberid', 'groups_id', 'attempts', 'claim_token'])
                ->where('groups_id', $groupId)
                ->where('recovery_kind', 'body_preamble')
                ->where('attempts', '<', $this->partRepairMaxTries);
            $this->whereClaimAvailable($query);

            // Prefer never-attempted queue entries before revisiting cooled
            // rows. Otherwise a low article number that repeatedly defers can
            // become eligible every few seconds and permanently starve the
            // rest of the recovery queue. These columns also match the
            // recovery-claim index used by MariaDB.
            $query->orderBy('attempts')
                ->orderBy('claim_expires_at')
                ->orderBy('id')
                ->limit($limit);
            if (DB::getDriverName() !== 'sqlite') {
                $query->forceIndex('ix_missed_parts_recovery_claim');
                $query->lock('FOR UPDATE SKIP LOCKED');
            }

            $ids = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            if ($ids === []) {
                return [];
            }

            $claim = DB::table('missed_parts')
                ->whereIn('id', $ids)
                ->where('groups_id', $groupId)
                ->where('recovery_kind', 'body_preamble')
                ->where('attempts', '<', $this->partRepairMaxTries);
            $this->whereClaimAvailable($claim);
            $claim->update([
                'claim_token' => $token,
                'claim_owner' => $owner,
                'claim_expires_at' => $expiresAt,
                'updated_at' => now(),
            ]);

            return DB::table('missed_parts')
                ->select(['id', 'numberid', 'groups_id', 'attempts', 'claim_token'])
                ->whereIn('id', $ids)
                ->where('claim_token', $token)
                ->orderBy('numberid')
                ->get()
                ->all();
        }, 3);
    }

    /** @param list<int> $ids */
    public function removeRepairedClaimedParts(array $ids, string $token): int
    {
        return $this->mutateClaimedChunks(
            $ids,
            $token,
            static fn (Builder $query): int => $query->delete()
        );
    }

    /** @param list<int> $ids */
    public function incrementClaimedAttempts(array $ids, string $token): int
    {
        return $this->mutateClaimedChunks(
            $ids,
            $token,
            static fn (Builder $query): int => $query->increment('attempts')
        );
    }

    /** @param list<int> $ids */
    public function releaseClaimedParts(array $ids, string $token): int
    {
        return $this->mutateClaimedChunks(
            $ids,
            $token,
            static fn (Builder $query): int => $query->update([
                'claim_token' => null,
                'claim_owner' => null,
                'claim_expires_at' => null,
                'updated_at' => now(),
            ])
        );
    }

    /** @param list<int> $ids */
    public function deferClaimedParts(
        array $ids,
        string $token,
        CarbonInterface|string $availableAt
    ): int {
        $nextAttemptAt = $availableAt instanceof CarbonInterface
            ? CarbonImmutable::instance($availableAt)
            : CarbonImmutable::parse($availableAt);

        return $this->mutateClaimedChunks(
            $ids,
            $token,
            static fn (Builder $query): int => $query->update([
                'claim_token' => null,
                'claim_owner' => null,
                'claim_expires_at' => $nextAttemptAt,
                'updated_at' => now(),
            ])
        );
    }

    /** @param list<int> $ids */
    public function renewClaimedParts(array $ids, string $token, CarbonInterface|string $leaseUntil): int
    {
        $expiresAt = $leaseUntil instanceof CarbonInterface
            ? CarbonImmutable::instance($leaseUntil)
            : CarbonImmutable::parse($leaseUntil);

        return $this->mutateClaimedChunks(
            $ids,
            $token,
            static fn (Builder $query): int => $query->update([
                'claim_expires_at' => $expiresAt,
                'updated_at' => now(),
            ])
        );
    }

    /** @param list<int> $ids */
    public function countExistingClaimedIds(array $ids, string $token): int
    {
        if ($token === '') {
            return 0;
        }

        $count = 0;
        foreach ($this->idChunks($ids) as $chunk) {
            $count += DB::table('missed_parts')
                ->whereIn('id', $chunk)
                ->where('claim_token', $token)
                ->where('claim_expires_at', '>', now())
                ->count('id');
        }

        return $count;
    }

    /**
     * Increment attempts for parts that weren't repaired.
     */
    public function incrementAttempts(int $groupId, int $maxNumberId): void
    {
        DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->where('numberid', '<=', $maxNumberId)
            ->increment('attempts');
    }

    /** @param list<int> $ids */
    public function incrementAttemptsForIds(array $ids, int $groupId): void
    {
        foreach (array_chunk(array_values(array_unique($ids)), $this->chunkSize) as $chunk) {
            DB::table('missed_parts')
                ->where('groups_id', $groupId)
                ->whereIn('id', $chunk)
                ->increment('attempts');
        }
    }

    /** @param list<int> $numbers */
    public function decrementAttempts(array $numbers, int $groupId): void
    {
        if ($numbers === []) {
            return;
        }

        foreach (array_chunk(array_values(array_unique($numbers)), $this->chunkSize) as $chunk) {
            DB::table('missed_parts')
                ->where('groups_id', $groupId)
                ->whereIn('numberid', $chunk)
                ->where('attempts', '>', 0)
                ->decrement('attempts');
        }
    }

    /**
     * Increment attempts for specific article range (part repair NNTP failures).
     */
    public function incrementRangeAttempts(int $groupId, int $first, int $last): void
    {
        if ($first === $last) {
            MissedPart::query()
                ->where('groups_id', $groupId)
                ->where('numberid', $first)
                ->increment('attempts');
        } else {
            MissedPart::query()
                ->where('groups_id', $groupId)
                ->whereIn('numberid', range($first, $last))
                ->increment('attempts');
        }
    }

    /**
     * Get count of remaining missed parts.
     */
    public function getCount(int $groupId, int $maxNumberId): int
    {
        return DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->where('numberid', '<=', $maxNumberId)
            ->count('id');
    }

    /** @param list<int> $ids */
    public function countExistingIds(array $ids, int $groupId): int
    {
        $count = 0;
        foreach (array_chunk(array_values(array_unique($ids)), $this->chunkSize) as $chunk) {
            $count += DB::table('missed_parts')
                ->where('groups_id', $groupId)
                ->whereIn('id', $chunk)
                ->count('id');
        }

        return $count;
    }

    /**
     * Remove parts that exceeded max tries.
     */
    public function cleanupExhaustedParts(int $groupId): void
    {
        // Single DELETE — InnoDB autocommits atomically; the explicit
        // transaction wrapper would just add round-trips.
        $query = DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->where('attempts', '>=', $this->partRepairMaxTries);

        if ($this->supportsClaims()) {
            $query->where(static function (Builder $query): void {
                $query->whereNull('recovery_kind')
                    ->orWhere('recovery_kind', '!=', 'body_preamble');
            });
        }

        $query->delete();
    }

    private function supportsClaims(): bool
    {
        return $this->claimColumnsAvailable ??= Schema::hasColumns('missed_parts', [
            'recovery_kind',
            'claim_token',
            'claim_expires_at',
        ]);
    }

    private function whereClaimAvailable(Builder $query): void
    {
        $query->where(static function (Builder $query): void {
            $query->whereNull('claim_expires_at')
                ->orWhere('claim_expires_at', '<=', now());
        });
    }

    /**
     * @param  list<int>  $ids
     * @param  callable(Builder): int  $mutation
     */
    private function mutateClaimedChunks(array $ids, string $token, callable $mutation): int
    {
        if ($token === '') {
            return 0;
        }

        $affected = 0;
        foreach ($this->idChunks($ids) as $chunk) {
            $affected += $mutation(
                DB::table('missed_parts')
                    ->whereIn('id', $chunk)
                    ->where('claim_token', $token)
                    ->where('claim_expires_at', '>', now())
            );
        }

        return $affected;
    }

    /**
     * @param  list<int>  $ids
     * @return list<list<int>>
     */
    private function idChunks(array $ids): array
    {
        return array_chunk(array_values(array_unique($ids)), $this->chunkSize);
    }
}
