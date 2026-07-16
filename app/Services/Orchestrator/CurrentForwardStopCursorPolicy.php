<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

/**
 * Parses immutable, manually audited current-forward corridors.
 *
 * Format: group:first-last@stop. Every corridor contains an integral number of
 * exact 10k windows and may be consumed only in cursor order.
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
        $corridor = $this->window($group);

        return $corridor !== null
            && $stop === $corridor['stop']
            && $last - $first + 1 === 10_000
            && $first >= $corridor['first']
            && $last <= $corridor['last']
            && ($first - $corridor['first']) % 10_000 === 0;
    }

    /** @return array{first:int,last:int,stop:int}|null */
    public function nextWindow(string $group, int $cursor): ?array
    {
        $corridor = $this->window($group);
        if ($corridor === null || $cursor < $corridor['first'] - 1 || $cursor >= $corridor['last']) {
            return null;
        }
        $first = $cursor + 1;
        $last = $first + 9_999;
        if (($first - $corridor['first']) % 10_000 !== 0 || $last > $corridor['last']) {
            return null;
        }

        return ['first' => $first, 'last' => $last, 'stop' => $corridor['stop']];
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
            $length = $last - $first + 1;
            if (isset($this->windows[$group]) || $length < 10_000 || $length % 10_000 !== 0 || $stop < $last) {
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
