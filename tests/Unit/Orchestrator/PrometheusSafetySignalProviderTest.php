<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\PrometheusSafetySignalProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PrometheusSafetySignalProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nntmux.orchestrator.prometheus_url' => 'http://prometheus.test/',
            'nntmux.orchestrator.promql.storage_available' => 'storage-query',
            'nntmux.orchestrator.promql.database_memory' => 'memory-query',
            'nntmux.orchestrator.promql.database_cpu' => 'cpu-query',
            'nntmux.orchestrator.promql_freshness.storage_available' => 'storage-freshness-query',
            'nntmux.orchestrator.promql_freshness.database_memory' => 'memory-freshness-query',
            'nntmux.orchestrator.promql_freshness.database_cpu' => 'cpu-freshness-query',
            'nntmux.orchestrator.storage_floor_bytes' => 18_500,
            'nntmux.orchestrator.database_memory_limit_bytes' => 4_250,
            'nntmux.orchestrator.database_cpu_limit_cores' => 3,
            'nntmux.orchestrator.prometheus_retry_attempts' => 3,
            'nntmux.orchestrator.prometheus_sample_max_age_seconds' => 120,
        ]);
    }

    public function test_it_returns_fresh_safe_signals_from_three_single_series_queries(): void
    {
        Http::fakeSequence()
            ->push($this->prometheusResult('20000'))
            ->push($this->prometheusFreshnessResult())
            ->push($this->prometheusResult('4000'))
            ->push($this->prometheusFreshnessResult())
            ->push($this->prometheusResult('2.5'))
            ->push($this->prometheusFreshnessResult());

        self::assertSame([
            'fresh' => true,
            'memory_safe' => true,
            'cpu_safe' => true,
            'storage_safe' => true,
            'storage_available_bytes' => 20_000,
        ], (new PrometheusSafetySignalProvider)->signals());

        $queries = [];
        Http::assertSent(function (Request $request) use (&$queries): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
            $queries[] = $parameters['query'] ?? null;

            return str_starts_with($request->url(), 'http://prometheus.test/api/v1/query?');
        });
        self::assertSame([
            'storage-query',
            'storage-freshness-query',
            'memory-query',
            'memory-freshness-query',
            'cpu-query',
            'cpu-freshness-query',
        ], $queries);
    }

    public function test_an_http_failure_fails_the_affected_signal_closed(): void
    {
        Http::fakeSequence()
            ->pushStatus(503)
            ->pushStatus(503)
            ->pushStatus(503)
            ->push($this->prometheusResult('4000'))
            ->push($this->prometheusFreshnessResult())
            ->push($this->prometheusResult('2.5'))
            ->push($this->prometheusFreshnessResult());

        $signals = (new PrometheusSafetySignalProvider)->signals();

        self::assertFalse($signals['fresh']);
        self::assertTrue($signals['memory_safe']);
        self::assertTrue($signals['cpu_safe']);
        self::assertFalse($signals['storage_safe']);
        self::assertSame(0, $signals['storage_available_bytes']);
    }

    public function test_a_single_transient_http_failure_is_retried_without_weakening_the_gate(): void
    {
        Http::fakeSequence()
            ->pushStatus(503)
            ->push($this->prometheusResult('20000'))
            ->push($this->prometheusFreshnessResult())
            ->push($this->prometheusResult('4000'))
            ->push($this->prometheusFreshnessResult())
            ->push($this->prometheusResult('2.5'))
            ->push($this->prometheusFreshnessResult());

        $signals = (new PrometheusSafetySignalProvider)->signals();

        self::assertTrue($signals['fresh']);
        self::assertTrue($signals['memory_safe']);
        self::assertTrue($signals['cpu_safe']);
        self::assertTrue($signals['storage_safe']);
        Http::assertSentCount(7);
    }

    public function test_multiple_series_are_rejected_as_ambiguous_cardinality(): void
    {
        Http::fakeSequence()
            ->push($this->prometheusResult('20000', '19000'))
            ->push($this->prometheusResult('4000'))
            ->push($this->prometheusFreshnessResult())
            ->push($this->prometheusResult('2.5'))
            ->push($this->prometheusFreshnessResult());

        $signals = (new PrometheusSafetySignalProvider)->signals();

        self::assertFalse($signals['fresh']);
        self::assertFalse($signals['storage_safe']);
        self::assertSame(0, $signals['storage_available_bytes']);
    }

    public function test_stale_future_or_malformed_samples_fail_closed(): void
    {
        foreach ([time() - 121, time() + 31, 'invalid'] as $sampleTimestamp) {
            Http::fakeSequence()
                ->push($this->prometheusResult('20000'))
                ->push($this->prometheusResult((string) $sampleTimestamp))
                ->push($this->prometheusResult('4000'))
                ->push($this->prometheusFreshnessResult())
                ->push($this->prometheusResult('2.5'))
                ->push($this->prometheusFreshnessResult());

            $signals = (new PrometheusSafetySignalProvider)->signals();

            self::assertFalse($signals['fresh']);
            self::assertFalse($signals['storage_safe']);
            self::assertSame(0, $signals['storage_available_bytes']);
        }
    }

    /** @return array{status: string, data: array{result: list<array{value: array{int, string}}>}} */
    private function prometheusResult(string ...$values): array
    {
        return [
            'status' => 'success',
            'data' => [
                'result' => array_map(
                    static fn (string $value): array => ['value' => [time(), $value]],
                    $values,
                ),
            ],
        ];
    }

    /** @return array{status: string, data: array{result: list<array{value: array{int, string}}>}} */
    private function prometheusFreshnessResult(): array
    {
        return $this->prometheusResult((string) time());
    }
}
