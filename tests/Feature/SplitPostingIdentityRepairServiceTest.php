<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Diagnostics\SplitPostingIdentityRepairService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The merge itself. Whether the merged shape then SURVIVES the release pipeline
 * is asserted separately in SplitPostingRepairSurvivesReleaseGatesTest, because
 * a sibling service passed every merge test and still had its output deleted
 * downstream, cascading 512 production collections away. Neither file suffices
 * alone.
 *
 * The fixture is transcribed from the live `alt.binaries.cinemageddon` cohort
 * (collections 477644-477653), not invented: the subject quote layout, the
 * multi-binary fragment with scattered filenumbers, and the sidecar files are
 * all shapes production actually holds.
 */
final class SplitPostingIdentityRepairServiceTest extends TestCase
{
    private const GROUP_ID = 5079;

    private const GROUP_NAME = 'alt.binaries.cinemageddon';

    private const POSTER = 'Helljahve <helljahve@example.com>';

    private const STEM = 'The Paper Boy (1994) Dvdrip by Helljahve.avi';

    private int $nextBinaryId = 1;

    private int $nextArticle = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
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
            -- accepts a negative filenumber that MariaDB silently clamps to 0,
            -- which is how a sibling repair failed in production while its
            -- tests stayed green.
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
        DB::table('settings')->insert(['name' => 'minfilestoformrelease', 'value' => '2']);
    }

    public function test_dry_run_reports_the_union_without_touching_anything(): void
    {
        $this->seedPaperBoy();

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, false);

        $this->assertFalse($summary['updated']);
        // 3 cohorts: the .avi posting, plus the .jpg and .nzb sidecars, which
        // are each one file and therefore refused.
        $this->assertSame(3, $summary['cohorts_found']);
        $this->assertSame(2, $summary['cohorts_skipped']);

        $avi = $this->cohort($summary, self::STEM);
        // The payload and its par2 volumes are ONE posting spread over 4
        // collections; the biggest fragment holds several files by itself.
        $this->assertSame(4, $avi['collection_count']);
        $this->assertSame(6, $avi['file_count']);
        $this->assertNull($avi['refusal']);

        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['collections_removed']);
        $this->assertSame(6, (int) DB::table('collections')->count());
        $this->assertSame(8, (int) DB::table('binaries')->count());
    }

    public function test_update_collapses_the_posting_onto_one_collection(): void
    {
        $this->seedPaperBoy();
        $partsBefore = (int) DB::table('parts')->count();

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertTrue($summary['updated']);
        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(3, $summary['collections_removed']);
        $this->assertSame(6, $summary['binaries_retained']);

        // 3 absorbed of the .avi cohort are gone; the survivor plus the two
        // refused sidecars remain.
        $this->assertSame(3, (int) DB::table('collections')->count());
        // No article is lost: the merge rehomes parts, never deletes them.
        $this->assertSame($partsBefore, (int) DB::table('parts')->count());

        $survivor = DB::table('collections')
            ->where('collectionhash', 'not like', 'seed-%')
            ->first();
        $this->assertNotNull($survivor);
        $this->assertSame(6, (int) $survivor->totalfiles);
        $this->assertSame(0, (int) $survivor->filecheck);
        // Oldest member's timestamp, so the delaytime gate is already cleared.
        $this->assertSame('2026-07-31 04:00:00', (string) $survivor->dateadded);

        $binaries = DB::table('binaries')
            ->where('collections_id', $survivor->id)
            ->orderBy('filenumber')
            ->get();

        // Dense 1..N: two gates read MAX(filenumber) as a file count.
        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            $binaries->map(static fn ($b): int => (int) $b->filenumber)->all()
        );

        // Payload files ahead of the par2 set, each in natural order, and the
        // name is now the real filename rather than the full subject.
        $this->assertSame(
            [
                'The Paper Boy (1994) Dvdrip by Helljahve.avi.001',
                'The Paper Boy (1994) Dvdrip by Helljahve.avi.002',
                'The Paper Boy (1994) Dvdrip by Helljahve.avi.017',
                'The Paper Boy (1994) Dvdrip by Helljahve.avi.vol000+045.par2',
                'The Paper Boy (1994) Dvdrip by Helljahve.avi.vol045+090.par2',
                'The Paper Boy (1994) Dvdrip by Helljahve.avi.vol135+174.par2',
            ],
            $binaries->map(static fn ($b): string => (string) $b->name)->all()
        );

        foreach ($binaries as $binary) {
            $this->assertSame(
                (int) DB::table('parts')->where('binaries_id', $binary->id)->count(),
                (int) $binary->currentparts
            );
            // Let stage 3 re-evaluate now that each file's parts sit together.
            $this->assertSame(0, (int) $binary->partcheck);
        }
    }

    public function test_the_sidecar_files_of_the_posting_are_left_stranded(): void
    {
        $this->seedPaperBoy();

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $reasons = array_column($summary['skipped'], 'reason', 'name');
        // A .jpg or .nzb alone is one file: merging it produces exactly the
        // shape deleteCollectionsUnderThreshold() cascades.
        $this->assertSame('below_min_files', $reasons['The Paper Boy (1994) Dvdrip by Helljahve.avi.jpg'] ?? null);
        $this->assertSame('below_min_files', $reasons['The Paper Boy (1994).nzb'] ?? null);

        // Stranded, not cascaded: recoverable until retention. Still carrying
        // their seeded hash, so the merge did not adopt them either.
        $this->assertSame(
            ['seed-paperboy-jpg', 'seed-paperboy-nzb'],
            $this->sorted(
                DB::table('collections')
                    ->whereIn('collectionhash', ['seed-paperboy-jpg', 'seed-paperboy-nzb'])
                    ->pluck('collectionhash')
                    ->map(static fn ($v): string => (string) $v)
                    ->all()
            )
        );
        $this->assertSame(5, (int) DB::table('parts')->whereIn(
            'binaries_id',
            DB::table('binaries')->where('name', 'like', '%.jpg"%')->orWhere('name', 'like', '%.nzb"%')->pluck('id')
        )->count());
    }

    public function test_a_second_update_is_a_clean_no_op(): void
    {
        $this->seedPaperBoy();
        $this->service()->repair(self::GROUP_ID, 50, null, true);

        $collections = (int) DB::table('collections')->count();
        $binaries = (int) DB::table('binaries')->count();
        $parts = (int) DB::table('parts')->count();

        $second = $this->service()->repair(self::GROUP_ID, 50, null, true);

        // Re-running must converge: the survivor already owns the target hash,
        // so it is adopted rather than merged into itself again.
        $this->assertSame(0, $second['collections_removed']);
        $this->assertSame(0, $second['binaries_removed']);
        $this->assertSame($collections, (int) DB::table('collections')->count());
        $this->assertSame($binaries, (int) DB::table('binaries')->count());
        $this->assertSame($parts, (int) DB::table('parts')->count());
    }

    public function test_an_already_single_collection_posting_still_gets_dense_filenumbers(): void
    {
        // The live fragment 477646 shape: one collection, several files, and
        // filenumbers scattered far above the file count, so stage 1 reads
        // `3 >= CEIL(242 * 0.94)` and never passes. Nothing to merge -- the fix
        // is the renumber and the totalfiles rewrite.
        $collection = $this->seedCollection('one-frag', 242, '2026-07-31 04:00:00');
        $this->seedBinary($collection, self::STEM.'.001', 242, 4, 38);
        $this->seedBinary($collection, self::STEM.'.002', 242, 3, 172);
        $this->seedBinary($collection, self::STEM.'.vol000+045.par2', 242, 2, 120);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['collections_removed']);
        $this->assertSame(0, $summary['binaries_removed']);

        $this->assertSame(3, (int) DB::table('collections')->find($collection)->totalfiles);
        $this->assertSame(
            [1, 2, 3],
            DB::table('binaries')->orderBy('filenumber')->pluck('filenumber')
                ->map(static fn ($v): int => (int) $v)->all()
        );
        $this->assertSame(9, (int) DB::table('parts')->count());
    }

    public function test_a_par2_only_posting_is_refused_not_merged(): void
    {
        // Clears min_files but createReleases()' $par2Only filter deletes it.
        $a = $this->seedCollection('par2-a', 12, '2026-07-31 04:00:00');
        $this->seedBinary($a, 'Orphan.Set.2026.mkv.vol000+045.par2', 12, 12);
        $b = $this->seedCollection('par2-b', 14, '2026-07-31 04:05:00');
        $this->seedBinary($b, 'Orphan.Set.2026.mkv.vol045+090.par2', 14, 14);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(1, $summary['cohorts_skipped']);
        $this->assertSame('par2_only', $summary['skipped'][0]['reason']);
        $this->assertSame(2, (int) DB::table('collections')->count());
        $this->assertSame(26, (int) DB::table('parts')->count());
    }

    public function test_a_cohort_declaring_a_real_file_count_is_refused_not_merged(): void
    {
        // The live `Nativ` posting in alt.binaries.cinemageddon, which a
        // production dry-run named as the first apply target before this guard
        // existed. Its subject carries a REAL file counter, so totalfiles=93 is
        // correct and the 4 binaries present mean 89 files were never
        // downloaded. Merging would rewrite totalfiles to 4 and publish a
        // 4-of-93 archive as complete -- unextractable, and unlike a stall it
        // cannot be undone by waiting. It clears min_files and is not par2-only,
        // so nothing ELSE would have refused it.
        $this->seedNativ();

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(1, $summary['cohorts_skipped']);
        $this->assertSame('declares_a_real_file_count', $summary['skipped'][0]['reason']);
        // The operator sees the gap that made it a refusal, not just a label.
        $this->assertSame(
            ['declared_files' => 93, 'files_present' => 4],
            $summary['skipped'][0]['values']
        );

        // Untouched: every collection keeps its seeded hash, its part count and
        // its declared totalfiles.
        $this->assertSame(0, $summary['collections_removed']);
        $this->assertSame(0, $summary['binaries_removed']);
        $this->assertSame(3, (int) DB::table('collections')->count());
        $this->assertSame(4, (int) DB::table('binaries')->count());
        $this->assertSame(10, (int) DB::table('parts')->count());
        $this->assertSame(
            [93, 93, 93],
            DB::table('collections')->orderBy('id')->pluck('totalfiles')
                ->map(static fn ($v): int => (int) $v)->all()
        );
        $this->assertSame(
            ['seed-nativ-a', 'seed-nativ-b', 'seed-nativ-c'],
            $this->sorted(
                DB::table('collections')->pluck('collectionhash')
                    ->map(static fn ($v): string => (string) $v)->all()
            )
        );
    }

    public function test_one_member_carrying_a_file_counter_disqualifies_the_whole_cohort(): void
    {
        // The counter is a property of the POSTING, not of the row: ingest keys
        // members separately, so a cohort can hold both shapes. Refusing only
        // the annotated members would merge the rest and still write a file
        // count for a posting whose real count is known and larger.
        $counted = $this->seedCollection(
            'mixed-counted',
            93,
            '2026-07-31 04:00:00',
            null,
            self::POSTER,
            '(Nativ) [58/93] - "Nativ.part57.rar" yEnc'
        );
        $this->seedBinary($counted, 'Nativ.part57.rar', 93, 2, 57, '(Nativ) [58/93] - "Nativ.part57.rar" yEnc');

        $bare = $this->seedCollection(
            'mixed-bare',
            226,
            '2026-07-31 04:05:00',
            null,
            self::POSTER,
            '(Nativ) - "Nativ.part58.rar" yEnc'
        );
        $this->seedBinary($bare, 'Nativ.part58.rar', 226, 2, 1, '(Nativ) - "Nativ.part58.rar" yEnc');

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame('declares_a_real_file_count', $summary['skipped'][0]['reason']);
        $this->assertSame(2, (int) DB::table('collections')->count());
    }

    /**
     * The counter's delimiter is not a constant, and a bracket-only pattern
     * passed 333 live collections straight through to a merge. Each case here is
     * transcribed from a subject production actually holds.
     *
     * @return iterable<string, array{0: string, 1: int, 2: int}>
     */
    public static function undelimitedFileCounters(): iterable
    {
        // Found while picking the first apply target AFTER the bracket fix
        // shipped: 62 files declared, 58 present.
        yield 'parenthesised, mid-subject' => [
            'The Borrowers (1997) ==(37/62) - yEnc "theBorrowers-faye-xvid.part32.rar"',
            62,
            2,
        ];
        // No space before the paren, so a `\s\(` anchor would miss it too.
        yield 'parenthesised, glued to a tag' => [
            'AMEq2(06/19) "Queen Greatest Video Hits 2a DTS 5.1 DVD9 by AME.part004.rar" - yEnc',
            19,
            2,
        ];
        // Ingest ate the opening bracket, leaving a counter with one delimiter.
        yield 'half-eaten delimiter' => [
            'star trek the next generation s3 d425/96] - "star trek the next generation s3 d4.part24.rar" yEnc',
            137,
            2,
        ];
        // Spaces inside the counter, as the TrollHD poster writes them.
        yield 'spaced inside the brackets' => [
            '[ 02813 ] - [ TrollHD ] - [ 19/34 ] - "Travelscope - Madhya Pradesh.part18.rar" yEnc',
            34,
            2,
        ];
    }

    #[DataProvider('undelimitedFileCounters')]
    public function test_a_file_counter_is_honoured_whatever_delimits_it(
        string $subject,
        int $declared,
        int $files
    ): void {
        $a = $this->seedCollection('delim-a', $declared, '2026-07-31 04:00:00', null, self::POSTER, $subject);
        $this->seedBinary($a, 'Delimited.Test.part01.rar', $declared, 3, 1, $subject.' " yEnc "Delimited.Test.part01.rar" yEnc');
        $b = $this->seedCollection('delim-b', $declared, '2026-07-31 04:05:00', null, self::POSTER, $subject);
        $this->seedBinary($b, 'Delimited.Test.part02.rar', $declared, 3, 1, $subject.' " yEnc "Delimited.Test.part02.rar" yEnc');

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame('declares_a_real_file_count', $summary['skipped'][0]['reason']);
        $this->assertSame(
            ['declared_files' => $declared, 'files_present' => $files],
            $summary['skipped'][0]['values']
        );
        $this->assertSame(2, (int) DB::table('collections')->count());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function subjectsWithoutAFileCounter(): iterable
    {
        // The real bug class: totalfiles holds a PART count and the subject
        // carries no file counter at all. Measured live, none of the 5,413
        // collections in this class contains an `n/m` substring anywhere -- which
        // is what makes the loose pattern free.
        yield 'the live cinemageddon layout' => [
            'The Paper Boy (1994) Dvdrip by Helljahve " yEnc "The Paper Boy (1994) Dvdrip by Helljahve.avi" yEnc',
        ];
        // A ratio-looking token that is NOT a counter must still merge, or the
        // loose pattern would quietly strand rows it was never meant to touch.
        yield 'a resolution in the title' => [
            'Some.Doc.2026 - "Some.Doc.2026.mkv.001" - 1440x1080 - yEnc',
        ];
        yield 'an audio channel tag' => [
            'Another.Film.2026 DTS 5.1 - "Another.Film.2026.mkv.001" yEnc',
        ];
    }

    #[DataProvider('subjectsWithoutAFileCounter')]
    public function test_a_posting_with_no_file_counter_is_still_merged(string $subject): void
    {
        $a = $this->seedCollection('nc-a', 242, '2026-07-31 04:00:00', null, self::POSTER, $subject);
        $this->seedBinary($a, 'Merge.Me.mkv.001', 242, 3, 1, $subject.' " yEnc "Merge.Me.mkv.001" yEnc');
        $b = $this->seedCollection('nc-b', 63, '2026-07-31 04:05:00', null, self::POSTER, $subject);
        $this->seedBinary($b, 'Merge.Me.mkv.002', 63, 3, 1, $subject.' " yEnc "Merge.Me.mkv.002" yEnc');

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['cohorts_skipped']);
        $this->assertSame(1, (int) DB::table('collections')->count());
        $this->assertSame(2, (int) DB::table('collections')->first()->totalfiles);
    }

    public function test_the_file_counter_refusal_reads_the_same_in_a_dry_run(): void
    {
        // A dry-run that merged on paper and refused on apply -- or the reverse
        // -- would make the operator's go/no-go read on the wrong plan. This
        // refusal is the one where that read matters most.
        $this->seedNativ();

        $dry = $this->service()->repair(self::GROUP_ID, 50, null, false);
        $applied = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame($dry['skipped'], $applied['skipped']);
        $this->assertSame(
            'declares_a_real_file_count',
            $this->cohort($dry, 'Nativ')['refusal']
        );
    }

    public function test_a_posting_without_a_file_counter_still_merges_beside_a_refused_one(): void
    {
        // The guard has to be narrow: the bug class it protects is the one where
        // totalfiles holds a PART count and no file counter exists anywhere in
        // the subject. Both shapes live in the same group, and refusing both
        // would strand the residue this service exists to reclaim.
        $this->seedNativ();
        $this->seedPaperBoy();

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $reasons = array_column($summary['skipped'], 'reason', 'name');
        $this->assertSame('declares_a_real_file_count', $reasons['Nativ'] ?? null);
        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(3, $summary['collections_removed']);

        // Paper Boy's 4 fragments collapsed to 1; Nativ's 3 and the two refused
        // sidecars are all still there.
        $survivor = DB::table('collections')
            ->where('collectionhash', 'not like', 'seed-%')
            ->first();
        $this->assertNotNull($survivor);
        $this->assertSame(6, (int) $survivor->totalfiles);
        $this->assertSame(3, (int) DB::table('collections')->where('collectionhash', 'like', 'seed-nativ-%')->count());
        $this->assertSame(6, (int) DB::table('collections')->count());
    }

    public function test_dry_run_reports_the_same_refusals_as_apply(): void
    {
        $c = $this->seedCollection('lonely', 40, '2026-07-31 04:00:00');
        $this->seedBinary($c, 'Lonely.Movie.2026.mkv', 40, 40);

        $dry = $this->service()->repair(self::GROUP_ID, 50, null, false);
        $applied = $this->service()->repair(self::GROUP_ID, 50, null, true);

        // A dry-run reporting a clean pass here would be actively misleading.
        $this->assertSame(1, $dry['cohorts_skipped']);
        $this->assertSame('below_min_files', $dry['skipped'][0]['reason']);
        $this->assertSame($dry['skipped'], $applied['skipped']);
    }

    public function test_two_postings_that_the_subject_cleaner_would_fuse_stay_apart(): void
    {
        // CollectionsCleaningService's generic path strips digit runs, reducing
        // both of these to 'Movie. yEnc Movie. yEnc'. Keying the union on the
        // cleaned name would merge two unrelated postings into one release --
        // the exact fusion the brace-token investigation traced. The filename
        // stem cannot do that.
        $a = $this->seedCollection('m2024-a', 9, '2026-07-31 04:00:00', 'Movie.2024');
        $this->seedBinary($a, 'Movie.2024.mkv.001', 9, 9);
        $b = $this->seedCollection('m2024-b', 7, '2026-07-31 04:01:00', 'Movie.2024');
        $this->seedBinary($b, 'Movie.2024.mkv.vol000+045.par2', 7, 7);
        $c = $this->seedCollection('m2025-a', 8, '2026-07-31 04:02:00', 'Movie.2025');
        $this->seedBinary($c, 'Movie.2025.mkv.001', 8, 8);
        $d = $this->seedCollection('m2025-b', 6, '2026-07-31 04:03:00', 'Movie.2025');
        $this->seedBinary($d, 'Movie.2025.mkv.vol000+045.par2', 6, 6);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(2, $summary['cohorts_found']);
        $this->assertSame(2, $summary['cohorts_merged']);
        // Two survivors, not one.
        $this->assertSame(2, (int) DB::table('collections')->count());
        $this->assertSame(
            ['Movie.2024.mkv', 'Movie.2025.mkv'],
            $summary['cohorts'] === [] ? [] : $this->sorted(array_column($summary['cohorts'], 'posting'))
        );
    }

    public function test_the_same_filename_from_two_posters_is_not_one_posting(): void
    {
        // Two people posting an identically named file are two postings. Keying
        // on the filename alone would union them and produce a release whose
        // segments come from both.
        $a = $this->seedCollection('poster-a-1', 9, '2026-07-31 04:00:00', 'Shared.Film', 'Alice <a@example.com>');
        $this->seedBinary($a, 'Shared.Film.mkv.001', 9, 9);
        $b = $this->seedCollection('poster-a-2', 7, '2026-07-31 04:01:00', 'Shared.Film', 'Alice <a@example.com>');
        $this->seedBinary($b, 'Shared.Film.mkv.002', 7, 7);
        $c = $this->seedCollection('poster-b-1', 8, '2026-07-31 04:02:00', 'Shared.Film', 'Bob <b@example.com>');
        $this->seedBinary($c, 'Shared.Film.mkv.001', 8, 8);
        $d = $this->seedCollection('poster-b-2', 6, '2026-07-31 04:03:00', 'Shared.Film', 'Bob <b@example.com>');
        $this->seedBinary($d, 'Shared.Film.mkv.002', 6, 6);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(2, $summary['cohorts_found']);
        $this->assertSame(2, $summary['cohorts_merged']);
        $this->assertSame(2, (int) DB::table('collections')->count());
        // Each survivor holds only its own poster's two files.
        foreach (DB::table('collections')->get() as $survivor) {
            $this->assertSame(2, (int) DB::table('binaries')->where('collections_id', $survivor->id)->count());
        }
    }

    public function test_a_named_posting_admits_only_that_cohort(): void
    {
        $this->seedPaperBoy();
        $other = $this->seedCollection('other-1', 10, '2026-07-31 05:00:00', 'Other.Film.2026');
        $this->seedBinary($other, 'Other.Film.2026.mkv.001', 10, 10);
        $other2 = $this->seedCollection('other-2', 11, '2026-07-31 05:01:00', 'Other.Film.2026');
        $this->seedBinary($other2, 'Other.Film.2026.mkv.002', 11, 11);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true, null, self::STEM);

        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(1, $summary['cohorts_merged']);
        // The filter selects cohorts; it does not narrow the SQL scan.
        $this->assertSame(8, $summary['collections_scanned']);
        // Paper Boy's 4 collapsed to 1; sidecars and Other.Film untouched.
        $this->assertSame(5, (int) DB::table('collections')->count());
        $this->assertSame(2, (int) DB::table('binaries')->where('name', 'like', '%"Other.Film%')->count());
    }

    public function test_a_named_posting_that_matches_nothing_is_a_clean_no_op(): void
    {
        $this->seedPaperBoy();

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true, null, 'The Paper Boy (1994)');

        $this->assertSame(0, $summary['cohorts_found']);
        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(6, (int) DB::table('collections')->count());
    }

    public function test_before_excludes_collections_still_receiving_articles(): void
    {
        $this->seedPaperBoy();

        $summary = $this->service()->repair(self::GROUP_ID, 50, '2026-07-31 04:12:00', false);

        // Only the members added before the bound are considered, so the cohort
        // reported is a partial one -- which is why --before must name a posting
        // whose articles have stopped arriving.
        $avi = $this->cohort($summary, self::STEM);
        $this->assertSame(2, $avi['collection_count']);
        $this->assertSame(4, $avi['file_count']);
    }

    public function test_survivors_are_parked_above_the_filenumbers_the_cohort_holds(): void
    {
        // Every absorbed fragment carries filenumber 1 and the survivor carries
        // 1..3, so the final dense ordinals are numbers members already hold. A
        // direct write collides under UNIQUE (collections_id, filenumber), and a
        // negative park clamps to 0 on MariaDB's unsigned column and collides
        // too.
        $survivor = $this->seedCollection('park-survivor', 3, '2026-07-31 04:00:00', 'Park.Test');
        $this->seedBinary($survivor, 'Park.Test.mkv.001', 3, 3, 1);
        $this->seedBinary($survivor, 'Park.Test.mkv.002', 3, 2, 2);
        $this->seedBinary($survivor, 'Park.Test.mkv.003', 3, 1, 3);
        $absorbed = $this->seedCollection('park-absorbed', 4, '2026-07-31 04:05:00', 'Park.Test');
        $this->seedBinary($absorbed, 'Park.Test.mkv.004', 4, 4, 1);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(
            [1, 2, 3, 4],
            DB::table('binaries')->orderBy('filenumber')->pluck('filenumber')
                ->map(static fn ($v): int => (int) $v)->all()
        );
        $this->assertSame(10, (int) DB::table('parts')->count());
    }

    public function test_two_binaries_of_one_file_are_folded_and_their_parts_rehomed(): void
    {
        // A file split across two collections: both hold a binary for it, and
        // the parts must end up under one binary without losing any.
        $a = $this->seedCollection('fold-a', 9, '2026-07-31 04:00:00', 'Fold.Test');
        $first = $this->seedBinary($a, 'Fold.Test.mkv.001', 9, 4);
        $this->seedBinary($a, 'Fold.Test.mkv.002', 9, 5, 2);
        $b = $this->seedCollection('fold-b', 9, '2026-07-31 04:05:00', 'Fold.Test');
        $this->seedBinary($b, 'Fold.Test.mkv.001', 9, 5);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(1, $summary['binaries_removed']);
        $this->assertSame(5, $summary['parts_moved']);
        $this->assertSame(2, $summary['binaries_retained']);

        // 4 + 5 articles of file .001 now sit under the surviving binary.
        $this->assertSame(9, (int) DB::table('parts')->where('binaries_id', $first)->count());
        $this->assertSame(14, (int) DB::table('parts')->count());
        $this->assertSame(9, (int) DB::table('binaries')->find($first)->currentparts);
    }

    public function test_a_file_whose_binaries_share_an_article_number_is_refused(): void
    {
        // parts is PRIMARY KEY (binaries_id, number), so folding these would
        // abort the transaction. Refuse the cohort instead.
        $a = $this->seedCollection('clash-a', 9, '2026-07-31 04:00:00', 'Clash.Test');
        $first = $this->seedBinary($a, 'Clash.Test.mkv.001', 9, 3);
        $this->seedBinary($a, 'Clash.Test.mkv.002', 9, 3, 2);
        $b = $this->seedCollection('clash-b', 9, '2026-07-31 04:05:00', 'Clash.Test');
        $second = $this->seedBinary($b, 'Clash.Test.mkv.001', 9, 0);

        $shared = (int) DB::table('parts')->where('binaries_id', $first)->value('number');
        DB::table('parts')->insert([
            'binaries_id' => $second,
            'messageid' => 'dupe@news',
            'number' => $shared,
            'partnumber' => 1,
            'size' => 740162,
        ]);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(1, $summary['cohorts_skipped']);
        $this->assertSame('duplicate_article_number', $summary['skipped'][0]['reason']);
        $this->assertSame('Clash.Test.mkv.001', $summary['skipped'][0]['file']);
        $this->assertSame([$shared], $summary['skipped'][0]['values']);
        // Untouched, so a later run can still reclaim it.
        $this->assertSame(2, (int) DB::table('collections')->count());
        $this->assertSame(7, (int) DB::table('parts')->count());
    }

    public function test_pre_existing_duplicate_partnumbers_are_reported_not_refused(): void
    {
        // The live fragment holds a binary with 859 parts over 242 distinct
        // partnumbers -- damage from pre-v217 ingest. Refusing on it would
        // strand the residue permanently over something the merge neither
        // causes nor worsens.
        $a = $this->seedCollection('dup-a', 9, '2026-07-31 04:00:00', 'Dup.Test');
        $binary = $this->seedBinary($a, 'Dup.Test.mkv.001', 9, 3);
        $b = $this->seedCollection('dup-b', 9, '2026-07-31 04:05:00', 'Dup.Test');
        $this->seedBinary($b, 'Dup.Test.mkv.002', 9, 3);

        DB::table('parts')->insert([
            'binaries_id' => $binary,
            'messageid' => 'extra@news',
            'number' => $this->nextArticle++,
            // Same partnumber as an existing row under this binary.
            'partnumber' => 1,
            'size' => 740162,
        ]);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['binaries_with_duplicate_partnumbers']);
        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['cohorts_skipped']);
        $this->assertSame(7, (int) DB::table('parts')->count());
    }

    public function test_obfuscated_rows_are_left_to_their_own_repairs(): void
    {
        // Three services merging the same rows onto three different keys would
        // fight, so each excludes the others' styles.
        $brace = $this->seedCollection(
            'brace',
            1,
            '2026-08-02 05:00:00',
            null,
            self::POSTER,
            '{Soulm8te.2026.part01.rar} {aBcDeFgHiJkL} yEnc'
        );
        $this->seedBinary($brace, '{Soulm8te.2026.part01.rar} {aBcDeFgHiJkL} yEnc', 1, 1);

        $par2Set = $this->seedCollection(
            'par2set',
            1,
            '2026-08-02 05:00:00',
            null,
            self::POSTER,
            '[01/16] - "'.str_repeat('a', 40).'.par2" yEnc'
        );
        $this->seedBinary($par2Set, '[01/16] - "'.str_repeat('a', 40).'.par2" yEnc', 1, 1);

        $hashSet = $this->seedCollection(
            'hashset',
            1,
            '2026-08-02 05:00:00',
            null,
            self::POSTER,
            '4ziawpwr3jog2svzoiczyzt3fgzzwdmzj - "'.str_repeat('b', 34).'.mkv" yEnc'
        );
        $this->seedBinary($hashSet, '4ziawpwr3jog2svzoiczyzt3fgzzwdmzj - "'.str_repeat('b', 34).'.mkv" yEnc', 1, 1);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, false);

        $this->assertSame(0, $summary['collections_scanned']);
        $this->assertSame(0, $summary['cohorts_found']);
    }

    public function test_a_group_override_raises_the_refusal_floor(): void
    {
        // deleteCollectionsUnderThreshold() applies the override, so merging
        // against the site setting would hand it a collection to cascade.
        DB::table('usenet_groups')->where('id', self::GROUP_ID)->update(['minfilestoformrelease' => 8]);
        $this->seedPaperBoy();

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(8, $summary['min_files']);
        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(3, $summary['cohorts_skipped']);
        $this->assertSame(6, (int) DB::table('collections')->count());
    }

    public function test_limit_bounds_cohorts_and_reports_the_truncation(): void
    {
        $this->seedPaperBoy();
        $other = $this->seedCollection('other-1', 10, '2026-07-31 05:00:00', 'Other.Film.2026');
        $this->seedBinary($other, 'Other.Film.2026.mkv.001', 10, 10);

        $summary = $this->service()->repair(self::GROUP_ID, 1, null, false);

        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertTrue($summary['cohort_limit_reached']);
        // The admitted cohort is still whole: a row limit would slice a posting.
        $this->assertSame(4, $summary['cohorts'][0]['collection_count']);
        $this->assertSame(6, $summary['cohorts'][0]['file_count']);
    }

    public function test_limit_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->repair(self::GROUP_ID, 0, null, false);
    }

    public function test_min_files_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->repair(self::GROUP_ID, 50, null, false, 0);
    }

    public function test_unknown_group_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->repair('alt.binaries.nope', 50, null, false);
    }

    private function service(): SplitPostingIdentityRepairService
    {
        return new SplitPostingIdentityRepairService;
    }

    /** @param array<string,mixed> $summary */
    private function cohort(array $summary, string $posting): array
    {
        foreach ($summary['cohorts'] as $cohort) {
            if ($cohort['posting'] === $posting) {
                return $cohort;
            }
        }

        $this->fail('No cohort for posting '.$posting);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * The live cohort: one posting spread over 4 collections, one of which
     * already holds several files with scattered filenumbers, plus a .jpg and a
     * .nzb sidecar that each land alone.
     */
    private function seedPaperBoy(): void
    {
        // The fragment that holds several payload files at once, filenumbers
        // scattered over 1..172 as production carries them.
        $big = $this->seedCollection('paperboy-big', 242, '2026-07-31 04:00:00');
        $this->seedBinary($big, self::STEM.'.001', 242, 6, 38);
        $this->seedBinary($big, self::STEM.'.002', 242, 5, 172);
        $this->seedBinary($big, self::STEM.'.017', 242, 4, 1);

        // Single-file fragments, each keyed by its own part count.
        $vol1 = $this->seedCollection('paperboy-vol000', 63, '2026-07-31 04:10:00');
        $this->seedBinary($vol1, self::STEM.'.vol000+045.par2', 63, 3);
        $vol2 = $this->seedCollection('paperboy-vol045', 124, '2026-07-31 04:15:00');
        $this->seedBinary($vol2, self::STEM.'.vol045+090.par2', 124, 3);
        $vol3 = $this->seedCollection('paperboy-vol135', 238, '2026-07-31 04:20:00');
        $this->seedBinary($vol3, self::STEM.'.vol135+174.par2', 238, 3);

        // Sidecars: real files of the posting, but each its own stem.
        $jpg = $this->seedCollection('paperboy-jpg', 3, '2026-07-31 04:25:00');
        $this->seedBinary($jpg, self::STEM.'.jpg', 3, 3);
        $nzb = $this->seedCollection('paperboy-nzb', 2, '2026-07-31 04:30:00', 'The Paper Boy (1994) Dvdrip by Helljahve');
        $this->seedBinary($nzb, 'The Paper Boy (1994).nzb', 2, 2);
    }

    /**
     * The live `Nativ` posting: 3 collections, 4 real files, and a subject
     * carrying `[58/93]`. It clears minfilestoformrelease and holds no par2, so
     * only FILE_COUNTER_PATTERN stands between it and a 4-of-93 release.
     */
    private function seedNativ(): void
    {
        $subject = static fn (string $file): string => '(Nativ) [58/93] - "'.$file.'" yEnc';

        $a = $this->seedCollection('nativ-a', 93, '2026-07-31 04:00:00', null, self::POSTER, $subject('Nativ.part57.rar'));
        $this->seedBinary($a, 'Nativ.part57.rar', 93, 3, 57, $subject('Nativ.part57.rar'));
        $this->seedBinary($a, 'Nativ.part58.rar', 93, 3, 58, $subject('Nativ.part58.rar'));

        $b = $this->seedCollection('nativ-b', 93, '2026-07-31 04:05:00', null, self::POSTER, $subject('Nativ.part59.rar'));
        $this->seedBinary($b, 'Nativ.part59.rar', 93, 2, 59, $subject('Nativ.part59.rar'));

        $c = $this->seedCollection('nativ-c', 93, '2026-07-31 04:10:00', null, self::POSTER, $subject('Nativ.part60.rar'));
        $this->seedBinary($c, 'Nativ.part60.rar', 93, 2, 60, $subject('Nativ.part60.rar'));
    }

    private function seedCollection(
        string $hash,
        int $totalFiles,
        string $dateadded,
        ?string $description = null,
        string $fromName = self::POSTER,
        ?string $subject = null
    ): int {
        $description ??= 'The Paper Boy (1994) Dvdrip by Helljahve';

        return (int) DB::table('collections')->insertGetId([
            // The production quote layout: the FIRST quoted run is the literal
            // ' yEnc ', not the filename.
            'subject' => $subject ?? $description.' " yEnc "'.$description.'.avi" yEnc',
            'fromname' => $fromName,
            'date' => $dateadded,
            'groups_id' => self::GROUP_ID,
            // The defect in one value: a PART count sitting in totalfiles.
            'totalfiles' => $totalFiles,
            'collectionhash' => 'seed-'.$hash,
            'dateadded' => $dateadded,
            'filecheck' => 0,
        ]);
    }

    private function seedBinary(
        int $collectionId,
        string $file,
        int $declaredParts,
        int $parts,
        ?int $fileNumber = null,
        ?string $name = null
    ): int {
        $binaryId = $this->nextBinaryId++;

        DB::table('binaries')->insert([
            'id' => $binaryId,
            'binaryhash' => md5($file.$collectionId),
            // binaries.name holds the whole subject, quotes included -- which is
            // why the service extracts the filename rather than trusting it.
            'name' => $name ?? 'The Paper Boy (1994) Dvdrip by Helljahve " yEnc "'.$file.'" yEnc',
            'collections_id' => $collectionId,
            'filenumber' => $fileNumber ?? 1,
            'totalparts' => $declaredParts,
            'currentparts' => $parts,
            'partcheck' => 0,
            'partsize' => 740162 * $parts,
        ]);

        $rows = [];
        for ($i = 1; $i <= $parts; $i++) {
            $rows[] = [
                'binaries_id' => $binaryId,
                'messageid' => 'part'.$this->nextArticle.'@news',
                'number' => $this->nextArticle++,
                'partnumber' => $i,
                'size' => 740162,
            ];
        }
        if ($rows !== []) {
            DB::table('parts')->insert($rows);
        }

        return $binaryId;
    }
}
