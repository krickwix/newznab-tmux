<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Traits\DetectsHashedNames;

/**
 * Categorizer for miscellaneous content and hash detection.
 * This runs FIRST with high priority to detect hashes early and prevent
 * them from being incorrectly categorized by group-based or content-based rules.
 */
class MiscCategorizer extends AbstractCategorizer
{
    use DetectsHashedNames;

    protected int $priority = 1; // Highest priority - run first to catch hashes

    public function getName(): string
    {
        return 'Misc';
    }

    public function categorize(ReleaseContext $context): CategorizationResult
    {
        $name = $context->releaseName;

        // Check for hash patterns first
        if ($result = $this->checkHash($name)) {
            return $result;
        }

        $analysis = $this->inspectSignals($name);
        if ($this->isZeroVowelLongToken($analysis['coreName'])) {
            return $this->matched(Category::OTHER_HASHED, 0.78, 'gibberish_zero_vowels');
        }

        // Check for obfuscated/encoded patterns
        if ($result = $this->checkObfuscated($context)) {
            return $result;
        }

        // Check low-signal names that only contain random-looking tokens
        if ($result = $this->checkLowSignal($name)) {
            return $result;
        }

        // Check for gibberish patterns (character-analysis heuristics)
        if ($result = $this->checkGibberish($name)) {
            return $result;
        }

        // Check for archive formats
        if ($result = $this->checkArchive($name)) {
            return $result;
        }

        // Check for dataset/dump patterns
        if ($result = $this->checkDataset($name)) {
            return $result;
        }

        return $this->noMatch();
    }

    /**
     * Inspect a release name for media signal markers used by the safety-net pipe.
     *
     * @return array{coreName: string, coreLength: int, signalScore: int, markers: list<string>, lowSignal: bool}
     */
    public function inspectSignals(ReleaseContext|string $context): array
    {
        $name = $context instanceof ReleaseContext ? $context->releaseName : $context;
        $cleaned = $this->stripExtensionsForAnalysis($name);
        $coreName = $this->getCoreNameWithoutSeparators($cleaned);

        $patterns = [
            'season_episode' => '/\bS\d{1,3}[._ -]?E\d{1,4}\b/i',
            'season_pack' => '/\bS\d{1,3}\b/i',
            'resolution' => '/\b(480p|576p|720p|1080[pi]?|2160p|4k|uhd)\b/i',
            'codec' => '/\b(x264|x265|h\.?264|h\.?265|hevc|xvid|av1)\b/i',
            'source' => '/\b(bluray|bdrip|brrip|hdtv|web[._ -]?dl|web[._ -]?rip|dvdrip|remux)\b/i',
            'audio' => '/\b(aac|ac3|ddp|dts|flac|mp3)\b/i',
            'scene_tag' => '/\b(proper|repack|internal|limited|complete|dubbed|subbed|readnfo)\b/i',
            'year' => '/\b(19|20)\d{2}\b/',
            'release_group' => '/-[A-Za-z0-9][A-Za-z0-9._-]{1,20}$/',
            'known_extension' => '/\.(mkv|avi|mp4|mp3|flac|iso|epub|pdf|exe|nzb|rar|7z)$/i',
        ];

        $markers = [];
        foreach ($patterns as $marker => $pattern) {
            if (preg_match($pattern, $name)) {
                $markers[] = $marker;
            }
        }

        $signalScore = count($markers);
        $isCoreToken = preg_match('/^[A-Za-z0-9+\/_=-]+$/', $coreName) === 1;
        $lowSignal = $signalScore === 0
            && $isCoreToken
            && strlen($coreName) >= 12
            && ! $this->hasStrongWordStructure($name, $coreName);

        return [
            'coreName' => $coreName,
            'coreLength' => strlen($coreName),
            'signalScore' => $signalScore,
            'markers' => $markers,
            'lowSignal' => $lowSignal,
        ];
    }

