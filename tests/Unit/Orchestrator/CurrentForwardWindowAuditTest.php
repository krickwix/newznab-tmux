<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardWindowAudit;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CurrentForwardWindowAuditTest extends TestCase
{
    public function test_accepts_an_exact_dense_ten_thousand_article_window(): void
    {
        $headers = [];
        for ($number = 101; $number <= 10_100; $number++) {
            $part = $number - 100;
            $headers[] = [
                'Number' => $number,
                'Subject' => sprintf('"release.mkv" yEnc (%d/10000)', $part),
                'Message-ID' => sprintf('<%d@example.test>', $number),
            ];
        }

        $audit = (new CurrentForwardWindowAudit)->analyze($headers, 101, 10_100);

        self::assertSame(10_000, $audit['headers']);
        self::assertSame(10_000, $audit['yenc_headers']);
        self::assertSame(10_000, $audit['multipart_headers']);
        self::assertSame(1, $audit['complete_binary_files']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $audit['evidence_hash']);
    }

    public function test_rejects_a_window_below_the_ninety_percent_coverage_floor(): void
    {
        $headers = [];
        for ($number = 101; $number < 9_099; $number++) {
            $headers[] = [
                'Number' => $number,
                'Subject' => sprintf('"release.mkv" yEnc (%d/10000)', $number - 100),
            ];
        }
        $headers[] = ['Number' => 10_100, 'Subject' => '"release.mkv" yEnc (10000/10000)'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('below the 90% coverage floor');

        (new CurrentForwardWindowAudit)->analyze($headers, 101, 10_100);
    }

    public function test_evidence_hash_is_canonical_when_provider_order_changes(): void
    {
        $headers = [];
        for ($number = 101; $number <= 10_100; $number++) {
            $headers[] = [
                'Number' => $number,
                'Subject' => sprintf('"release.mkv" yEnc (%d/10000)', $number - 100),
                'Message-ID' => sprintf('<%d@example.test>', $number),
            ];
        }

        $audit = new CurrentForwardWindowAudit;
        $ascending = $audit->analyze($headers, 101, 10_100);
        $descending = $audit->analyze(array_reverse($headers), 101, 10_100);

        self::assertSame($ascending['evidence_hash'], $descending['evidence_hash']);
    }

    public function test_rejects_inconsistent_totals_in_the_same_post_identity(): void
    {
        $headers = [];
        for ($number = 101; $number < 10_100; $number++) {
            $headers[] = [
                'Number' => $number,
                'Subject' => sprintf('[post] "release.mkv" yEnc (%d/10000)', $number - 100),
                'From' => 'poster@example.test',
            ];
        }
        $headers[] = [
            'Number' => 10_100,
            'Subject' => '[post] "release.mkv" yEnc (1/1)',
            'From' => 'poster@example.test',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inconsistent multipart totals');

        (new CurrentForwardWindowAudit)->analyze($headers, 101, 10_100);
    }

    public function test_evidence_hash_covers_overview_identity_and_payload_fields(): void
    {
        $headers = [];
        for ($number = 101; $number <= 10_100; $number++) {
            $headers[] = [
                'Number' => $number,
                'Subject' => sprintf('"release.mkv" yEnc (%d/10000)', $number - 100),
                'Message-ID' => sprintf('<%d@example.test>', $number),
                'From' => 'poster@example.test',
                'Date' => '17 Jul 2026 12:00:00 GMT',
                'Bytes' => '1024',
            ];
        }
        $changed = array_map(static function (array $header): array {
            $header['From'] = 'other@example.test';

            return $header;
        }, $headers);
        $changed[0]['Date'] = '17 Jul 2026 12:00:01 GMT';
        $changed[0]['Bytes'] = '2048';

        $audit = new CurrentForwardWindowAudit;

        self::assertNotSame(
            $audit->analyze($headers, 101, 10_100)['evidence_hash'],
            $audit->analyze($changed, 101, 10_100)['evidence_hash'],
        );
    }

    public function test_rejects_duplicate_part_numbers_for_one_post_identity(): void
    {
        $headers = [];
        for ($number = 101; $number <= 10_100; $number++) {
            $part = min(9_999, $number - 100);
            $headers[] = [
                'Number' => $number,
                'Subject' => sprintf('[post] "release.mkv" yEnc (%d/9999)', $part),
                'From' => 'poster@example.test',
            ];
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate multipart part numbers');

        (new CurrentForwardWindowAudit)->analyze($headers, 101, 10_100);
    }

    public function test_incomplete_multipart_window_requires_explicit_continuation_mode(): void
    {
        $headers = [];
        for ($number = 101; $number <= 10_100; $number++) {
            $headers[] = [
                'Number' => $number,
                'Subject' => sprintf('"fragment-%d.mkv" yEnc (1/2)', $number),
                'Message-ID' => sprintf('<%d@example.test>', $number),
            ];
        }

        $audit = (new CurrentForwardWindowAudit)->analyze(
            $headers,
            101,
            10_100,
            requireCompleteBinary: false,
        );

        self::assertSame(10_000, $audit['multipart_headers']);
        self::assertSame(0, $audit['complete_binary_files']);
    }

    public function test_normal_audit_still_rejects_an_incomplete_multipart_window(): void
    {
        $headers = [];
        for ($number = 101; $number <= 10_100; $number++) {
            $headers[] = [
                'Number' => $number,
                'Subject' => sprintf('"fragment-%d.mkv" yEnc (1/2)', $number),
            ];
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no complete multipart binary');

        (new CurrentForwardWindowAudit)->analyze($headers, 101, 10_100);
    }
}
