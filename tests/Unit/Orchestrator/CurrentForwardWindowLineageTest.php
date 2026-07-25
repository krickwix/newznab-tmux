<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardWindowLineage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CurrentForwardWindowLineageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.current_forward_continuation_enabled' => true,
        ]);
        DB::purge('sqlite');
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation')->nullable()->unique();
            $table->string('state', 32);
            $table->unsignedBigInteger('chain_root_id')->nullable();
            $table->unsignedBigInteger('parent_window_id')->nullable();
            $table->unsignedTinyInteger('chain_ordinal')->default(1);
            $table->dateTime('continuation_deadline_at')->nullable();
        });
        Schema::create('current_forward_object_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('object_type', 16);
            $table->unsignedBigInteger('object_id');
            $table->unsignedBigInteger('chain_root_id');
            $table->timestamps();
            $table->unique(['object_type', 'object_id']);
        });
        Schema::create('current_forward_window_objects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('window_id');
            $table->unsignedBigInteger('chain_root_id');
            $table->string('object_type', 16);
            $table->unsignedBigInteger('object_id');
            $table->unsignedBigInteger('parent_object_id')->nullable();
            $table->unsignedInteger('inserted_parts')->default(0);
            $table->boolean('created_in_window')->default(false);
            $table->boolean('touched_in_window')->default(true);
            $table->timestamps();
            $table->unique(['window_id', 'object_type', 'object_id']);
        });
        Schema::create('current_forward_continuation_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('window_id')->unique();
            $table->unsignedBigInteger('chain_root_id');
            $table->unsignedTinyInteger('chain_ordinal');
            $table->unsignedBigInteger('baseline_present_parts');
            $table->unsignedBigInteger('current_present_parts');
            $table->unsignedBigInteger('useful_progress_parts');
            $table->unsignedBigInteger('expected_parts');
            $table->unsignedInteger('observed_files');
            $table->unsignedInteger('complete_files');
            $table->unsignedInteger('unresolved_collections');
            $table->unsignedBigInteger('cumulative_parts');
            $table->unsignedInteger('cumulative_binaries');
            $table->unsignedInteger('cumulative_collections');
            $table->unsignedInteger('cumulative_releases');
            $table->unsignedInteger('cumulative_ready_nzbs');
            $table->string('decision', 32);
            $table->string('reason', 120);
            $table->string('pipeline_hash', 64);
            $table->string('cohort_hash', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->dateTime('observed_at');
            $table->timestamps();
        });
        Schema::create('current_forward_release_dispositions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id')->unique();
            $table->unsignedBigInteger('chain_root_id');
            $table->unsignedBigInteger('window_id');
            $table->unsignedBigInteger('parent_collection_id')->nullable();
            $table->string('reason', 120);
            $table->unsignedInteger('categories_id')->nullable();
            $table->unsignedTinyInteger('nzbstatus')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->dateTime('disposed_at');
            $table->timestamps();
        });
        Schema::create('current_forward_collection_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_collection_id');
            $table->unsignedBigInteger('target_collection_id');
            $table->unsignedBigInteger('chain_root_id');
            $table->unsignedBigInteger('source_window_id');
            $table->unsignedBigInteger('target_window_id');
            $table->unsignedInteger('moved_binary_count');
            $table->char('moved_binary_ids_hash', 64);
            $table->string('reason', 40);
            $table->dateTime('handed_off_at');
            $table->timestamps();
            $table->unique(['chain_root_id', 'source_collection_id']);
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('totalfiles')->default(1);
            $table->unsignedTinyInteger('filecheck')->default(0);
            $table->unsignedBigInteger('filesize')->default(0);
            $table->unsignedBigInteger('releases_id')->nullable();
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collections_id');
            $table->unsignedInteger('totalparts')->default(1);
            $table->unsignedInteger('currentparts')->default(1);
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('categories_id')->default(2040);
            $table->unsignedTinyInteger('nzbstatus')->default(0);
            $table->unsignedBigInteger('size')->default(0);
        });
    }

    public function test_release_attribution_is_fenced_by_the_open_root_state(): void
    {
        $lineage = new CurrentForwardWindowLineage;
        $rootId = $this->window(41, 'ATTRIBUTING');
        DB::table('current_forward_window_objects')->insert([
            'window_id' => $rootId,
            'chain_root_id' => $rootId,
            'object_type' => CurrentForwardWindowLineage::COLLECTION,
            'object_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('current_forward_object_owners')->insert([
            'object_type' => CurrentForwardWindowLineage::COLLECTION,
            'object_id' => 10,
            'chain_root_id' => $rootId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::transaction(fn () => $lineage->recordReleaseForCollection(10, 100));
        $this->assertDatabaseHas('current_forward_window_objects', [
            'chain_root_id' => $rootId,
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => 100,
            'parent_object_id' => 10,
        ]);

        DB::table('current_forward_windows')->where('id', $rootId)->update(['state' => 'PRODUCTIVE']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current-forward lineage is no longer open for release attribution.');
        DB::transaction(fn () => $lineage->recordReleaseForCollection(10, 101));
    }

    public function test_one_object_cannot_be_owned_by_two_lineage_roots(): void
    {
        $lineage = new CurrentForwardWindowLineage;
        $firstRoot = $this->window(41, 'CLAIMED');
        $secondRoot = $this->window(42, 'CLAIMED');
        DB::table('collections')->insert(['id' => 10]);
        DB::table('binaries')->insert(['id' => 20, 'collections_id' => 10]);
        DB::transaction(fn () => $lineage->recordHeaderChunk(
            41,
            [10],
            [10],
            [20],
            [20],
            [['binaries_id' => 20, 'number' => 1]],
        ));

        try {
            DB::transaction(fn () => $lineage->recordHeaderChunk(
                42,
                [10],
                [],
                [20],
                [],
                [],
            ));
            self::fail('Cross-root ownership was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Current-forward object is already owned by another lineage root.',
                $exception->getMessage(),
            );
        }

        self::assertSame($firstRoot, (int) DB::table('current_forward_object_owners')
            ->where('object_type', CurrentForwardWindowLineage::COLLECTION)
            ->where('object_id', 10)
            ->value('chain_root_id'));
        self::assertNotSame($firstRoot, $secondRoot);
    }

    public function test_terminal_lineage_owner_can_be_reassigned_to_a_new_root(): void
    {
        $lineage = new CurrentForwardWindowLineage;
        $terminalRoot = $this->window(41, 'CLAIMED');
        $newRoot = $this->window(42, 'CLAIMED');
        DB::table('collections')->insert(['id' => 10]);
        DB::table('binaries')->insert(['id' => 20, 'collections_id' => 10]);

        DB::transaction(fn () => $lineage->recordHeaderChunk(
            41,
            [10],
            [10],
            [20],
            [20],
            [['binaries_id' => 20, 'number' => 1]],
        ));
        DB::table('current_forward_windows')
            ->where('id', $terminalRoot)
            ->update(['state' => 'QUARANTINED']);

        DB::transaction(fn () => $lineage->recordHeaderChunk(
            42,
            [10],
            [],
            [20],
            [],
            [['binaries_id' => 20, 'number' => 2]],
        ));

        self::assertSame($newRoot, (int) DB::table('current_forward_object_owners')
            ->where('object_type', CurrentForwardWindowLineage::COLLECTION)
            ->where('object_id', 10)
            ->value('chain_root_id'));
        self::assertDatabaseHas('current_forward_window_objects', [
            'window_id' => $terminalRoot,
            'chain_root_id' => $terminalRoot,
            'object_type' => CurrentForwardWindowLineage::COLLECTION,
            'object_id' => 10,
        ]);
        self::assertDatabaseHas('current_forward_window_objects', [
            'window_id' => $newRoot,
            'chain_root_id' => $newRoot,
            'object_type' => CurrentForwardWindowLineage::COLLECTION,
            'object_id' => 10,
        ]);

        DB::table('current_forward_windows')->where('id', $newRoot)->update(['state' => 'ATTRIBUTING']);
        DB::table('collections')->where('id', 10)->update(['releases_id' => 100]);
        DB::table('releases')->insert(['id' => 100]);
        DB::transaction(fn () => $lineage->recordReleaseForCollection(10, 100));

        self::assertDatabaseHas('current_forward_window_objects', [
            'window_id' => $newRoot,
            'chain_root_id' => $newRoot,
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => 100,
            'parent_object_id' => 10,
        ]);
    }

    public function test_terminal_root_with_an_open_child_cannot_be_reassigned(): void
    {
        $lineage = new CurrentForwardWindowLineage;
        $terminalRoot = $this->window(41, 'CLAIMED');
        DB::table('collections')->insert(['id' => 10]);
        DB::transaction(fn () => $lineage->recordHeaderChunk(41, [10], [10], [], [], []));
        DB::table('current_forward_windows')
            ->where('id', $terminalRoot)
            ->update(['state' => 'QUARANTINED']);

        $openChild = $this->window(42, 'CONTINUATION_PENDING');
        DB::table('current_forward_windows')->where('id', $openChild)->update([
            'chain_root_id' => $terminalRoot,
            'parent_window_id' => $terminalRoot,
            'chain_ordinal' => 2,
        ]);
        $this->window(43, 'CLAIMED');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current-forward object is already owned by another lineage root.');
        DB::transaction(fn () => $lineage->recordHeaderChunk(43, [10], [], [], [], []));
    }

    public function test_quarantined_root_with_chained_child_cannot_be_reassigned(): void
    {
        $lineage = new CurrentForwardWindowLineage;
        $quarantinedRoot = $this->window(41, 'CLAIMED');
        DB::table('collections')->insert(['id' => 10]);
        DB::transaction(fn () => $lineage->recordHeaderChunk(41, [10], [10], [], [], []));
        DB::table('current_forward_windows')
            ->where('id', $quarantinedRoot)
            ->update(['state' => 'QUARANTINED']);

        $chainedChild = $this->window(42, 'CHAINED');
        DB::table('current_forward_windows')->where('id', $chainedChild)->update([
            'chain_root_id' => $quarantinedRoot,
            'parent_window_id' => $quarantinedRoot,
            'chain_ordinal' => 2,
        ]);
        $this->window(43, 'CLAIMED');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current-forward object is already owned by another lineage root.');
        DB::transaction(fn () => $lineage->recordHeaderChunk(43, [10], [], [], [], []));
    }

    public function test_productive_root_with_chained_child_can_be_reassigned(): void
    {
        $lineage = new CurrentForwardWindowLineage;
        $productiveRoot = $this->window(41, 'CLAIMED');
        DB::table('collections')->insert(['id' => 10]);
        DB::table('binaries')->insert(['id' => 20, 'collections_id' => 10]);
        DB::transaction(fn () => $lineage->recordHeaderChunk(
            41,
            [10],
            [10],
            [20],
            [20],
            [['binaries_id' => 20, 'number' => 1]],
        ));
        DB::table('current_forward_windows')
            ->where('id', $productiveRoot)
            ->update(['state' => 'PRODUCTIVE']);

        $chainedChild = $this->window(42, 'CHAINED');
        DB::table('current_forward_windows')->where('id', $chainedChild)->update([
            'chain_root_id' => $productiveRoot,
            'parent_window_id' => $productiveRoot,
            'chain_ordinal' => 2,
        ]);
        $newRoot = $this->window(43, 'CLAIMED');

        DB::transaction(fn () => $lineage->recordHeaderChunk(
            43,
            [10],
            [],
            [20],
            [],
            [['binaries_id' => 20, 'number' => 2]],
        ));

        foreach ([
            [CurrentForwardWindowLineage::COLLECTION, 10],
            [CurrentForwardWindowLineage::BINARY, 20],
        ] as [$type, $objectId]) {
            self::assertSame($newRoot, (int) DB::table('current_forward_object_owners')
                ->where('object_type', $type)
                ->where('object_id', $objectId)
                ->value('chain_root_id'));
        }
    }

    public function test_header_mutation_requires_an_active_transaction(): void
    {
        $this->window(41, 'CLAIMED');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current-forward lineage mutation requires an active transaction.');
        (new CurrentForwardWindowLineage)->recordHeaderChunk(41, [], [], [], [], []);
    }

    public function test_release_mutation_requires_an_active_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current-forward lineage mutation requires an active transaction.');
        (new CurrentForwardWindowLineage)->recordReleaseForCollection(10, 100);
    }

    public function test_collection_handoff_requires_an_active_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current-forward collection handoff requires an active transaction.');
        (new CurrentForwardWindowLineage)->recordCollectionHandoffsForMerge([11], 10, 'split collection merge');
    }

    public function test_collection_handoff_rejects_a_still_claimed_ingest_root(): void
    {
        $rootId = $this->window(41, 'CLAIMED');
        DB::table('current_forward_object_owners')->insert([
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'chain_root_id' => $rootId],
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'chain_root_id' => $rootId],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current-forward collection handoff root is not open.');
        DB::transaction(
            fn () => (new CurrentForwardWindowLineage)->recordCollectionHandoffsForMerge(
                [11],
                10,
                'split collection merge',
            ),
        );
    }

    public function test_split_handoff_resolves_missing_binary_only_after_target_release_resolution(): void
    {
        $lineage = new CurrentForwardWindowLineage;
        $rootId = $this->window(41, 'ATTRIBUTING');
        DB::table('collections')->insert([
            ['id' => 10, 'totalfiles' => 2, 'filecheck' => 0, 'filesize' => 1_000, 'releases_id' => null],
            ['id' => 11, 'totalfiles' => 2, 'filecheck' => 0, 'filesize' => 100, 'releases_id' => null],
        ]);
        DB::table('binaries')->insert([
            ['id' => 20, 'collections_id' => 10],
            ['id' => 21, 'collections_id' => 11],
        ]);
        DB::table('current_forward_window_objects')->insert([
            ['window_id' => $rootId, 'chain_root_id' => $rootId, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'parent_object_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['window_id' => $rootId, 'chain_root_id' => $rootId, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'parent_object_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['window_id' => $rootId, 'chain_root_id' => $rootId, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 20, 'parent_object_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['window_id' => $rootId, 'chain_root_id' => $rootId, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 21, 'parent_object_id' => 11, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('current_forward_object_owners')->insert([
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'chain_root_id' => $rootId, 'created_at' => now(), 'updated_at' => now()],
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'chain_root_id' => $rootId, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::transaction(function () use ($lineage): void {
            self::assertSame(1, $lineage->recordCollectionHandoffsForMerge(
                [11],
                10,
                'Split collection merge',
            ));
            DB::table('binaries')->where('id', 21)->update(['collections_id' => 10]);
            DB::table('collections')->where('id', 11)->delete();
        });

        $liveHandoff = $lineage->integrity($rootId);
        self::assertTrue($liveHandoff['integrity_ok']);
        self::assertSame([11], $liveHandoff['handed_off_collection_ids']);
        self::assertSame([], $liveHandoff['missing_collection_ids']);
        self::assertSame([], $liveHandoff['missing_binary_ids']);
        self::assertDatabaseHas('current_forward_collection_handoffs', [
            'chain_root_id' => $rootId,
            'source_collection_id' => 11,
            'target_collection_id' => 10,
            'reason' => 'split_collection_merge',
        ]);

        DB::table('binaries')->where('id', 21)->delete();
        $unresolvedTarget = $lineage->integrity($rootId);
        self::assertFalse($unresolvedTarget['integrity_ok']);
        self::assertSame([], $unresolvedTarget['missing_collection_ids']);
        self::assertSame([21], $unresolvedTarget['missing_binary_ids']);

        DB::table('collections')->where('id', 10)->update(['releases_id' => 100]);
        DB::table('releases')->insert(['id' => 100, 'categories_id' => 5020, 'nzbstatus' => 1, 'size' => 1_100]);
        DB::table('current_forward_window_objects')->insert([
            'window_id' => $rootId,
            'chain_root_id' => $rootId,
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => 100,
            'parent_object_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::assertTrue($lineage->integrity($rootId)['integrity_ok']);
    }

    public function test_collection_handoff_rolls_back_with_failed_merge(): void
    {
        $rootId = $this->window(41, 'ATTRIBUTING');
        DB::table('collections')->insert([
            ['id' => 10, 'totalfiles' => 2],
            ['id' => 11, 'totalfiles' => 2],
        ]);
        DB::table('binaries')->insert(['id' => 21, 'collections_id' => 11]);
        DB::table('current_forward_window_objects')->insert([
            ['window_id' => $rootId, 'chain_root_id' => $rootId, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'parent_object_id' => null],
            ['window_id' => $rootId, 'chain_root_id' => $rootId, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'parent_object_id' => null],
            ['window_id' => $rootId, 'chain_root_id' => $rootId, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 21, 'parent_object_id' => 11],
        ]);
        DB::table('current_forward_object_owners')->insert([
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'chain_root_id' => $rootId],
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'chain_root_id' => $rootId],
        ]);

        try {
            DB::transaction(function (): void {
                (new CurrentForwardWindowLineage)->recordCollectionHandoffsForMerge([11], 10, 'split collection merge');
                throw new RuntimeException('force rollback');
            });
            self::fail('Cleanup transaction unexpectedly committed.');
        } catch (RuntimeException $exception) {
            self::assertSame('force rollback', $exception->getMessage());
        }

        self::assertSame(0, DB::table('current_forward_collection_handoffs')->count());
        self::assertTrue(DB::table('collections')->where('id', 11)->exists());
    }

    public function test_observation_reports_an_unrecorded_missing_release_as_integrity_failure(): void
    {
        $rootId = $this->window(41, 'ATTRIBUTING');
        DB::table('current_forward_window_objects')->insert([
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::COLLECTION,
                'object_id' => 10,
                'parent_object_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::BINARY,
                'object_id' => 20,
                'parent_object_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::RELEASE,
                'object_id' => 100,
                'parent_object_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $observation = (new CurrentForwardWindowLineage)->observe($rootId);

        self::assertFalse($observation['integrity_ok']);
        self::assertSame([100], $observation['orphan_release_ids']);
        self::assertSame([10], $observation['missing_collection_ids']);
        self::assertSame([20], $observation['missing_binary_ids']);
    }

    public function test_recorded_release_disposition_makes_intentional_descendant_removal_explicit(): void
    {
        $rootId = $this->window(41, 'QUARANTINED');
        DB::table('current_forward_window_objects')->insert([
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::COLLECTION,
                'object_id' => 10,
                'parent_object_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::BINARY,
                'object_id' => 20,
                'parent_object_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::RELEASE,
                'object_id' => 100,
                'parent_object_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('current_forward_release_dispositions')->insert([
            'release_id' => 100,
            'chain_root_id' => $rootId,
            'window_id' => $rootId,
            'parent_collection_id' => 10,
            'reason' => 'executable',
            'disposed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $observation = (new CurrentForwardWindowLineage)->observe($rootId);

        self::assertTrue($observation['integrity_ok']);
        self::assertSame([], $observation['orphan_release_ids']);
        self::assertSame([100], $observation['disposed_release_ids']);
        self::assertSame([], $observation['missing_collection_ids']);
        self::assertSame([], $observation['missing_binary_ids']);
    }

    public function test_release_disposition_rejects_parent_or_snapshot_identity_drift(): void
    {
        $rootId = $this->window(41, 'ATTRIBUTING');
        DB::table('releases')->insert([
            'id' => 100,
            'categories_id' => 5030,
            'nzbstatus' => -1,
            'size' => 1_000,
        ]);
        DB::table('current_forward_window_objects')->insert([
            'window_id' => $rootId,
            'chain_root_id' => $rootId,
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => 100,
            'parent_object_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('current_forward_object_owners')->insert([
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => 100,
            'chain_root_id' => $rootId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('current_forward_release_dispositions')->insert([
            'release_id' => 100,
            'chain_root_id' => $rootId,
            'window_id' => $rootId,
            'parent_collection_id' => 999,
            'reason' => 'executable',
            'categories_id' => 5030,
            'nzbstatus' => -1,
            'size' => 1_000,
            'disposed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current-forward release disposition identity drift detected.');
        DB::transaction(
            fn () => (new CurrentForwardWindowLineage)->recordReleaseDispositionForDeletion(100, 'Executable'),
        );
    }

    public function test_repeated_observation_must_be_identical(): void
    {
        $lineage = new CurrentForwardWindowLineage;
        $rootId = $this->window(41, 'CONTINUATION_PENDING');
        $observation = [
            'parts' => 100,
            'binaries' => 1,
            'collections' => 1,
            'original_expected_parts' => 200,
            'original_present_parts' => 100,
            'original_observed_files' => 1,
            'original_complete_files' => 0,
            'unresolved_collections' => 1,
            'releases' => 0,
            'ready_nzbs' => 0,
            'hash' => str_repeat('a', 64),
        ];
        $lineage->recordObservation($rootId, $rootId, 1, $observation, 'CONTINUE', 'partial', 100, 100);
        $lineage->recordObservation($rootId, $rootId, 1, $observation, 'CONTINUE', 'partial', 100, 100);
        self::assertSame(1, DB::table('current_forward_continuation_observations')->count());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Current-forward continuation observation identity drift detected.');
        $lineage->recordObservation(
            $rootId,
            $rootId,
            1,
            [...$observation, 'hash' => str_repeat('b', 64)],
            'CONTINUE',
            'partial',
            100,
            100,
        );
    }

    public function test_observation_keeps_complete_child_collection_open_until_release_attribution(): void
    {
        $lineage = new CurrentForwardWindowLineage;
        $rootId = $this->window(41, 'CONTINUATION_PENDING');
        $childId = $this->window(42, 'CHAINED');
        DB::table('current_forward_windows')->where('id', $childId)->update([
            'chain_root_id' => $rootId,
            'parent_window_id' => $rootId,
            'chain_ordinal' => 2,
        ]);
        DB::table('collections')->insert([
            ['id' => 10, 'totalfiles' => 1, 'releases_id' => 100],
            ['id' => 11, 'totalfiles' => 1, 'releases_id' => null],
        ]);
        DB::table('binaries')->insert([
            ['id' => 20, 'collections_id' => 10, 'totalparts' => 1, 'currentparts' => 1],
            ['id' => 21, 'collections_id' => 11, 'totalparts' => 1, 'currentparts' => 1],
        ]);
        DB::table('releases')->insert([
            'id' => 100,
            'categories_id' => 5030,
            'nzbstatus' => 1,
            'size' => 1_000,
        ]);
        $objects = [
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::COLLECTION,
                'object_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::BINARY,
                'object_id' => 20,
                'parent_object_id' => 10,
                'inserted_parts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::RELEASE,
                'object_id' => 100,
                'parent_object_id' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'window_id' => $childId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::COLLECTION,
                'object_id' => 11,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'window_id' => $childId,
                'chain_root_id' => $rootId,
                'object_type' => CurrentForwardWindowLineage::BINARY,
                'object_id' => 21,
                'parent_object_id' => 11,
                'inserted_parts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];
        foreach ($objects as $object) {
            DB::table('current_forward_window_objects')->insert($object);
        }

        $pending = $lineage->observe($rootId);

        self::assertSame(2, $pending['collections']);
        self::assertSame(1, $pending['unresolved_collections']);

        DB::table('collections')->where('id', 11)->update(['releases_id' => 101]);
        DB::table('releases')->insert([
            'id' => 101,
            'categories_id' => 5030,
            'nzbstatus' => 1,
            'size' => 1_000,
        ]);
        DB::table('current_forward_window_objects')->insert([
            'window_id' => $childId,
            'chain_root_id' => $rootId,
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => 101,
            'parent_object_id' => 11,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(0, $lineage->observe($rootId)['unresolved_collections']);
    }

    private function window(int $generation, string $state): int
    {
        $id = (int) DB::table('current_forward_windows')->insertGetId([
            'generation' => $generation,
            'state' => $state,
            'chain_root_id' => null,
            'chain_ordinal' => 1,
        ]);
        DB::table('current_forward_windows')->where('id', $id)->update(['chain_root_id' => $id]);

        return $id;
    }
}
