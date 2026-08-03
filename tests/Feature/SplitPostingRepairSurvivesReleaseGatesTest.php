<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Diagnostics\SplitPostingIdentityRepairService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The gap that let a reclaim destroy 512 production collections.
 *
 * SplitPostingIdentityRepairServiceTest stops at the merge: it proves rows were
 * rehomed and every part survived. That is necessary but it is not the question
 * that mattered. A repaired collection still has to travel stage 1 -> stage 3 ->
 * stage 4 -> processCollectionSizes -> Sized before it can become a release, and
 * TWO delete predicates sit on that road. Both were satisfied by a previous
 * repair's output, so the pipeline deleted the collections -- with
 * FK_Collections ON DELETE CASCADE taking their binaries and every part --
 * hours after a merge that had verified clean.
 *
 * The predicates, both in App\Services\ReleaseProcessingService:
 *
 *  1. deleteCollectionsUnderThreshold() deletes any Sized collection with
 *     `totalfiles < minfilestoformrelease` (production: 2). The value the repair
 *     writes is irrelevant: stage 4 (runCollectionFileCheckStage4, ~line 1410)
 *     has already overwritten totalfiles with
 *     `(SELECT COUNT(b2.id) FROM binaries b2 WHERE b2.collections_id = collections.id)`.
 *     The surviving BINARY COUNT is what the floor actually measures.
 *
 *  2. createReleases()' $par2Only filter deletes any collection whose binaries
 *     are ALL par2 volumes.
 *
 * This is why the repair merges per POSTING -- payload files and their par2
 * volumes in one collection, one binary per file -- and refuses any cohort whose
 * merged shape trips either predicate.
 *
 * The predicates and stage clauses are transcribed from the production source
 * (referenced per method) because ReleaseProcessingService cannot be driven
 * against this fixture's hand-built sqlite schema. If they ever drift from it,
 * that is a real defect this file is meant to surface.
 */
final class SplitPostingRepairSurvivesReleaseGatesTest extends TestCase
{
    private const GROUP_ID = 5079;

    private const GROUP_NAME = 'alt.binaries.cinemageddon';

    /** Production `settings.minfilestoformrelease`, no override on 5079. */
    private const MIN_FILES_TO_FORM_RELEASE = 2;

    /** Production `settings.completionpercent`. */
    private const COMPLETION_PERCENT = 94;

