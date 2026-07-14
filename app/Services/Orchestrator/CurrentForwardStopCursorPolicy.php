<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

/**
 * Parses immutable, manually audited current-forward windows.
 *
 * Format: group:first-last@stop. Every admitted window is exactly 10k
 * articles and may be consumed only while the live cursor is first - 1.
 */
final class CurrentForwardStopCursorPolicy
{
    /** @var array<string, array{first:int,last:int,stop:int}>|null */
    private ?array $windows = null;

    private bool $valid = true;

    public function isValid(): bool
    {
        $this->parse();

        return $this->valid;
    }

    /** @return list<string> */
    public function groups(): array
    {
        $this->parse();

        return $this->valid ? array_keys($this->windows ?? []) : [];
    }

    /** @return array{first:int,last:int,stop:int}|null */
    public function window(string $group): ?array
    {
        $this->parse();

        return $this->valid ? ($this->windows[trim($group)] ?? null) : null;
    }

    public function matches(string $group, int $first, int $last, int $stop): bool
    {
        return $this->window($group) === compact('first', 'last', 'stop');
    }

    public function protects(string $group): bool
    {
        return $this->window($group) !== null;
    }

    private function parse(): void
    {
        if ($this->windows !== null) {
            return;
        }

        $this->windows = [];
        $raw = trim((string) config('nntmux.orchestrator.current_forward_windows', ''));
        if ($raw === '') {
            return;
        }

        foreach (explode(',', $raw) as $entry) {
            if (preg_match('/^([^:\s,]+):([1-9][0-9]*)-([1-9][0-9]*)@([1-9][0-9]*)$/', trim($entry), $match) !== 1) {
                $this->fail();

                return;
            }
            $group = $match[1];
            $first = (int) $match[2];
            $last = (int) $match[3];
            $stop = (int) $match[4];
            if (isset($this->windows[$group]) || $last - $first + 1 !== 10_000 || $stop < $last) {
                $this->fail();

                return;
            }
            $this->windows[$group] = compact('first', 'last', 'stop');
        }
    }

    private function fail(): void
    {
        $this->valid = false;
        $this->windows = [];
    }
}
