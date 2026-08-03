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
 * Stripping the token is necessary but not sufficient: the surviving name still
 * has to key the collection, and the default key runs the cleaned subject
 * through CollectionsCleaningService, which strips digit runs. Measured against
 * the live cleaner, the 98 real filenames of four postings collapse onto FIVE
 * collection keys ('{Soulm8te. } yEnc' absorbs part01..part35 plus every par2
 * volume). With file_number pinned to 1 and binaries carrying UNIQUE
 * (collections_id, filenumber), each of those files would then resolve to the
 * same single binary and pile every part onto it -- one NZB "file" standing in
 * for 43. The collection therefore gets an explicit per-file key instead; see
 * collectionKey().
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

        return [
            'name' => trim($captured['name'].rtrim($captured['trailer'])),
            // These subjects describe a single file; the only counter present
            // is the part counter, which must not be mistaken for a file count.
            'file_number' => 1,
            'total_files' => 1,
        ];
    }

    /**
     * The collection key shared by every article of one brace-token file.
     *
     * Keyed on the de-tokenised filename rather than the cleaned subject: the
     * cleaner strips digit runs, so 'part01.rar' and 'part02.rar' clean to the
     * same string and would share a collection, and with file_number pinned to
     * 1/1 they would then share a binary too.
     *
     * Single source of truth: the ingest path keys new collections with this,
     * and the brace-token repair pass (nntmux:repair-brace-token-identity)
     * rewrites stranded collections onto the same value. If the two ever
     * disagree, repaired rows become invisible to ingest and the next article
     * for that file mints a fresh stalled collection.
     *
     * @param  string  $normalizedName  The subject with its token already stripped.
     */
    public static function collectionKey(string $normalizedName, int $groupId): string
    {
        return \sprintf('bracetoken:g%d:%s', $groupId, sha1(trim($normalizedName)));
    }

    /**
     * The collection key shared by every FILE of one brace-token posting.
     *
     * Keying per file (collectionKey() above) produces a collection holding a
     * single binary, and the release pipeline deletes that shape twice over:
     *
     *  - runCollectionFileCheckStage6() rewrites totalfiles to COUNT(binaries),
     *    so a one-binary collection lands below `minfilestoformrelease` and
     *    deleteCollectionsUnderThreshold() removes it;
     *  - a par2 file's lone binary is 100% par2, so createReleases()' par2-only
     *    filter removes it even when the floor is cleared.
     *
     * Both deletes cascade through FK_Collections and take the parts with them.
     * Grouping a posting's payload volumes and its par2 recovery set into one
     * collection -- each file its own binary -- is what satisfies them, and is
     * also the shape an unobfuscated post already has.
     *
     * @param  string  $postingName  The posting stem from postingIdentity().
     */
    public static function postingKey(string $postingName, int $groupId): string
    {
        return \sprintf('bracetokenpost:g%d:%s', $groupId, sha1(trim($postingName)));
    }

    /**
     * Split a de-tokenised filename into its posting stem and file ordering.
     *
     * Only the *relative* order within a kind is derivable from one filename,
     * which is why this returns a sort hint rather than a file number: dense
     * ordinals need the payload count, so they can only be allocated once the
     * whole cohort is known (see BraceTokenIdentityRepairService). Two gates
     * read MAX(filenumber) as a file count, so sparse or high-band numbering
     * would leave a collection permanently incomplete.
     *
     * 'kind' orders payload files ahead of the par2 set, matching how a normal
     * post is laid out.
     *
     * @param  string  $normalizedName  Subject with its token already stripped.
     * @return array{posting: string, kind: int, hint: int}
     */
    public static function postingIdentity(string $normalizedName): array
    {
        $name = trim($normalizedName);
        // Work on the braced filename, keeping whatever trailer ('yEnc') follows
        // so the reassembled posting subject stays in the same shape.
        $inner = preg_match('/^\{(?P<inner>[^{}]+)\}/', $name, $braced) === 1
            ? $braced['inner']
            : $name;

        if (preg_match('/^(?P<stem>.+)\.part(?P<n>\d+)\.rar$/i', $inner, $m) === 1) {
            return ['posting' => $m['stem'], 'kind' => 0, 'hint' => (int) $m['n']];
        }

        if (preg_match('/^(?P<stem>.+)\.vol(?P<start>\d+)\+\d+\.par2$/i', $inner, $m) === 1) {
            return ['posting' => $m['stem'], 'kind' => 1, 'hint' => (int) $m['start']];
        }

        if (preg_match('/^(?P<stem>.+)\.par2$/i', $inner, $m) === 1) {
            return ['posting' => $m['stem'], 'kind' => 1, 'hint' => -1];
        }

        if (preg_match('/^(?P<stem>.+)\.(?:rar|r\d+|\d+)$/i', $inner, $m) === 1) {
            return ['posting' => $m['stem'], 'kind' => 0, 'hint' => 1];
        }

        // Unrecognised layout: treat the file as its own posting rather than
        // guessing a stem and fusing unrelated files.
        return ['posting' => $inner, 'kind' => 0, 'hint' => 1];
    }
}
