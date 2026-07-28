<?php

declare(strict_types=1);

namespace App\Services\Binaries;

/**
 * Restores a shared collection identity for "hash set" obfuscated posts.
 *
 * Some posters publish a multi-file set where every file is named by its own
 * SHA-1, and only the bracket counter relates them:
 *
 *   [001/199] - "002377997d944ccb76d4ae1814579afe1930411f" yEnc
 *   [002/199] - "00cb51d150ef49c6cd0716b6282796df8ab4b828" yEnc
 *   [003/199] - "03234d0658b14b4c72b4d92a0dbe7a2972446578.par2" yEnc
 *
 * Unlike the brace-token style (see ObfuscatedSubjectNormalizer), the bracket
 * counter here is a genuine FILE counter: the post really does contain 199
 * files, each of which is itself split into many parts. Confirmed against live
 * article bodies, where three articles of one such file all report the same
 * name with part=N of total=562:
 *
 *   =ybegin part=5  total=562 size=402653184 name=233d5dd...b3f
 *   =ybegin part=35 total=562 size=402653184 name=233d5dd...b3f
 *
 * So `totalfiles` must be preserved, and the file number must NOT be pinned to
 * 1/1 -- doing that would shatter one post into N single-file releases.
 *
 * The real defect is the collection key. CollectionHandler keys collections on
 * the cleaned subject name plus totalfiles, but because every file carries a
 * different SHA-1 name, each file mints its own collection holding exactly one
 * file number. Each then waits forever for the other 198, so the set never
 * satisfies the completion gate and never yields a release.
 *
 * There is no shared token in the subject to key on, but the articles of one
 * post do share a stable triple: newsgroup, posted date, and declared total.
 * Measured across 1,070 such cohorts / 68,500 live collections, grouping on
 * (group, article date, totalfiles) produced ZERO cohorts where the collection
 * count exceeded the distinct file-number count -- i.e. no two distinct posts
 * were merged -- while collapsing each post down to a single collection that
 * holds 198 of 199 distinct file numbers (>= the 94% completionpercent gate).
 *
 * The date is used at whole-second precision deliberately: every article of a
 * post shares an identical Date header, and widening it to a tolerance window
 * would start merging unrelated posts that happen to declare the same total.
 */
final class ObfuscatedHashSetNormalizer
{
    /**
     * Bracket file counter, then a quoted bare SHA-1 with an optional extension.
     *
     * The name is deliberately restricted to exactly 40 hex characters so that
     * ordinary quoted filenames (which carry words, years, resolutions, etc.)
     * are never captured.
     */
    private const string HASH_SET_PATTERN = '/^\[\s*(?P<file>\d{1,5})\s*\/\s*(?P<total>\d{1,5})\s*\]\s*(?:-\s*)?"(?P<hash>[0-9a-f]{40})(?P<ext>\.[A-Za-z0-9][A-Za-z0-9.+_-]{0,11})?"\s+yEnc$/';

    /** @var list<string> */
    private array $groups;

    /**
     * @param  list<string>|null  $groups  Newsgroup names to apply to; null reads config.
     */
    public function __construct(?array $groups = null)
    {
        $this->groups = array_values(array_filter(array_map(
            static fn (mixed $group): string => strtolower(trim((string) $group)),
            $groups ?? (array) config('nntmux.obfuscated_hash_set_groups', []),
        )));
    }

    /**
     * Whether cohort keying is enabled for the given group.
     */
    public function appliesTo(string $groupName): bool
    {
        return $this->groups !== [] && \in_array(strtolower(trim($groupName)), $this->groups, true);
    }

    /**
     * Derive a cohort-stable collection name for a hash-set subject.
     *
     * Returns null when the subject is not of the hash-set shape, in which case
     * the caller must keep its existing key derivation untouched.
     *
     * @param  string  $parsedSubject  Subject with its (x/y) part counter removed (matches[1]).
     * @param  int  $groupId  Newsgroup id the article was collected from.
     * @param  int  $articleUnixtime  Posted date of the article, whole seconds.
     * @return array{name: string, file_number: int, total_files: int}|null
     */
    public function normalize(string $parsedSubject, int $groupId, int $articleUnixtime): ?array
    {
        if (preg_match(self::HASH_SET_PATTERN, trim($parsedSubject), $captured) !== 1) {
            return null;
        }

        $fileNumber = (int) $captured['file'];
        $totalFiles = (int) $captured['total'];

        // A set must declare more than one file, and the file number must fall
        // inside the declared range; anything else is not a coherent cohort.
        if ($totalFiles < 2 || $fileNumber < 1 || $fileNumber > $totalFiles) {
            return null;
        }

        return [
            'name' => \sprintf('hashset:g%d:t%d:d%d', $groupId, $totalFiles, $articleUnixtime),
            'file_number' => $fileNumber,
            'total_files' => $totalFiles,
        ];
    }
}
