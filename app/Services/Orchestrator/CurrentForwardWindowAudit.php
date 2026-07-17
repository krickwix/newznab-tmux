<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use RuntimeException;

final class CurrentForwardWindowAudit
{
    private const int WINDOW_SIZE = 10_000;

    private const float MINIMUM_HEADER_COVERAGE = 0.9;

    private const float MINIMUM_YENC_RATIO = 0.5;

    /**
     * @param  array<mixed>  $headers
     * @return array{headers:int,yenc_headers:int,multipart_headers:int,complete_binary_files:int,evidence_hash:string}
     */
    public function analyze(array $headers, int $first, int $last): array
    {
        if ($first <= 0 || $last - $first + 1 !== self::WINDOW_SIZE) {
            throw new RuntimeException('Current-forward audit requires one exact 10,000-article window.');
        }

        $numbers = [];
        $yencHeaders = 0;
        $multipartHeaders = 0;
        $binaryParts = [];
        $inconsistentMultipartIdentity = false;
        $duplicateMultipartPart = false;
        $canonicalEvidence = [];

        foreach ($headers as $header) {
            if (! is_array($header) || ! isset($header['Number']) || ! is_numeric($header['Number'])) {
                throw new RuntimeException('Current-forward XOVER contains an invalid article number.');
            }
            $number = (int) $header['Number'];
            if ($number < $first || $number > $last || isset($numbers[$number])) {
                throw new RuntimeException('Current-forward XOVER is outside or duplicates the requested window.');
            }
            $numbers[$number] = true;

            $subject = (string) ($header['Subject'] ?? '');
            $messageId = (string) ($header['Message-ID'] ?? $header['MessageId'] ?? '');
            $canonicalEvidence[$number] = json_encode([
                'number' => $number,
                'message_id' => $messageId,
                'subject' => $subject,
                'from' => (string) ($header['From'] ?? ''),
                'date' => (string) ($header['Date'] ?? ''),
                'bytes' => (string) ($header['Bytes'] ?? ''),
                'lines' => (string) ($header['Lines'] ?? ''),
                'xref' => (string) ($header['Xref'] ?? ''),
                'references' => (string) ($header['References'] ?? ''),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

            if (preg_match('/yEnc/i', $subject) === 1) {
                $yencHeaders++;
            }
            if (preg_match('/[\(\[]\s*\d+\s*\/\s*\d+\s*[\)\]]/', $subject) === 1) {
                $multipartHeaders++;
            }
            if (preg_match('/"([^"]+)"\s+yEnc\s+[\(\[]\s*(\d+)\s*\/\s*(\d+)\s*[\)\]]/i', $subject, $matches) !== 1) {
                continue;
            }

            $part = (int) $matches[2];
            $total = (int) $matches[3];
            if ($part < 1 || $total < $part) {
                continue;
            }
            $normalizedSubject = preg_replace(
                '/([\(\[])\s*\d+\s*\/\s*\d+\s*([\)\]])/',
                '$1#/#$2',
                $subject,
            ) ?? $subject;
            $identity = hash('sha256', implode("\0", [
                mb_strtolower(trim($matches[1])),
                trim((string) ($header['From'] ?? '')),
                $normalizedSubject,
            ]));
            if (isset($binaryParts[$identity]['total']) && $binaryParts[$identity]['total'] !== $total) {
                $inconsistentMultipartIdentity = true;
            }
            if (isset($binaryParts[$identity]['parts'][$part])) {
                $duplicateMultipartPart = true;
            }
            $binaryParts[$identity]['total'] = $total;
            $binaryParts[$identity]['parts'][$part] = true;
        }

        $headerCount = count($headers);
        if (! isset($numbers[$first], $numbers[$last])) {
            throw new RuntimeException('Current-forward XOVER does not contain both exact window boundaries.');
        }
        if ($headerCount < (int) ceil(self::WINDOW_SIZE * self::MINIMUM_HEADER_COVERAGE)) {
            throw new RuntimeException('Current-forward XOVER is below the 90% coverage floor.');
        }
        if ($yencHeaders / max(1, $headerCount) < self::MINIMUM_YENC_RATIO) {
            throw new RuntimeException('Current-forward XOVER is below the 50% yEnc density floor.');
        }
        if ($multipartHeaders < 1) {
            throw new RuntimeException('Current-forward XOVER contains no multipart data.');
        }
        if ($inconsistentMultipartIdentity) {
            throw new RuntimeException('Current-forward XOVER contains inconsistent multipart totals for one post identity.');
        }
        if ($duplicateMultipartPart) {
            throw new RuntimeException('Current-forward XOVER contains duplicate multipart part numbers for one post identity.');
        }

        $completeBinaryFiles = 0;
        foreach ($binaryParts as $binary) {
            if (count($binary['parts']) >= $binary['total']) {
                $completeBinaryFiles++;
            }
        }
        if ($completeBinaryFiles < 1) {
            throw new RuntimeException('Current-forward XOVER contains no complete multipart binary.');
        }

        ksort($canonicalEvidence, SORT_NUMERIC);
        $hash = hash_init('sha256');
        foreach ($canonicalEvidence as $evidenceLine) {
            hash_update($hash, $evidenceLine);
        }

        return [
            'headers' => $headerCount,
            'yenc_headers' => $yencHeaders,
            'multipart_headers' => $multipartHeaders,
            'complete_binary_files' => $completeBinaryFiles,
            'evidence_hash' => hash_final($hash),
        ];
    }
}
