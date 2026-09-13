<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use App\Models\Settings;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

final class BackfillDateEligibilityPolicy
{
    public function __construct(
        private readonly int $mode,
        private readonly int $globalDays = 0,
    ) {}

    public static function fromRuntime(): self
    {
        $override = config('nntmux.orchestrator.backfill_days_override');
        $mode = $override === null || $override === ''
            ? (int) Settings::settingValue('backfill_days')
            : (int) $override;
        $globalDays = $mode === 2
            ? (int) now()->diffInDays(Carbon::createFromFormat('Y-m-d', (string) Settings::settingValue('safebackfilldate')), true)
            : 0;

        return new self($mode, $globalDays);
    }

    public function datePendingSql(string $alias): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/i', $alias) !== 1) {
            throw new InvalidArgumentException('Invalid SQL table alias.');
        }

        $days = $this->mode === 1 ? "{$alias}.backfill_target" : (string) ($this->mode === 2 ? $this->globalDays : 0);

        return "(NOW() - INTERVAL {$days} DAY) < {$alias}.first_record_postdate";
    }

    public function isPending(?string $firstRecordPostdate, int $groupTargetDays, ?Carbon $at = null): bool
    {
        if ($firstRecordPostdate === null || trim($firstRecordPostdate) === '') {
            return false;
        }

        try {
            $cursorPostdate = Carbon::parse($firstRecordPostdate);
        } catch (Throwable) {
            return false;
        }

        $observedAt = ($at ?? now())->copy();
        if ($cursorPostdate->lt(Carbon::parse('2000-01-01 00:00:00'))
            || $cursorPostdate->gt($observedAt)
        ) {
            return false;
        }

        $days = $this->mode === 1
            ? max(0, $groupTargetDays)
            : ($this->mode === 2 ? $this->globalDays : 0);

        return $observedAt->subDays($days)->lt($cursorPostdate);
    }
}
