<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

/**
 * Scheduled backfill windows.
 *
 * Busy obfuscated groups (boneless, aviation) keep forward fetch, the
 * collection backlog and the database too busy for the orchestrator ever to
 * admit backfill. During a window their forward fetch pauses, so the
 * orchestrator stops counting them as unfetched, never picks them as the
 * backfill target, and may use a window-specific collections low mark. Outside
 * a window nothing changes.
 *
 * The schedule is a comma list of UTC "HH:MM-HH:MM" ranges; a range whose end
 * is before its start wraps past midnight. Malformed ranges are ignored.
 */
final readonly class BackfillWindow
{
    /** @var list<array{int, int}> start/end minute of day */
    private array $ranges;

    /** @var list<string> lower-cased group names */
    private array $pausedGroups;

    /**
     * @param  list<string>|null  $pausedGroups
     */
    public function __construct(
        ?string $schedule = null,
        ?array $pausedGroups = null,
        private ?int $collectionsLow = null,
    ) {
        $this->ranges = self::parse($schedule ?? (string) config('nntmux.orchestrator.backfill_window.schedule', ''));
        $this->pausedGroups = array_values(array_unique(array_filter(array_map(
            static fn (mixed $group): string => strtolower(trim((string) $group)),
            $pausedGroups ?? (array) config('nntmux.orchestrator.backfill_window.paused_groups', []),
        ), static fn (string $group): bool => $group !== '')));
    }

    public function isOpen(?int $now = null): bool
    {
        if ($this->ranges === [] || $this->pausedGroups === []) {
            return false;
        }
        $timestamp = $now ?? time();
        $minute = (int) gmdate('G', $timestamp) * 60 + (int) gmdate('i', $timestamp);
        foreach ($this->ranges as [$start, $end]) {
            $inside = $start < $end
                ? $minute >= $start && $minute < $end
                : $minute >= $start || $minute < $end;
            if ($inside) {
                return true;
            }
        }

        return false;
    }

    /**
     * Groups whose forward fetch is paused right now; empty outside a window.
     *
     * @return list<string>
     */
    public function pausedGroups(?int $now = null): array
    {
        return $this->isOpen($now) ? $this->pausedGroups : [];
    }

    public function pauses(string $group, ?int $now = null): bool
    {
        return in_array(strtolower(trim($group)), $this->pausedGroups($now), true);
    }

    /**
     * Collections low mark to use inside an open window, or null for the
     * normal low marks.
     */
    public function collectionsLow(?int $now = null): ?int
    {
        $low = $this->collectionsLow ?? (int) config('nntmux.orchestrator.backfill_window.collections_low', 0);

        return $low > 0 && $this->isOpen($now) ? $low : null;
    }

    /**
     * @return list<array{int, int}>
     */
    private static function parse(string $schedule): array
    {
        $ranges = [];
        foreach (explode(',', $schedule) as $range) {
            if (! preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $range, $m)) {
                continue;
            }
            [$sh, $sm, $eh, $em] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
            if ($sh > 23 || $eh > 24 || $sm > 59 || $em > 59 || ($eh === 24 && $em !== 0)) {
                continue;
            }
            $start = $sh * 60 + $sm;
            $end = $eh * 60 + $em;
            if ($start === $end) {
                continue;
            }
            $ranges[] = [$start, $end % 1440];
        }

        return $ranges;
    }
}
