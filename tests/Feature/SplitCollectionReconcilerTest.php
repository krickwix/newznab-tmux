<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\SplitCollectionReconciler;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SplitCollectionReconcilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        $this->createTables();

        DB::table('usenet_groups')->insert(['id' => 5, 'name' => 'alt.binaries.movies.dvd']);
        config()->set('nntmux.split_collection_reconcile_groups', ['alt.binaries.movies.dvd']);
    }

    public function test_merges_unique_complete_main_and_obfuscated_parity_pair(): void
    {
        $this->seedCollection(10, '[01/11] - "Law and Order S11E11.mkv" yEnc', 'poster@example.com', 11, '2026-07-13 02:18:15');
        $this->seedCollection(11, '[02/11] - "14c92ae0bb82105f487e5346504fd1c4.par2" yEnc', 'poster@example.com', 11, '2026-07-13 02:18:45');
        $this->seedBinary(100, 10, 1, 'Law and Order S11E11.mkv', 4496, 4496);
        for ($fileNumber = 2; $fileNumber <= 11; $fileNumber++) {
            $this->seedBinary(100 + $fileNumber, 11, $fileNumber, sprintf('14c92ae0bb82105f487e5346504fd1c4.vol%03d+001.par2', $fileNumber), 1, 1);
        }

        $result = (new SplitCollectionReconciler)->reconcile(5);

        self::assertSame(1, $result);
        self::assertSame(11, DB::table('binaries')->where('collections_id', 10)->count());
        self::assertFalse(DB::table('collections')->where('id', 11)->exists());
        self::assertSame(
            range(1, 11),
            DB::table('binaries')->where('collections_id', 10)->orderBy('filenumber')->pluck('filenumber')->map('intval')->all(),
        );
        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
    }

    public function test_refuses_incomplete_main_or_non_par2_companion(): void
    {
        $this->seedCollection(20, '[01/03] - "Incomplete.mkv" yEnc', 'poster@example.com', 3, '2026-07-13 02:18:15');
        $this->seedCollection(21, '[02/03] - "hash.par2" yEnc', 'poster@example.com', 3, '2026-07-13 02:18:16');
        $this->seedBinary(200, 20, 1, 'Incomplete.mkv', 9, 10);
        $this->seedBinary(201, 21, 2, 'hash.par2', 1, 1);
        $this->seedBinary(202, 21, 3, 'hash.vol001+001.par2', 1, 1);

        $this->seedCollection(30, '[01/03] - "Readable.mkv" yEnc', 'other@example.com', 3, '2026-07-13 02:19:15');
        $this->seedCollection(31, '[02/03] - "sidecar.rar" yEnc', 'other@example.com', 3, '2026-07-13 02:19:16');
        $this->seedBinary(300, 30, 1, 'Readable.mkv', 10, 10);
        $this->seedBinary(301, 31, 2, 'sidecar.rar', 1, 1);
        $this->seedBinary(302, 31, 3, 'sidecar.par2', 1, 1);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->whereIn('id', [21, 31])->count() === 2);
    }

    public function test_refuses_ambiguous_anchor_pair(): void
    {
        foreach ([40, 42] as $anchorId) {
            $this->seedCollection($anchorId, '[01/03] - "Episode.mkv" yEnc', 'poster@example.com', 3, '2026-07-13 02:20:15');
            $this->seedBinary($anchorId * 10, $anchorId, 1, 'Episode.mkv', 10, 10);
        }
        DB::table('collections')->where('id', 42)->update(['xref' => 'alt.binaries.movies.dvd:40']);
        $this->seedCollection(41, '[02/03] - "hash.par2" yEnc', 'poster@example.com', 3, '2026-07-13 02:20:16');
        $this->seedBinary(411, 41, 2, 'hash.par2', 1, 1);
        $this->seedBinary(412, 41, 3, 'hash.vol001+001.par2', 1, 1);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 41)->exists());
    }

    public function test_refuses_group_outside_allowlist(): void
    {
        config()->set('nntmux.split_collection_reconcile_groups', []);
        $this->seedCollection(50, '[01/02] - "Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(51, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        $this->seedBinary(500, 50, 1, 'Episode.mkv', 10, 10);
        $this->seedBinary(510, 51, 2, 'hash.par2', 1, 1);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 51)->exists());
    }

    public function test_full_cohort_uniqueness_sees_matching_anchor_outside_discovery_page(): void
    {
        $this->seedCollection(1, '[01/02] - "Hidden.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedBinary(10, 1, 1, 'Hidden.Episode.mkv', 10, 10);
        for ($id = 2; $id <= 199; $id++) {
            $this->seedCollection($id, '[01/02] - "Filler.mkv" yEnc', 'filler-'.$id.'@example.com', 2, '2026-07-13 04:20:15');
        }
        $this->seedCollection(1000, '[01/02] - "Visible.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(1001, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        $this->seedBinary(10000, 1000, 1, 'Visible.Episode.mkv', 10, 10);
        $this->seedBinary(10010, 1001, 2, 'hash.par2', 1, 1);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 1001)->exists());
    }

    private function seedCollection(
        int $id,
        string $subject,
        string $fromName,
        int $totalFiles,
        string $date,
    ): void {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => $subject,
            'fromname' => $fromName,
            'date' => $date,
            'dateadded' => now(),
            'xref' => 'alt.binaries.movies.dvd:'.$id,
            'groups_id' => 5,
            'totalfiles' => $totalFiles,
            'filecheck' => 0,
            'collectionhash' => 'collection-'.$id,
            'releases_id' => null,
        ]);
    }

    private function seedBinary(
        int $id,
        int $collectionId,
        int $fileNumber,
        string $name,
        int $currentParts,
        int $totalParts,
    ): void {
        DB::table('binaries')->insert([
            'id' => $id,
            'name' => $name,
            'collections_id' => $collectionId,
            'currentparts' => $currentParts,
            'totalparts' => $totalParts,
            'filenumber' => $fileNumber,
        ]);
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255) UNIQUE)');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            dateadded DATETIME NULL,
            xref TEXT,
            groups_id INTEGER,
            totalfiles INTEGER,
            filecheck INTEGER,
            collectionhash VARCHAR(255),
            releases_id INTEGER NULL
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            collections_id INTEGER,
            currentparts INTEGER,
            totalparts INTEGER,
            filenumber INTEGER,
            UNIQUE(collections_id, filenumber)
        )');
    }
}
