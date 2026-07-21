<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardTerminalSplitRepair;
use App\Services\Orchestrator\CurrentForwardWindowLineage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CurrentForwardTerminalSplitRepairTest extends TestCase
{
    private const string GROUP = 'alt.binaries.movies.dvd';

    private object $terminalEvidenceMigration;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'nntmux.orchestrator.current_forward_continuation_enabled' => true,
            'nntmux.split_collection_terminal_pair_repair_groups' => [self::GROUP],
            'nntmux.split_collection_terminal_pair_repair_roots' => [1],
        ]);
        DB::purge();
        DB::reconnect();
        $this->createSchemas();
        $this->terminalEvidenceMigration = require database_path(
            'migrations/2026_07_18_094500_add_current_forward_terminal_split_repairs.php',
        );
        $this->terminalEvidenceMigration->up();
    }

    public function test_begin_and_finish_preserve_pre_existing_defects_and_append_repair_evidence(): void
    {
        $this->seedEligiblePair(withOrphanRelease: true);
        $lineage = new CurrentForwardWindowLineage;
        self::assertSame([999], $lineage->integrity(1)['orphan_release_ids']);

        $context = DB::transaction(function (): array {
            $repair = new CurrentForwardTerminalSplitRepair;
            $context = $repair->beginPairRepair(11, 10, $this->facts());
            self::assertIsArray($context);
            DB::table('binaries')->where('collections_id', 11)->update(['collections_id' => 10]);
            DB::table('collections')->where('id', 11)->delete();
            $repair->finishPairRepair($context);

            return $context;
        });

        self::assertSame(1, (int) $context['repair_id']);
        self::assertDatabaseHas('current_forward_collection_handoffs', [
            'chain_root_id' => 1,
            'source_collection_id' => 11,
            'target_collection_id' => 10,
            'moved_binary_count' => 1,
        ]);
        self::assertDatabaseHas('current_forward_terminal_collection_repairs', [
            'id' => 1,
            'chain_root_id' => 1,
            'source_collection_id' => 11,
            'target_collection_id' => 10,
            'root_state' => 'QUARANTINED',
            'group_name' => self::GROUP,
            'anchor_totalparts' => 7500,
            'anchor_article_span' => 3001,
            'forward_article_gap' => 4499,
            'residual' => 0,
        ]);
        self::assertFalse(DB::table('collections')->where('id', 11)->exists());
        self::assertSame([20, 21], DB::table('binaries')
            ->where('collections_id', 10)->orderBy('id')->pluck('id')->map('intval')->all());
        $postIntegrity = $lineage->integrity(1);
        self::assertSame([999], $postIntegrity['orphan_release_ids']);
        self::assertSame([], $postIntegrity['invalid_collection_handoff_ids']);
        self::assertSame([], $postIntegrity['missing_collection_ids']);
        self::assertSame([], $postIntegrity['missing_binary_ids']);
    }

    public function test_feature_root_group_and_idle_gates_leave_no_evidence(): void
    {
        $this->seedEligiblePair();

        config()->set('nntmux.split_collection_terminal_pair_repair_groups', []);
        $this->assertBeginRejected('not enabled for this group and root');

        config()->set('nntmux.split_collection_terminal_pair_repair_groups', [self::GROUP]);
        config()->set('nntmux.split_collection_terminal_pair_repair_roots', []);
        $this->assertBeginRejected('not enabled for this group and root');

        config()->set('nntmux.split_collection_terminal_pair_repair_roots', [1]);
        DB::table('settings')->where('name', 'orchestrator_cf_permit')->update(['value' => '42']);
        $this->assertBeginRejected('requires idle backfill and current-forward inputs');

        DB::table('settings')->where('name', 'orchestrator_cf_permit')->update(['value' => '0']);
        DB::table('current_forward_windows')->insert([
            'id' => 2,
            'generation' => 42,
            'state' => 'CLAIMED',
            'chain_root_id' => 2,
            'chain_ordinal' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertBeginRejected('requires all current-forward input to be idle');
        DB::table('current_forward_windows')->where('id', 2)->delete();

        DB::table('current_forward_windows')->where('id', 1)->update(['state' => 'PRODUCTIVE']);
        self::assertNull(DB::transaction(
            fn () => (new CurrentForwardTerminalSplitRepair)->beginPairRepair(11, 10, $this->facts()),
        ));
        $this->assertNoEvidence();
    }

    public function test_unrelated_chained_window_does_not_block_repair_but_claimed_window_does(): void
    {
        $this->seedEligiblePair();
        $this->insertUnrelatedWindow(2, 'CLAIMED');
        $this->assertBeginRejected('requires all current-forward input to be idle');
        DB::table('current_forward_windows')->where('id', 2)->delete();
        $this->insertUnrelatedWindow(2, 'CHAINED');

        $this->completeRepair();
        self::assertSame(1, DB::table('current_forward_terminal_collection_repairs')->count());
    }

    public function test_chain_member_with_a_different_failure_reason_rejects_repair(): void
    {
        $this->seedEligiblePair();
        DB::table('current_forward_windows')->insert([
            'id' => 2,
            'generation' => 42,
            'state' => 'QUARANTINED',
            'chain_root_id' => 1,
            'parent_window_id' => 1,
            'chain_ordinal' => 2,
            'failure_reason' => 'current_forward_continuation_deadline',
            'settled_at' => now()->subMinute(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->assertBeginRejected('chain is not fully quarantined');
    }

    public function test_post_state_drift_rolls_back_merge_and_all_evidence(): void
    {
        $this->seedEligiblePair();

        try {
            DB::transaction(function (): void {
                $repair = new CurrentForwardTerminalSplitRepair;
                $context = $repair->beginPairRepair(11, 10, $this->facts());
                self::assertIsArray($context);
                DB::table('binaries')->where('collections_id', 11)->update(['collections_id' => 10]);
                DB::table('collections')->where('id', 11)->delete();
                DB::table('current_forward_windows')->where('id', 1)->update(['state' => 'PRODUCTIVE']);
                $repair->finishPairRepair($context);
            });
            self::fail('Post-state drift unexpectedly committed terminal split evidence.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('chain is not fully quarantined', $exception->getMessage());
        }

        self::assertDatabaseHas('current_forward_windows', ['id' => 1, 'state' => 'QUARANTINED']);
        self::assertTrue(DB::table('collections')->where('id', 11)->exists());
        self::assertSame(11, (int) DB::table('binaries')->where('id', 21)->value('collections_id'));
        $this->assertNoEvidence();
    }

    public function test_release_attribution_succeeds_idempotently_and_rejects_snapshot_drift(): void
    {
        $this->seedEligiblePair();
        $this->completeRepair();
        config()->set('nntmux.split_collection_terminal_pair_repair_groups', []);
        config()->set('nntmux.split_collection_terminal_pair_repair_roots', []);

        DB::transaction(function (): void {
            DB::table('releases')->insert([
                'id' => 100,
                'categories_id' => 5020,
                'nzbstatus' => 1,
                'size' => 1_100,
            ]);
            DB::table('collections')->where('id', 10)->update(['releases_id' => 100]);
            $repair = new CurrentForwardTerminalSplitRepair;
            self::assertTrue($repair->recordReleaseAttribution(10, 100));
            self::assertTrue($repair->recordReleaseAttribution(10, 100));
        });

        self::assertSame(1, DB::table('current_forward_terminal_release_attributions')->count());
        self::assertDatabaseHas('current_forward_terminal_release_attributions', [
            'release_id' => 100,
            'repair_id' => 1,
            'handoff_id' => 1,
            'chain_root_id' => 1,
            'window_id' => 1,
            'target_collection_id' => 10,
            'target_binary_count' => 2,
            'release_categories_id' => 5020,
            'release_nzbstatus' => 1,
            'release_size' => 1_100,
            'policy_version' => 'terminal-split-pair-repair-v1',
        ]);
        self::assertDatabaseHas('current_forward_object_owners', [
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => 100,
            'chain_root_id' => 1,
        ]);
        self::assertDatabaseHas('current_forward_window_objects', [
            'window_id' => 1,
            'chain_root_id' => 1,
            'object_type' => CurrentForwardWindowLineage::RELEASE,
            'object_id' => 100,
            'parent_object_id' => 10,
        ]);

        $evidenceHash = (string) DB::table('current_forward_terminal_release_attributions')
            ->where('release_id', 100)->value('evidence_hash');
        try {
            DB::transaction(function (): void {
                DB::table('releases')->where('id', 100)->update(['categories_id' => 5030]);
                (new CurrentForwardTerminalSplitRepair)->recordReleaseAttribution(10, 100);
            });
            self::fail('Release snapshot drift unexpectedly replaced immutable attribution evidence.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('evidence identity drift', $exception->getMessage());
        }
        self::assertSame(5020, (int) DB::table('releases')->where('id', 100)->value('categories_id'));
        self::assertSame($evidenceHash, (string) DB::table('current_forward_terminal_release_attributions')
            ->where('release_id', 100)->value('evidence_hash'));
        self::assertSame(1, DB::table('current_forward_terminal_release_attributions')->count());
    }

    public function test_transferred_target_collection_owner_rejects_attribution_and_rolls_back_release(): void
    {
        $this->assertTransferredAttributionRejected('collection_owner');
    }

    public function test_transferred_target_latest_membership_rejects_attribution_and_rolls_back_release(): void
    {
        $this->assertTransferredAttributionRejected('collection_membership');
    }

    public function test_transferred_binary_owner_rejects_attribution_and_rolls_back_release(): void
    {
        $this->assertTransferredAttributionRejected('binary_owner');
    }

    public function test_control_evidence_drift_between_repair_and_release_rejects_attribution(): void
    {
        $this->seedEligiblePair();
        $this->completeRepair();
        DB::table('current_forward_windows')->where('id', 1)->update(['updated_at' => now()->addSecond()]);

        try {
            DB::transaction(function (): void {
                DB::table('releases')->insert([
                    'id' => 100,
                    'categories_id' => 5020,
                    'nzbstatus' => 0,
                    'size' => 1_100,
                ]);
                DB::table('collections')->where('id', 10)->update(['releases_id' => 100]);
                (new CurrentForwardTerminalSplitRepair)->recordReleaseAttribution(10, 100);
            });
            self::fail('Control evidence drift unexpectedly admitted terminal release attribution.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('control evidence changed since repair', $exception->getMessage());
        }

        self::assertFalse(DB::table('releases')->where('id', 100)->exists());
        self::assertNull(DB::table('collections')->where('id', 10)->value('releases_id'));
        self::assertSame(0, DB::table('current_forward_terminal_release_attributions')->count());
    }

    private function completeRepair(): void
    {
        DB::transaction(function (): void {
            $repair = new CurrentForwardTerminalSplitRepair;
            $context = $repair->beginPairRepair(11, 10, $this->facts());
            self::assertIsArray($context);
            DB::table('binaries')->where('collections_id', 11)->update(['collections_id' => 10]);
            DB::table('collections')->where('id', 11)->delete();
            $repair->finishPairRepair($context);
        });
    }

    private function assertBeginRejected(string $message): void
    {
        try {
            DB::transaction(
                fn () => (new CurrentForwardTerminalSplitRepair)->beginPairRepair(11, 10, $this->facts()),
            );
            self::fail('Terminal split gate unexpectedly admitted the pair.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertNoEvidence();
    }

    private function assertTransferredAttributionRejected(string $drift): void
    {
        $this->seedEligiblePair();
        $this->completeRepair();
        $this->insertUnrelatedWindow(2, 'QUARANTINED');

        if ($drift === 'collection_owner') {
            DB::table('current_forward_object_owners')
                ->where('object_type', CurrentForwardWindowLineage::COLLECTION)
                ->where('object_id', 10)
                ->update(['chain_root_id' => 2]);
        } elseif ($drift === 'collection_membership') {
            DB::table('current_forward_window_objects')->insert([
                'window_id' => 2,
                'chain_root_id' => 2,
                'object_type' => CurrentForwardWindowLineage::COLLECTION,
                'object_id' => 10,
                'parent_object_id' => null,
                'inserted_parts' => 0,
                'created_in_window' => false,
                'touched_in_window' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('current_forward_object_owners')
                ->where('object_type', CurrentForwardWindowLineage::BINARY)
                ->where('object_id', 20)
                ->update(['chain_root_id' => 2]);
        }

        try {
            DB::transaction(function (): void {
                DB::table('releases')->insert([
                    'id' => 100,
                    'categories_id' => 5020,
                    'nzbstatus' => 1,
                    'size' => 1_100,
                ]);
                DB::table('collections')->where('id', 10)->update(['releases_id' => 100]);
                (new CurrentForwardTerminalSplitRepair)->recordReleaseAttribution(10, 100);
            });
            self::fail('Transferred terminal lineage identity unexpectedly accepted release attribution.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                $drift === 'binary_owner' ? 'binary ownership changed' : 'target ownership changed',
                $exception->getMessage(),
            );
        }

        self::assertFalse(DB::table('releases')->where('id', 100)->exists());
        self::assertNull(DB::table('collections')->where('id', 10)->value('releases_id'));
        self::assertSame(0, DB::table('current_forward_terminal_release_attributions')->count());
        self::assertFalse(DB::table('current_forward_object_owners')
            ->where('object_type', CurrentForwardWindowLineage::RELEASE)
            ->where('object_id', 100)
            ->exists());
        self::assertFalse(DB::table('current_forward_window_objects')
            ->where('object_type', CurrentForwardWindowLineage::RELEASE)
            ->where('object_id', 100)
            ->exists());
    }

    private function insertUnrelatedWindow(int $id, string $state): void
    {
        DB::table('current_forward_windows')->insert([
            'id' => $id,
            'generation' => 40 + $id,
            'state' => $state,
            'chain_root_id' => $id,
            'chain_ordinal' => 1,
            'failure_reason' => $state === 'QUARANTINED'
                ? 'current_forward_continuation_exhausted'
                : null,
            'settled_at' => $state === 'QUARANTINED' ? now()->subMinute() : null,
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
    }

    private function assertNoEvidence(): void
    {
        self::assertSame(0, DB::table('current_forward_collection_handoffs')->count());
        self::assertSame(0, DB::table('current_forward_terminal_collection_repairs')->count());
        self::assertSame(0, DB::table('current_forward_terminal_release_attributions')->count());
    }

    /** @return array{group:string,totalparts:int,span:int,gap:int,residual:int} */
    private function facts(): array
    {
        return [
            'group' => self::GROUP,
            'totalparts' => 7500,
            'span' => 3001,
            'gap' => 4499,
            'residual' => 0,
        ];
    }

    private function seedEligiblePair(bool $withOrphanRelease = false): void
    {
        DB::table('current_forward_windows')->insert([
            'id' => 1,
            'generation' => 41,
            'state' => 'QUARANTINED',
            'chain_root_id' => 1,
            'chain_ordinal' => 1,
            'failure_reason' => 'current_forward_continuation_admission_timeout',
            'settled_at' => now()->subMinute(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinute(),
        ]);
        DB::table('collections')->insert([
            ['id' => 10, 'totalfiles' => 2, 'filecheck' => 0, 'filesize' => 1_000, 'releases_id' => null],
            ['id' => 11, 'totalfiles' => 2, 'filecheck' => 0, 'filesize' => 100, 'releases_id' => null],
        ]);
        DB::table('binaries')->insert([
            ['id' => 20, 'collections_id' => 10, 'totalparts' => 7500, 'currentparts' => 7500],
            ['id' => 21, 'collections_id' => 11, 'totalparts' => 1, 'currentparts' => 1],
        ]);
        $objects = [
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'parent_object_id' => null],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 20, 'parent_object_id' => 10],
            ['window_id' => 1, 'chain_root_id' => 1, 'object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 21, 'parent_object_id' => 11],
        ];
        if ($withOrphanRelease) {
            $objects[] = [
                'window_id' => 1,
                'chain_root_id' => 1,
                'object_type' => CurrentForwardWindowLineage::RELEASE,
                'object_id' => 999,
                'parent_object_id' => 10,
            ];
        }
        DB::table('current_forward_window_objects')->insert(array_map(
            static fn (array $object): array => [
                ...$object,
                'inserted_parts' => 0,
                'created_in_window' => true,
                'touched_in_window' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            $objects,
        ));
        DB::table('current_forward_object_owners')->insert([
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 10, 'chain_root_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['object_type' => CurrentForwardWindowLineage::COLLECTION, 'object_id' => 11, 'chain_root_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 20, 'chain_root_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['object_type' => CurrentForwardWindowLineage::BINARY, 'object_id' => 21, 'chain_root_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach ([
            'orchestrator_bf_permit', 'orchestrator_bf_claimed', 'orchestrator_bf_completed', 'orchestrator_bf_failed',
            'orchestrator_cf_permit', 'orchestrator_cf_claimed', 'orchestrator_cf_completed', 'orchestrator_cf_failed',
        ] as $name) {
            DB::table('settings')->insert(['name' => $name, 'value' => '0']);
        }
    }

    private function createSchemas(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation')->nullable()->unique();
            $table->string('state', 32);
            $table->unsignedBigInteger('chain_root_id')->nullable();
            $table->unsignedBigInteger('parent_window_id')->nullable();
            $table->unsignedTinyInteger('chain_ordinal')->default(1);
            $table->dateTime('continuation_deadline_at')->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->timestamps();
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
            $table->unsignedTinyInteger('chain_ordinal')->default(1);
            $table->string('cohort_hash', 64)->default('');
            $table->dateTime('observed_at')->nullable();
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
            $table->smallInteger('nzbstatus')->default(0);
            $table->unsignedBigInteger('size')->default(0);
        });
    }
}
