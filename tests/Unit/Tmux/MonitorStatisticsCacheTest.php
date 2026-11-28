<?php

declare(strict_types=1);

namespace Tests\Unit\Tmux;

use App\Services\Tmux\MonitorStatisticsCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The fleet-shared statistics snapshot must be whole or absent.
 *
 * getProcessCounts() and getTableCounts() swallow their own exceptions, so a
 * failed pass leaves counts short rather than throwing. Short is the dangerous
 * shape: DistributedJobCatalog reads every count with a ?? 0 default, so a
 * missing key reads as "no work" and disables the lane. An empty-array test
 * does not catch that -- it only catches total failure, and the failure
 * happens partway.
 */
final class MonitorStatisticsCacheTest extends TestCase
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

    public function test_a_complete_snapshot_round_trips(): void
    {
        $cache = new MonitorStatisticsCache;
        $cache->write(['monitor' => 30], $this->completeCounts(), ['tmux_time' => 1], ['newestrelease' => 5]);

        $read = $cache->read();

        self::assertNotNull($read);
        self::assertSame(30, $read['settings']['monitor']);
        self::assertSame(7, $read['counts']['work']);
    }

    public function test_a_pass_that_failed_partway_is_not_published(): void
    {
        $cache = new MonitorStatisticsCache;

        // What getCategoryCounts() alone leaves behind when getProcessCounts()
        // then throws: category keys, none of the process ones. Non-empty, so
        // the old === [] guard published it and every post-processing lane
        // adopted it as "nothing to do" for the whole TTL.
        $cache->write(['monitor' => 30], [
            'tv' => 10, 'movies' => 11, 'audio' => 12, 'books' => 13, 'console' => 14,
            'pc' => 15, 'xxx' => 16, 'misc' => 17, 'other_hashed' => 18,
        ], ['tmux_time' => 1], []);

        self::assertNull($cache->read());
    }

    public function test_any_single_missing_key_is_enough_to_reject_a_snapshot(): void
    {
        foreach (MonitorStatisticsCache::REQUIRED_COUNT_KEYS as $missing) {
            Cache::store('array')->flush();
            $counts = $this->completeCounts();
            unset($counts[$missing]);

            (new MonitorStatisticsCache)->write(['monitor' => 30], $counts, [], []);

            self::assertNull(
                (new MonitorStatisticsCache)->read(),
                'A snapshot without '.$missing.' must not be published.'
            );
        }
    }

    public function test_a_short_snapshot_already_in_the_cache_is_not_adopted(): void
    {
        // A snapshot left by an older build, whose statistics pass produced a
        // different set of keys, must not be trusted either.
        Cache::store('array')->put('nntmux:tmux-monitor:statistics', [
            'settings' => ['monitor' => 30],
            'counts' => ['collections_table' => 5],
            'query_timers' => [],
            'new_old' => [],
        ], 20);

        self::assertNull((new MonitorStatisticsCache)->read());
    }

    /**
     * Guards the hand-written key list against drift -- within stated limits,
     * which are worth knowing before anyone trusts it further than it goes.
     *
     * The scan sees literal quoted keys only, so it structurally cannot see
     * backfillGroupWorkCount()'s $counts[$countKey]. Those two keys
     * (backfill_groups_date, backfill_groups_days) are in the list because I
     * read the method, not because this test found them, and a future dynamic
     * read will still need a human.
     *
     * It also reads DistributedJobCatalog only. TmuxMonitorService::setKillswitches()
     * reads counts['now']['total_work'] and ['collections_table'] too;
     * collections_table happens to be in the list, and total_work is derived
     * locally by calculateStatistics() from the adopted counts rather than
     * carried in the snapshot. Both are fine today, but not because this scan
     * covers them.
     */
    public function test_the_required_keys_cover_every_count_the_job_catalog_reads(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/Services/Distributed/DistributedJobCatalog.php');
        self::assertIsString($source);

        preg_match_all('/\$counts\[\'([a-z0-9_]+)\'\]/', $source, $matches);
        $read = array_values(array_unique($matches[1]));

        self::assertNotSame([], $read, 'The scan found no count reads at all; the pattern has rotted.');

        // The guard is only as good as this list, and the list is hand-written,
        // so a new read in the catalog has to fail here rather than silently
        // reintroduce the partial-snapshot hole.
        self::assertSame(
            [],
            array_values(array_diff($read, MonitorStatisticsCache::REQUIRED_COUNT_KEYS)),
            'DistributedJobCatalog reads a count that MonitorStatisticsCache does not require.'
        );
    }

    /**
     * @return array<string, int>
     */
    private function completeCounts(): array
    {
        return array_fill_keys(MonitorStatisticsCache::REQUIRED_COUNT_KEYS, 7);
    }
}
