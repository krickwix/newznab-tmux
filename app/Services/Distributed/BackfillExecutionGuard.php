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
