<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\ObfuscatedSubjectNormalizer;
use App\Services\Diagnostics\BraceTokenIdentityRepairService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The gap that let a reclaim destroy 512 production collections.
 *
 * BraceTokenIdentityRepairServiceTest stops at the merge: it proves the rows
 * were rehomed correctly and every part survived. That is necessary but it is
 * not the question that mattered. A repaired collection still has to travel
 * stage 6 -> processCollectionSizes -> Sized before it can become a release,
 * and TWO delete predicates sit on that road. Both were satisfied by the
 * repaired shape, so the pipeline deleted the collection -- with
 * FK_Collections ON DELETE CASCADE taking its binary and every part with it --
 * hours after a merge that had verified clean.
 *
 * The predicates, both in App\Services\ReleaseProcessingService:
 *
 *  1. deleteCollectionsUnderThreshold() (~line 740) deletes any Sized
 *     collection with `totalfiles < minfilestoformrelease` (production: 2).
 *     Crucially, the value the repair writes is irrelevant: stage 6 (~line
 *     1520) has already overwritten totalfiles with
 *     `(SELECT COUNT(b.id) FROM binaries b WHERE b.collections_id = collections.id)`.
 *     So the surviving BINARY COUNT is what the floor actually measures.
 *
 *  2. createReleases()' $par2Only filter (~line 513) deletes any collection
 *     whose binaries are ALL par2 volumes.
 *
 * A target state of "one collection, one binary" fails both by construction:
 * one binary is below the floor, and a par2 file's lone binary is 100% par2.
 * Grouping per POSTING instead -- payload files and their par2 volumes in one
 * collection, each file its own binary -- is what clears them.
 *
 * These tests therefore assert on the two predicates rather than on the merge.
 * The predicates are transcribed from the production source (referenced above)
 * because ReleaseProcessingService cannot be driven against this fixture's
 * hand-built sqlite schema; if they ever drift from it, that is a real defect
 * this file is meant to surface, not an inconvenience to paper over.
 */
final class BraceTokenRepairSurvivesReleaseGatesTest extends TestCase
{
    private const GROUP_ID = 6979;

    private const GROUP_NAME = 'alt.binaries.movies';

    /** Production `settings.minfilestoformrelease`, with no group override on 6979. */
    private const MIN_FILES_TO_FORM_RELEASE = 2;

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
            name VARCHAR(255)
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
            filenumber INT DEFAULT 0,
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

