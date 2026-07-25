<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

enum ControlProfile: string
{
    case FailSafe = 'fail_safe';
    case Drain = 'drain';
    case Balanced = 'balanced';
    case Fill = 'fill';

    public function rung(): int
    {
        return match ($this) {
            self::FailSafe => 0,
            self::Drain => 1,
            self::Balanced => 2,
            self::Fill => 3,
        };
    }

    public function stepDown(): self
    {
        return match ($this) {
            self::Fill => self::Balanced,
            self::Balanced => self::Drain,
            self::Drain, self::FailSafe => self::FailSafe,
        };
    }

    public function stepUp(): self
    {
        return match ($this) {
            self::FailSafe => self::Drain,
            self::Drain => self::Balanced,
            self::Balanced, self::Fill => self::Fill,
        };
    }
}
