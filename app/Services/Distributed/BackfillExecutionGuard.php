<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use App\Models\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Fail-closed admission at the last boundary before managed backfill can touch
 * the NNTP provider.
 */
class BackfillExecutionGuard
{
    public function enforcementEnabled(): bool
    {
        return (bool) config('nntmux.orchestrator.require_backfill_permit', false);
    }

    public function assertLegacyCommandAllowed(string $command): void
    {
        if (! $this->enforcementEnabled()) {
            return;
        }

        throw new RuntimeException(
            "{$command} is disabled while generation-scoped backfill permits are required."
        );
    }

    public function assertRangeAllowed(int $generation, string $group, int $first, int $last): void
    {
        if (! $this->enforcementEnabled()) {
            return;
        }

        DB::transaction(fn () => $this->validateLocked($generation, $group, $first, $last), 3);
    }

    public function claimRange(int $generation, string $group, int $first, int $last): int
    {
        if (! $this->enforcementEnabled()) {
            return 0;
        }

        return DB::transaction(function () use ($generation, $group, $first, $last): int {
            $this->validateLocked($generation, $group, $first, $last);
            if (! Schema::hasTable('backfill_execution_ranges')) {
                throw new RuntimeException('Backfill execution receipt storage is unavailable.');
            }

            $overlap = DB::table('backfill_execution_ranges')
                ->where('generation', $generation)
                ->where('first_article', '<=', $last)
                ->where('last_article', '>=', $first)
                ->lockForUpdate()
                ->first();
            if ($overlap !== null) {
                throw new RuntimeException('Backfill range overlaps an existing generation receipt.');
            }

            return (int) DB::table('backfill_execution_ranges')->insertGetId([
                'generation' => $generation,
                'group_name' => $group,
                'first_article' => $first,
                'last_article' => $last,
                'status' => 'CLAIMED',
                'claimed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function completeRange(int $receiptId): bool
    {
        if ($receiptId <= 0) {
            return ! $this->enforcementEnabled();
        }

        return DB::table('backfill_execution_ranges')
            ->where('id', $receiptId)
            ->where('status', 'CLAIMED')
            ->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    public function failRange(int $receiptId, string $error): void
    {
        if ($receiptId <= 0 || ! Schema::hasTable('backfill_execution_ranges')) {
            return;
        }

        DB::table('backfill_execution_ranges')
            ->where('id', $receiptId)
            ->where('status', 'CLAIMED')
            ->update([
                'status' => 'FAILED',
                'error' => mb_substr($error, 0, 1000),
                'updated_at' => now(),
            ]);
    }

    /**
     * Drop settled receipts once they are older than the retention window.
     *
     * Nothing prunes this table otherwise, so it grew to 1,075 abandoned rows
     * from a ten-day window in July and sat there for 95 days. With
     * enforcement on it gains one row per chunk -- 35 per 350k permit -- so it
     * needs a floor sweep, not a one-off cleanup.
     *
     * Two guards make this safe to run while backfill is live:
     *  - generation < orchestrator_bf_claimed, so the in-flight generation and
     *    anything newer are untouchable. complete() reads a generation's
     *    receipts seconds after the claim; deleting them would fail a permit
     *    that did its work.
     *  - claimed_at older than the window. claimed_at is NOT NULL, while
     *    completed_at is null for CLAIMED and FAILED rows.
     *
     * @return array{retention_days: int, cutoff: string, floor_generation: int, deleted: int, applied: bool}
     */
    public function pruneSettledReceipts(?int $retentionDays = null, int $limit = 20_000, bool $apply = false): array
    {
        $retentionDays = max(1, $retentionDays ?? (int) config('nntmux.backfill_receipt_retention_days', 14));
        $limit = max(1, $limit);
        $cutoff = now()->subDays($retentionDays);
        $summary = [
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toDateTimeString(),
            'floor_generation' => 0,
            'deleted' => 0,
            'applied' => $apply,
        ];
        if (! Schema::hasTable('backfill_execution_ranges')) {
            return $summary;
        }

        // A claimed generation with no settlement yet is the live one. Fall
        // back to the completed marker, and to 0 (prune nothing) if neither is
        // readable, so a missing settings row cannot widen the delete.
        $claimed = (int) Settings::settingValue('orchestrator_bf_claimed');
        $completed = (int) Settings::settingValue('orchestrator_bf_completed');
        $floor = max(0, $claimed > 0 ? $claimed : $completed);
        $summary['floor_generation'] = $floor;
        if ($floor <= 0) {
            return $summary;
        }

        $query = DB::table('backfill_execution_ranges')
            ->where('generation', '<', $floor)
            ->where('claimed_at', '<', $cutoff);

        if (! $apply) {
            $summary['deleted'] = (int) (clone $query)->limit($limit)->count();

            return $summary;
        }

        $summary['deleted'] = (int) $query->limit($limit)->delete();

        return $summary;
    }

    private function validateLocked(int $generation, string $group, int $first, int $last): void
    {
        $rows = Settings::query()
            ->whereIn('name', [
                'orchestrator_mode',
                'orchestrator_lease_until',
                'orchestrator_bf_claimed',
                'orchestrator_bf_completed',
                'orchestrator_bf_failed',
                'orchestrator_bfc_group',
                'orchestrator_bfc_first',
                'orchestrator_bfc_last',
            ])
            ->orderBy('name')
            ->lockForUpdate()
            ->get()
            ->mapWithKeys(static fn (Settings $setting): array => [
                $setting->name => $setting->getRawOriginal('value'),
            ]);

        $envelopeFirst = (int) $rows->get('orchestrator_bfc_first', 0);
        $envelopeLast = (int) $rows->get('orchestrator_bfc_last', 0);
        $valid = $generation > 0
            && $first > 0
            && $last >= $first
            && (string) $rows->get('orchestrator_mode', '') === 'active'
            && (int) $rows->get('orchestrator_lease_until', 0) >= time()
            && (int) $rows->get('orchestrator_bf_claimed', 0) === $generation
            && (int) $rows->get('orchestrator_bf_completed', 0) !== $generation
            && (int) $rows->get('orchestrator_bf_failed', 0) !== $generation
            && hash_equals((string) $rows->get('orchestrator_bfc_group', ''), $group)
            && $envelopeFirst > 0
            && $envelopeLast >= $envelopeFirst
            && $first >= $envelopeFirst
            && $last <= $envelopeLast;

        if (! $valid) {
            throw new RuntimeException('Backfill generation was stale or did not match the immutable group/range envelope.');
        }
    }
}
