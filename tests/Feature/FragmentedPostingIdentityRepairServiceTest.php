<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Diagnostics\FragmentedPostingIdentityRepairService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The bijection is the whole safety argument, so most of this file attacks it.
 *
 * This pass keys cohorts on (groups_id, fromname, totalfiles), which on its own
 * would happily merge two different postings by one poster that share a file
 * count. What makes it safe is the acceptance test: the union of binaries must
 * hold exactly `totalfiles` filenumbers, spanning 1..totalfiles, with no
 * duplicates. A missing file leaves a hole; a chimera leaves a duplicate.
 * Neither can pass, so a partial archive cannot be published as complete --
 * the one failure mode here that waiting cannot undo, and the one that cost
 * 512 production collections on 2026-08-03 when a sibling pass wrote a file
 * count it had not proven.
 *
 * The fixture is transcribed from the live `alt.binaries.movies` cohort that
 * motivated the class: 102 collections, 243 binaries, filenumbers 1..243,
 * random per-file names sharing no stem. Scaled down, shape preserved.
 */
final class FragmentedPostingIdentityRepairServiceTest extends TestCase
{
    private const GROUP_ID = 5081;

    private const GROUP_NAME = 'alt.binaries.movies';

    private const POSTER = 'ZgyFdf.efNOnunCuZ@JKbumewfB.rNO';

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
        DB::table('settings')->insert(['name' => 'minfilestoformrelease', 'value' => '1']);
    }

    /**
     * One collection per file, exactly as ingest leaves them.
     *
     * @param  list<int>  $filenumbers
     */
    private function seedFragments(
        array $filenumbers,
        int $declaredFiles,
        string $poster = self::POSTER,
        string $extension = '.mkv',
        string $dateadded = '2026-08-01 10:00:00',
    ): void {
        foreach ($filenumbers as $index => $filenumber) {
            $collectionId = (int) DB::table('collections')->insertGetId([
                'subject' => sprintf('[%03d/%d] - "randomname%02d%s" yEnc', $filenumber, $declaredFiles, $filenumber, $extension),
                'fromname' => $poster,
                'date' => $dateadded,
                'groups_id' => self::GROUP_ID,
                'totalfiles' => $declaredFiles,
                // Random per file: this is exactly why the cleaner mints a
                // separate key for every one of them.
                'collectionhash' => sha1($poster.$extension.$filenumber.$index.$declaredFiles),
                'dateadded' => $index === 0 ? $dateadded : '2026-08-02 18:00:00',
                'filecheck' => 0,
            ]);

            $binaryId = (int) DB::table('binaries')->insertGetId([
                'binaryhash' => sha1('b'.$collectionId.$filenumber),
                'name' => sprintf('randomname%02d%s', $filenumber, $extension),
                'collections_id' => $collectionId,
                'filenumber' => $filenumber,
                'totalparts' => 2,
                'currentparts' => 2,
                'partcheck' => 1,
                'partsize' => 200,
            ]);

            foreach ([1, 2] as $partNumber) {
                DB::table('parts')->insert([
                    'binaries_id' => $binaryId,
                    'messageid' => 'm'.($this->nextArticle++),
                    'number' => $this->nextArticle,
                    'partnumber' => $partNumber,
                    'size' => 100,
                ]);
            }
        }
    }

    private function repair(bool $update): array
    {
        return (new FragmentedPostingIdentityRepairService)
            ->repair(self::GROUP_NAME, 50, null, null, $update);
    }

    public function test_a_complete_fragmented_posting_is_merged_into_one_collection(): void
    {
        $this->seedFragments(range(1, 6), 6);
        self::assertSame(6, DB::table('collections')->count());

        $summary = $this->repair(true);

        self::assertSame(1, $summary['cohorts_merged']);
        self::assertSame(5, $summary['collections_removed']);
        self::assertSame(1, DB::table('collections')->count());

        $survivor = DB::table('collections')->first();
        self::assertNotNull($survivor);
        // Not rewritten: the bijection already proved it correct.
        self::assertSame(6, (int) $survivor->totalfiles);
        self::assertSame(0, (int) $survivor->filecheck);

        // Every file and every article survives the move.
        self::assertSame(6, DB::table('binaries')->where('collections_id', $survivor->id)->count());
        self::assertSame(12, DB::table('parts')->count());
        self::assertSame(
            range(1, 6),
            DB::table('binaries')->orderBy('filenumber')->pluck('filenumber')
                ->map(static fn ($n): int => (int) $n)->all(),
        );
    }

    public function test_the_survivor_keeps_the_oldest_members_timestamps(): void
    {
        $this->seedFragments(range(1, 4), 4);

        $this->repair(true);

        $survivor = DB::table('collections')->first();
        self::assertNotNull($survivor);
        // Back-dating clears the delaytime gate rather than restarting the wait.
        self::assertSame('2026-08-01 10:00:00', (string) $survivor->dateadded);
    }

    /**
     * The load-bearing refusal. A hole means a file was never downloaded, and
     * merging would present a partial archive as complete.
     */
    public function test_a_cohort_missing_a_file_is_refused(): void
    {
        // Declares 6 files, holds 5: filenumber 4 never arrived.
        $this->seedFragments([1, 2, 3, 5, 6], 6);

        $summary = $this->repair(true);

        self::assertSame(0, $summary['cohorts_merged']);
        self::assertSame(0, $summary['collections_removed']);
        self::assertSame(5, DB::table('collections')->count());
    }

    /**
     * A chimera: two postings by one poster sharing a file count would collide
     * on the cohort key, and the duplicate filenumber is what catches it.
     */
    public function test_a_cohort_with_a_duplicate_filenumber_is_refused(): void
    {
        $this->seedFragments([1, 2, 3], 4);
        // A fourth collection re-using filenumber 3 -- the shape a second
        // posting under the same key produces.
        $this->seedFragments([3], 4, self::POSTER, '.mkv', '2026-08-01 11:00:00');

        $summary = $this->repair(true);

        self::assertSame(0, $summary['cohorts_merged']);
        self::assertSame(4, DB::table('collections')->count());
    }

    /**
     * Merging a par2-only cohort hands the release pipeline something its
     * $par2Only predicate deletes, cascading every part away.
     */
    public function test_a_par2_only_cohort_is_refused(): void
    {
        $this->seedFragments(range(1, 3), 3, self::POSTER, '.vol000+001.par2');

        $summary = $this->repair(true);

        self::assertSame(0, $summary['cohorts_merged']);
        self::assertSame('par2_only', $summary['skipped'][0]['refusal'] ?? null);
        self::assertSame(3, DB::table('collections')->count());
    }

    /**
     * The shape that got past the guard in production on the first apply.
     *
     * Brace-token binaries carry the token AFTER the extension --
     * `{Lioness.S03.vol063+64.par2} {sraBl51wo8je} yEnc` -- so an end-anchored
     * par2 test misses every one of them. 616 collections across two cohorts
     * merged that BraceTokenIdentityRepairService had already refused by name,
     * and only ReleaseProcessingService's own unanchored predicate stopped a
     * par2-only release from being published. A guard that is weaker than the
     * gate it mirrors is worse than no guard, because it reads as coverage.
     */
    public function test_a_par2_only_cohort_is_refused_when_the_extension_is_not_at_the_end(): void
    {
        foreach ([1, 2, 3] as $filenumber) {
            $collectionId = (int) DB::table('collections')->insertGetId([
                'subject' => sprintf('{Lioness.S03.vol063+64.par2} {tok%02d} yEnc', $filenumber),
                'fromname' => self::POSTER,
                'date' => '2026-08-01 10:00:00',
                'groups_id' => self::GROUP_ID,
                'totalfiles' => 3,
                'collectionhash' => sha1('brace'.$filenumber),
                'dateadded' => '2026-08-01 10:00:00',
                'filecheck' => 0,
            ]);
            DB::table('binaries')->insert([
                'binaryhash' => sha1('bb'.$collectionId),
                'name' => sprintf('{Lioness.S03.vol063+64.par2} {tok%02d} yEnc', $filenumber),
                'collections_id' => $collectionId,
                'filenumber' => $filenumber,
                'totalparts' => 1,
                'currentparts' => 1,
                'partcheck' => 1,
                'partsize' => 100,
            ]);
        }

        $summary = $this->repair(true);

        self::assertSame(0, $summary['cohorts_merged']);
        self::assertSame('par2_only', $summary['skipped'][0]['refusal'] ?? null);
        self::assertSame(3, DB::table('collections')->count());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->seedFragments(range(1, 5), 5);

        $summary = $this->repair(false);

        self::assertSame(1, $summary['cohorts_mergeable']);
        self::assertSame(0, $summary['cohorts_merged']);
        self::assertSame(5, DB::table('collections')->count());
        self::assertSame(5, DB::table('binaries')->count());
    }

    /**
     * A posting that is already one collection is not this bug, and re-running
     * after a merge must converge rather than churn.
     */
    public function test_an_unfragmented_posting_is_left_alone(): void
    {
        $this->seedFragments(range(1, 4), 4);
        $this->repair(true);

        $second = $this->repair(true);

        self::assertSame(0, $second['cohorts_found']);
        self::assertSame(1, DB::table('collections')->count());
        self::assertSame(4, DB::table('binaries')->count());
    }

    public function test_an_unknown_group_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FragmentedPostingIdentityRepairService)->repair('alt.binaries.nope', 10, null, null, false);
    }
}
