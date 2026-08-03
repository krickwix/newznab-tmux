<?php

declare(strict_types=1);

namespace Tests\Unit\Binaries;

use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\ObfuscatedSubjectNormalizer;
use App\Services\BlacklistService;
use PHPUnit\Framework\TestCase;

final class HeaderParserDateTest extends TestCase
{
    public function test_it_receives_but_does_not_store_headers_without_a_sane_date(): void
    {
        $parser = $this->parser();
        $valid = $this->header(201, '2026-07-12 09:00:00 UTC');
        $invalid = $this->header(202, '0000-12-12 15:09:20');
        $missing = $this->header(203, null);

        $result = $parser->parse([$valid, $invalid, $missing], 'alt.test');

        self::assertSame([201, 202, 203], $result['received']);
        self::assertSame([201], array_column($result['headers'], 'Number'));
        self::assertSame(2, $result['invalidDate']);
        self::assertSame(0, $result['notYEnc']);
        self::assertSame(0, $result['blacklisted']);
    }

    public function test_range_uses_numeric_extremes_and_nearest_sane_endpoint_dates(): void
    {
        $parser = $this->parser();
        $headers = [
            ['Number' => 199, 'Date' => '2018-12-02 00:00:00 UTC'],
            ['Number' => 100, 'Date' => '0000-12-12 15:09:20'],
            ['Number' => 200, 'Date' => '2999-01-01 00:00:00 UTC'],
            ['Number' => 101, 'Date' => '2018-12-01 00:00:00 UTC'],
        ];

        self::assertSame([
            'firstArticleNumber' => 100,
            'firstArticleDate' => '2018-12-01 00:00:00 UTC',
            'lastArticleNumber' => 200,
            'lastArticleDate' => '2018-12-02 00:00:00 UTC',
        ], $parser->getArticleRange($headers));

        self::assertSame([
            'firstArticleNumber' => 100,
            'lastArticleNumber' => 200,
        ], $parser->getArticleRange([
            ['Number' => 200, 'Date' => '2999-01-01 00:00:00 UTC'],
            ['Number' => 100, 'Date' => '0000-12-12 15:09:20'],
        ]));
    }

    private function parser(): HeaderParser
    {
        $blacklist = new class extends BlacklistService
        {
            public function isBlackListed(array $msg, string $groupName): bool
            {
                return false;
            }
        };

        // No container is booted here, so the normalizer's config-reading
        // default constructor would fail; these cases are about dates only.
        return new HeaderParser($blacklist, new ObfuscatedSubjectNormalizer([]));
    }

    /** @return array<string, mixed> */
    private function header(int $number, ?string $date): array
    {
        $header = [
            'Number' => $number,
            'Subject' => "Release.{$number} (1/1)",
            'From' => 'poster@example.com',
            'Bytes' => 100,
            'Message-ID' => "<{$number}@example.com>",
            'Xref' => "news.example.com alt.test:{$number}",
        ];

        if ($date !== null) {
            $header['Date'] = $date;
        }

        return $header;
    }
}
