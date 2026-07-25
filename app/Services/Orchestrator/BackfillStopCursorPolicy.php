<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

/**
 * Parses and enforces audited per-source backfill floors.
 *
 * A non-empty malformed configuration fails closed for every managed source so
 * planner and executor cannot disagree about an unsafe partial configuration.
 */
final class BackfillStopCursorPolicy
{
    /** @var array<string, int>|null */
    private ?array $stops = null;

    private bool $valid = true;

    public function isValid(): bool
    {
        $this->parse();

        return $this->valid;
    }

    public function stopCursor(string $group): ?int
    {
        $this->parse();
        if (! $this->valid) {
            return null;
        }

        return $this->stops[trim($group)] ?? null;
    }

    public function remainingArticles(string $group, int $cursor, int $remainingArticles): int
    {
        $remainingArticles = max(0, $remainingArticles);
        $this->parse();
        if (! $this->valid) {
            return 0;
        }

        $stopCursor = $this->stops[trim($group)] ?? null;
        if ($stopCursor === null) {
            return $remainingArticles;
        }

        $providerFirst = max(0, $cursor - $remainingArticles);
        if ($stopCursor < $providerFirst || $stopCursor >= $cursor) {
            return 0;
        }

        // The planner subtracts a 10k provider reserve when deriving a permit.
        // Add it around the explicit floor so the final grant lands on it.
        return min($remainingArticles, $cursor - $stopCursor + 10_000);
    }

    public function clampQuantity(string $group, int $cursor, int $requested): int
    {
        $requested = max(0, $requested);
        $this->parse();
        if (! $this->valid) {
            return 0;
        }

        $stopCursor = $this->stops[trim($group)] ?? null;
        if ($stopCursor === null) {
            return $requested;
        }

        return min($requested, max(0, $cursor - $stopCursor));
    }

    private function parse(): void
    {
        if ($this->stops !== null) {
            return;
        }

        $this->stops = [];
        $configured = config('nntmux.orchestrator.backfill_stop_cursors', '');
        if (is_array($configured)) {
            foreach ($configured as $group => $cursor) {
                if (! is_string($group) || ! $this->add(trim($group), trim((string) $cursor))) {
                    $this->valid = false;

                    return;
                }
            }

            return;
        }

        $raw = trim((string) $configured);
        if ($raw === '') {
            return;
        }

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if (substr_count($entry, ':') !== 1) {
                $this->valid = false;

                return;
            }
            [$group, $cursor] = array_map('trim', explode(':', $entry, 2));
            if (! $this->add($group, $cursor)) {
                $this->valid = false;

                return;
            }
        }
    }

    private function add(string $group, string $cursor): bool
    {
        if ($group === '' || isset($this->stops[$group]) || $cursor === '' || preg_match('/^[1-9][0-9]*$/', $cursor) !== 1) {
            return false;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($cursor) > strlen($max) || (strlen($cursor) === strlen($max) && strcmp($cursor, $max) > 0)) {
            return false;
        }

        $this->stops[$group] = (int) $cursor;

        return true;
    }
}
