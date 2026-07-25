<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

/**
 * Parses durable refresh-only trust anchors without making them executable
 * static current-forward corridors.
 *
 * Format: group:first-last. Dedicated anchors are exactly one 10k window.
 * Existing static corridors remain trusted for backwards compatibility.
 */
final class CurrentForwardRefreshTrustPolicy
{
    /** @var array<string, array{first:int,last:int}>|null */
    private ?array $anchors = null;

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

        return $this->valid ? array_keys($this->anchors ?? []) : [];
    }

    /** @return array{first:int,last:int}|null */
    public function anchor(string $group): ?array
    {
        $this->parse();

        return $this->valid ? ($this->anchors[trim($group)] ?? null) : null;
    }

    public function protects(string $group): bool
    {
        return $this->anchor($group) !== null;
    }

    private function parse(): void
    {
        if ($this->anchors !== null) {
            return;
        }

        $this->anchors = [];
        $static = new CurrentForwardStopCursorPolicy;
        if (! $static->isValid()) {
            $this->fail();

            return;
        }
        foreach ($static->groups() as $group) {
            $window = $static->window($group);
            if ($window !== null) {
                $this->anchors[$group] = [
                    'first' => $window['first'],
                    'last' => $window['last'],
                ];
            }
        }

        $raw = trim((string) config('nntmux.orchestrator.current_forward_refresh_sources', ''));
        if ($raw === '') {
            return;
        }
        foreach (explode(',', $raw) as $entry) {
            if (preg_match('/^([^:\s,]+):([1-9][0-9]*)-([1-9][0-9]*)$/D', trim($entry), $match) !== 1) {
                $this->fail();

                return;
            }
            $group = $match[1];
            $first = (int) $match[2];
            $last = (int) $match[3];
            if ($last - $first + 1 !== 10_000
                || (isset($this->anchors[$group])
                    && $this->anchors[$group] !== ['first' => $first, 'last' => $last])
            ) {
                $this->fail();

                return;
            }
            $this->anchors[$group] = compact('first', 'last');
        }
    }

    private function fail(): void
    {
        $this->valid = false;
        $this->anchors = [];
    }
}
