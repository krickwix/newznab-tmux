<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\BinariesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the detection of misaligned NNTP overview fields. When the overview format cache is
 * shared between two readers with different shapes, every header shifts one position and 'Date'
 * receives the Message-ID — so the whole batch fails to parse. That must abort the scan rather
 * than silently discarding the articles and queueing the entire range for part repair.
 */
final class BinariesMisalignedHeaderBatchTest extends TestCase
{
    public function test_batch_with_every_date_unparseable_is_flagged(): void
    {
        $this->assertTrue($this->isMisaligned(invalidDate: 4242, msgCount: 4242));
    }

    public function test_batch_with_scattered_bad_dates_is_not_flagged(): void
    {
        // Genuinely broken articles do occur; they must not abort an otherwise healthy scan.
        $this->assertFalse($this->isMisaligned(invalidDate: 12, msgCount: 4242));
        $this->assertFalse($this->isMisaligned(invalidDate: 2000, msgCount: 4242));
    }

    public function test_clean_batch_is_not_flagged(): void
    {
        $this->assertFalse($this->isMisaligned(invalidDate: 0, msgCount: 4242));
    }

    public function test_small_batches_are_never_flagged(): void
    {
        // A tiny batch can legitimately be all-bad, so the ratio proves nothing there.
        $this->assertFalse($this->isMisaligned(invalidDate: 3, msgCount: 3));
        $this->assertFalse($this->isMisaligned(invalidDate: 49, msgCount: 49));
    }

    public function test_threshold_boundary(): void
    {
        // 99% of 100 articles.
        $this->assertTrue($this->isMisaligned(invalidDate: 99, msgCount: 100));
        $this->assertFalse($this->isMisaligned(invalidDate: 98, msgCount: 100));
    }

    private function isMisaligned(int $invalidDate, int $msgCount): bool
    {
        $reflection = new ReflectionClass(BinariesService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $invalidDateProperty = $reflection->getProperty('invalidDate');
        $invalidDateProperty->setValue($service, $invalidDate);

        $method = $reflection->getMethod('isMisalignedHeaderBatch');

        return (bool) $method->invoke($service, $msgCount);
    }
}