    private const POSTER = 'Helljahve <helljahve@example.com>';

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
            -- CHECK mirrors the MariaDB `int(10) unsigned`; see the note on the
            -- same column in SplitPostingIdentityRepairServiceTest.
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
        DB::table('settings')->insert([
            ['name' => 'minfilestoformrelease', 'value' => (string) self::MIN_FILES_TO_FORM_RELEASE],
            ['name' => 'completionpercent', 'value' => (string) self::COMPLETION_PERCENT],
        ]);
    }

    /**
     * The whole point of the reclaim: a repaired posting has to reach Sized
     * still alive. This is the assertion whose absence cost 512 collections.
     */
    public function test_a_repaired_posting_survives_the_min_files_floor_and_the_par2_only_rule(): void
    {
        $this->seedCompletePosting();

        $this->service()->repair(self::GROUP_ID, 50, null, true);
        $this->advanceStage4RewritingTotalFiles();

        self::assertSame(
            [],
            $this->collectionsDeletedByMinFilesFloor(),
            'A repaired posting was deleted by the min-files floor. Stage 4 sets '
            .'totalfiles to COUNT(binaries), so a collection holding one file '
            .'lands below minfilestoformrelease and cascades.'
        );

        self::assertSame(
            [],
            $this->collectionsDeletedByPar2OnlyRule(),
            'A repaired posting was deleted for being all-par2. The payload and '
            .'its par2 volumes have to share one collection.'
        );
    }

    /**
     * The stall this repair exists to clear. Before it, the biggest live Paper
     * Boy fragment reads `16 >= CEIL(242 * 0.94)` -- 16 files measured against a
     * part count -- and can never pass at any completeness.
     */
    public function test_the_repair_is_what_lets_the_posting_clear_stage_one(): void
    {
        $this->seedCompletePosting();

        self::assertSame(
            [],
            $this->collectionsClearingStage1(),
            'Fixture is wrong: these collections were supposed to be stalled, '
            .'so the repair below has something to prove.'
        );

        $this->service()->repair(self::GROUP_ID, 50, null, true);

        self::assertNotSame(
            [],
            $this->collectionsClearingStage1(),
            'The merged posting still cannot clear stage 1. Dense filenumbers '
            .'1..N and totalfiles = the real file count are both required.'
        );
    }

    /**
     * Guards the mechanism rather than the outcome: it is the surviving binary
     * count the floor measures, because stage 4 overwrites whatever totalfiles
     * the repair wrote. A repair that "fixed" this by writing a larger
     * totalfiles would pass a naive test and still be deleted in production.
     */
    public function test_stage_four_overwrites_the_repairs_totalfiles_with_the_binary_count(): void
    {
        $this->seedCompletePosting();

        $this->service()->repair(self::GROUP_ID, 50, null, true);
        $this->advanceStage4RewritingTotalFiles();

        $rows = DB::table('collections')->whereNotIn('collectionhash', $this->strandedHashes())->get();
        self::assertNotEmpty($rows);

        foreach ($rows as $row) {
            $binaries = (int) DB::table('binaries')->where('collections_id', $row->id)->count();

            self::assertSame(
                $binaries,
                (int) $row->totalfiles,
                'totalfiles must equal COUNT(binaries) after stage 4, whatever the repair wrote.'
            );
            self::assertGreaterThanOrEqual(
                self::MIN_FILES_TO_FORM_RELEASE,
                $binaries,
                'A collection needs at least minfilestoformrelease binaries to outlive the floor.'
            );
        }
    }

    /**
     * The merge is lossy, so proving survival is worthless if it cost articles.
     */
    public function test_surviving_the_gates_costs_no_parts(): void
    {
        $seeded = $this->seedCompletePosting();

        $this->service()->repair(self::GROUP_ID, 50, null, true);
        $this->advanceStage4RewritingTotalFiles();

        $doomed = array_merge(
            $this->collectionsDeletedByMinFilesFloor(),
            $this->collectionsDeletedByPar2OnlyRule(),
            // The refused sidecars, counted out because seedCompletePosting()
            // reports only the merged posting. They are separately asserted to
            // still be present in
            // SplitPostingIdentityRepairServiceTest::test_the_sidecar_files_...
            DB::table('collections')
                ->whereIn('collectionhash', $this->strandedHashes())
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
        $survivors = array_values(array_diff(
            DB::table('collections')->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            $doomed
        ));

        $reachableParts = (int) DB::table('parts')
            ->join('binaries', 'binaries.id', '=', 'parts.binaries_id')
            ->whereIn('binaries.collections_id', $survivors)
            ->count();

        self::assertSame(
            $seeded['parts'],
            $reachableParts,
            'Parts went missing between the merge and the gates.'
        );

        $files = (int) DB::table('binaries')->whereIn('collections_id', $survivors)->count();
        self::assertSame(
            $seeded['files'],
            $files,
            'Every real file of the posting must survive as its own binary, so '
            .'the NZB lists all of them instead of collapsing to one.'
        );
    }

    /**
     * The limit of this repair, asserted rather than assumed.
     *
     * Measured against production: after simulating this merge across the whole
     * residue, only 24.1% of files (15,122 of 62,668) hold >= 94% of their
     * declared parts, so just 197 of 1,435 surviving cohorts clear stage 4
     * immediately. Merging fixes the collection IDENTITY; it cannot supply
     * articles that were never downloaded.
     *
     * What matters is that such a cohort ends up WAITING, not deleted -- it
     * clears stage 1, holds every part it had, and sits for the backfill instead
     * of cascading. A repair that turned "stalled" into "deleted" for 85% of the
     * residue would be far worse than leaving it alone.
     */
    public function test_a_posting_whose_files_are_incomplete_waits_rather_than_being_deleted(): void
    {
        $seeded = $this->seedIncompletePosting();

        $this->service()->repair(self::GROUP_ID, 50, null, true);

        // It clears stage 1 -- the identity is fixed.
        self::assertNotSame([], $this->collectionsClearingStage1());

        // But its files are short of the completion bar, so stage 3 will not
        // mark them complete and stage 4 will not advance the collection.
        self::assertSame(
            [],
            $this->binariesMarkedCompleteByStage3(),
            'Fixture is wrong: these files were supposed to be incomplete.'
        );

        $this->advanceStage4RewritingTotalFiles();

        self::assertSame([], $this->collectionsDeletedByMinFilesFloor());
        self::assertSame([], $this->collectionsDeletedByPar2OnlyRule());

        // Every article is still there for the backfill to complete.
        self::assertSame($seeded['parts'], (int) DB::table('parts')->count());
        self::assertSame(
            $seeded['files'],
            (int) DB::table('binaries')->count(),
            'An incomplete posting must keep one binary per real file.'
        );
    }

    /**
     * Mirror of runCollectionFileCheckStage1()'s HAVING clause
     * (ReleaseProcessingService:1213). The CASE is transcribed verbatim in
     * intent: a positive filenumber is the file ordinal, otherwise the binary id
     * stands in.
     *
     * @return list<int>
     */
    private function collectionsClearingStage1(): array
    {
        return DB::table('collections')
            ->join('binaries', 'binaries.collections_id', '=', 'collections.id')
            ->where('collections.groups_id', self::GROUP_ID)
            ->where('collections.totalfiles', '>', 0)
            ->where('collections.filecheck', 0)
            ->groupBy(['binaries.collections_id', 'collections.totalfiles', 'collections.id'])
            ->havingRaw(
                'COUNT(DISTINCT CASE WHEN binaries.filenumber > 0 THEN binaries.filenumber ELSE binaries.id END) '
                .'>= MAX(1, CAST((collections.totalfiles * ? + 99) / 100 AS INTEGER))',
                [self::COMPLETION_PERCENT]
            )
            ->orderBy('collections.id')
            ->pluck('collections.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Mirror of markCompleteBinaries()'s predicate
     * (ReleaseProcessingService:1438): `currentparts >= CEIL(totalparts * pct / 100)`.
     *
     * @return list<int>
     */
    private function binariesMarkedCompleteByStage3(): array
    {
        return DB::table('binaries')
            ->where('totalparts', '>', 0)
            ->whereRaw(
                'currentparts >= MAX(1, CAST((totalparts * ? + 99) / 100 AS INTEGER))',
                [self::COMPLETION_PERCENT]
            )
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Mirror of runCollectionFileCheckStage4()'s update
     * (ReleaseProcessingService:1410). Only the totalfiles rewrite matters here;
     * the completeness HAVING clause governs *when* a collection arrives, not
     * whether the floor then deletes it.
     */
    private function advanceStage4RewritingTotalFiles(): void
    {
        DB::statement(
            'UPDATE collections SET totalfiles = (
                SELECT COUNT(b2.id) FROM binaries b2 WHERE b2.collections_id = collections.id
            )'
        );
    }

    /**
     * Mirror of deleteCollectionsUnderThreshold(). Cohorts the repair refused
     * are excluded: they were never merged, so the pipeline taking them is the
     * pre-existing situation, not something this repair caused.
     *
     * @return list<int>
     */
    private function collectionsDeletedByMinFilesFloor(): array
    {
        return DB::table('collections')
            ->where('groups_id', self::GROUP_ID)
            ->whereNotIn('collectionhash', $this->strandedHashes())
            ->where('totalfiles', '<', self::MIN_FILES_TO_FORM_RELEASE)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Mirror of the $par2Only filter in createReleases(): a collection every one
     * of whose binaries is a par2 volume is deleted.
     *
     * @return list<int>
     */
    private function collectionsDeletedByPar2OnlyRule(): array
    {
        $deleted = [];

        $ids = DB::table('collections')
            ->whereNotIn('collectionhash', $this->strandedHashes())
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $names = DB::table('binaries')->where('collections_id', (int) $id)->pluck('name');
            if ($names->isEmpty()) {
                continue;
            }

            $par2 = $names->filter(
                static fn ($name): bool => preg_match('/\.(vol\d+\+\d+\.par2|par2)/i', (string) $name) === 1
            )->count();

            if ($par2 === $names->count()) {
                $deleted[] = (int) $id;
            }
        }

        return $deleted;
    }

    /**
     * Collections the repair deliberately refused, still carrying their seeded
     * hash because no merge adopted them.
     *
     * @return list<string>
     */
    private function strandedHashes(): array
    {
        return ['seed-jpg', 'seed-nzb'];
    }

    /**
     * A posting whose files are all complete: the case the reclaim can actually
     * drain. Payload volumes plus a par2 recovery set, split across collections
     * by part count, plus the two sidecars that land alone.
     *
     * @return array{files: int, parts: int}
     */
    private function seedCompletePosting(): array
    {
        $stem = 'The Paper Boy (1994) Dvdrip by Helljahve.avi';

        // The fragment holding several payload files with scattered filenumbers.
        $big = $this->seedCollection('big', 242, '2026-07-31 04:00:00');
        $this->seedBinary($big, $stem.'.001', 242, 242, 38);
        $this->seedBinary($big, $stem.'.002', 242, 242, 172);

        // Single-file fragments, each keyed by its own part count.
        $vol1 = $this->seedCollection('vol000', 63, '2026-07-31 04:10:00');
        $this->seedBinary($vol1, $stem.'.vol000+045.par2', 63, 63);
        $vol2 = $this->seedCollection('vol045', 124, '2026-07-31 04:15:00');
        $this->seedBinary($vol2, $stem.'.vol045+090.par2', 124, 124);

        // Sidecars: real files of the posting, but each its own stem, so each is
        // a one-file cohort the repair must refuse rather than merge.
        $jpg = $this->seedCollection('jpg', 3, '2026-07-31 04:25:00');
        $this->seedBinary($jpg, $stem.'.jpg', 3, 3);
        $nzb = $this->seedCollection('nzb', 2, '2026-07-31 04:30:00');
        $this->seedBinary($nzb, 'The Paper Boy (1994).nzb', 2, 2);

        return [
            'files' => 4,
            'parts' => 242 + 242 + 63 + 124,
        ];
    }

    /**
     * The majority case, transcribed from the live cohort: the payload volumes
     * declare 242 parts and hold ~80% of them, so no amount of regrouping makes
     * them complete.
     *
     * @return array{files: int, parts: int}
     */
    private function seedIncompletePosting(): array
    {
        $stem = 'Short.Payload.2026.avi';

        $a = $this->seedCollection('short-a', 242, '2026-07-31 04:00:00', $stem);
        $this->seedBinary($a, $stem.'.001', 242, 194);
        $b = $this->seedCollection('short-b', 242, '2026-07-31 04:05:00', $stem);
        $this->seedBinary($b, $stem.'.002', 242, 188);
        $c = $this->seedCollection('short-c', 63, '2026-07-31 04:10:00', $stem);
        $this->seedBinary($c, $stem.'.vol000+045.par2', 63, 51);

        return ['files' => 3, 'parts' => 194 + 188 + 51];
    }

    private function service(): SplitPostingIdentityRepairService
    {
        return new SplitPostingIdentityRepairService;
    }

    private function seedCollection(
        string $hash,
        int $totalFiles,
        string $dateadded,
        string $description = 'The Paper Boy (1994) Dvdrip by Helljahve'
    ): int {
        return (int) DB::table('collections')->insertGetId([
            // The production quote layout: the FIRST quoted run is ' yEnc '.
            'subject' => $description.' " yEnc "'.$description.'.avi" yEnc',
            'fromname' => self::POSTER,
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
        ?int $fileNumber = null
    ): int {
        $binaryId = $this->nextBinaryId++;

        DB::table('binaries')->insert([
            'id' => $binaryId,
            'binaryhash' => md5($file.$collectionId),
            'name' => 'The Paper Boy (1994) Dvdrip by Helljahve " yEnc "'.$file.'" yEnc',
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
