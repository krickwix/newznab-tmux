<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Extractors;

final class ObfuscatedSubjectExtractor
{
    /**
     * @var list<string>
     */
    private const array PREFIX_PATTERNS = [
        '/^\s*N:\/NZB\s*\[\d+\/\d+\]\s*-\s*/i',
        '/^\s*N[\s._:-]*NZB[\s._-]*\[\d+(?:[\/_]\d+)?\][\s._-]*-\s*/i',
        '/^\s*\[[^\]]{2,80}\]\s*/',
        '/^\s*(?:re\s*)?posted\s+by\s+[^-]{2,120}\s*-\s*/i',
    ];

    /**
     * @var list<string>
     */
    private const array TRAILING_ARCHIVE_PATTERNS = [
        '/\.part\d+\.rar$/i',
        '/\.r\d{2,4}$/i',
        '/\.7z\.\d{2,4}$/i',
        '/\.7z$/i',
        '/\.rar$/i',
        '/\.par2?$/i',
        '/\.(?:nfo|sfv|nzb|srr|srs|txt|md5|sha1)$/i',
        '/\.part$/i',
        '/\.vol\d+[+\-]\d+\.par2?$/i',
        '/\.\d{3}$/',
    ];

    public function extract(string $value, string $groupName = ''): ?string
    {
        $original = trim($value);
        if ($original === '') {
            return null;
        }

        // De-obfuscate ROT13/ROT5-encoded subjects (letters rot13, digits rot5)
        // before any other normalization, so downstream logic + the group
        // context guards see the readable title. Only rewrites when decoding
        // clearly turns garbage into a real release signature (see maybeDeRot13).
        $original = $this->maybeDeRot13($original);

        if ($this->isVintageClassicGroup($groupName) && $this->hasReadableContextOutsideQuotedFilename($original)) {
            return null;
        }

        if ($this->isAudioGroup($groupName) && $this->hasReadableContextOutsideQuotedAudioFilename($original)) {
            return null;
        }

        $normalized = $original;
        $looksObfuscated = false;
        foreach (self::PREFIX_PATTERNS as $pattern) {
            $updated = preg_replace($pattern, '', $normalized) ?? $normalized;
            if ($updated !== $normalized) {
                $looksObfuscated = true;
                $normalized = $updated;
            }
        }

        if (preg_match('/"([^"]{3,240})"/', $normalized, $quoted) === 1) {
            if ($this->hasReadableContextAroundShortPar2Sidecar($normalized, $quoted[1])) {
                return null;
            }

            $normalized = $quoted[1];
            $looksObfuscated = true;
        } elseif (preg_match("/'([^']{3,240})'/", $normalized, $quoted) === 1) {
            if ($this->hasReadableContextAroundShortPar2Sidecar($normalized, $quoted[1])) {
                return null;
            }

            $normalized = $quoted[1];
            $looksObfuscated = true;
        }

        if ($looksObfuscated) {
            foreach (self::TRAILING_ARCHIVE_PATTERNS as $pattern) {
                $normalized = preg_replace($pattern, '', $normalized) ?? $normalized;
            }
        }

        $normalized = str_replace(['_', '+'], [' ', ' '], $normalized);
        $normalized = preg_replace('/\.(?=[A-Za-z0-9])/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s{2,}/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized, " \t\n\r\0\x0B\"'`-_.");
        $normalized = $this->toReadableTitle($normalized);

        if ($normalized === '' || $normalized === $original) {
            return null;
        }

        return $normalized;
    }

    private function isVintageClassicGroup(string $groupName): bool
    {
        return preg_match('/(?:alt\.binaries|a\.b)\..*?(?:vintage[.-]?film|classic[.-]?film|old[.-]?movies?|movies?[.-]?classic|movie[.-]?classic|dvd[.-]?classic)/i', $groupName) === 1;
    }

    private function isAudioGroup(string $groupName): bool
    {
        return preg_match('/(?:alt\.binaries|a\.b)\..*?(?:sounds?|music|mp3|lossless)/i', $groupName) === 1;
    }

    private function hasReadableContextOutsideQuotedAudioFilename(string $subject): bool
    {
        if (preg_match('/["\'][^"\']+\.(?:flac|mp3|m4a|aac|ogg|wav|ape)["\']/i', $subject) !== 1) {
            return false;
        }

        $outsideQuotedFilename = trim((string) preg_replace('/["\'][^"\']+["\']/', ' ', $subject));
        $outsideQuotedFilename = preg_replace('/\[[\d\/]+\]|\b(?:yEnc|NMR|repost(?:ed)?|posted|file|part)\b/i', ' ', $outsideQuotedFilename) ?? $outsideQuotedFilename;

        return $this->countReadableWords($outsideQuotedFilename) >= 2
            && preg_match('/\b(?:19|20)\d{2}\b/', $outsideQuotedFilename) === 1;
    }

    private function hasReadableContextOutsideQuotedFilename(string $subject): bool
    {
        if (preg_match('/["\'][^"\']+\.(?:part\d+\.rar|r\d{2,4}|rar|par2?|nfo|sfv|nzb|srr|srs)["\']/i', $subject) !== 1) {
            return false;
        }

        $outsideQuotedFilename = trim((string) preg_replace('/["\'][^"\']+["\']/', ' ', $subject));
        $hasMediaEvidence = preg_match('/\b(?:avi|xvid|divx|dvd(?:-?[59])?|dvdrip|vhsrip|480p|576p|720p|1080p|mkv|mp4|mpg|mpeg|vob|iso)\b/i', $outsideQuotedFilename) === 1;
        $outsideQuotedFilename = preg_replace('/\[[\d\/]+\]/', ' ', $outsideQuotedFilename) ?? $outsideQuotedFilename;
        $outsideQuotedFilename = preg_replace('/\b(?:yEnc|NMR|repost(?:ed)?|posted|file|part|vol|par2?|rar|nfo|sfv|nzb|srr|srs|dvd(?:-?[59])?|avi|xvid|divx|dvdrip|vhsrip)\b/i', ' ', $outsideQuotedFilename) ?? $outsideQuotedFilename;

        $readableWords = $this->countReadableWords($outsideQuotedFilename);

        return $readableWords >= 3
            || ($readableWords >= 2 && $hasMediaEvidence)
            || preg_match('/\b(?:19|20)\d{2}\b/', $outsideQuotedFilename) === 1;
    }

    private function hasReadableContextAroundShortPar2Sidecar(string $subject, string $quotedFilename): bool
    {
        if (preg_match('/\.(?:vol\d+[+\-]\d+\.par2?|par2?)$/i', $quotedFilename) !== 1) {
            return false;
        }

        $outsideQuotedFilename = trim((string) preg_replace('/["\'][^"\']+["\']/', ' ', $subject));
        if (preg_match('/\b(19|20)\d{2}\b/', $outsideQuotedFilename) !== 1 || $this->countReadableWords($outsideQuotedFilename) < 2) {
            return false;
        }

        $stem = preg_replace('/\.(?:vol\d+[+\-]\d+\.par2?|par2?)$/i', '', $quotedFilename) ?? $quotedFilename;
        $stem = str_replace(['_', '.', '+'], ' ', $stem);

        return $this->countReadableWords($stem) < 2;
    }

    /**
     * If the value looks ROT13/ROT5-obfuscated (letters rotated 13, digits
     * rotated 5), return the decoded form; otherwise return the value unchanged.
     *
     * Conservative: only rewrites when the DECODED string looks like a real
     * release (has both a 4-digit year and a recognised format token) AND the
     * ORIGINAL does not. This avoids ever mangling names that are already
     * readable, and never "decodes" genuine hashed/garbage names into more
     * garbage (those fail the release-signature test post-decode).
     */
    private function maybeDeRot13(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Byte-wise ROT13 on ASCII letters + ROT5 on ASCII digits. Non-ASCII
        // (UTF-8 multibyte) bytes are left untouched so we never corrupt them.
        $decoded = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($value[$i]);
            if ($c >= 0x41 && $c <= 0x5A) {            // A-Z
                $decoded .= chr(($c - 0x41 + 13) % 26 + 0x41);
            } elseif ($c >= 0x61 && $c <= 0x7A) {      // a-z
                $decoded .= chr(($c - 0x61 + 13) % 26 + 0x61);
            } elseif ($c >= 0x30 && $c <= 0x39) {      // 0-9
                $decoded .= chr(($c - 0x30 + 5) % 10 + 0x30);
            } else {
                $decoded .= $value[$i];
            }
        }

        if ($decoded === $value) {
            return $value;
        }

        if ($this->looksLikeRealRelease($decoded) && ! $this->looksLikeRealRelease($value)) {
            // Prefer the decoded title stem (everything before the Usenet
            // segment marker like " [46/56]") so downstream quoted-filename
            // preference does not grab the short par2/rar sidecar. Fall back to
            // the full decoded string if no segment marker is present.
            $stem = preg_split('/\s*\[\d+\/\d+\]/', $decoded, 2)[0] ?? $decoded;
            $stem = trim((string) $stem);

            return ($stem !== '' && $this->looksLikeRealRelease($stem)) ? $stem : $decoded;
        }

        return $value;
    }

    /**
     * NOTE: this token list is intentionally mirrored by the ops monitor
     * (bin/nntmux-rot13-count.py in the OpenClaw workspace) which counts
     * rot13-rescuable releases. Keep the two in sync when tuning tokens.
     */
    private function looksLikeRealRelease(string $value): bool
    {
        return preg_match('/\b(?:19|20)\d{2}\b/', $value) === 1
            && preg_match('/\b(?:480p|576p|720p|1080p|2160p|x264|x265|h264|h265|hevc|xvid|divx|avc|bluray|bdrip|dvdrip|dvdr|dvd9|dvd5|webrip|web-dl|hdtv|remux|ntsc|pal|aac|ac3|dts|ddp|hdrip|brrip)\b/i', $value) === 1;
    }

    private function countReadableWords(string $value): int
    {
        preg_match_all('/[A-Za-z]{3,}/', $value, $matches);

        return count($matches[0]);
    }

    private function toReadableTitle(string $value): string
    {
        // If the candidate looks fully lowercase, present a cleaner title-cased
        // variant for UI/searchname while preserving separators and numbers.
        if (preg_match('/\p{Lu}/u', $value) === 1) {
            return $value;
        }

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
}
