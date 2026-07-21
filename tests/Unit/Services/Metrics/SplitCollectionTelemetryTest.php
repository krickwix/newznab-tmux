<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Metrics;

use App\Services\Metrics\SplitCollectionTelemetry;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SplitCollectionTelemetryTest extends TestCase
{
    public function test_records_only_bounded_groups_and_fixed_decisions(): void
    {
        config()->set('nntmux.distributed_lock_store', 'array');
        Cache::store('array')->flush();
        $telemetry = new SplitCollectionTelemetry;

        self::assertTrue($telemetry->record([
            'alt.binaries.movies.dvd' => [
                'dynamic_eligible_shadow' => 2,
                'dynamic_accept' => 1,
                'unbounded-result' => 99,
            ],
            'invalid group label' => ['dynamic_accept' => 99],
        ]));

        $snapshot = $telemetry->snapshot(['alt.binaries.movies.dvd', 'invalid group label']);
        self::assertTrue($snapshot['available']);
        self::assertSame(2, $snapshot['groups']['alt.binaries.movies.dvd']['dynamic_eligible_shadow']);
        self::assertSame(1, $snapshot['groups']['alt.binaries.movies.dvd']['dynamic_accept']);
        self::assertArrayNotHasKey('invalid group label', $snapshot['groups']);
    }

    public function test_store_failure_is_non_blocking_and_fail_visible(): void
    {
        config()->set('nntmux.distributed_lock_store', 'missing-split-telemetry-store');
        $telemetry = new SplitCollectionTelemetry;

        self::assertFalse($telemetry->record([
            'alt.binaries.movies.dvd' => ['dynamic_accept' => 1],
        ]));
        self::assertSame(['available' => false, 'groups' => []], $telemetry->snapshot([
            'alt.binaries.movies.dvd',
        ]));
    }
}
