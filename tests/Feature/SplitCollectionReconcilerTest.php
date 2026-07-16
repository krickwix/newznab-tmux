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

    public function test_merges_unique_complete_main_and_single_file_parity_fanout(): void
    {
        DB::table('usenet_groups')->where('id', 5)->update(['name' => 'alt.binaries.documentaries']);
        config()->set('nntmux.split_collection_reconcile_groups', ['alt.binaries.documentaries']);
        config()->set('nntmux.split_collection_xref_gap_overrides', ['alt.binaries.documentaries' => 2000]);

        $this->seedCollection(12, '[01/11] - "X-Men.97.S02E05.720p.WEB.H264-AFO.mkv" yEnc', 'poster@example.com', 11, '2026-07-16 05:47:20');
        DB::table('collections')->where('id', 12)->update([
            'xref' => 'alt.binaries.documentaries:190308495 alt.binaries.documentaries:190309760',
        ]);
        $this->seedBinary(120, 12, 1, 'X-Men.97.S02E05.720p.WEB.H264-AFO.mkv', 1166, 1166);

        for ($fileNumber = 2; $fileNumber <= 11; $fileNumber++) {
            $collectionId = 12 + $fileNumber;
            $this->seedCollection(
                $collectionId,
                sprintf('[%02d/11] - "hash-%02d.par2" yEnc', $fileNumber, $fileNumber),
                'poster@example.com',
                11,
                '2026-07-16 05:47:29',
            );
            DB::table('collections')->where('id', $collectionId)->update([
                'xref' => 'alt.binaries.documentaries:'.(190309650 + $fileNumber),
            ]);
            $this->seedBinary(120 + $fileNumber, $collectionId, $fileNumber, 'hash-'.$fileNumber.'.par2', 1, 1);
        }

        $result = (new SplitCollectionReconciler)->reconcile(5);

        self::assertSame(1, $result);
        self::assertSame(11, DB::table('binaries')->where('collections_id', 12)->count());
        self::assertSame(
            range(1, 11),
            DB::table('binaries')->where('collections_id', 12)->orderBy('filenumber')->pluck('filenumber')->map('intval')->all(),
        );
        self::assertSame(1, DB::table('collections')->whereBetween('id', [12, 23])->count());
        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
    }

    public function test_refuses_incomplete_single_file_parity_fanout(): void
    {
        $this->seedCollection(24, '[01/03] - "Episode.mkv" yEnc', 'fanout@example.com', 3, '2026-07-16 05:47:20');
        $this->seedBinary(240, 24, 1, 'Episode.mkv', 100, 100);
        foreach ([2, 3] as $fileNumber) {
            $collectionId = 24 + $fileNumber;
            $this->seedCollection($collectionId, sprintf('[%02d/03] - "hash.par2" yEnc', $fileNumber), 'fanout@example.com', 3, '2026-07-16 05:47:29');
            $this->seedBinary(240 + $fileNumber, $collectionId, $fileNumber, 'hash-'.$fileNumber.'.par2', $fileNumber === 2 ? 9 : 10, 10);
        }

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertSame(3, DB::table('collections')->whereBetween('id', [24, 27])->count());
    }

    public function test_refuses_ambiguous_single_file_parity_fanout(): void
    {
        foreach ([30, 31] as $anchorId) {
            $this->seedCollection($anchorId, '[01/03] - "Episode.mkv" yEnc', 'ambiguous@example.com', 3, '2026-07-16 05:47:20');
            $this->seedBinary(300 + $anchorId, $anchorId, 1, 'Episode.mkv', 100, 100);
        }
        foreach ([2, 3] as $fileNumber) {
            $collectionId = 31 + $fileNumber;
            $this->seedCollection($collectionId, sprintf('[%02d/03] - "hash.par2" yEnc', $fileNumber), 'ambiguous@example.com', 3, '2026-07-16 05:47:29');
            $this->seedBinary(330 + $fileNumber, $collectionId, $fileNumber, 'hash-'.$fileNumber.'.par2', 10, 10);
        }

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertSame(4, DB::table('collections')->whereBetween('id', [30, 34])->count());
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

    public function test_uses_only_current_group_articles_when_foreign_crosspost_would_hide_valid_pair(): void
    {
        $this->seedCollection(60, '[01/02] - "Crossposted.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(61, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        DB::table('collections')->where('id', 60)->update([
            'xref' => 'ALT.BINARIES.MOVIES.DVD:100 alt.binaries.foreign:9000000',
        ]);
        DB::table('collections')->where('id', 61)->update([
            'xref' => 'alt.binaries.movies.dvd:101 alt.binaries.foreign:1',
        ]);
        $this->seedBinary(600, 60, 1, 'Crossposted.Episode.mkv', 10, 10);
        $this->seedBinary(610, 61, 2, 'hash.par2', 1, 1);

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 61)->exists());
    }

    public function test_refuses_pair_when_only_foreign_crosspost_articles_are_within_gap(): void
    {
        $this->seedCollection(70, '[01/02] - "Distant.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(71, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        DB::table('collections')->where('id', 70)->update([
            'xref' => 'alt.binaries.movies.dvd:100 alt.binaries.foreign:1000',
        ]);
        DB::table('collections')->where('id', 71)->update([
            'xref' => 'alt.binaries.movies.dvd:5000 alt.binaries.foreign:1001',
        ]);
        $this->seedBinary(700, 70, 1, 'Distant.Episode.mkv', 10, 10);
        $this->seedBinary(710, 71, 2, 'hash.par2', 1, 1);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 71)->exists());
    }

    public function test_refuses_pair_when_current_group_xref_is_missing(): void
    {
        $this->seedCollection(80, '[01/02] - "Wrong.Group.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(81, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        DB::table('collections')->where('id', 80)->update(['xref' => 'alt.binaries.foreign:100']);
        DB::table('collections')->where('id', 81)->update(['xref' => 'alt.binaries.foreign:101']);
        $this->seedBinary(800, 80, 1, 'Wrong.Group.Episode.mkv', 10, 10);
        $this->seedBinary(810, 81, 2, 'hash.par2', 1, 1);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 81)->exists());
    }

    public function test_refuses_malformed_or_non_exact_current_group_tokens(): void
    {
        $this->seedCollection(90, '[01/02] - "Malformed.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(91, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        DB::table('collections')->where('id', 90)->update([
            'xref' => 'alt.binaries.movies.dvd:100junk alt.binaries.movies.dvd:-1 alt.binaries.movies.dvd:100:900 xalt.binaries.movies.dvd:100',
        ]);
        DB::table('collections')->where('id', 91)->update([
            'xref' => 'alt.binaries.movies.dvd:101junk alt.binaries.movies.dvd:-2 alt.binaries.movies.dvd:101:901 xalt.binaries.movies.dvd:101',
        ]);
        $this->seedBinary(900, 90, 1, 'Malformed.Episode.mkv', 10, 10);
        $this->seedBinary(910, 91, 2, 'hash.par2', 1, 1);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 91)->exists());
    }

    public function test_accepts_xref_gap_at_limit(): void
    {
        $this->seedCollection(100, '[01/02] - "Boundary.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(101, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        DB::table('collections')->where('id', 100)->update(['xref' => 'alt.binaries.movies.dvd:1000']);
        DB::table('collections')->where('id', 101)->update(['xref' => 'alt.binaries.movies.dvd:2000']);
        $this->seedBinary(1000, 100, 1, 'Boundary.Episode.mkv', 10, 10);
        $this->seedBinary(1010, 101, 2, 'hash.par2', 1, 1);

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 101)->exists());
    }

    public function test_refuses_xref_gap_one_past_limit(): void
    {
        $this->seedCollection(110, '[01/02] - "Past.Boundary.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(111, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        DB::table('collections')->where('id', 110)->update(['xref' => 'alt.binaries.movies.dvd:1000']);
        DB::table('collections')->where('id', 111)->update(['xref' => 'alt.binaries.movies.dvd:2001']);
        $this->seedBinary(1100, 110, 1, 'Past.Boundary.Episode.mkv', 10, 10);
        $this->seedBinary(1110, 111, 2, 'hash.par2', 1, 1);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 111)->exists());
    }

    public function test_hdtv_group_can_use_a_bounded_2000_article_gap_override(): void
    {
        DB::table('usenet_groups')->where('id', 5)->update(['name' => 'alt.binaries.hdtv.tv-episodes']);
        config()->set('nntmux.split_collection_reconcile_groups', ['alt.binaries.hdtv.tv-episodes']);
        config()->set('nntmux.split_collection_xref_gap_overrides', ['alt.binaries.hdtv.tv-episodes' => 2000]);
        $this->seedCollection(120, '[01/02] - "HDTV.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(121, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        DB::table('collections')->where('id', 120)->update(['xref' => 'alt.binaries.hdtv.tv-episodes:1000']);
        DB::table('collections')->where('id', 121)->update(['xref' => 'alt.binaries.hdtv.tv-episodes:3000']);
        $this->seedBinary(1200, 120, 1, 'HDTV.Episode.mkv', 10, 10);
        $this->seedBinary(1210, 121, 2, 'hash.par2', 1, 1);

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
    }

    public function test_hdtv_gap_override_rejects_2001(): void
    {
        DB::table('usenet_groups')->where('id', 5)->update(['name' => 'alt.binaries.hdtv.tv-episodes']);
        config()->set('nntmux.split_collection_reconcile_groups', ['alt.binaries.hdtv.tv-episodes']);
        config()->set('nntmux.split_collection_xref_gap_overrides', ['alt.binaries.hdtv.tv-episodes' => 2000]);
        $this->seedCollection(130, '[01/02] - "HDTV.Past.Boundary.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection(131, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:20:16');
        DB::table('collections')->where('id', 130)->update(['xref' => 'alt.binaries.hdtv.tv-episodes:1000']);
        DB::table('collections')->where('id', 131)->update(['xref' => 'alt.binaries.hdtv.tv-episodes:3001']);
        $this->seedBinary(1300, 130, 1, 'HDTV.Past.Boundary.mkv', 10, 10);
        $this->seedBinary(1310, 131, 2, 'hash.par2', 1, 1);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
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

    public function test_locked_identity_gate_rejects_cohort_field_drift(): void
    {
        $method = new \ReflectionMethod(SplitCollectionReconciler::class, 'sameCohortIdentity');
        $identity = [
            'id' => 10,
            'groups_id' => 5,
            'fromname' => 'poster@example.com',
            'totalfiles' => 11,
            'date' => '2026-07-13 02:18:15',
            'xref' => 'alt.binaries.movies.dvd:100',
        ];

        self::assertTrue($method->invoke(new SplitCollectionReconciler, $identity, $identity));
        foreach (['groups_id', 'fromname', 'totalfiles', 'date', 'xref'] as $field) {
            $drifted = $identity;
            $drifted[$field] = is_int($identity[$field]) ? $identity[$field] + 1 : $identity[$field].'-changed';
            self::assertFalse($method->invoke(new SplitCollectionReconciler, $identity, $drifted), $field);
        }
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
