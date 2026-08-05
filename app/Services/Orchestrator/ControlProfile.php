<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

enum ControlProfile: string
{
    case FailSafe = 'fail_safe';
    case Drain = 'drain';
    case Balanced = 'balanced';
    case Fill = 'fill';

    /**
     * Every governor off: no sleeps, no permit gating, no admission checks.
     *
     * Deliberately unreachable by the adaptive ladder -- stepUp() stops at Fill,
     * so pressure can never promote INTO this. It is entered only by explicit
     * operator config. The gates it bypasses exist because of real incidents in
     * this fleet: a 177k-collection ingest burst that self-locked the
     * orchestrator into fail_safe, and a MariaDB working set that pinned it
     * there for hours. Nothing should arrive here by inference.
     */
    case FreeRun = 'free_run';

    /**
     * Operator-facing summary, shown by `nntmux:orchestrator-mode list`.
     */
    public function description(): string
    {
        return match ($this) {
            self::FailSafe => 'Everything parked. Backfill off, long sleeps. The floor the safety gates force.',
            self::Drain => 'Work the existing backlog down. Backfill off, no new headers admitted.',
            self::Balanced => 'Steady state. Backfill on at one group/thread, moderate sleeps.',
            self::Fill => 'Top of the adaptive ladder. Backfill at the configured fill width, short sleeps.',
            self::FreeRun => 'Every governor off: no sleeps, no permit gating, no safety demotion. Operator only.',
        };
    }

    /**
     * Whether this mode ignores the hard-safety gates.
     *
     * Only free-run does. Pinning any other mode still yields to a database or
     * disk in trouble -- the pin replaces the ladder's choice, not the brakes.
     */
    public function bypassesSafety(): bool
    {
        return $this === self::FreeRun;
    }

    public function rung(): int
    {
        return match ($this) {
            self::FailSafe => 0,
            self::Drain => 1,
            self::Balanced => 2,
            self::Fill => 3,
            self::FreeRun => 4,
        };
    }

    public function stepDown(): self
    {
        return match ($this) {
            // FreeRun steps down like Fill. If an operator turns free-run off
            // while pressure is high, the ladder resumes at Balanced rather
            // than dropping straight to fail_safe.
            self::FreeRun, self::Fill => self::Balanced,
            self::Balanced => self::Drain,
            self::Drain, self::FailSafe => self::FailSafe,
        };
    }

    public function stepUp(): self
    {
        return match ($this) {
            self::FailSafe => self::Drain,
            self::Drain => self::Balanced,
            // Fill is the top of the ADAPTIVE ladder; FreeRun is never reached
            // by promotion, only by explicit config.
            self::Balanced, self::Fill => self::Fill,
            self::FreeRun => self::FreeRun,
        };
    }
}
