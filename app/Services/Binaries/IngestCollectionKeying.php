<?php

declare(strict_types=1);

namespace App\Services\Binaries;

/**
 * The switch for docs/design/2026-08-04-ingest-collection-keying.md.
 *
 * When a group is enabled here, HeaderStorageService stops letting a PART
 * counter that leaked in through extractFileNumberAndTotal()'s raw-subject
 * fallback reach either the collection key or collections.totalfiles, and
 * allocates a dense per-collection ordinal for the binary instead. Groups that
 * are NOT enabled keep byte-identical behaviour, which is what makes this a
 * switch rather than a rewrite.
 *
 * Two deliberate differences from nntmux.ingest_partcount_key_groups, which
 * this does NOT replace:
 *
 *  1. That flag gates a LOG LINE. This one gates WRITES. They are separate keys
 *     on purpose: the reporting flag is already deployed fleet-wide as `all`
 *     (mediahome/manifests/media/nntmux/distributed-workers.yaml), and reusing
 *     the name would have turned the next image build into a fleet-wide
 *     behaviour change with nobody having asked for one.
 *
 *  2. There is NO `all` sentinel. The reporting flag has one because a
 *     measurement window wants every group at once. The rollout for this one is
 *     explicitly group by group -- cinemageddon first, because its subjects
 *     carry real names, so a wrong merge is visible -- and a sentinel that
 *     enables ingest everywhere in one edit is the opposite of that. A literal
 *     `all` simply never matches a newsgroup name, so it reads as "off".
 */
final class IngestCollectionKeying
{
    /** @var list<string> */
    private array $groups;

    private bool $legacyAdoption;

    /**
     * @param  list<string>|null  $groups  Newsgroup names to apply to; null reads config.
     * @param  bool|null  $legacyAdoption  Null reads config.
     */
    public function __construct(?array $groups = null, ?bool $legacyAdoption = null)
    {
        $this->groups = array_values(array_filter(array_map(
            static fn (mixed $group): string => strtolower(trim((string) $group)),
            $groups ?? (array) config('nntmux.ingest_collection_keying_groups', []),
        )));

        $this->legacyAdoption = $legacyAdoption
            ?? (bool) config('nntmux.ingest_collection_keying_legacy_adoption', false);
    }

    /**
     * Whether the keying change is enabled for the given group.
     */
    public function appliesTo(string $groupName): bool
    {
        $groupName = strtolower(trim($groupName));

        if ($this->groups === [] || $groupName === '') {
            return false;
        }

        return \in_array($groupName, $this->groups, true);
    }

    /**
     * Whether a key miss may adopt the collection the old key would have hit.
     *
     * Design section 4, and the design calls it optional: "without it the hourly
     * sweep simply has more to do for a week". It defaults OFF because adoption
     * has a hazard the design does not discuss.
     *
     * An adopted collection keeps its old `totalfiles` -- the wrong part count,
     * say 2. Feeding it the rest of a 30-file posting takes it past the stage 1
     * gate (COUNT(DISTINCT filenumber) >= CEIL(totalfiles * completion / 100)),
     * so it promotes to filecheck=1 holding 6 of 30 files and releases short.
     * Without adoption the in-flight fragment simply stalls where it already
     * stalls today and the hourly sweep merges it.
     *
     * The hazard is not created here -- two files of one posting that happen to
     * declare the same part count already share a collection with a too-small
     * totalfiles today -- but adoption feeds it more, so it is opt-in on top of
     * an already opt-in flag.
     */
    public function legacyAdoptionEnabled(): bool
    {
        return $this->legacyAdoption;
    }
}
