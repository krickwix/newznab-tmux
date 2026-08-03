<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\ObfuscatedSubjectNormalizer;
use App\Services\Diagnostics\BraceTokenIdentityRepairService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The merge itself. Whether the merged shape then SURVIVES the release pipeline
 * is a separate question, asserted in BraceTokenRepairSurvivesReleaseGatesTest
 * -- an earlier version of this service passed every test here and still had
 * its output deleted, with 512 production collections and ~541 MB of articles
 * cascaded away. Neither file is sufficient alone.
 */
final class BraceTokenIdentityRepairServiceTest extends TestCase
{
    private const GROUP_ID = 6979;

    private const GROUP_NAME = 'alt.binaries.movies';

    private int $nextBinaryId = 1;

    private int $nextArticle = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.obfuscated_brace_token_groups' => [self::GROUP_NAME],
        ]);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            minfilestoformrelease INT NULL
        )');

        DB::statement('CREATE TABLE settings (
            name VARCHAR(255) PRIMARY KEY,
            value VARCHAR(255)
        )');

        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255) DEFAULT \'\',
            date DATETIME NULL,
            xref TEXT DEFAULT \'\',
            groups_id INT,
            totalfiles INT DEFAULT 0,
            collectionhash VARCHAR(255) UNIQUE,
            collection_regexes_id INT DEFAULT 0,
            dateadded DATETIME NULL,
            filecheck INT DEFAULT 0,
            filesize INT DEFAULT 0,
            releases_id INT NULL,
            noise VARCHAR(32) DEFAULT \'\'
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(1000),
            collections_id INT,
            -- CHECK mirrors the MariaDB `int(10) unsigned`. Without it sqlite
            -- accepts a negative filenumber that production silently clamps to
            -- 0, which is exactly how the first production run of this repair
            -- failed while these tests were green.
            filenumber INT DEFAULT 0 CHECK (filenumber >= 0),
            totalparts INT DEFAULT 0,
            currentparts INT DEFAULT 0,
            partcheck INT DEFAULT 0,
            partsize INT DEFAULT 0,
            UNIQUE(collections_id, filenumber)
        )');

        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            messageid VARCHAR(255),
            number INT,
            partnumber INT,
            size INT,
            PRIMARY KEY (binaries_id, number)
        )');

        DB::table('usenet_groups')->insert([
            'id' => self::GROUP_ID,
            'name' => self::GROUP_NAME,
            'minfilestoformrelease' => null,
        ]);
        // Production site value; group 6979 has no override, so this is the floor
        // the repair must measure postings against.
        DB::table('settings')->insert(['name' => 'minfilestoformrelease', 'value' => '2']);
    }

    public function test_dry_run_reports_cohorts_without_touching_anything(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 512);
        $this->seedStrandedFile('{Soulm8te.2026.part02.rar} yEnc', 512);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, false);

        $this->assertFalse($summary['updated']);
        // Both files belong to ONE posting, which is the unit that has to reach a
        // release: a per-file collection is deleted downstream.
        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(1024, $summary['collections_in_cohorts']);
        $this->assertSame(2, $summary['files_in_cohorts']);
        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['collections_removed']);
        $this->assertSame(0, $summary['cohorts_skipped']);

        // Nothing moved.
        $this->assertSame(1024, DB::table('collections')->count());
        $this->assertSame(1024, DB::table('binaries')->count());
    }

    public function test_update_collapses_a_posting_onto_one_collection_with_one_binary_per_file(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 40);
        $this->seedStrandedFile('{Soulm8te.2026.part02.rar} yEnc', 12);
        $this->seedStrandedFile('{Soulm8te.2026.vol000+01.par2} yEnc', 5);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['cohorts_skipped']);
        $this->assertSame(57 - 1, $summary['collections_removed']);
        $this->assertSame(57 - 3, $summary['binaries_removed']);
        $this->assertSame(3, $summary['binaries_retained']);
        $this->assertSame(57 - 3, $summary['parts_moved']);

        $this->assertSame(1, DB::table('collections')->count());
        // One binary per real file, so the NZB describes 3 files rather than
        // collapsing them onto one.
        $this->assertSame(3, DB::table('binaries')->count());
        // Every part is retained -- the merge rehomes them, never drops them.
        $this->assertSame(57, DB::table('parts')->count());

        $hash = sha1(ObfuscatedSubjectNormalizer::postingKey('Soulm8te.2026', self::GROUP_ID));
        $collection = DB::table('collections')->where('collectionhash', $hash)->first();
        $this->assertNotNull($collection);
        $this->assertSame('{Soulm8te.2026}', $collection->subject);
        $this->assertSame(3, (int) $collection->totalfiles);
        $this->assertSame(0, (int) $collection->filecheck);

        $expected = [
            '{Soulm8te.2026.part01.rar} yEnc' => 40,
            '{Soulm8te.2026.part02.rar} yEnc' => 12,
            '{Soulm8te.2026.vol000+01.par2} yEnc' => 5,
        ];

        $binaries = DB::table('binaries')
            ->where('collections_id', $collection->id)
            ->orderBy('filenumber')
            ->get();

        // Payload volumes first, then the par2 set: the layout an unobfuscated
        // post already has.
        $this->assertSame(array_keys($expected), $binaries->pluck('name')->all());

        $ordinal = 0;
        foreach ($binaries as $binary) {
            $ordinal++;
            $parts = $expected[$binary->name];

            // Dense 1..N. Two gates read MAX(filenumber) as a file count, so a
            // gap or high-band offset leaves the collection never complete.
            $this->assertSame($ordinal, (int) $binary->filenumber, $binary->name);
            $this->assertSame($parts, (int) $binary->currentparts, $binary->name);
            $this->assertSame($parts, (int) $binary->totalparts, $binary->name);
            $this->assertSame(0, (int) $binary->partcheck);
            $this->assertSame(
                $parts,
                DB::table('parts')->where('binaries_id', $binary->id)->count(),
                $binary->name
            );
            $this->assertSame(
                (int) DB::table('parts')->where('binaries_id', $binary->id)->sum('size'),
                (int) $binary->partsize
            );
        }
    }

    public function test_survivor_keeps_the_oldest_member_timestamps(): void
    {
        $this->seedStrandedFile('{Lioness.S03.part01.rar} yEnc', 3, [
            'dates' => ['2026-08-02 05:00:00', '2026-08-02 03:30:04', '2026-08-02 07:15:00'],
            'dateadded' => ['2026-08-02 06:00:00', '2026-08-02 04:00:00', '2026-08-02 08:00:00'],
        ]);
        $this->seedStrandedFile('{Lioness.S03.vol007+08.par2} yEnc', 2, [
            'dates' => ['2026-08-02 09:00:00', '2026-08-02 09:30:00'],
            'dateadded' => ['2026-08-02 09:00:00', '2026-08-02 09:30:00'],
        ]);

        $this->service()->repair(self::GROUP_ID, 50, null, true);

        $collection = DB::table('collections')->first();
        $this->assertSame('2026-08-02 03:30:04', $collection->date);
        $this->assertSame('2026-08-02 04:00:00', $collection->dateadded);
    }

    public function test_a_second_update_is_idempotent(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 6);
        $this->seedStrandedFile('{Soulm8te.2026.part02.rar} yEnc', 4);

        $first = $this->service()->repair(self::GROUP_ID, 50, null, true);
        $this->assertSame(1, $first['cohorts_merged']);

        $before = [
            'collections' => DB::table('collections')->get()->toArray(),
            'binaries' => DB::table('binaries')->orderBy('id')->get()->toArray(),
            'parts' => DB::table('parts')->orderBy('number')->get()->toArray(),
        ];

        $second = $this->service()->repair(self::GROUP_ID, 50, null, true);

        // A repaired survivor carries the de-tokenised subject, so it is no
        // longer a candidate at all -- the pass converges rather than churning.
        $this->assertSame(0, $second['cohorts_found']);
        $this->assertSame(0, $second['collections_removed']);
        $this->assertSame(0, $second['binaries_removed']);
        $this->assertSame(0, $second['parts_moved']);

        $this->assertEquals($before['collections'], DB::table('collections')->get()->toArray());
        $this->assertEquals($before['binaries'], DB::table('binaries')->orderBy('id')->get()->toArray());
        $this->assertEquals($before['parts'], DB::table('parts')->orderBy('number')->get()->toArray());
    }

    public function test_a_posting_whose_file_members_claim_the_same_partnumber_is_skipped(): void
    {
        // Two collections both holding part 1 of the same file are not one file;
        // merging them would silently drop an article.
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 2, [
            'partnumbers' => [1, 1],
        ]);
        $this->seedStrandedFile('{Soulm8te.2026.part02.rar} yEnc', 2);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(1, $summary['cohorts_skipped']);
        $this->assertSame('duplicate_partnumber', $summary['skipped'][0]['reason']);
        $this->assertSame('{Soulm8te.2026.part01.rar} yEnc', $summary['skipped'][0]['file']);
        $this->assertSame([1], $summary['skipped'][0]['values']);

        // The whole posting is left untouched: the clean file is not merged on
        // its own, because a one-file collection is deleted downstream.
        $this->assertSame(4, DB::table('collections')->count());
        $this->assertSame(4, DB::table('binaries')->count());
        $this->assertSame(4, DB::table('parts')->count());
    }

    /**
     * A posting resolving to fewer real files than `minfilestoformrelease` is
     * left stranded rather than merged: deleteCollectionsUnderThreshold() would
     * delete the merged row and FK_Collections would take every part with it.
     * Stranded rows are recoverable until retention; cascaded ones are not.
     */
    public function test_a_posting_below_the_min_files_floor_is_refused(): void
    {
        $this->seedStrandedFile('{Lioness.2023.S03E01.2160p.H.265-FLUX.rar} yEnc', 6);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(2, $summary['min_files']);
        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(1, $summary['cohorts_skipped']);
        $this->assertSame('below_min_files', $summary['skipped'][0]['reason']);

        $this->assertSame(6, DB::table('collections')->count());
        $this->assertSame(6, DB::table('parts')->count());
    }

    /**
     * The Lioness.S03 case from production: eight par2 volumes whose payload
     * carries an unrelated filename, so no filename-derived key groups them.
     * createReleases()' $par2Only filter deletes an all-par2 collection even
     * when it clears the file floor, so this must not be merged either.
     */
    public function test_an_all_par2_posting_is_refused_even_above_the_floor(): void
    {
        $this->seedStrandedFile('{Lioness.S03.vol000+01.par2} yEnc', 3);
        $this->seedStrandedFile('{Lioness.S03.vol001+02.par2} yEnc', 3);
        $this->seedStrandedFile('{Lioness.S03.vol003+04.par2} yEnc', 3);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(1, $summary['cohorts_skipped']);
        $this->assertSame('par2_only', $summary['skipped'][0]['reason']);
        $this->assertSame(3, \count($summary['skipped'][0]['files']));

        $this->assertSame(9, DB::table('collections')->count());
        $this->assertSame(9, DB::table('parts')->count());
    }

    /** A dry-run reports the same refusals, so it cannot read as a clean pass. */
    public function test_a_dry_run_reports_the_refusals_it_would_make(): void
    {
        $this->seedStrandedFile('{Lioness.S03.vol000+01.par2} yEnc', 3);
        $this->seedStrandedFile('{Lioness.S03.vol001+02.par2} yEnc', 3);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, false);

        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(1, $summary['cohorts_skipped']);
        $this->assertSame('par2_only', $summary['skipped'][0]['reason']);
    }

    /** A group override wins over the site setting, as in the release pipeline. */
    public function test_a_group_override_relaxes_the_floor(): void
    {
        DB::table('usenet_groups')->where('id', self::GROUP_ID)->update(['minfilestoformrelease' => 1]);
        $this->seedStrandedFile('{Lioness.2023.S03E01.2160p.H.265-FLUX.rar} yEnc', 4);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['min_files']);
        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['cohorts_skipped']);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(4, DB::table('parts')->count());
    }

    public function test_it_adopts_a_collection_ingest_already_created_under_the_repaired_key(): void
    {
        $posting = 'Soulm8te.2026';
        $part01 = '{Soulm8te.2026.part01.rar} yEnc';
        $part02 = '{Soulm8te.2026.part02.rar} yEnc';

        // The stranded rows for one file, plus a row the fixed ingest path minted
        // later under the correct posting key with a HIGHER id -- already holding
        // a binary for the OTHER file at filenumber 1.
        $this->seedStrandedFile($part01, 4);
        $ingestId = $this->seedCollection(
            '{'.$posting.'}',
            sha1(ObfuscatedSubjectNormalizer::postingKey($posting, self::GROUP_ID)),
            '2026-08-03 10:00:00',
            '2026-08-03 10:00:00',
        );
        $this->seedBinary($ingestId, $part02, 1, 2, [99, 100]);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(1, DB::table('collections')->count());
        // The pre-existing owner of the hash survives, not the lowest id.
        $this->assertSame($ingestId, (int) DB::table('collections')->value('id'));
        $this->assertSame(6, DB::table('parts')->count());

        // The adopted binary keeps its parts and is renumbered into the dense
        // sequence. Assigning ordinals directly would have collided with the
        // filenumber 1 it already held, which is why survivors are parked first.
        $binaries = DB::table('binaries')->orderBy('filenumber')->get();
        $this->assertSame([$part01, $part02], $binaries->pluck('name')->all());
        $this->assertSame([1, 2], $binaries->pluck('filenumber')->map(static fn ($v): int => (int) $v)->all());
        $this->assertSame([4, 2], $binaries->pluck('currentparts')->map(static fn ($v): int => (int) $v)->all());
    }

    public function test_the_cohort_limit_bounds_cohorts_and_reports_the_truncation(): void
    {
        foreach (['Soulm8te.2026', 'Maddie\'s.Secret.2026', 'Supergirl.2026'] as $posting) {
            $this->seedStrandedFile('{'.$posting.'.part01.rar} yEnc', 3);
            $this->seedStrandedFile('{'.$posting.'.part02.rar} yEnc', 3);
        }

        $summary = $this->service()->repair(self::GROUP_ID, 2, null, true);

        $this->assertTrue($summary['cohort_limit_reached']);
        $this->assertSame(2, $summary['cohorts_found']);
        $this->assertSame(2, $summary['cohorts_merged']);
        // The two merged postings collapse to 1 collection each; the third is
        // left whole, not half-merged.
        $this->assertSame(2 + 6, DB::table('collections')->count());
        $this->assertSame(18, DB::table('parts')->count());
    }

    /**
     * A staged production drain has to name the posting it is draining. --limit
     * admits cohorts in collection-id order, which is arbitrary with respect to
     * which posting has been validated as safe to merge next.
     */
    public function test_a_named_posting_admits_only_that_cohort(): void
    {
        foreach (['Soulm8te.2026', 'Maddie\'s.Secret.2026', 'Supergirl.2026'] as $posting) {
            $this->seedStrandedFile('{'.$posting.'.part01.rar} yEnc', 3);
            $this->seedStrandedFile('{'.$posting.'.part02.rar} yEnc', 3);
        }

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true, null, 'Maddie\'s.Secret.2026');

        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame('Maddie\'s.Secret.2026', $summary['cohorts'][0]['posting']);
        // Every collection is still scanned -- the filter selects cohorts, it
        // does not narrow the SQL -- but only the named one is touched.
        $this->assertSame(18, $summary['collections_scanned']);
        $this->assertSame(6, $summary['collections_in_cohorts']);
        $this->assertSame(1 + 12, DB::table('collections')->count());
        $this->assertSame(
            "{Maddie's.Secret.2026}",
            (string) DB::table('collections')->where('totalfiles', 2)->value('subject')
        );
        $this->assertSame(18, DB::table('parts')->count());
    }

    public function test_a_named_posting_that_matches_nothing_is_a_clean_no_op(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 3);
        $this->seedStrandedFile('{Soulm8te.2026.part02.rar} yEnc', 3);

        // Near-miss on purpose: the stem must match exactly, so a typo cannot
        // silently fall back to "everything".
        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true, null, 'Soulm8te.2026.part01');

        $this->assertSame(0, $summary['cohorts_found']);
        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(6, DB::table('collections')->count());
        $this->assertSame(6, DB::table('parts')->count());
    }

    public function test_it_ignores_collections_that_are_not_brace_token(): void
    {
        $this->seedCollection(
            'Some.Normal.Release.2024 - [01/20] - "file.rar" yEnc',
            'plain-hash',
            '2026-08-02 05:00:00',
            '2026-08-02 05:00:00',
        );
        // Braced metadata that is not a random token must be left alone too.
        $this->seedCollection(
            '{Movie.Name.2024.rar} {Some.Group.Name} yEnc',
            'metadata-hash',
            '2026-08-02 05:00:00',
            '2026-08-02 05:00:00',
        );

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(0, $summary['cohorts_found']);
        $this->assertSame(2, DB::table('collections')->count());
    }

    public function test_before_excludes_newer_collections(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 2, [
            'dateadded' => ['2026-08-01 00:00:00', '2026-08-03 00:00:00'],
        ]);

        $summary = $this->service()->repair(self::GROUP_ID, 50, '2026-08-02 00:00:00', false);

        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(1, $summary['collections_in_cohorts']);
    }

    public function test_it_refuses_to_apply_to_a_group_ingest_does_not_normalize(): void
    {
        config(['nntmux.obfuscated_brace_token_groups' => []]);
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 2);

        $summary = (new BraceTokenIdentityRepairService)->repair(self::GROUP_ID, 50, null, false);
        $this->assertFalse($summary['group_normalization_enabled']);
        $this->assertSame(1, $summary['cohorts_found']);

        $this->expectException(InvalidArgumentException::class);
        (new BraceTokenIdentityRepairService)->repair(self::GROUP_ID, 50, null, true);
    }

    /**
     * The shape that broke the first production run.
     *
     * Production carries `filenumber = partnumber` on these rows, so a cohort's
     * members hold filenumbers spread across the whole part range -- 1..512 for
     * a 512-part file. The final ordinals (1..N over the files) are therefore a
     * subset of numbers the cohort already holds, so survivors must be parked
     * before they are renumbered. The park value has to be a POSITIVE number
     * outside that range: `binaries.filenumber` is `int(10) unsigned`, so a
     * negative park is clamped to 0 by MariaDB and the second park collides on
     * '<survivor>-0'. The CHECK on the fixture's filenumber column makes that
     * failure reachable here instead of only in production.
     */
    public function test_survivors_are_parked_above_the_filenumbers_the_cohort_already_holds(): void
    {
        // Two files, six parts each, filenumber == partnumber: every ordinal the
        // merge wants to write (1 and 2) is already taken by some member.
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 6);
        $this->seedStrandedFile('{Soulm8te.2026.part02.rar} yEnc', 6);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['cohorts_skipped']);
        $this->assertSame(2, $summary['binaries_retained']);

        $binaries = DB::table('binaries')->orderBy('filenumber')->get();
        $this->assertSame([1, 2], $binaries->pluck('filenumber')->map(static fn ($v): int => (int) $v)->all());
        $this->assertSame([6, 6], $binaries->pluck('currentparts')->map(static fn ($v): int => (int) $v)->all());
        // No article is lost on the way through the scratch band.
        $this->assertSame(12, DB::table('parts')->count());
    }

    public function test_unknown_group_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->repair('alt.binaries.nonexistent', 50, null, false);
    }

    public function test_limit_below_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->repair(self::GROUP_ID, 0, null, false);
    }

    public function test_min_files_below_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->repair(self::GROUP_ID, 50, null, false, 0);
    }

    private function service(): BraceTokenIdentityRepairService
    {
        return new BraceTokenIdentityRepairService(
            new ObfuscatedSubjectNormalizer([self::GROUP_NAME])
        );
    }

    /**
     * Reproduce the pre-fix shape: one collection per ARTICLE, each holding one
     * binary with one part, keyed on a subject that still carries its token.
     *
     * @param  array{dates?: list<string>, dateadded?: list<string>, partnumbers?: list<int>}  $overrides
     */
    private function seedStrandedFile(string $normalizedName, int $parts, array $overrides = []): void
    {
        for ($i = 0; $i < $parts; $i++) {
            $partNumber = $overrides['partnumbers'][$i] ?? ($i + 1);
            $token = substr(str_pad((string) ($i + 1), 12, 'aBcDeFgHiJkL'), 0, 12);
            $subject = str_replace('} yEnc', '} {'.$token.'} yEnc', $normalizedName);

            $collectionId = $this->seedCollection(
                $subject,
                sha1($subject.$parts),
                $overrides['dates'][$i] ?? '2026-08-02 05:00:00',
                $overrides['dateadded'][$i] ?? '2026-08-02 05:00:00',
            );

            $this->seedBinary($collectionId, $subject, $partNumber, $parts, [$partNumber]);
        }
    }

    private function seedCollection(string $subject, string $hash, string $date, string $dateadded): int
    {
        return (int) DB::table('collections')->insertGetId([
            'subject' => $subject,
            'fromname' => 'Ultraman <bowman@test.com>',
            'date' => $date,
            'groups_id' => self::GROUP_ID,
            'totalfiles' => 1,
            'collectionhash' => $hash,
            'dateadded' => $dateadded,
            'filecheck' => 0,
        ]);
    }

    /** @param  list<int>  $partNumbers */
    private function seedBinary(int $collectionId, string $name, int $fileNumber, int $totalParts, array $partNumbers): int
    {
        $binaryId = $this->nextBinaryId++;
        DB::table('binaries')->insert([
            'id' => $binaryId,
            'binaryhash' => md5($name.$collectionId),
            'name' => $name,
            'collections_id' => $collectionId,
            'filenumber' => $fileNumber,
            'totalparts' => $totalParts,
            'currentparts' => \count($partNumbers),
            'partcheck' => 0,
            'partsize' => 740162 * \count($partNumbers),
        ]);

        foreach ($partNumbers as $partNumber) {
            DB::table('parts')->insert([
                'binaries_id' => $binaryId,
                'messageid' => 'article'.$this->nextArticle.'@ngPost',
                'number' => $this->nextArticle++,
                'partnumber' => $partNumber,
                'size' => 740162,
            ]);
        }

        return $binaryId;
    }
}
