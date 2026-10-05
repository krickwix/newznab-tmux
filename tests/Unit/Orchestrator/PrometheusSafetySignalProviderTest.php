<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\PrometheusSafetySignalProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
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
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.safety_sample_hold_seconds' => 120,
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
            'memory_known' => true,
            'cpu_known' => true,
            'storage_known' => true,
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

    public function test_a_connection_failure_on_one_query_does_not_erase_the_other_signals(): void
    {
        config(['nntmux.orchestrator.safety_sample_hold_seconds' => 0]);
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
            if (str_starts_with((string) $parameters['query'], 'storage')) {
                throw new ConnectionException('timeout');
            }

            return Http::response(str_ends_with((string) $parameters['query'], 'freshness-query')
                ? $this->prometheusFreshnessResult()
                : $this->prometheusResult('1'));
        });

        $signals = (new PrometheusSafetySignalProvider)->signals();

        self::assertFalse($signals['storage_known']);
        self::assertTrue($signals['memory_known']);
        self::assertTrue($signals['cpu_known']);
    }

    public function test_a_failed_fetch_within_the_hold_window_reuses_the_last_good_sample(): void
    {
        $this->fakeGoodSample('20000', '4000', '2.5');
        $good = (new PrometheusSafetySignalProvider)->signals();
        $this->resetHttpFake();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $signals = (new PrometheusSafetySignalProvider)->signals();

        self::assertTrue($signals['fresh']);
        self::assertSame('held', $signals['sample_source']);
        self::assertGreaterThanOrEqual(0, $signals['sample_age_seconds']);
        self::assertLessThanOrEqual(2, $signals['sample_age_seconds']);
        self::assertSame($good, array_diff_key($signals, ['sample_source' => 1, 'sample_age_seconds' => 1]));
    }

    public function test_the_hold_expires_and_falls_back_to_fail_closed(): void
    {
        $this->fakeGoodSample('20000', '4000', '2.5');
        (new PrometheusSafetySignalProvider)->signals();
        $this->resetHttpFake();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $key = 'nntmux:orchestrator:last-good-safety-sample';
        $last = Cache::store('array')->get($key);
        $last['observed_at'] -= 121;
        Cache::store('array')->put($key, $last, 300);
        $signals = (new PrometheusSafetySignalProvider)->signals();

        self::assertFalse($signals['fresh']);
        self::assertArrayNotHasKey('sample_source', $signals);
    }

    public function test_a_held_sample_with_a_real_breach_still_reports_it(): void
    {
        $this->fakeGoodSample('20000', '9000', '2.5');
        (new PrometheusSafetySignalProvider)->signals();
        $this->resetHttpFake();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $signals = (new PrometheusSafetySignalProvider)->signals();

        self::assertSame('held', $signals['sample_source']);
        self::assertFalse($signals['memory_safe']);
        self::assertTrue($signals['memory_known']);
    }

    public function test_a_live_breach_is_never_masked_by_the_hold(): void
    {
        $this->fakeGoodSample('20000', '4000', '2.5');
        (new PrometheusSafetySignalProvider)->signals();
        $this->resetHttpFake();
        Http::fakeSequence()
            ->push($this->prometheusResult('20000'))
            ->push($this->prometheusFreshnessResult())
            ->push($this->prometheusResult('9000'))
            ->push($this->prometheusFreshnessResult())
            ->pushStatus(503)->pushStatus(503)->pushStatus(503);

        $signals = (new PrometheusSafetySignalProvider)->signals();

        self::assertFalse($signals['fresh']);
        self::assertFalse($signals['memory_safe']);
        self::assertArrayNotHasKey('sample_source', $signals);
    }

    public function test_a_zero_hold_disables_reuse(): void
    {
        config(['nntmux.orchestrator.safety_sample_hold_seconds' => 0]);
        $this->fakeGoodSample('20000', '4000', '2.5');
        (new PrometheusSafetySignalProvider)->signals();
        $this->resetHttpFake();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        self::assertFalse((new PrometheusSafetySignalProvider)->signals()['fresh']);
    }

    private function resetHttpFake(): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
    }

    private function fakeGoodSample(string $storage, string $memory, string $cpu): void
    {
        Http::fakeSequence()
            ->push($this->prometheusResult($storage))
            ->push($this->prometheusFreshnessResult())
            ->push($this->prometheusResult($memory))
            ->push($this->prometheusFreshnessResult())
            ->push($this->prometheusResult($cpu))
            ->push($this->prometheusFreshnessResult());
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
