<?php

declare(strict_types=1);

namespace Tests\Unit\NNTP;

use PHPUnit\Framework\TestCase;

/**
 * The overview format cache is shared between parent::getOverview() (part repair) and
 * getXOVER() (the update scan). The two used to store incompatible shapes — one with a
 * leading 'Number' key and one without — so whichever ran second shifted every header
 * field by one position. 'Date' then received the Message-ID and HeaderParser discarded
 * the whole batch as "invalid date".
 */
final class NNTPServiceOverviewFormatCacheTest extends TestCase
{
    public function test_xover_maps_fields_correctly_when_it_populates_the_cache_itself(): void
    {
        $service = new FakeOverviewFormatNNTPService;

        $this->assertHeaderIsAligned($service->getXOVER('123456-123456'));
    }

    public function test_xover_maps_fields_correctly_when_part_repair_populated_the_cache_first(): void
    {
        $service = new FakeOverviewFormatNNTPService;

        // parent::getOverview() caches the format with 'Number' already prepended. This is
        // the ordering that updateGroup() always produces: part repair runs before the scan.
        $service->primeCacheWithNumberInclusiveShape();

        $this->assertHeaderIsAligned($service->getXOVER('123456-123456'));
    }

    public function test_cache_shape_is_stable_across_repeated_xover_calls(): void
    {
        $service = new FakeOverviewFormatNNTPService;

        $this->assertHeaderIsAligned($service->getXOVER('123456-123456'));
        $this->assertHeaderIsAligned($service->getXOVER('123456-123456'));

        // A second reader (part repair) must still see the 'Number'-inclusive shape.
        $cache = $service->exposeCache();
        $this->assertIsArray($cache);
        $this->assertArrayHasKey('Number', $cache);
    }

    private function assertHeaderIsAligned(mixed $headers): void
    {
        $this->assertIsArray($headers);
        $this->assertCount(1, $headers);

        $header = reset($headers);
        $this->assertIsArray($header);

        $this->assertSame('123456', $header['Number']);
        $this->assertSame('Example Post (1/2) yEnc', $header['Subject']);
        $this->assertSame('poster@example.local', $header['From']);
        $this->assertSame('Sat, 01 Aug 2026 17:48:51 UTC', $header['Date']);
        $this->assertSame('<part1of2.abc@example.local>', $header['Message-ID']);
        $this->assertSame('news alt.binaries.test:123456', $header['Xref']);

        // 'Number' must appear exactly once; a duplicate is what shifted every later field.
        $this->assertSame(1, array_count_values(array_keys($header))['Number']);
    }
}
