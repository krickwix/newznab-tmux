<?php

declare(strict_types=1);

namespace Tests\Unit\NNTP;

use App\Services\NNTP\NntpArticleDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class NntpArticleDateTest extends TestCase
{
    private int $now;

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-07-12 09:00:00 UTC')->timestamp;
    }

    public function test_it_accepts_the_exact_bounds_and_common_provider_formats(): void
    {
        self::assertSame(946684800, NntpArticleDate::timestamp('2000-01-01 00:00:00 UTC', $this->now));
        self::assertSame($this->now + 86_400, NntpArticleDate::timestamp($this->now + 86_400, $this->now));
        self::assertSame(1544546886, NntpArticleDate::timestamp('Tue, 11 Dec 2018 16:48:06 +0000', $this->now));
    }

    public function test_it_rejects_values_outside_the_bounds_or_without_a_real_date(): void
    {
        foreach ([
            946684799,
            $this->now + 86_401,
            '0000-12-12 15:09:20',
            '',
            null,
            'not-a-date',
            [],
            new \stdClass,
        ] as $value) {
            self::assertNull(NntpArticleDate::timestamp($value, $this->now));
        }
    }
}
