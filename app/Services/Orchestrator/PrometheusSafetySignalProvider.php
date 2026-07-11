<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Support\Facades\Http;
use Throwable;

class PrometheusSafetySignalProvider
{
    /** @return array{fresh: bool, memory_safe: bool, cpu_safe: bool, storage_safe: bool, storage_available_bytes: int} */
    public function signals(): array
    {
        try {
            $storage = $this->query((string) config('nntmux.orchestrator.promql.storage_available'));
            $memory = $this->query((string) config('nntmux.orchestrator.promql.database_memory'));
            $cpu = $this->query((string) config('nntmux.orchestrator.promql.database_cpu'));

            return [
                'fresh' => $storage !== null && $memory !== null && $cpu !== null,
                'memory_safe' => $memory !== null && $memory < (float) config('nntmux.orchestrator.database_memory_limit_bytes'),
                'cpu_safe' => $cpu !== null && $cpu < (float) config('nntmux.orchestrator.database_cpu_limit_cores'),
                'storage_safe' => $storage !== null && $storage >= (float) config('nntmux.orchestrator.storage_floor_bytes'),
                'storage_available_bytes' => max(0, (int) ($storage ?? 0)),
            ];
        } catch (Throwable) {
            return [
                'fresh' => false,
                'memory_safe' => false,
                'cpu_safe' => false,
                'storage_safe' => false,
                'storage_available_bytes' => 0,
            ];
        }
    }

    private function query(string $query): ?float
    {
        if ($query === '') {
            return null;
        }

        $response = Http::timeout(5)
            ->retry(1, 100)
            ->get(rtrim((string) config('nntmux.orchestrator.prometheus_url'), '/').'/api/v1/query', ['query' => $query]);
        if (! $response->successful() || $response->json('status') !== 'success') {
            return null;
        }

        $result = $response->json('data.result');
        if (! is_array($result) || count($result) !== 1 || ! isset($result[0]['value'][1]) || ! is_numeric($result[0]['value'][1])) {
            return null;
        }

        return (float) $result[0]['value'][1];
    }
}