        DB::table('usenet_groups')->insert(['id' => self::GROUP_ID, 'name' => self::GROUP_NAME]);
    }

    /**
     * The whole point of the reclaim: a repaired posting has to reach Sized
     * still alive. This is the assertion whose absence cost 512 collections.
     */
    public function test_a_repaired_posting_survives_the_min_files_floor_and_the_par2_only_rule(): void
    {
        $this->seedPosting();

        $this->service()->repair(self::GROUP_ID, 50, null, true, self::MIN_FILES_TO_FORM_RELEASE);

        $this->advanceStage6RewritingTotalFiles();

        self::assertSame(
            [],
            $this->collectionsDeletedByMinFilesFloor(),
            'A repaired posting was deleted by the min-files floor. This is the '
            .'defect that destroyed 512 production collections: stage 6 sets '
            .'totalfiles to COUNT(binaries), so one binary per collection lands '
            .'below minfilestoformrelease.'
        );

        self::assertSame(
            [],
            $this->collectionsDeletedByPar2OnlyRule(),
            'A repaired posting was deleted for being all-par2. One collection '
            .'per par2 FILE is 100% par2 by construction; the payload and its '
            .'par2 volumes have to share one collection.'
        );
    }

    /**
     * Guards the mechanism rather than the outcome: it is the surviving binary
     * count that the floor measures, because stage 6 overwrites whatever
     * totalfiles the repair wrote. A repair that "fixes" this by writing a
     * larger totalfiles would pass a naive test and still be deleted in
     * production.
     */
    public function test_stage_six_overwrites_the_repairs_totalfiles_with_the_binary_count(): void
    {
        $this->seedPosting();

        $this->service()->repair(self::GROUP_ID, 50, null, true, self::MIN_FILES_TO_FORM_RELEASE);
        $this->advanceStage6RewritingTotalFiles();

        $rows = DB::table('collections')->get();
        self::assertNotEmpty($rows);

        foreach ($rows as $row) {
            $binaries = DB::table('binaries')->where('collections_id', $row->id)->count();

            self::assertSame(
                $binaries,
                (int) $row->totalfiles,
                'totalfiles must equal COUNT(binaries) after stage 6, whatever the repair wrote.'
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
     * Every seeded part must still be reachable from a live binary.
     */
    public function test_surviving_the_gates_costs_no_parts(): void
    {
        $seeded = $this->seedPosting();

        $this->service()->repair(self::GROUP_ID, 50, null, true, self::MIN_FILES_TO_FORM_RELEASE);
        $this->advanceStage6RewritingTotalFiles();

        $survivors = array_values(array_diff(
            DB::table('collections')->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            $this->collectionsDeletedByMinFilesFloor(),
            $this->collectionsDeletedByPar2OnlyRule(),
        ));

        $reachableParts = DB::table('parts')
            ->join('binaries', 'binaries.id', '=', 'parts.binaries_id')
            ->whereIn('binaries.collections_id', $survivors)
            ->count();

        self::assertSame(
            $seeded['parts'],
            $reachableParts,
            'Parts went missing between the merge and the gates.'
        );

        $files = DB::table('binaries')->whereIn('collections_id', $survivors)->count();
        self::assertSame(
            $seeded['files'],
            $files,
            'Every real file of the posting must survive as its own binary, so '
            .'the NZB lists all of them instead of collapsing to one.'
        );
    }

    /**
     * Mirror of runCollectionFileCheckStage6()'s update
     * (ReleaseProcessingService ~line 1520). Only the totalfiles rewrite
     * matters here; the completeness HAVING clause governs *when* a collection
     * arrives, not whether the floor then deletes it.
     */
    private function advanceStage6RewritingTotalFiles(): void
    {
        DB::statement(
            'UPDATE collections SET totalfiles = (
                SELECT COUNT(b.id) FROM binaries b WHERE b.collections_id = collections.id
            )'
        );
    }

    /**
     * Mirror of deleteCollectionsUnderThreshold() (~line 740). filesize > 0 is
     * part of the predicate; processCollectionSizesSlice() sets it from
     * SUM(binaries.partsize) on the way to Sized, so it is nonzero for anything
     * holding parts.
     *
     * @return list<int>
     */
    private function collectionsDeletedByMinFilesFloor(): array
    {
        return DB::table('collections')
            ->where('groups_id', self::GROUP_ID)
            ->where('totalfiles', '<', self::MIN_FILES_TO_FORM_RELEASE)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Mirror of the $par2Only filter in createReleases() (~line 513): a
     * collection every one of whose binaries is a par2 volume is deleted.
     *
     * @return list<int>
     */
    private function collectionsDeletedByPar2OnlyRule(): array
    {
        $deleted = [];

        foreach (DB::table('collections')->orderBy('id')->pluck('id') as $id) {
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
     * One real posting in the shape the residue actually has: a payload split
     * into .partNN.rar volumes plus its .volNNN+NN.par2 recovery set, every
     * article of every file stranded in its own collection.
     *
     * Mirrors Maddie's.Secret.2026 / Soulm8te.2026 from production, scaled down.
     *
     * @return array{files: int, parts: int}
     */
    private function seedPosting(): array
    {
        $files = [
            '{Maddie\'s.Secret.2026.part01.rar} yEnc' => 4,
            '{Maddie\'s.Secret.2026.part02.rar} yEnc' => 4,
            '{Maddie\'s.Secret.2026.part03.rar} yEnc' => 3,
            '{Maddie\'s.Secret.2026.vol000+01.par2} yEnc' => 2,
            '{Maddie\'s.Secret.2026.vol001+02.par2} yEnc' => 2,
        ];

        foreach ($files as $name => $parts) {
            $this->seedStrandedFile($name, $parts);
        }

        return ['files' => \count($files), 'parts' => array_sum($files)];
    }

    private function service(): BraceTokenIdentityRepairService
    {
        return new BraceTokenIdentityRepairService(
            new ObfuscatedSubjectNormalizer([self::GROUP_NAME])
        );
    }

    /** Pre-fix shape: one collection per ARTICLE, each with one binary holding one part. */
    private function seedStrandedFile(string $normalizedName, int $parts): void
    {
        for ($i = 0; $i < $parts; $i++) {
            $partNumber = $i + 1;
            $token = substr(str_pad((string) ($i + 1), 12, 'aBcDeFgHiJkL'), 0, 12);
            $subject = str_replace('} yEnc', '} {'.$token.'} yEnc', $normalizedName);

            $collectionId = (int) DB::table('collections')->insertGetId([
                'subject' => $subject,
                'fromname' => 'Ultraman <bowman@test.com>',
                'date' => '2026-08-02 05:00:00',
                'groups_id' => self::GROUP_ID,
                'totalfiles' => 1,
                'collectionhash' => sha1($subject.$parts),
                'dateadded' => '2026-08-02 05:00:00',
                'filecheck' => 0,
            ]);

            $binaryId = $this->nextBinaryId++;
            DB::table('binaries')->insert([
                'id' => $binaryId,
                'binaryhash' => md5($subject.$collectionId),
                'name' => $subject,
                'collections_id' => $collectionId,
                'filenumber' => $partNumber,
                'totalparts' => $parts,
                'currentparts' => 1,
                'partcheck' => 0,
                'partsize' => 740162,
            ]);

            DB::table('parts')->insert([
                'binaries_id' => $binaryId,
                'messageid' => 'article'.$this->nextArticle.'@ngPost',
                'number' => $this->nextArticle++,
                'partnumber' => $partNumber,
                'size' => 740162,
            ]);
        }
    }
}
