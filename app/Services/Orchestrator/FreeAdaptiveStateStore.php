<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;

/**
 * Durable home of the free_adaptive state, in the same settings table as the
 * other orchestrator_* rows so every pod and the status service can read it.
 *
 * Redis is deliberately not used: a flush would silently reset the learned
 * delay.
 */
class FreeAdaptiveStateStore
{
    public const string DELAY = 'orchestrator_fa_delay';

    public const string UPDATED_AT = 'orchestrator_fa_changed';

    public const string CHECKED_AT = 'orchestrator_fa_checked';

    // Setting names must fit settings.name varchar(25).

    /** Stamped by BackfillPermitGate on complete() and fail(). */
    public const string LAST_DONE_AT = 'orchestrator_fa_done';

    /** Derived (last_done_at + delay), published for display only. */
    public const string NEXT_AT = 'orchestrator_fa_next_at';

    public const string REASON = 'orchestrator_fa_reason';

    /** Decision-window accumulator: sum and count of fresh samples, and its start. */
    public const string WINDOW_SUM = 'orchestrator_fa_psum';

    public const string WINDOW_SAMPLES = 'orchestrator_fa_pn';

    public const string WINDOW_START = 'orchestrator_fa_wstart';

    public const string WINDOW_BAD = 'orchestrator_fa_pbad';

    /** @var list<string> */
    public const array ALL = [
        self::DELAY, self::UPDATED_AT, self::CHECKED_AT, self::LAST_DONE_AT, self::NEXT_AT, self::REASON,
        self::WINDOW_SUM, self::WINDOW_SAMPLES, self::WINDOW_START, self::WINDOW_BAD,
    ];

    /** Reads the stored state; a missing delay row yields delay -1 (never stored). */
    public function read(): FreeAdaptiveState
    {
        return self::fromRows(array_combine(
            self::ALL,
            array_map(static fn (string $name): mixed => Settings::settingValue($name), self::ALL),
        ));
    }

    /** @param  array<string, mixed>  $rows  settings by name */
    public static function fromRows(array $rows): FreeAdaptiveState
    {
        $delay = $rows[self::DELAY] ?? null;
        $meta = json_decode((string) ($rows[self::REASON] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];

        return new FreeAdaptiveState(
            delay: $delay === null || $delay === '' ? -1 : max(0, (int) $delay),
            updatedAt: (int) ($rows[self::UPDATED_AT] ?? 0),
            checkedAt: (int) ($rows[self::CHECKED_AT] ?? 0),
            lastDoneAt: (int) ($rows[self::LAST_DONE_AT] ?? 0),
            reason: (string) ($meta['reason'] ?? 'none'),
            bindingStage: (string) ($meta['stage'] ?? ''),
            projected: (float) ($meta['p'] ?? 0.0),
            ewma: (float) ($meta['ewma'] ?? 0.0),
            windowSum: (float) ($rows[self::WINDOW_SUM] ?? 0.0),
            windowSamples: (int) ($rows[self::WINDOW_SAMPLES] ?? 0),
            windowStart: (int) ($rows[self::WINDOW_START] ?? 0),
            windowBad: (int) ($rows[self::WINDOW_BAD] ?? 0),
        );
    }

    public function write(FreeAdaptiveState $state): void
    {
        $rows = [
            self::DELAY => (string) $state->delay,
            self::UPDATED_AT => (string) $state->updatedAt,
            self::CHECKED_AT => (string) $state->checkedAt,
            self::WINDOW_SUM => (string) round($state->windowSum, 6),
            self::WINDOW_SAMPLES => (string) $state->windowSamples,
            self::WINDOW_START => (string) $state->windowStart,
            self::WINDOW_BAD => (string) $state->windowBad,
            self::NEXT_AT => (string) $state->nextEligibleAt(),
            self::REASON => json_encode([
                'reason' => $state->reason,
                'stage' => $state->bindingStage,
                'p' => round($state->projected, 4),
                'ewma' => round($state->ewma, 2),
            ], JSON_THROW_ON_ERROR),
        ];
        foreach ($rows as $name => $value) {
            Settings::query()->updateOrCreate(['name' => $name], ['value' => $value]);
        }
        Settings::forgetCachedSettings();
    }
}
