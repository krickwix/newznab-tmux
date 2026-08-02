<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexReleaseJob;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

/**
 * ReindexReleaseJob calls Search::updateRelease(), which re-enters
 * insertRelease() and dispatches the job again when the write fails once more.
 * Horizon's `tries` cannot bound that cycle, because each pass is a freshly
 * dispatched job rather than a retried attempt of the same one.
 */
class ReindexReleaseRequeueBoundTest extends TestCase
{
    private function queueReindex(int $releaseId): void
    {
        $driver = new ManticoreSearchDriver(['host' => '127.0.0.1', 'port' => 9308, 'indexes' => ['releases' => 'releases_rt']]);
        $method = (new ReflectionClass($driver))->getMethod('queueReleaseReindex');
        $method->setAccessible(true);
        $method->invoke($driver, $releaseId);
    }

    public function test_it_queues_a_retry_for_a_release_the_index_refused(): void
    {
        Cache::flush();
        Queue::fake();

        $this->queueReindex(4242);

        Queue::assertPushed(ReindexReleaseJob::class, 1);
    }

    public function test_it_stops_requeueing_once_the_attempt_budget_is_spent(): void
    {
        Cache::flush();
        Queue::fake();

        // Each failed write re-enters the driver, so simulate the cycle running
        // well past the cap rather than exactly to it.
        for ($i = 0; $i < 10; $i++) {
            $this->queueReindex(4242);
        }

        Queue::assertPushed(ReindexReleaseJob::class, 3);
    }

    public function test_the_budget_is_tracked_per_release(): void
    {
        Cache::flush();
        Queue::fake();

        for ($i = 0; $i < 10; $i++) {
            $this->queueReindex(4242);
        }
        // A different release must not inherit the exhausted budget, otherwise
        // one poison document would suppress indexing retries for everything.
        $this->queueReindex(9999);

        Queue::assertPushed(ReindexReleaseJob::class, 4);
    }

    public function test_the_retry_backs_off_instead_of_hammering_the_index(): void
    {
        Cache::flush();
        Queue::fake();

        $this->queueReindex(4242);
        $this->queueReindex(4242);

        $delays = [];
        Queue::assertPushed(ReindexReleaseJob::class, function (ReindexReleaseJob $job) use (&$delays): bool {
            // Rounded because the delay is measured against a now() that has
            // advanced by a fraction of a second since the job was queued.
            $delays[] = (int) round((float) $job->delay?->diffInSeconds(now(), true));

            return true;
        });

        self::assertSame([2, 4], $delays);
    }
}
