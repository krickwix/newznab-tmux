<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

/**
 * Tuning for the free_adaptive backfill delay, validated at the boundary.
 *
 * The governor runs inside the orchestrator control loop, which must never
 * throw on a bad env value, so an inconsistent set falls back to the defaults
 * as a whole rather than being half-applied.
 */
final readonly class FreeAdaptiveConfig
{
    public const array KNOWN_STAGES = ['parts', 'binaries', 'collections'];

    /**
     * @param  list<string>  $stages
     * @param  array<string, int>  $highWatermarks
     */
    public function __construct(
        public int $initialDelay = 120,
        public int $minDelay = 0,
        public int $maxDelay = 3600,
        public float $increaseFactor = 1.5,
        public int $increaseStep = 30,
        public float $decreaseFactor = 0.8,
        public int $snapToMinBelow = 10,
        public float $growThreshold = 1.0,
        public float $shrinkThreshold = 0.9,
        public int $horizonMinutes = 10,
        public int $settleSeconds = 300,
        public int $staleSeconds = 180,
        public int $minSamples = 3,
        public array $stages = self::KNOWN_STAGES,
        public array $highWatermarks = [],
    ) {}

    public static function fromConfig(): self
    {
        /** @var mixed $raw */
        $raw = config('nntmux.orchestrator.free_adaptive', []);
        $raw = is_array($raw) ? $raw : [];
        $defaults = new self;
        $stages = $raw['stages'] ?? $defaults->stages;
        $stages = is_string($stages) ? explode(',', $stages) : (is_array($stages) ? $stages : []);
        $stages = array_values(array_intersect(self::KNOWN_STAGES, array_map(
            static fn ($stage): string => trim((string) $stage),
            $stages,
        )));
        $watermarks = [];
        foreach (self::KNOWN_STAGES as $stage) {
            $watermarks[$stage] = (int) config('nntmux.orchestrator.high_watermarks.'.$stage, 0);
        }

        $candidate = new self(
            initialDelay: (int) ($raw['initial_delay_seconds'] ?? $defaults->initialDelay),
            minDelay: (int) ($raw['min_delay_seconds'] ?? $defaults->minDelay),
            maxDelay: (int) ($raw['max_delay_seconds'] ?? $defaults->maxDelay),
            increaseFactor: (float) ($raw['increase_factor'] ?? $defaults->increaseFactor),
            increaseStep: (int) ($raw['increase_step_seconds'] ?? $defaults->increaseStep),
            decreaseFactor: (float) ($raw['decrease_factor'] ?? $defaults->decreaseFactor),
            snapToMinBelow: (int) ($raw['snap_to_min_below_seconds'] ?? $defaults->snapToMinBelow),
            growThreshold: (float) ($raw['grow_threshold'] ?? $defaults->growThreshold),
            shrinkThreshold: (float) ($raw['shrink_threshold'] ?? $defaults->shrinkThreshold),
            horizonMinutes: min(120, max(1, (int) ($raw['horizon_minutes'] ?? $defaults->horizonMinutes))),
            minSamples: (int) ($raw['min_samples'] ?? $defaults->minSamples),
            settleSeconds: (int) ($raw['settle_seconds'] ?? $defaults->settleSeconds),
            staleSeconds: (int) ($raw['stale_seconds'] ?? $defaults->staleSeconds),
            stages: $stages,
            highWatermarks: $watermarks,
        );

        return $candidate->isConsistent() ? $candidate : new self(highWatermarks: $watermarks);
    }

    private function isConsistent(): bool
    {
        return $this->minDelay >= 0
            && $this->minDelay <= $this->initialDelay
            && $this->initialDelay <= $this->maxDelay
            && $this->increaseFactor > 1.0
            && $this->increaseStep >= 1
            && $this->decreaseFactor > 0.0 && $this->decreaseFactor < 1.0
            && $this->snapToMinBelow >= 0
            && $this->shrinkThreshold > 0.0
            && $this->shrinkThreshold <= $this->growThreshold
            && $this->minSamples >= 1
            && $this->settleSeconds >= 0
            && $this->staleSeconds > 0
            && $this->stages !== [];
    }
}
