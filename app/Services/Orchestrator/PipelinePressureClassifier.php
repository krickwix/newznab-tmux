<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

final readonly class PipelinePressureClassifier
{
    /**
     * @param  array<string, int>|null  $highWatermarks
     * @param  array<string, int>|null  $ageSloSeconds
     */
    public function __construct(
        private ?array $highWatermarks = null,
        private ?array $ageSloSeconds = null,
        private ?int $projectionHorizonMinutes = null,
    ) {}

    /**
     * @param  array<string, int>  $backlogs
     * @param  array<string, int>  $ages
     * @param  array<string, float>  $ewma
     */
    public function isHigh(array $backlogs, array $ages, array $ewma): bool
    {
        foreach ($backlogs as $stage => $value) {
            $limit = $this->highWatermark($stage);
            if ($value >= $limit || $this->projectsBreach($value, $limit, $ewma[$stage] ?? 0.0)) {
                return true;
            }
        }

        foreach ($ages as $stage => $age) {
            $low = (int) floor($this->highWatermark($stage) * 0.6);
            if (($backlogs[$stage] ?? 0) > $low && $age >= $this->ageSlo($stage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $backlogs
     * @param  array<string, float>  $ewma
     */
    public function isLow(array $backlogs, array $ewma): bool
    {
        foreach (['parts', 'binaries'] as $stage) {
            $limit = $this->highWatermark($stage);
            if ($backlogs[$stage] >= $limit
                || $this->projectsBreach($backlogs[$stage], $limit, $ewma[$stage] ?? 0.0)
            ) {
                return false;
            }
        }

        foreach (['collections', 'collections_total', 'recovery_sources', 'releases', 'nzbs'] as $stage) {
            if (! array_key_exists($stage, $backlogs)) {
                continue;
            }
            $low = (int) floor($this->highWatermark($stage) * 0.6);
            if ($backlogs[$stage] >= $low
                || $this->projectsBreach($backlogs[$stage], $low, $ewma[$stage] ?? 0.0)
            ) {
                return false;
            }
        }

        return true;
    }

    private function projectsBreach(int $value, int $limit, float $growthPerMinute): bool
    {
        if (! is_finite($growthPerMinute)) {
            return true;
        }
        if ($growthPerMinute <= 0.0 || $value >= $limit) {
            return $value >= $limit;
        }

        return ($limit - $value) / $growthPerMinute <= $this->horizonMinutes();
    }

    private function highWatermark(string $stage): int
    {
        return max(1, (int) ($this->highWatermarks[$stage]
            ?? config('nntmux.orchestrator.high_watermarks.'.$stage, PHP_INT_MAX)));
    }

    private function ageSlo(string $stage): int
    {
        return max(1, (int) ($this->ageSloSeconds[$stage]
            ?? config('nntmux.orchestrator.age_slo_seconds.'.$stage, PHP_INT_MAX)));
    }

    private function horizonMinutes(): int
    {
        return max(1, $this->projectionHorizonMinutes
            ?? (int) config('nntmux.orchestrator.pressure_projection_horizon_minutes', 120));
    }
}
