<?php

declare(strict_types=1);

namespace App\Services;

class XrefService
{
    /**
     * Regex patterns to parse Xref tokens (e.g. alt.binaries.* optionally followed by :number).
     */
    private const XREF_PATTERN_WITH_NUM = '/(^[a-zA-Z]{2,3}\\.(bin(aries|arios|aer))\\.[a-zA-Z0-9]?.+)(:\\d+)/i';

    private const XREF_PATTERN_NO_NUM = '/(^[a-zA-Z]{2,3}\\.(bin(aries|arios|aer))\\.[a-zA-Z0-9]?.+)/i';

    /**
     * Extracts valid Xref tokens from a space-separated Xref string.
     *
     * @return array<string, mixed>
     */
    public function extractTokens(?string $xref): array
    {
        if (empty($xref)) {
            return [];
        }
        $tokens = [];
        foreach (preg_split('/\s+/', trim($xref)) ?: [] as $token) {
            if (preg_match(self::XREF_PATTERN_WITH_NUM, $token, $m) || preg_match(self::XREF_PATTERN_NO_NUM, $token, $m)) {
                $tokens[] = $m[0];
            }
        }

        return $tokens;
    }

    /**
     * Returns tokens that appear in $headerXref but not in $existingXref.
     *
     * @return list<string>
     */
    public function diffNewTokens(?string $existingXref, ?string $headerXref): array
    {
        $existingGroups = [];
        foreach ($this->extractTokens($existingXref) as $token) {
            $existingGroups[$this->groupForToken($token)] = true;
        }

        $newTokens = [];
        foreach ($this->extractTokens($headerXref) as $token) {
            $group = $this->groupForToken($token);
            if (isset($existingGroups[$group]) || isset($newTokens[$group])) {
                continue;
            }

            $newTokens[$group] = $token;
        }

        return array_values($newTokens);
    }

    private function groupForToken(string $token): string
    {
        return strtolower((string) preg_replace('/:\d+$/', '', $token));
    }
}
