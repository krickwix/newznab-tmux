<?php

declare(strict_types=1);

namespace App\Services\Binaries;

/**
 * Collapses "brace token" obfuscated subjects back onto a stable identity.
 *
 * Some posters publish every article of a single file with a per-article
 * random token appended after the real filename, e.g.
 *
 *   {Supergirl.2026.vol127+72.par2} {5Zn03jrjsly2} yEnc (410/710)
 *   {Supergirl.2026.vol127+72.par2} {K7x0l9F182pk} yEnc (168/710)
 *
 * The real filename is intact; only the token varies. Because the token is
 * part of the parsed subject, it leaks into both the collection key
 * (sha1(name + totalFiles)) and the binary hash (md5(name + from + groupId)),
 * so every single article mints its own collection AND its own binary. A
 * 710-part file becomes 710 collections and 710 binaries, each stuck at
 * currentparts=1/totalparts=710, which can never satisfy the parts-complete
 * gate and therefore never yields a release.
 *
 * Stripping the token restores the same shape a normal multi-part binary has:
 * one identity shared by every article, with the (x/y) part counter carrying
 * the per-article difference.
 *
 * Additionally, these subjects carry no file counter ("[1/12]"), only a part
 * counter. The generic file-count probe falls back to the raw subject and
 * misreads the part counter as a file count (totalfiles=710 for what is
 * actually a single file). We therefore pin the collection file numbers
 * explicitly to 1 of 1.
 *
 * Opt-in per group: obfuscation styles are poster-specific, so we only apply
 * this where it has been observed rather than reshaping every group's headers.
 */
final class ObfuscatedSubjectNormalizer
{
    /**
     * Real filename in braces, followed by a braced random token.
     *
     * The token is deliberately constrained to a bare alphanumeric run so that
     * legitimate braced metadata (which almost always contains separators such
     * as '.', '-' or spaces) is left alone.
     */
    private const string BRACE_TOKEN_PATTERN = '/^(?P<name>\{[^{}]+\})\s*\{[A-Za-z0-9]{8,16}\}(?P<trailer>.*)$/';

    /** @var list<string> */
    private array $groups;

    /**
     * @param  list<string>|null  $groups  Newsgroup names to apply to; null reads config.
     */
    public function __construct(?array $groups = null)
    {
        $this->groups = array_values(array_filter(array_map(
            static fn (mixed $group): string => strtolower(trim((string) $group)),
            $groups ?? (array) config('nntmux.obfuscated_brace_token_groups', []),
        )));
    }

    /**
     * Whether normalization is enabled for the given group.
     */
    public function appliesTo(string $groupName): bool
    {
        return $this->groups !== [] && \in_array(strtolower(trim($groupName)), $this->groups, true);
    }

    /**
     * Strip the per-article token from an already-parsed subject.
     *
     * @param  string  $parsedSubject  The subject with its (x/y) counter removed (matches[1]).
     * @return array{name: string, file_number: int, total_files: int}|null Null when not obfuscated.
     */
    public function normalize(string $parsedSubject): ?array
    {
        if (preg_match(self::BRACE_TOKEN_PATTERN, $parsedSubject, $captured) !== 1) {
            return null;
        }

        $name = $captured['name'].rtrim($captured['trailer']);

        return [
            'name' => trim($name),
            // These subjects describe a single file; the only counter present
            // is the part counter, which must not be mistaken for a file count.
            'file_number' => 1,
            'total_files' => 1,
        ];
    }
}
