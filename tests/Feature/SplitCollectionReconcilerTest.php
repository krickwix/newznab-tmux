<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Metrics\SplitCollectionTelemetry;
use App\Services\Orchestrator\CurrentForwardWindowLineage;
use App\Services\Releases\SplitCollectionReconciler;
use Illuminate\Support\Facades\Cache;
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
        config()->set('nntmux.split_collection_reconcile_cursor_store', 'array');
        config()->set('nntmux.distributed_lock_store', 'array');
        Cache::store('array')->flush();
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

    public function test_merge_records_transactional_current_forward_collection_resolution(): void
    {
        $this->seedCollection(10, '[01/02] - "Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:18:15');
        $this->seedCollection(11, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:18:16');
        $this->seedBinary(100, 10, 1, 'Episode.mkv', 10, 10);
        $this->seedBinary(110, 11, 2, 'hash.par2', 1, 1);
        DB::table('current_forward_windows')->insert([
            'id' => 1,
            'generation' => 41,
            'state' => 'ATTRIBUTING',
            'chain_root_id' => 1,
            'chain_ordinal' => 1,
        ]);
        DB::table('current_forward_window_objects')->insert([
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 100, 'parent_object_id' => 10],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 110, 'parent_object_id' => 11],
        ]);
        DB::table('current_forward_object_owners')->insert([
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'chain_root_id' => 1],
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'chain_root_id' => 1],
        ]);

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));

        self::assertDatabaseHas('current_forward_collection_handoffs', [
            'chain_root_id' => 1,
            'source_window_id' => 1,
            'target_window_id' => 1,
            'source_collection_id' => 11,
            'target_collection_id' => 10,
            'moved_binary_count' => 1,
            'reason' => 'split_collection_merge',
        ]);
        self::assertSame(10, (int) DB::table('binaries')->where('id', 110)->value('collections_id'));
        $integrity = (new CurrentForwardWindowLineage)->integrity(1);
        self::assertTrue($integrity['integrity_ok']);
        self::assertSame([11], $integrity['handed_off_collection_ids']);
    }

    public function test_merge_failure_rolls_back_handoff_reparent_and_source_delete(): void
    {
        $this->seedCollection(10, '[01/02] - "Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:18:15');
        $this->seedCollection(11, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:18:16');
        $this->seedBinary(100, 10, 1, 'Episode.mkv', 10, 10);
        $this->seedBinary(110, 11, 2, 'hash.par2', 1, 1);
        DB::table('current_forward_windows')->insert([
            'id' => 1,
            'generation' => 41,
            'state' => 'ATTRIBUTING',
            'chain_root_id' => 1,
            'chain_ordinal' => 1,
        ]);
        DB::table('current_forward_window_objects')->insert([
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 100, 'parent_object_id' => 10],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 110, 'parent_object_id' => 11],
        ]);
        DB::table('current_forward_object_owners')->insert([
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'chain_root_id' => 1],
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'chain_root_id' => 1],
        ]);
        $anchorXref = (string) DB::table('collections')->where('id', 10)->value('xref');
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_companion_delete
            BEFORE DELETE ON collections
            WHEN OLD.id = 11
            BEGIN
                SELECT RAISE(ABORT, 'forced merge delete failure');
            END
        SQL);

        try {
            (new SplitCollectionReconciler)->reconcile(5);
            self::fail('Split merge unexpectedly survived the forced delete failure.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('forced merge delete failure', $exception->getMessage());
        }

        self::assertSame(0, DB::table('current_forward_collection_handoffs')->count());
        self::assertTrue(DB::table('collections')->where('id', 11)->exists());
        self::assertSame(11, (int) DB::table('binaries')->where('id', 110)->value('collections_id'));
        self::assertSame($anchorXref, (string) DB::table('collections')->where('id', 10)->value('xref'));
    }

    public function test_refuses_unowned_source_merge_into_lineage_owned_anchor(): void
    {
        $this->seedCollection(10, '[01/02] - "Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-13 02:18:15');
        $this->seedCollection(11, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-13 02:18:16');
        $this->seedBinary(100, 10, 1, 'Episode.mkv', 10, 10);
        $this->seedBinary(110, 11, 2, 'hash.par2', 1, 1);
        DB::table('current_forward_windows')->insert([
            'id' => 1,
            'generation' => 41,
            'state' => 'ATTRIBUTING',
            'chain_root_id' => 1,
            'chain_ordinal' => 1,
        ]);
        DB::table('current_forward_window_objects')->insert([
            'window_id' => 1,
            'chain_root_id' => 1,
            'object_type' => CurrentForwardWindowLineage::COLLECTION,
            'object_id' => 10,
            'parent_object_id' => null,
        ]);
        DB::table('current_forward_object_owners')->insert([
            'object_type' => CurrentForwardWindowLineage::COLLECTION,
            'object_id' => 10,
            'chain_root_id' => 1,
        ]);

        try {
            (new SplitCollectionReconciler)->reconcile(5);
            self::fail('Split merge unexpectedly crossed into a lineage-owned anchor.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('source ownership is incomplete', $exception->getMessage());
        }

        self::assertTrue(DB::table('collections')->where('id', 11)->exists());
        self::assertSame(11, (int) DB::table('binaries')->where('id', 110)->value('collections_id'));
        self::assertSame(0, DB::table('current_forward_collection_handoffs')->count());
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

    public function test_xref_gap_override_admits_a_large_anchor_fanout(): void
    {
        DB::table('usenet_groups')->where('id', 5)->update(['name' => 'alt.binaries.hdtv.x264']);
        config()->set('nntmux.split_collection_reconcile_groups', ['alt.binaries.hdtv.x264']);
        config()->set('nntmux.split_collection_xref_gap_overrides', ['alt.binaries.hdtv.x264' => 5000]);

        $this->seedLargeAnchorFanout('alt.binaries.hdtv.x264', 12, 2869);

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertSame(12, DB::table('binaries')->where('collections_id', 40)->count());
        self::assertSame(1, DB::table('collections')->whereBetween('id', [40, 51])->count());
    }

    public function test_large_anchor_fanout_is_refused_without_a_gap_override(): void
    {
        DB::table('usenet_groups')->where('id', 5)->update(['name' => 'alt.binaries.hdtv.x264']);
        config()->set('nntmux.split_collection_reconcile_groups', ['alt.binaries.hdtv.x264']);

        $this->seedLargeAnchorFanout('alt.binaries.hdtv.x264', 12, 2869);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertSame(12, DB::table('collections')->whereBetween('id', [40, 51])->count());
    }

    public function test_xref_gap_override_is_clamped_to_the_ceiling(): void
    {
        DB::table('usenet_groups')->where('id', 5)->update(['name' => 'alt.binaries.hdtv.x264']);
        config()->set('nntmux.split_collection_reconcile_groups', ['alt.binaries.hdtv.x264']);
        config()->set('nntmux.split_collection_xref_gap_overrides', ['alt.binaries.hdtv.x264' => 500000]);

        $this->seedLargeAnchorFanout('alt.binaries.hdtv.x264', 12, 25000);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertSame(12, DB::table('collections')->whereBetween('id', [40, 51])->count());
    }

    public function test_fanout_cap_override_admits_a_cohort_above_the_default(): void
    {
        DB::table('usenet_groups')->where('id', 5)->update(['name' => 'alt.binaries.hdtv.x264']);
        config()->set('nntmux.split_collection_reconcile_groups', ['alt.binaries.hdtv.x264']);
        config()->set('nntmux.split_collection_xref_gap_overrides', ['alt.binaries.hdtv.x264' => 5000]);
        config()->set('nntmux.split_collection_max_fanout_files', 40);

        $this->seedLargeAnchorFanout('alt.binaries.hdtv.x264', 25, 2869);

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertSame(25, DB::table('binaries')->where('collections_id', 40)->count());
    }

    public function test_fanout_above_the_default_cap_is_refused_without_an_override(): void
    {
        DB::table('usenet_groups')->where('id', 5)->update(['name' => 'alt.binaries.hdtv.x264']);
        config()->set('nntmux.split_collection_reconcile_groups', ['alt.binaries.hdtv.x264']);
        config()->set('nntmux.split_collection_xref_gap_overrides', ['alt.binaries.hdtv.x264' => 5000]);

        $this->seedLargeAnchorFanout('alt.binaries.hdtv.x264', 25, 2869);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertSame(25, DB::table('collections')->whereBetween('id', [40, 64])->count());
    }

    /**
     * Reproduce the deployed shape: one complete payload anchor whose parts push
     * the PAR2 companions well past the small-anchor xref gap default.
     */
    private function seedLargeAnchorFanout(string $groupName, int $totalFiles, int $articleGap): void
    {
        $anchorArticle = 8649442804;

        $this->seedCollection(40, sprintf('[01/%02d] - "Episode.mkv" yEnc', $totalFiles), 'large@example.com', $totalFiles, '2026-07-30 03:43:24');
        DB::table('collections')->where('id', 40)->update([
            'xref' => $groupName.':'.$anchorArticle,
        ]);
        $this->seedBinary(400, 40, 1, 'Episode.mkv', 2876, 2876);

        for ($fileNumber = 2; $fileNumber <= $totalFiles; $fileNumber++) {
            $collectionId = 39 + $fileNumber;
            $this->seedCollection(
                $collectionId,
                sprintf('[%02d/%02d] - "hash-%02d.par2" yEnc', $fileNumber, $totalFiles, $fileNumber),
                'large@example.com',
                $totalFiles,
                '2026-07-30 03:43:24',
            );
            DB::table('collections')->where('id', $collectionId)->update([
                'xref' => $groupName.':'.($anchorArticle + $articleGap + $fileNumber),
            ]);
            $this->seedBinary(400 + $fileNumber, $collectionId, $fileNumber, 'hash-'.$fileNumber.'.par2', 1, 1);
        }
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

    public function test_dynamic_pair_gap_empty_allowlist_keeps_static_plus_one_rejected(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', []);
        $this->seedDynamicPair(
            300,
            301,
            4001,
            'alt.binaries.movies.dvd:100000 alt.binaries.movies.dvd:102999',
            'alt.binaries.movies.dvd:104000',
        );

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 301)->exists());
    }

    public function test_dynamic_pair_gap_accepts_exact_residual_and_preserves_lineage_handoff(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        $this->seedDynamicPair(
            300,
            301,
            7500,
            'alt.binaries.movies.dvd:100000 alt.binaries.movies.dvd:103000',
            'alt.binaries.movies.dvd:107499',
        );
        DB::table('current_forward_windows')->insert([
            'id' => 1,
            'generation' => 42,
            'state' => 'ATTRIBUTING',
            'chain_root_id' => 1,
            'chain_ordinal' => 1,
        ]);
        DB::table('current_forward_window_objects')->insert([
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 300, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 301, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 3000, 'parent_object_id' => 300],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 3010, 'parent_object_id' => 301],
        ]);
        DB::table('current_forward_object_owners')->insert([
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 300, 'chain_root_id' => 1],
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 301, 'chain_root_id' => 1],
        ]);

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 301)->exists());
        self::assertSame(300, (int) DB::table('binaries')->where('id', 3010)->value('collections_id'));
        self::assertDatabaseHas('current_forward_collection_handoffs', [
            'chain_root_id' => 1,
            'source_collection_id' => 301,
            'target_collection_id' => 300,
            'reason' => 'split_collection_merge',
        ]);
        self::assertTrue((new CurrentForwardWindowLineage)->integrity(1)['integrity_ok']);
        $decisions = (new SplitCollectionTelemetry)->snapshot(['alt.binaries.movies.dvd']);
        self::assertSame(1, $decisions['groups']['alt.binaries.movies.dvd']['dynamic_accept']);
    }

    public function test_dynamic_pair_gap_repairs_an_enabled_settled_quarantined_root_atomically(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        config()->set('nntmux.split_collection_terminal_pair_repair_groups', ['alt.binaries.movies.dvd']);
        config()->set('nntmux.split_collection_terminal_pair_repair_roots', [1]);
        $this->seedDynamicPair(
            300,
            301,
            7500,
            'alt.binaries.movies.dvd:100000 alt.binaries.movies.dvd:103000',
            'alt.binaries.movies.dvd:107499',
        );
        DB::table('current_forward_windows')->insert([
            'id' => 1,
            'generation' => 42,
            'state' => 'QUARANTINED',
            'chain_root_id' => 1,
            'chain_ordinal' => 1,
            'failure_reason' => 'current_forward_continuation_admission_timeout',
            'settled_at' => now()->subMinute(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinute(),
        ]);
        DB::table('current_forward_window_objects')->insert([
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 300, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 301, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 3000, 'parent_object_id' => 300],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 3010, 'parent_object_id' => 301],
        ]);
        DB::table('current_forward_object_owners')->insert([
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 300, 'chain_root_id' => 1],
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 301, 'chain_root_id' => 1],
            ['object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 3000, 'chain_root_id' => 1],
            ['object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 3010, 'chain_root_id' => 1],
        ]);
        foreach ([
            'orchestrator_bf_permit', 'orchestrator_bf_claimed', 'orchestrator_bf_completed', 'orchestrator_bf_failed',
            'orchestrator_cf_permit', 'orchestrator_cf_claimed', 'orchestrator_cf_completed', 'orchestrator_cf_failed',
        ] as $name) {
            DB::table('settings')->insert(['name' => $name, 'value' => '0']);
        }

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));

        self::assertFalse(DB::table('collections')->where('id', 301)->exists());
        self::assertSame(300, (int) DB::table('binaries')->where('id', 3010)->value('collections_id'));
        self::assertDatabaseHas('current_forward_terminal_collection_repairs', [
            'chain_root_id' => 1,
            'source_collection_id' => 301,
            'target_collection_id' => 300,
            'root_state' => 'QUARANTINED',
            'group_name' => 'alt.binaries.movies.dvd',
            'residual' => 0,
        ]);
        $integrity = (new CurrentForwardWindowLineage)->integrity(1);
        self::assertSame([], $integrity['invalid_collection_handoff_ids']);
        self::assertSame([], $integrity['missing_collection_ids']);
        self::assertSame([], $integrity['missing_binary_ids']);
    }

    public function test_dynamic_pair_gap_rejects_unsupported_positive_residual(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        $this->seedDynamicPair(
            300,
            301,
            7500,
            'alt.binaries.movies.dvd:100000 alt.binaries.movies.dvd:103000',
            'alt.binaries.movies.dvd:107500',
        );

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 301)->exists());
    }

    public function test_dynamic_pair_gap_accepts_observed_negative_three_residual_boundary(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        $this->seedDynamicPair(
            300,
            301,
            7500,
            'alt.binaries.movies.dvd:100000 alt.binaries.movies.dvd:103000',
            'alt.binaries.movies.dvd:107496',
        );

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 301)->exists());
    }

    public function test_dynamic_pair_gap_rejects_actual_forward_gap_12001(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        $this->seedDynamicPair(
            300,
            301,
            12000,
            'alt.binaries.movies.dvd:100000',
            'alt.binaries.movies.dvd:112001',
        );

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 301)->exists());
    }

    public function test_dynamic_pair_gap_rejects_anchor_totalparts_12001(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        $this->seedDynamicPair(
            300,
            301,
            12001,
            'alt.binaries.movies.dvd:100000 alt.binaries.movies.dvd:103000',
            'alt.binaries.movies.dvd:112000',
        );

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 301)->exists());
    }

    public function test_dynamic_pair_gap_rejects_anchor_span_equal_to_totalparts(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        $this->seedDynamicPair(
            300,
            301,
            3001,
            'alt.binaries.movies.dvd:100000 alt.binaries.movies.dvd:103000',
            'alt.binaries.movies.dvd:104001',
        );

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 301)->exists());
    }

    public function test_dynamic_pair_gap_ignores_foreign_group_xrefs(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        $this->seedDynamicPair(
            300,
            301,
            7500,
            'alt.binaries.movies.dvd:100000 alt.binaries.foreign:999999999 alt.binaries.movies.dvd:103000',
            'alt.binaries.movies.dvd:107499 alt.binaries.foreign:1',
        );

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 301)->exists());
    }

    public function test_dynamic_pair_gap_rejects_malformed_huge_current_group_xref(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        $this->seedDynamicPair(
            300,
            301,
            7500,
            'alt.binaries.movies.dvd:999999999999999999999999999',
            'alt.binaries.movies.dvd:100007499',
        );

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 301)->exists());
    }

    public function test_dynamic_pair_gap_still_rejects_ambiguous_anchor_pair(): void
    {
        config()->set('nntmux.split_collection_dynamic_pair_gap_groups', ['alt.binaries.movies.dvd']);
        $this->seedDynamicPair(
            300,
            301,
            7500,
            'alt.binaries.movies.dvd:100000 alt.binaries.movies.dvd:103000',
            'alt.binaries.movies.dvd:107499',
        );
        $this->seedCollection(302, '[01/02] - "Dynamic.Episode.mkv" yEnc', 'dynamic@example.com', 2, '2026-07-13 02:20:15');
        DB::table('collections')->where('id', 302)->update([
            'xref' => 'alt.binaries.movies.dvd:100000 alt.binaries.movies.dvd:103000',
        ]);
        $this->seedBinary(3020, 302, 1, 'Dynamic.Episode.mkv', 7500, 7500);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 301)->exists());
        self::assertSame(3, DB::table('collections')->whereIn('id', [300, 301, 302])->count());
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

    public function test_default_24_hour_lookback_excludes_an_otherwise_valid_48_hour_old_pair(): void
    {
        config()->set('nntmux.split_collection_reconcile_lookback_hours', 24);
        $this->seedCollection(200, '[01/02] - "Old.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-16 02:20:15');
        $this->seedCollection(201, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-16 02:20:16');
        $this->seedBinary(2000, 200, 1, 'Old.Episode.mkv', 10, 10);
        $this->seedBinary(2010, 201, 2, 'hash.par2', 1, 1);
        DB::table('collections')->whereIn('id', [200, 201])->update(['dateadded' => now()->subHours(48)]);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 201)->exists());
        self::assertSame(201, (int) DB::table('binaries')->where('id', 2010)->value('collections_id'));
    }

    public function test_configured_72_hour_lookback_admits_an_otherwise_valid_48_hour_old_pair(): void
    {
        config()->set('nntmux.split_collection_reconcile_lookback_hours', 72);
        $this->seedCollection(210, '[01/02] - "Old.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-16 02:20:15');
        $this->seedCollection(211, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-16 02:20:16');
        $this->seedBinary(2100, 210, 1, 'Old.Episode.mkv', 10, 10);
        $this->seedBinary(2110, 211, 2, 'hash.par2', 1, 1);
        DB::table('collections')->whereIn('id', [210, 211])->update(['dateadded' => now()->subHours(48)]);

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 211)->exists());
        self::assertSame(210, (int) DB::table('binaries')->where('id', 2110)->value('collections_id'));
    }

    public function test_lookback_is_clamped_to_72_hours(): void
    {
        config()->set('nntmux.split_collection_reconcile_lookback_hours', 999);
        $this->seedCollection(220, '[01/02] - "Too.Old.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-16 02:20:15');
        $this->seedCollection(221, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-16 02:20:16');
        $this->seedBinary(2200, 220, 1, 'Too.Old.Episode.mkv', 10, 10);
        $this->seedBinary(2210, 221, 2, 'hash.par2', 1, 1);
        DB::table('collections')->whereIn('id', [220, 221])->update(['dateadded' => now()->subHours(73)]);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertTrue(DB::table('collections')->where('id', 221)->exists());
        self::assertSame(221, (int) DB::table('binaries')->where('id', 2210)->value('collections_id'));
    }

    public function test_bounded_fair_discovery_does_not_starve_an_old_valid_pair_behind_newer_fillers(): void
    {
        config()->set('nntmux.split_collection_reconcile_lookback_hours', 72);
        $this->seedCollection(230, '[01/02] - "Old.Fair.Episode.mkv" yEnc', 'poster@example.com', 2, '2026-07-16 02:20:15');
        $this->seedCollection(231, '[02/02] - "hash.par2" yEnc', 'poster@example.com', 2, '2026-07-16 02:20:16');
        $this->seedBinary(2300, 230, 1, 'Old.Fair.Episode.mkv', 10, 10);
        $this->seedBinary(2310, 231, 2, 'hash.par2', 1, 1);
        DB::table('collections')->whereIn('id', [230, 231])->update(['dateadded' => now()->subHours(48)]);

        for ($id = 1000; $id <= 1204; $id++) {
            $this->seedCollection($id, '[01/02] - "Filler.mkv" yEnc', 'filler-'.$id.'@example.com', 2, '2026-07-18 02:20:15');
        }

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 231)->exists());
        self::assertSame(230, (int) DB::table('binaries')->where('id', 2310)->value('collections_id'));
    }

    public function test_rotating_discovery_reaches_a_valid_pair_between_oldest_and_newest_reserves(): void
    {
        config()->set('nntmux.split_collection_reconcile_lookback_hours', 72);
        for ($id = 1; $id <= 199; $id++) {
            $this->seedCollection($id, '[01/02] - "Old.Filler.mkv" yEnc', 'old-filler-'.$id.'@example.com', 2, '2026-07-16 01:00:00');
        }
        $this->seedCollection(200, '[01/02] - "Middle.Episode.mkv" yEnc', 'middle@example.com', 2, '2026-07-16 02:20:15');
        $this->seedCollection(201, '[02/02] - "middle.par2" yEnc', 'middle@example.com', 2, '2026-07-16 02:20:16');
        $this->seedBinary(2000, 200, 1, 'Middle.Episode.mkv', 10, 10);
        $this->seedBinary(2010, 201, 2, 'middle.par2', 1, 1);
        for ($id = 202; $id <= 450; $id++) {
            $this->seedCollection($id, '[01/02] - "New.Filler.mkv" yEnc', 'new-filler-'.$id.'@example.com', 2, '2026-07-18 01:00:00');
        }
        DB::table('collections')->whereBetween('id', [1, 450])->update(['dateadded' => now()->subHours(48)]);

        self::assertSame(0, (new SplitCollectionReconciler)->reconcile(5));
        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 201)->exists());
        self::assertSame(200, (int) DB::table('binaries')->where('id', 2010)->value('collections_id'));
    }

    public function test_sampled_anchor_expands_exact_cohort_beyond_page_overlap(): void
    {
        config()->set('nntmux.split_collection_reconcile_lookback_hours', 72);
        for ($id = 1; $id <= 99; $id++) {
            $this->seedCollection($id, '[01/02] - "Old.Filler.mkv" yEnc', 'old-filler-'.$id.'@example.com', 2, '2026-07-16 01:00:00');
        }
        $this->seedCollection(100, '[01/02] - "Boundary.Episode.mkv" yEnc', 'boundary@example.com', 2, '2026-07-16 02:20:15');
        $this->seedBinary(1000, 100, 1, 'Boundary.Episode.mkv', 10, 10);
        for ($id = 101; $id <= 130; $id++) {
            $this->seedCollection($id, '[01/02] - "Middle.Filler.mkv" yEnc', 'middle-filler-'.$id.'@example.com', 2, '2026-07-16 02:20:15');
        }
        $this->seedCollection(131, '[02/02] - "boundary.par2" yEnc', 'boundary@example.com', 2, '2026-07-16 02:20:16');
        $this->seedBinary(1310, 131, 2, 'boundary.par2', 1, 1);
        for ($id = 132; $id <= 300; $id++) {
            $this->seedCollection($id, '[01/02] - "New.Filler.mkv" yEnc', 'new-filler-'.$id.'@example.com', 2, '2026-07-18 01:00:00');
        }
        DB::table('collections')->whereBetween('id', [1, 300])->update(['dateadded' => now()->subHours(48)]);

        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 131)->exists());
        self::assertSame(100, (int) DB::table('binaries')->where('id', 1310)->value('collections_id'));
    }

    public function test_pair_progress_is_reserved_when_fanouts_fill_the_success_budget(): void
    {
        $lastFanoutCompanionId = 0;
        for ($cohort = 0; $cohort < 20; $cohort++) {
            $anchorId = 1000 + ($cohort * 3);
            $poster = 'fanout-budget-'.$cohort.'@example.com';
            $date = sprintf('2026-07-16 03:%02d:00', $cohort);
            $this->seedCollection($anchorId, '[01/03] - "Episode.mkv" yEnc', $poster, 3, $date);
            $this->seedBinary($anchorId * 10, $anchorId, 1, 'Episode.mkv', 10, 10);
            foreach ([2, 3] as $fileNumber) {
                $companionId = $anchorId + $fileNumber - 1;
                $this->seedCollection($companionId, sprintf('[%02d/03] - "hash.par2" yEnc', $fileNumber), $poster, 3, $date);
                $this->seedBinary($companionId * 10, $companionId, $fileNumber, 'hash-'.$fileNumber.'.par2', 1, 1);
                $lastFanoutCompanionId = $companionId;
            }
        }
        $this->seedCollection(9000, '[01/02] - "Reserved.Pair.mkv" yEnc', 'reserved-pair@example.com', 2, '2026-07-16 04:00:00');
        $this->seedCollection(9001, '[02/02] - "reserved.par2" yEnc', 'reserved-pair@example.com', 2, '2026-07-16 04:00:01');
        $this->seedBinary(90000, 9000, 1, 'Reserved.Pair.mkv', 10, 10);
        $this->seedBinary(90010, 9001, 2, 'reserved.par2', 1, 1);

        self::assertSame(20, (new SplitCollectionReconciler)->reconcile(5));
        self::assertFalse(DB::table('collections')->where('id', 9001)->exists());
        self::assertTrue(DB::table('collections')->where('id', $lastFanoutCompanionId)->exists());
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

    public function test_merge_budget_defaults_to_twenty_pairs_per_pass(): void
    {
        $this->seedIndependentPairs(25);

        self::assertSame(20, (new SplitCollectionReconciler)->reconcile(5));
    }

    public function test_merge_budget_is_raisable_by_config(): void
    {
        $this->seedIndependentPairs(25);
        config()->set('nntmux.split_collection_reconcile_max_pairs_per_pass', 25);
        config()->set('nntmux.split_collection_reconcile_max_source_collections_per_pass', 200);

        // Draining a split backlog is the whole point of the override: one pass
        // must be able to clear more than the steady-state budget of 20.
        self::assertSame(25, (new SplitCollectionReconciler)->reconcile(5));
    }

    public function test_merge_budget_override_is_clamped_to_sane_bounds(): void
    {
        $this->seedIndependentPairs(3);
        config()->set('nntmux.split_collection_reconcile_max_pairs_per_pass', 0);

        // A zero/negative override must not disable reconciliation outright.
        self::assertSame(1, (new SplitCollectionReconciler)->reconcile(5));
    }

    public function test_source_collection_budget_caps_a_raised_pair_budget(): void
    {
        $this->seedIndependentPairs(25);
        config()->set('nntmux.split_collection_reconcile_max_pairs_per_pass', 25);
        config()->set('nntmux.split_collection_reconcile_max_source_collections_per_pass', 5);

        // Each pair consumes one source collection, so the tighter budget wins.
        self::assertSame(5, (new SplitCollectionReconciler)->reconcile(5));
    }

    /**
     * Seed $count independent anchor/parity pairs, each its own cohort so every
     * pair is separately mergeable and the merge budget is the only limit.
     */
    private function seedIndependentPairs(int $count): void
    {
        for ($pair = 0; $pair < $count; $pair++) {
            $anchorId = 1000 + ($pair * 2);
            $companionId = $anchorId + 1;
            $poster = sprintf('poster%02d@example.com', $pair);
            $date = sprintf('2026-07-13 02:%02d:15', $pair);

            $this->seedCollection($anchorId, sprintf('[01/02] - "Episode.%02d.mkv" yEnc', $pair), $poster, 2, $date);
            $this->seedCollection($companionId, sprintf('[02/02] - "hash%02d.par2" yEnc', $pair), $poster, 2, $date);
            $this->seedBinary($anchorId * 10, $anchorId, 1, sprintf('Episode.%02d.mkv', $pair), 10, 10);
            $this->seedBinary($companionId * 10, $companionId, 2, sprintf('hash%02d.par2', $pair), 1, 1);
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

    private function seedDynamicPair(
        int $anchorId,
        int $companionId,
        int $anchorTotalParts,
        string $anchorXref,
        string $companionXref,
    ): void {
        $this->seedCollection($anchorId, '[01/02] - "Dynamic.Episode.mkv" yEnc', 'dynamic@example.com', 2, '2026-07-13 02:20:15');
        $this->seedCollection($companionId, '[02/02] - "dynamic.par2" yEnc', 'dynamic@example.com', 2, '2026-07-13 02:20:16');
        DB::table('collections')->where('id', $anchorId)->update(['xref' => $anchorXref]);
        DB::table('collections')->where('id', $companionId)->update(['xref' => $companionXref]);
        $this->seedBinary($anchorId * 10, $anchorId, 1, 'Dynamic.Episode.mkv', $anchorTotalParts, $anchorTotalParts);
        $this->seedBinary($companionId * 10, $companionId, 2, 'dynamic.par2', 1, 1);
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
            filesize INTEGER DEFAULT 0,
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
        DB::statement('CREATE TABLE current_forward_windows (
            id INTEGER PRIMARY KEY,
            generation INTEGER UNIQUE,
            state VARCHAR(32),
            chain_root_id INTEGER NULL,
            parent_window_id INTEGER NULL,
            chain_ordinal INTEGER DEFAULT 1,
            continuation_deadline_at DATETIME NULL,
            failure_reason VARCHAR(120) NULL,
            settled_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        DB::statement('CREATE TABLE current_forward_object_owners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            object_type VARCHAR(16),
            object_id INTEGER,
            chain_root_id INTEGER,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(object_type, object_id)
        )');
        DB::statement('CREATE TABLE current_forward_window_objects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            window_id INTEGER,
            chain_root_id INTEGER,
            object_type VARCHAR(16),
            object_id INTEGER,
            parent_object_id INTEGER NULL,
            inserted_parts INTEGER DEFAULT 0,
            created_in_window INTEGER DEFAULT 0,
            touched_in_window INTEGER DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(window_id, object_type, object_id)
        )');
        DB::statement('CREATE TABLE current_forward_continuation_observations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            window_id INTEGER UNIQUE,
            chain_root_id INTEGER NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        DB::statement('CREATE TABLE current_forward_collection_handoffs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_collection_id INTEGER,
            target_collection_id INTEGER,
            chain_root_id INTEGER,
            source_window_id INTEGER,
            target_window_id INTEGER,
            moved_binary_count INTEGER,
            moved_binary_ids_hash VARCHAR(64),
            reason VARCHAR(40),
            handed_off_at DATETIME,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(chain_root_id, source_collection_id)
        )');
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT NULL)');
        $migration = require database_path(
            'migrations/2026_07_18_094500_add_current_forward_terminal_split_repairs.php',
        );
        $migration->up();
    }
}
