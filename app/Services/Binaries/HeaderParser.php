<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\BlacklistService;
use App\Services\NNTP\NntpArticleDate;

/**
 * Parses and filters raw NNTP headers.
 */
final class HeaderParser
{
    private BlacklistService $blacklistService;

    private ObfuscatedSubjectNormalizer $obfuscatedSubjectNormalizer;

    private int $notYEnc = 0;

    private int $blacklisted = 0;

    private int $invalidDate = 0;

    private int $obfuscationNormalized = 0;

    public function __construct(
        ?BlacklistService $blacklistService = null,
        ?ObfuscatedSubjectNormalizer $obfuscatedSubjectNormalizer = null
    ) {
        $this->blacklistService = $blacklistService ?? new BlacklistService;
        $this->obfuscatedSubjectNormalizer = $obfuscatedSubjectNormalizer ?? new ObfuscatedSubjectNormalizer;
    }

    /**
     * Reset counters for a new batch.
     */
    public function reset(): void
    {
        $this->notYEnc = 0;
        $this->blacklisted = 0;
        $this->invalidDate = 0;
        $this->obfuscationNormalized = 0;
    }

    /**
     * Parse and filter raw headers from NNTP.
     *
     * @param  array<int, array<string, mixed>>  $headers  Raw headers from NNTP
     * @param  string  $groupName  The newsgroup name
     * @param  bool  $partRepair  Whether this is a part repair scan
     * @param  list<int>|null  $missingParts  Missing part numbers if part repair
     * @return array<string, mixed> Filtered and parsed headers with article info
     */
    public function parse(
        array $headers,
        string $groupName,
        bool $partRepair = false,
        ?array $missingParts = null
    ): array {
        $parsed = [];
        $headersRepaired = [];
        $receivedNumbers = [];
        $normalizeObfuscated = $this->obfuscatedSubjectNormalizer->appliesTo($groupName);
        $missingPartLookup = $missingParts === null
            ? null
            : array_fill_keys(
                array_map(static fn (mixed $number): string => (string) $number, $missingParts),
                true
            );

        foreach ($headers as $header) {
            // Check if we got the article
            if (! isset($header['Number'])) {
                continue;
            }

            $receivedNumbers[] = $header['Number'];

            // For part repair, only process missing parts
            if ($partRepair && $missingPartLookup !== null) {
                if (! isset($missingPartLookup[(string) $header['Number']])) {
                    continue;
                }
            }

            if (NntpArticleDate::timestamp($header['Date'] ?? null) === null) {
                $this->invalidDate++;

                continue;
            }

            // Parse subject to get base name and part/total like "(12/45)"
            if (! preg_match('/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/', $header['Subject'], $matches)) {
                $this->notYEnc++;

                continue;
            }

            // Normalize to include yEnc if missing
            if (stripos($header['Subject'], 'yEnc') === false) {
                $matches[1] .= ' yEnc';
            }

            // Collapse per-article obfuscation tokens so every article of a
            // file shares one collection key and one binary hash.
            if ($normalizeObfuscated) {
                $normalized = $this->obfuscatedSubjectNormalizer->normalize($matches[1]);
                if ($normalized !== null) {
                    $matches[1] = $normalized['name'];
                    $header['collection_file_number'] = $normalized['file_number'];
                    $header['collection_total_files'] = $normalized['total_files'];
                    // The token is gone from matches[1] by design, so nothing
                    // downstream can recognise this subject as brace-token any
                    // more. Flag it here so CollectionHandler keys the
                    // collection per real file instead of per cleaned name,
                    // which would fuse part01..partNN into one collection.
                    $header['collection_brace_token'] = true;
                    $this->obfuscationNormalized++;
                }
            }

            $header['matches'] = $matches;

            // Filter subject based on black/white list
            if ($this->blacklistService->isBlackListed($header, $groupName)) {
                $this->blacklisted++;

                continue;
            }

            // Ensure Bytes is set
            if (empty($header['Bytes'])) {
                $header['Bytes'] = $header[':bytes'] ?? 0;
            }

            $parsed[] = [
                'header' => $header,
                'repaired' => $partRepair,
            ];
            if ($partRepair) {
                $headersRepaired[] = $header['Number'];
            }
        }

        return [
            'headers' => array_column($parsed, 'header'),
            'repaired' => $headersRepaired,
            'received' => $receivedNumbers,
            'notYEnc' => $this->notYEnc,
            'blacklisted' => $this->blacklisted,
            'invalidDate' => $this->invalidDate,
            'obfuscationNormalized' => $this->obfuscationNormalized,
        ];
    }

    /**
     * Update blacklist last_activity for matched rules.
     */
    public function flushBlacklistUpdates(): void
    {
        $ids = $this->blacklistService->getAndClearIdsToUpdate();
        if (! empty($ids)) {
            $this->blacklistService->updateBlacklistUsage($ids); // @phpstan-ignore argument.type
        }
    }

    /**
     * Get count of non-yEnc headers filtered.
     */
    public function getNotYEncCount(): int
    {
        return $this->notYEnc;
    }

    /**
     * Get count of blacklisted headers.
     */
    public function getBlacklistedCount(): int
    {
        return $this->blacklisted;
    }

    /**
     * Get count of headers whose obfuscation token was stripped.
     */
    public function getObfuscationNormalizedCount(): int
    {
        return $this->obfuscationNormalized;
    }

    /**
     * Extract highest and lowest article info from headers.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return array<string, mixed>
     */
    public function getArticleRange(array $headers): array
    {
        $numbered = array_values(array_filter(
            $headers,
            static fn (array $header): bool => isset($header['Number']) && is_numeric($header['Number'])
        ));

        if ($numbered === []) {
            return [];
        }

        usort(
            $numbered,
            static fn (array $left, array $right): int => (int) $left['Number'] <=> (int) $right['Number']
        );

        $first = $numbered[0];
        $last = $numbered[array_key_last($numbered)];
        $firstDate = null;
        $lastDate = null;

        foreach ($numbered as $header) {
            if (NntpArticleDate::timestamp($header['Date'] ?? null) !== null) {
                $firstDate = $header['Date'];
                break;
            }
        }

        for ($index = \count($numbered) - 1; $index >= 0; $index--) {
            if (NntpArticleDate::timestamp($numbered[$index]['Date'] ?? null) !== null) {
                $lastDate = $numbered[$index]['Date'];
                break;
            }
        }

        $result = ['firstArticleNumber' => $first['Number']];
        if ($firstDate !== null) {
            $result['firstArticleDate'] = $firstDate;
        }
        $result['lastArticleNumber'] = $last['Number'];
        if ($lastDate !== null) {
            $result['lastArticleDate'] = $lastDate;
        }

        return $result;
    }
}
