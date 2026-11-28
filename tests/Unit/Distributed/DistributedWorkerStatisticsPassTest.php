<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\DistributedJobWorker;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Tmux\MonitorStatisticsCache;
use App\Services\Tmux\TmuxMonitorService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionClass;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The monitor statistics pass (~20 statements, including nine category counts
 * over releases) ran once per loop in every distributed worker pod, before the
 * worker knew whether its job was even enabled. Two changes: lanes whose plan
 * never reads the counts skip it, and the lanes that do read it share one
 * computation through the cache instead of each recomputing the same answer.
 */
final class DistributedWorkerStatisticsPassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.distributed_statistics_cache_seconds' => 20,
        ]);
        Cache::store('array')->flush();
    }

    public function test_a_settings_only_lane_refreshes_settings_instead_of_collecting_statistics(): void
    {
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once();
        $monitor->shouldReceive('collectStatistics')->never();
        $monitor->shouldReceive('refreshMonitorSettings')->once()->andReturn([
            // fix_crap_opt absent, so removecrap resolves disabled and the
            // single pass returns without running anything.
            'settings' => ['crap_timer' => 300],
            'constants' => ['sequential' => 0],
        ]);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('recordRunOutcome')->once()->with('removecrap', 'disabled');

        $worker = new DistributedJobWorker(new DistributedJobCatalog, $monitor, $telemetry);

        self::assertSame(0, $worker->run('removecrap', true, null, 60, new BufferedOutput));
    }

    public function test_a_counts_gated_lane_still_collects_the_full_statistics(): void
    {
        $monitor = Mockery::mock(TmuxMonitorService::class);
        $monitor->shouldReceive('initializeMonitor')->once();
        $monitor->shouldReceive('refreshMonitorSettings')->never();
        $monitor->shouldReceive('collectStatistics')->once()->andReturn([
            'settings' => [],
            'constants' => ['sequential' => 0],
            'counts' => ['now' => ['processtv' => 0]],
        ]);

        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('recordRunOutcome')->once()->with('post-tv', 'disabled');

        $worker = new DistributedJobWorker(new DistributedJobCatalog, $monitor, $telemetry);

        self::assertSame(0, $worker->run('post-tv', true, null, 60, new BufferedOutput));
    }

    public function test_every_lane_marked_settings_only_resolves_the_same_plan_without_the_statistics(): void
    {
        $catalog = new DistributedJobCatalog;
        $withStatistics = $this->richRunVar();
        $withoutStatistics = $withStatistics;
        unset($withoutStatistics['counts'], $withoutStatistics['killswitch']);

        $checked = 0;
        foreach (array_keys($catalog->jobs()) as $job) {
            if ($catalog->readsStatistics($job)) {
                continue;
            }

            $checked++;
            self::assertSame(
                $catalog->resolve($job, $withStatistics),
                $catalog->resolve($job, $withoutStatistics),
                sprintf('Job [%s] is marked settings-only but its plan changes when the counts are gone.', $job),
            );
        }

        self::assertGreaterThan(0, $checked);
    }

    /**
     * A runVar with every lane switched on and non-trivial statistics, so that
     * anything reading the counts or the killswitch would resolve differently
     * once they are removed.
     *
     * @return array<string, mixed>
     */
    private function richRunVar(): array
    {
        return [
            'settings' => [
                'monitor' => 30,
                'binaries_run' => 1,
                'backfill' => 1,
                'backfill_qty' => 10_000,
                'releases_run' => 1,
                'fix_names' => 1,
                'post' => 3,
                'post_amazon' => 1,
                'post_non' => 1,
                'processtvrage' => 1,
                'processanime' => 1,
                'processmovies' => 1,
                'processnfo' => 1,
                'fix_crap_opt' => 'All',
                'collections_kill' => 100,
                'postprocess_kill' => 100,
                'seq_timer' => 300,
            ],
            'constants' => ['sequential' => 0, 'run_ircscraper' => 1],
            'counts' => [
                'now' => [
                    'work' => 25,
                    'total_work' => 25,
                    'collections_table' => 500,
                    'processrenames' => 11,
                    'other_hashed' => 250_000,
                    'processnfo' => 7,
                    'processtv' => 9,
                    'processanime' => 4,
                    'processmovies' => 6,
                ],
            ],
            'killswitch' => ['pp' => true, 'coll' => true],
        ];
    }

    public function test_the_statistics_dependent_list_only_names_real_jobs(): void
    {
        $catalog = new DistributedJobCatalog;
        $declared = (array) (new ReflectionClass($catalog))->getConstant('STATISTICS_DEPENDENT_JOBS');

        self::assertNotSame([], $declared);
        foreach ($declared as $job) {
            self::assertArrayHasKey($job, $catalog->jobs());
            self::assertTrue($catalog->readsStatistics((string) $job));
        }
    }

    public function test_a_published_snapshot_is_served_back_whole(): void
    {
        $cache = new MonitorStatisticsCache;
        // Only a complete pass is publishable, so the fixture carries every
        // count the job catalog reads. MonitorStatisticsCacheTest covers the
        // short-snapshot rejection itself.
        $counts = array_fill_keys(MonitorStatisticsCache::REQUIRED_COUNT_KEYS, 7);
        $counts['collections_table'] = 42;
        $cache->write(
            ['monitor' => 30, 'post' => 1],
            $counts,
            ['tmux_time' => 1],
            ['newestrelease' => 5],
        );

        self::assertSame([
            'settings' => ['monitor' => 30, 'post' => 1],
            'counts' => $counts,
            'query_timers' => ['tmux_time' => 1],
            'new_old' => ['newestrelease' => 5],
        ], $cache->read());
    }

    public function test_sharing_is_off_when_the_ttl_is_zero(): void
    {
        config(['nntmux.distributed_statistics_cache_seconds' => 0]);
        $cache = new MonitorStatisticsCache;

        $cache->write(['monitor' => 30], ['work' => 7], [], []);

        self::assertNull($cache->read());
        self::assertFalse(Cache::store('array')->has('nntmux:tmux-monitor:statistics'));
    }

    public function test_an_empty_counts_pass_is_never_published(): void
    {
        $cache = new MonitorStatisticsCache;

        // getProcessCounts() and getTableCounts() swallow their exceptions, so
        // a failed pass leaves counts short rather than throwing. Publishing
        // that would spread one pod's failure across every lane.
        $cache->write(['monitor' => 30], [], ['tmux_time' => 1], []);
        self::assertNull($cache->read());

        // And a pass that failed partway, which is the shape the swallowed
        // exceptions actually produce. MonitorStatisticsCacheTest covers this
        // key by key.
        $cache->write(['monitor' => 30], ['tv' => 10, 'other_hashed' => 18], ['tmux_time' => 1], []);
        self::assertNull($cache->read());
    }

    public function test_a_snapshot_missing_a_part_is_rejected_rather_than_half_applied(): void
    {
        Cache::store('array')->put('nntmux:tmux-monitor:statistics', [
            'counts' => ['work' => 7],
            'query_timers' => [],
            'new_old' => [],
        ], 20);

        // Counts without the settings that interpret them would let the job
        // catalog decide on half a picture.
        self::assertNull((new MonitorStatisticsCache)->read());
    }

    public function test_a_refresh_adopts_a_shared_snapshot_instead_of_querying(): void
    {
        $counts = array_fill_keys(MonitorStatisticsCache::REQUIRED_COUNT_KEYS, 3);
        $counts['collections_table'] = 500;
        (new MonitorStatisticsCache)->write(
            ['monitor' => 30, 'collections_kill' => 100],
            $counts,
            ['tmux_time' => 2],
            ['newestrelease' => 9],
        );

        $service = new TmuxMonitorService;
        $runVar = (new ReflectionClass($service))->getProperty('runVar');
        $runVar->setValue($service, [
            'settings' => [],
            'counts' => ['now' => [], 'start' => [], 'diff' => [], 'percent' => []],
            'timers' => ['query' => [], 'newOld' => []],
        ]);

        (new ReflectionClass($service))->getMethod('refreshStatistics')->invoke($service);

        $state = $service->getRunVar();
        self::assertSame(30, $state['settings']['monitor']);
        self::assertSame(500, $state['counts']['now']['collections_table']);
        self::assertSame(9, $state['timers']['newOld']['newestrelease']);
        // calculateStatistics() still ran locally over the adopted counts, so
        // the per-process since-start deltas are present and this pod's own.
        self::assertArrayHasKey('collections_table', $state['counts']['diff']);
    }
}