    protected function checkHash(string $name): ?CategorizationResult
    {
        // MD5 hash (32 hex characters)
        if ($this->isBoundedMd5Hash($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_md5');
        }

        // SHA-1 hash (40 hex characters)
        if ($this->isBoundedSha1Hash($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_sha1');
        }

        // SHA-256 hash (64 hex characters)
        if ($this->isBoundedSha256Hash($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_sha256');
        }

        // Generic long hex hash (32-128 chars)
        if ($this->isBoundedGenericHash($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_generic');
        }

        if ($this->isBase64LikeToken($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.9, 'hash_base64_like');
        }

        // Strip extensions and separators for core-name checks
        $cleaned = $this->stripExtensionsForAnalysis($name);
        $coreName = $this->getCoreNameWithoutSeparators($cleaned);

        // UUID pattern
        if ($this->isUuidPattern($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_uuid');
        }

        // Pure hex string (≥16 chars)
        if ($this->isPureHexString($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.95, 'hash_hex');
        }

        return null;
    }

    protected function checkObfuscated(ReleaseContext $context): ?CategorizationResult
    {
        $name = $context->releaseName;

        // Release names consisting only of uppercase letters and numbers
        if ($this->isObfuscatedUppercaseString($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.7, 'obfuscated_uppercase');
        }

        // Mixed-case alphanumeric strings without separators
        if ($this->isObfuscatedMixedAlphanumeric($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.7, 'obfuscated_mixed_alphanumeric');
        }

        $cleaned = $this->stripExtensionsForAnalysis($name);
        $coreName = $this->getCoreNameWithoutSeparators($cleaned);
        if ($this->isSingleTokenForShortObfuscationCheck($cleaned) && $this->isObfuscatedShortMixedAlphanumeric($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.68, 'obfuscated_short_mixed_alphanumeric');
        }

        if ($this->isEncodedMediaMarkerSubject($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.86, 'obfuscated_prefixed_media_token');
        }

        if ($this->isObfuscatedUsenetPar2Volume($name)) {
            if ($this->isReadableVintageFilmSidecarSubject($name, $context)) {
                return null;
            }

            return $this->matched(Category::OTHER_HASHED, 0.86, 'obfuscated_usenet_par2_volume');
        }

        if ($this->isReadableVintageFilmSubject($name, $context)) {
            return null;
        }

        if ($this->isObfuscatedExtractedPar2Volume($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.86, 'obfuscated_extracted_par2_volume');
        }

        if ($this->isObfuscatedUsenetArchiveFilename($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.85, 'obfuscated_usenet_filename');
        }

        if ($this->isObfuscatedExtractedSubject($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.84, 'obfuscated_extracted_subject');
        }

        // Obfuscated filename embedded in usenet subject line format
        if ($this->isObfuscatedUsenetFilename($name)) {
            return $this->matched(Category::OTHER_HASHED, 0.85, 'obfuscated_usenet_filename');
        }

        // Only punctuation and numbers with no clear structure
        if ($this->isObfuscatedPunctuation($name)) {
            $analysis = $this->inspectSignals($name);
            $hashLike = $this->isBase64LikeToken($name)
                || $this->isBoundedGenericHash($name)
                || $this->isZeroVowelLongToken($analysis['coreName'], 12)
                || $analysis['lowSignal'];

            return $this->matched(
                $hashLike ? Category::OTHER_HASHED : Category::OTHER_MISC,
                $hashLike ? 0.75 : 0.5,
                'obfuscated_pattern',
                ['signal_score' => $analysis['signalScore'], 'markers' => $analysis['markers']]
            );
        }

        return null;
    }

    private function isEncodedMediaMarkerSubject(string $name): bool
    {
        if (! preg_match('/^(?P<token>[A-Z0-9]{24,})(?P<markers>(?:\s+(?i:480p|576p|720p|1080p|1080i|2160p|4k|uhd|dvdrip|bdrip|brrip|bluray|web-dl|webdl|webrip|xvid|divx|x264|x265|h264|h265|hevc|mp3|aac|ac3|dts|nogroup|aos))+)\s*$/', trim($name), $matches)) {
            return false;
        }

        $token = $matches['token'];

        if (! $this->hasEncodedPrefixNoiseShape($token)) {
            return false;
        }

        preg_match_all('/\S+/', trim($matches['markers']), $markerTokens);

        return count($markerTokens[0]) >= 2;
    }

    private function hasEncodedPrefixNoiseShape(string $token): bool
    {
        if (preg_match('/[A-Z]/', $token) !== 1 || preg_match('/\d/', $token) !== 1) {
            return false;
        }

        preg_match_all('/\d/', $token, $digits);
        preg_match_all('/[A-Z]/', $token, $letters);

        $length = strlen($token);
        $digitCount = count($digits[0]);
        $letterCount = count($letters[0]);

        return $length >= 24
            && $digitCount >= 8
            && $letterCount >= 6
            && ($digitCount / $length) >= 0.25
            && ! $this->hasStrongWordStructure($token, $token);
    }

    private function isObfuscatedUsenetArchiveFilename(string $name): bool
    {
        if (! preg_match('/"(?P<stem>[A-Za-z0-9_]{7,40})\.(?:part\d+\.rar|7z\.\d{1,4}|rar|zip)"/i', $name, $matches)) {
            return false;
        }

        return $this->isRandomQuotedStem($matches['stem']);
    }

    private function isObfuscatedUsenetPar2Volume(string $name): bool
    {
        if (! preg_match('/"(?P<stem>[A-Za-z0-9_]{7,40})\.(?:vol\d{1,4}\+\d{1,4}\.par2|par2)"/i', $name, $matches)) {
            return false;
        }

        return $this->isRandomQuotedStem($matches['stem']);
    }

    private function isReadableVintageFilmSidecarSubject(string $name, ReleaseContext $context): bool
    {
        if (! $this->isExplicitVintageFilmGroup($context)) {
            return false;
        }

        $outsideQuotedFilename = trim((string) preg_replace('/"[^"]+"/', ' ', $name));

        return preg_match('/\b(19|20)\d{2}\b/', $outsideQuotedFilename) === 1
            && $this->countAlphabeticWordTokens($outsideQuotedFilename) >= 2;
    }

    private function isReadableVintageFilmSubject(string $name, ReleaseContext $context): bool
    {
        if (! $this->isExplicitVintageFilmGroup($context)) {
            return false;
        }

        $hasYear = preg_match('/\b(19|20)\d{2}\b/', $name) === 1;
        if ($this->countAlphabeticWordTokens($name) < 2 && ! ($hasYear && $this->hasReadableSingleWordMovieTitle($name))) {
            return false;
        }

        return $hasYear
            || $this->looksLikeNumberedImageVideoSidecar($name)
            || preg_match('/(?:\.|\b)(?:avi|mkv|mp4|mpg|mpeg|vob)(?:\.\d{3})?"?\s*(?:yEnc)?$/i', $name) === 1
            || preg_match('/\b(?:480p|576p|720p|1080p|2160p|x264|x265|h\.?264|h\.?265|xvid|divx|dvdrip|vhsrip|tvrip|bdrip|bluray|dvd)\b/i', $name) === 1;
    }

    private function hasReadableSingleWordMovieTitle(string $name): bool
    {
        if (! preg_match('/^\s*(?<title>\p{Lu}\p{Ll}{3,}(?:[\'-]\p{L}{2,})?)\s+\b(?:19|20)\d{2}\b\s*$/u', $name, $matches)) {
            return false;
        }

        return preg_match('/[aeiouy]/i', $matches['title']) === 1;
    }

    private function looksLikeNumberedImageVideoSidecar(string $name): bool
    {
        return preg_match('/\b(?:avi|mkv|mp4|mpg|mpeg|vob)[._ -]\d{1,4}[._ -](?:jpe?g|png)\b/i', $name) === 1;
    }

    private function isExplicitVintageFilmGroup(ReleaseContext $context): bool
    {
        return $context->groupMatchesPattern('/(?:alt\.binaries|a\.b)\..*?(?:vintage[.-]?film|classic[.-]?film|old[.-]?movies?|movies?[.-]?classic|dvd[.-]classic)/i');
    }

    private function isObfuscatedExtractedPar2Volume(string $name): bool
    {
        if (! preg_match('/^(?P<stem>[A-Za-z0-9]{7,40})\s+vol\d{1,4}\s+\d{1,4}$/i', trim($name), $matches)) {
            if (! preg_match('/^(?P<stem>[A-Za-z0-9]+(?:\s+[A-Za-z0-9]+){1,4})\s+vol\d{1,4}\s+\d{1,4}(?:\s+par2)?$/i', trim($name), $matches)) {
                return false;
            }
        }

        return $this->isRandomQuotedStem($matches['stem'])
            || $this->isObfuscatedExtractedRandomStem($matches['stem'], 2);
    }

    private function isObfuscatedExtractedSubject(string $name): bool
    {
        $tokens = preg_split('/\s+/', trim($name)) ?: [];

        if ($this->isObfuscatedExtractedEncryptedPart($tokens)) {
            return true;
        }

        if ($this->isObfuscatedExtractedSingleToken($tokens)) {
            return true;
        }

        if ($this->isObfuscatedExtractedRandomStem($name, 2)) {
            return true;
        }

        if (count($tokens) !== 2) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! preg_match('/^[A-Za-z0-9]{4,24}$/', $token)) {
                return false;
            }
        }

        $stem = implode('', $tokens);

        return preg_match('/[A-Z]/', $stem) === 1
            && preg_match('/[a-z]/', $stem) === 1
            && preg_match('/\d/', $stem) === 1
            && $this->isRandomQuotedStem($stem);
    }

    /**
     * Matches extractor-normalized reverse/obfuscated multipart names such as
     * "FuRUbYFcVqJbZ9946AY cneg59 ene".
     *
     * @param  list<string>  $tokens
     */
    private function isObfuscatedExtractedEncryptedPart(array $tokens): bool
    {
        if (count($tokens) !== 3) {
            return false;
        }

        if (! preg_match('/^cneg\d{1,4}$/i', $tokens[1]) || strtolower($tokens[2]) !== 'ene') {
            return false;
        }

        return $this->isRandomQuotedStem($tokens[0]);
    }

    /**
     * Matches a quoted archive/PAR2 stem after ObfuscatedSubjectExtractor has
     * reduced the subject to only the filename stem, e.g. "fVkejF9".
     *
     * @param  list<string>  $tokens
     */
    private function isObfuscatedExtractedSingleToken(array $tokens): bool
    {
        if (count($tokens) !== 1) {
            return false;
        }

        $stem = $tokens[0];

        if (strlen($stem) < 7 || strlen($stem) > 24 || preg_match('/^[A-Za-z0-9]+$/', $stem) !== 1) {
            return false;
        }

        if (preg_match('/\b(19|20)\d{2}\b/', $stem) || preg_match('/^[A-Z][a-z]+([A-Z][a-z]+)+$/', $stem)) {
            return false;
        }

        return ! $this->hasStrongWordStructure($stem, $stem)
            && (
                $this->isRandomQuotedStem($stem)
                || $this->isMixedCaseNoiseArchiveStem($stem)
            );
    }

    private function isObfuscatedExtractedRandomStem(string $stem, int $minimumTokens): bool
    {
        $tokens = preg_split('/\s+/', trim($stem)) ?: [];
        $tokenCount = count($tokens);

        if ($tokenCount < $minimumTokens || $tokenCount > 5) {
            return false;
        }

        foreach ($tokens as $token) {
            if (preg_match('/^[A-Za-z0-9]{1,24}$/', $token) !== 1) {
                return false;
            }
        }

        $joined = implode('', $tokens);

        if (strlen($joined) < 12 || strlen($joined) > 60) {
            return false;
        }

        if (preg_match('/\b(19|20)\d{2}\b/', $joined)) {
            return false;
        }

        $hasRandomEvidence = $this->hasSegmentedRandomTokenEvidence($tokens, $joined)
            || $this->isMixedCaseNoiseArchiveStem($joined);

        if (! $hasRandomEvidence) {
            return false;
        }

        if ($this->inspectSignals($stem)['signalScore'] > 0 && preg_match('/\bS\d{1,3}\s*E\d{1,4}\b/i', $stem)) {
            return false;
        }

        return $this->looksLikeRandomString($joined)
            || $this->isRandomQuotedStem($joined)
            || $this->isRandomByCharacterAnalysis($joined, $stem)
            || $this->hasInsufficientWordStructure($joined)
            || $this->isDigitHeavyArchiveStem($joined)
            || $this->isMixedCaseNoiseArchiveStem($joined);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function hasSegmentedRandomTokenEvidence(array $tokens, string $joined): bool
    {
        $randomChunks = 0;

        foreach ($tokens as $token) {
            if (strlen($token) < 4) {
                continue;
            }

            if (
                (
                    preg_match('/\d/', $token) === 1
                    && preg_match('/[A-Z]/', $token) === 1
                    && preg_match('/[a-z]/', $token) === 1
                )
                || $this->isMixedCaseNoiseArchiveStem($token)
                || $this->looksLikeRandomString($token)
            ) {
                $randomChunks++;
            }
        }

        return $randomChunks >= 2
            || ($randomChunks >= 1 && (
                $this->looksLikeRandomString($joined)
                || $this->isMixedCaseNoiseArchiveStem($joined)
            ));
    }

    private function isRandomQuotedStem(string $stem): bool
    {
        $stem = str_replace('_', '', $stem);

        return ! $this->hasStrongWordStructure($stem, $stem)
            && (
                $this->isObfuscatedShortMixedAlphanumeric($stem)
                || $this->isObfuscatedMixedAlphanumeric($stem)
                || $this->looksLikeRandomString($stem)
                || $this->isRandomByCharacterAnalysis($stem, $stem)
                || $this->hasInsufficientWordStructure($stem)
                || $this->isDigitHeavyArchiveStem($stem)
                || $this->isMixedCaseNoiseArchiveStem($stem)
            );
    }

    private function isMixedCaseNoiseArchiveStem(string $stem): bool
    {
        $length = strlen($stem);

        if ($length < 7 || $length > 20 || preg_match('/^[A-Za-z]+$/', $stem) !== 1) {
            return false;
        }

        if (preg_match('/^[A-Z][a-z]+([A-Z][a-z]+)+$/', $stem)) {
            return false;
        }

        preg_match_all('/[A-Z]/', $stem, $upper);
        preg_match_all('/[a-z]/', $stem, $lower);

        if (count($upper[0]) < 3 || count($lower[0]) < 3) {
            return false;
        }

        $caseTransitions = 0;
        for ($i = 1; $i < $length; $i++) {
            if (ctype_upper($stem[$i]) !== ctype_upper($stem[$i - 1])) {
                $caseTransitions++;
            }
        }

        return ($caseTransitions / ($length - 1)) >= 0.35;
    }

    private function isDigitHeavyArchiveStem(string $stem): bool
    {
        if (preg_match('/\b(19|20)\d{2}\b/', $stem)) {
            return false;
        }

        preg_match_all('/\d/', $stem, $digits);

        return count($digits[0]) >= 5
            && preg_match('/^[A-Za-z0-9]{7,20}$/', $stem) === 1;
    }

    protected function isSingleTokenForShortObfuscationCheck(string $cleaned): bool
    {
        return preg_match('/^[A-Za-z0-9]+$/', $cleaned) === 1;
    }

    /**
     * Check for gibberish patterns using character-analysis heuristics.
     * These are patterns ported from FileNameCleaner that were previously
     * missing from the categorization pipeline.
     */
    protected function checkGibberish(string $name): ?CategorizationResult
    {
        $cleaned = $this->stripExtensionsForAnalysis($name);
        $coreName = $this->getCoreNameWithoutSeparators($cleaned);

        if ($this->hasStrongWordStructure($name, $coreName)) {
            return null;
        }

        // High character-transition rate suggests randomness
        if ($this->isRandomByCharacterAnalysis($coreName, $name)) {
            return $this->matched(Category::OTHER_HASHED, 0.75, 'gibberish_random_transitions');
        }

        // Long alphanumeric but lacks word-like letter sequences
        if ($this->hasInsufficientWordStructure($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.75, 'gibberish_no_word_structure');
        }

        // Random-looking patterns dominated by digits
        if ($this->isRandomDigitPattern($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.7, 'gibberish_random_digits');
        }

        if ($this->isZeroVowelLongToken($coreName)) {
            return $this->matched(Category::OTHER_HASHED, 0.78, 'gibberish_zero_vowels');
        }

        return null;
    }

    protected function checkLowSignal(string $name): ?CategorizationResult
    {
        $analysis = $this->inspectSignals($name);

        if ($this->hasStrongWordStructure($name, $analysis['coreName'])) {
            return null;
        }

        if ($this->isZeroVowelLongToken($analysis['coreName'])) {
            return null;
        }

        if ($analysis['lowSignal'] && $analysis['coreLength'] >= 20) {
            return $this->matched(
                Category::OTHER_HASHED,
                0.8,
                'gibberish_no_signal',
                [
                    'signal_score' => $analysis['signalScore'],
                    'markers' => $analysis['markers'],
                    'core_length' => $analysis['coreLength'],
                ]
            );
        }

        return null;
    }

    protected function hasStrongWordStructure(string $name, string $coreName): bool
    {
        return $this->getMaxConsecutiveLetters($coreName) >= 5
            && $this->hasNormalVowelRatio($coreName)
            && $this->countAlphabeticWordTokens($name) >= 2;
    }

    protected function countAlphabeticWordTokens(string $name): int
    {
        $tokens = preg_split('/[.\s_-]+/', $this->stripExtensionsForAnalysis($name)) ?: [];

        return count(array_filter(
            $tokens,
            static fn (string $token): bool => preg_match('/[a-z]{3,}/i', $token) === 1
        ));
    }

    protected function checkArchive(string $name): ?CategorizationResult
    {
        if (preg_match('/\.(zip|rar|7z|tar|gz|bz2|xz|tgz|tbz2|cab|iso|img|dmg|pkg|archive)$/i', $name)) {
            return $this->matched(Category::OTHER_MISC, 0.5, 'archive');
        }

        return null;
    }

    protected function checkDataset(string $name): ?CategorizationResult
    {
        // Dataset/dump patterns that aren't media
        if (preg_match('/\b(sql|csv|dump|backup|dataset|collection)\b/i', $name) &&
            ! preg_match('/\b(movie|tv|show|audio|video|book|game)\b/i', $name)) {
            return $this->matched(Category::OTHER_MISC, 0.6, 'dataset');
        }

        // Data leaks/dumps (be careful with these)
        if (preg_match('/\b(leak|breach|data|database)\b/i', $name) &&
            preg_match('/\b(dump|export|backup)\b/i', $name) &&
            ! preg_match('/\b(movie|tv|show|audio|video|book|game)\b/i', $name)) {
            return $this->matched(Category::OTHER_MISC, 0.6, 'data_dump');
        }

        return null;
    }
}
