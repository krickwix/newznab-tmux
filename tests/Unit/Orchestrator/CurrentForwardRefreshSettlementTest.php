<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardRefreshSettlement;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class CurrentForwardRefreshSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.backfill_min_target_byte_share' => 0.90,
            'nntmux.orchestrator.backfill_max_non_target_releases' => 1,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => 536_870_912,
            'nntmux.orchestrator.current_forward_settlement_grace_seconds' => 120,
            'nntmux.orchestrator.current_forward_zero_output_grace_seconds' => 300,
            'nntmux.orchestrator.current_forward_incomplete_grace_seconds' => 600,
        ]);
        DB::purge('sqlite');
        Schema::create('current_forward_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('group_name');
            $table->string('state', 32);
            $table->unsignedTinyInteger('strikes')->default(0);
            $table->unsignedBigInteger('last_productive_generation')->nullable();
            $table->unsignedBigInteger('last_productive_release_id')->nullable();
            $table->dateTime('last_productive_at')->nullable();
            $table->string('last_reason', 120)->nullable();
            $table->timestamps();
        });
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('generation')->nullable()->unique();
            $table->string('state', 32);
            $table->unsignedBigInteger('release_baseline')->nullable();
            $table->dateTime('cursor_postdate')->nullable();
            $table->dateTime('cursor_end_postdate')->nullable();
            $table->unsignedInteger('outcome_releases')->nullable();
            $table->unsignedInteger('outcome_ready_nzbs')->nullable();
            $table->unsignedBigInteger('outcome_target_bytes')->nullable();
            $table->unsignedBigInteger('outcome_non_target_bytes')->nullable();
            $table->unsignedBigInteger('outcome_release_high')->nullable();
            $table->unsignedInteger('outcome_pending_collections')->nullable();
            $table->string('observation_hash', 64)->nullable();
            $table->dateTime('observation_stable_since_at')->nullable();
            $table->dateTime('last_observed_at')->nullable();
            $table->dateTime('attribution_started_at')->nullable();
            $table->dateTime('zero_output_deadline_at')->nullable();
            $table->dateTime('drain_deadline_at')->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->dateTime('ingested_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->unsignedBigInteger('chain_root_id')->nullable();
            $table->unsignedBigInteger('parent_window_id')->nullable();
            $table->unsignedTinyInteger('chain_ordinal')->nullable()->default(1);
            $table->dateTime('continuation_deadline_at')->nullable();
            $table->timestamps();
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
        });
        Schema::create('current_forward_object_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('object_type', 16);
            $table->unsignedBigInteger('object_id');
            $table->unsignedBigInteger('chain_root_id');
            $table->timestamps();
            $table->unique(['object_type', 'object_id']);
        });
        Schema::create('current_forward_continuation_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('window_id');
            $table->unsignedBigInteger('chain_root_id');
            $table->unsignedTinyInteger('chain_ordinal');
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
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('totalfiles')->default(1);
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

    public function test_ingested_window_enters_attribution_before_outcome_is_observed(): void
    {
        $windowId = $this->window('INGESTED');
        $repository = Mockery::mock(PipelineSnapshotRepository::class);

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('pending', $result['status']);
        self::assertSame('current_forward_attribution_started', $result['reason']);
        self::assertSame('ATTRIBUTING', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
        self::assertNotNull(DB::table('current_forward_windows')->where('id', $windowId)->value('zero_output_deadline_at'));
        self::assertNotNull(DB::table('current_forward_windows')->where('id', $windowId)->value('drain_deadline_at'));
    }

    public function test_stable_fully_drained_target_nzbs_mark_the_window_productive(): void
    {
        $windowId = $this->window('ATTRIBUTING', updatedAt: now()->subSeconds(121), outcomes: [3, 3, 3_000, 0]);
        $repository = $this->repository(
            releases: 3,
            pendingCollections: 0,
            counts: ['target' => 3, 'non_target' => 0, 'uncategorized' => 0],
            bytes: ['target' => 3_000, 'non_target' => 0, 'uncategorized' => 0],
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('productive', $result['status']);
        self::assertSame(3, $result['ready_nzbs']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'PRODUCTIVE',
            'outcome_releases' => 3,
            'outcome_ready_nzbs' => 3,
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'READY',
            'strikes' => 0,
            'last_productive_generation' => 42,
        ]);
    }

    public function test_pending_collections_keep_the_window_attributing(): void
    {
        $windowId = $this->window('ATTRIBUTING', updatedAt: now()->subHour());
        $repository = $this->repository(
            releases: 1,
            pendingCollections: 1,
            counts: ['target' => 1, 'non_target' => 0, 'uncategorized' => 0],
            bytes: ['target' => 1_000, 'non_target' => 0, 'uncategorized' => 0],
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('pending', $result['status']);
        self::assertSame('current_forward_cohort_draining', $result['reason']);
        self::assertSame('ATTRIBUTING', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
    }

    public function test_pending_collections_after_grace_quarantine_the_window(): void
    {
        $windowId = $this->window(
            'ATTRIBUTING',
            ingestedAt: now()->subSeconds(601),
            updatedAt: now()->subSeconds(601),
        );
        $repository = $this->repository(
            releases: 1,
            pendingCollections: 1,
            counts: ['target' => 1, 'non_target' => 0, 'uncategorized' => 0],
            bytes: ['target' => 1_000, 'non_target' => 0, 'uncategorized' => 0],
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_cohort_drain_timeout', $result['reason']);
        self::assertSame('QUARANTINED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
    }

    public function test_operator_quality_lock_is_not_revived_by_productive_settlement(): void
    {
        $windowId = $this->window('ATTRIBUTING', updatedAt: now()->subSeconds(121), outcomes: [3, 3, 3_000, 0]);
        DB::table('current_forward_sources')->update([
            'state' => 'QUALITY_LOCKED',
            'strikes' => 2,
        ]);
        $repository = $this->repository(
            releases: 3,
            pendingCollections: 0,
            counts: ['target' => 3, 'non_target' => 0, 'uncategorized' => 0],
            bytes: ['target' => 3_000, 'non_target' => 0, 'uncategorized' => 0],
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_source_locked_at_settlement', $result['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'QUARANTINED',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'QUALITY_LOCKED',
            'strikes' => 2,
        ]);
    }

    public function test_zero_output_after_grace_quarantines_and_strikes_the_source(): void
    {
        $windowId = $this->window('ATTRIBUTING', ingestedAt: now()->subSeconds(301), updatedAt: now()->subSeconds(301));
        $repository = $this->repository(
            releases: 0,
            pendingCollections: 0,
            counts: ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
            bytes: ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_zero_output', $result['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_zero_output',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'READY',
            'strikes' => 1,
        ]);
    }

    public function test_release_accounting_mismatch_after_grace_quarantines_the_window(): void
    {
        $windowId = $this->window(
            'ATTRIBUTING',
            ingestedAt: now()->subSeconds(601),
            updatedAt: now()->subSeconds(601),
        );
        $repository = $this->repository(
            releases: 1,
            pendingCollections: 0,
            counts: ['target' => 2, 'non_target' => 0, 'uncategorized' => 0],
            bytes: ['target' => 2_000, 'non_target' => 0, 'uncategorized' => 0],
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_release_accounting_timeout', $result['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_release_accounting_timeout',
        ]);
    }

    public function test_release_without_ready_nzb_after_grace_quarantines_the_window(): void
    {
        $windowId = $this->window(
            'ATTRIBUTING',
            ingestedAt: now()->subSeconds(601),
            updatedAt: now()->subSeconds(601),
        );
        $repository = $this->repository(
            releases: 2,
            pendingCollections: 0,
            counts: ['target' => 1, 'non_target' => 0, 'uncategorized' => 0],
            bytes: ['target' => 1_000, 'non_target' => 0, 'uncategorized' => 0],
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_incomplete_after_grace', $result['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_incomplete_after_grace',
        ]);
    }

    public function test_productive_settlement_is_terminal_and_idempotent(): void
    {
        $windowId = $this->window('ATTRIBUTING', updatedAt: now()->subSeconds(121), outcomes: [3, 3, 3_000, 0]);
        $repository = $this->repository(
            releases: 3,
            pendingCollections: 0,
            counts: ['target' => 3, 'non_target' => 0, 'uncategorized' => 0],
            bytes: ['target' => 3_000, 'non_target' => 0, 'uncategorized' => 0],
        );
        $settlement = new CurrentForwardRefreshSettlement($repository);
        self::assertSame('productive', $settlement->settle($this->safeSnapshot(), time())['status']);

        $retry = $settlement->settle($this->safeSnapshot(), time());

        self::assertSame('none', $retry['status']);
        self::assertSame('current_forward_no_unsettled_ingest', $retry['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'PRODUCTIVE',
            'outcome_releases' => 3,
            'outcome_ready_nzbs' => 3,
        ]);
    }

    public function test_late_output_in_final_confirmation_resets_the_stability_hold(): void
    {
        $windowId = $this->window('ATTRIBUTING', updatedAt: now()->subSeconds(121), outcomes: [3, 3, 3_000, 0]);
        $first = [
            'release_count' => 3,
            'release_high' => 103,
            'pending_collections' => 0,
            'counts' => ['target' => 3, 'non_target' => 0, 'uncategorized' => 0],
            'bytes' => ['target' => 3_000, 'non_target' => 0, 'uncategorized' => 0],
        ];
        $second = [
            'release_count' => 4,
            'release_high' => 104,
            'pending_collections' => 0,
            'counts' => ['target' => 4, 'non_target' => 0, 'uncategorized' => 0],
            'bytes' => ['target' => 4_000, 'non_target' => 0, 'uncategorized' => 0],
        ];
        $repository = Mockery::mock(PipelineSnapshotRepository::class);
        $observations = [
            [...$first, 'hash' => hash('sha256', (string) json_encode($first, JSON_THROW_ON_ERROR))],
            [...$second, 'hash' => hash('sha256', (string) json_encode($second, JSON_THROW_ON_ERROR))],
        ];
        $repository->shouldReceive('currentForwardCohortObservation')
            ->twice()
            ->andReturnUsing(function () use (&$observations): array {
                self::assertSame(0, DB::connection()->transactionLevel());

                return array_shift($observations);
            });

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('pending', $result['status']);
        self::assertSame('current_forward_productive_stabilizing', $result['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'ATTRIBUTING',
            'outcome_release_high' => 104,
        ]);
    }

    public function test_pipeline_safety_timeout_quarantines_without_a_quality_strike(): void
    {
        $windowId = $this->window('ATTRIBUTING', ingestedAt: now()->subSeconds(601));
        $repository = Mockery::mock(PipelineSnapshotRepository::class);
        $unsafe = new PipelineSnapshot(
            100,
            10,
            2,
            1,
            0,
            lowPressure: false,
            databaseCurrentWaits: 1,
            databaseAdmissionSafe: false,
            eligibleNzbs: 1,
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($unsafe, time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_pipeline_settlement_timeout', $result['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'QUARANTINED',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'strikes' => 0,
        ]);
    }

    public function test_lineage_integrity_failure_preempts_generic_pipeline_timeout(): void
    {
        config()->set('nntmux.orchestrator.current_forward_continuation_enabled', true);
        $windowId = $this->window('ATTRIBUTING', ingestedAt: now()->subSeconds(601));
        DB::table('current_forward_windows')->where('id', $windowId)->update(['chain_root_id' => $windowId]);
        DB::table('current_forward_window_objects')->insert([
            'window_id' => $windowId,
            'chain_root_id' => $windowId,
            'object_type' => 'RELEASE',
            'object_id' => 100,
            'parent_object_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repository = Mockery::mock(PipelineSnapshotRepository::class);
        $unsafe = new PipelineSnapshot(
            100,
            10,
            2,
            1,
            0,
            lowPressure: false,
            databaseCurrentWaits: 1,
            databaseAdmissionSafe: false,
            eligibleNzbs: 1,
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($unsafe, time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_lineage_integrity_failure', $result['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_lineage_integrity_failure',
        ]);
    }

    public function test_delayed_first_settlement_does_not_grant_a_new_grace_period_after_restart(): void
    {
        $ingestedAt = now()->subSeconds(901);
        $windowId = $this->window('INGESTED', ingestedAt: $ingestedAt);
        $repository = $this->repository(
            releases: 0,
            pendingCollections: 0,
            counts: ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
            bytes: ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
        );
        $settlement = new CurrentForwardRefreshSettlement($repository);

        self::assertSame('pending', $settlement->settle($this->safeSnapshot(), time())['status']);
        $window = DB::table('current_forward_windows')->where('id', $windowId)->first();
        self::assertSame(
            $ingestedAt->copy()->addSeconds(300)->format('Y-m-d H:i:s'),
            (string) $window->zero_output_deadline_at,
        );
        self::assertSame(
            $ingestedAt->copy()->addSeconds(600)->format('Y-m-d H:i:s'),
            (string) $window->drain_deadline_at,
        );

        $result = $settlement->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_zero_output', $result['reason']);
    }

    public function test_expired_pending_continuation_is_terminalized_without_a_quality_strike(): void
    {
        config()->set('nntmux.orchestrator.current_forward_continuation_enabled', true);
        $windowId = $this->window('CONTINUATION_PENDING');
        DB::table('current_forward_windows')->where('id', $windowId)->update([
            'chain_root_id' => $windowId,
            'chain_ordinal' => 1,
            'continuation_deadline_at' => now()->subSecond(),
        ]);
        $repository = Mockery::mock(PipelineSnapshotRepository::class);

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_continuation_admission_timeout', $result['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_continuation_admission_timeout',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'READY',
            'strikes' => 0,
            'last_reason' => 'current_forward_continuation_admission_timeout',
        ]);
    }

    public function test_expired_continuation_terminalizes_an_active_child_without_a_quality_strike(): void
    {
        config()->set('nntmux.orchestrator.current_forward_continuation_enabled', true);
        $rootId = $this->window('CONTINUATION_PENDING');
        $root = DB::table('current_forward_windows')->where('id', $rootId)->first();
        DB::table('current_forward_windows')->where('id', $rootId)->update([
            'chain_root_id' => $rootId,
            'chain_ordinal' => 1,
            'continuation_deadline_at' => now()->subSecond(),
        ]);
        $childId = (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $root->source_id,
            'generation' => 43,
            'state' => 'INGESTED',
            'release_baseline' => 100,
            'cursor_postdate' => '2026-07-17 10:05:00',
            'cursor_end_postdate' => '2026-07-17 10:10:00',
            'ingested_at' => now(),
            'chain_root_id' => $rootId,
            'parent_window_id' => $rootId,
            'chain_ordinal' => 2,
            'continuation_deadline_at' => now()->subSecond(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repository = Mockery::mock(PipelineSnapshotRepository::class);

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        foreach ([$rootId, $childId] as $windowId) {
            $this->assertDatabaseHas('current_forward_windows', [
                'id' => $windowId,
                'state' => 'QUARANTINED',
                'failure_reason' => 'current_forward_continuation_admission_timeout',
            ]);
        }
        $this->assertDatabaseHas('current_forward_sources', [
            'id' => $root->source_id,
            'state' => 'READY',
            'strikes' => 0,
        ]);
    }

    public function test_disabling_continuation_immediately_terminalizes_an_open_chain(): void
    {
        config()->set('nntmux.orchestrator.current_forward_continuation_enabled', false);
        $rootId = $this->window('CONTINUATION_PENDING');
        DB::table('current_forward_windows')->where('id', $rootId)->update([
            'chain_root_id' => $rootId,
            'chain_ordinal' => 1,
            'continuation_deadline_at' => now()->addHour(),
        ]);
        $repository = Mockery::mock(PipelineSnapshotRepository::class);

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_continuation_disabled', $result['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $rootId,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_continuation_disabled',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'READY',
            'strikes' => 0,
        ]);
    }

    public function test_terminal_quarantine_closes_every_member_of_a_three_window_chain(): void
    {
        config()->set('nntmux.orchestrator.current_forward_continuation_enabled', true);
        Schema::drop('current_forward_continuation_observations');
        $rootId = $this->window('CONTINUATION_PENDING');
        DB::table('current_forward_windows')->where('id', $rootId)->update([
            'generation' => 40,
            'chain_root_id' => $rootId,
            'chain_ordinal' => 1,
            'continuation_deadline_at' => now()->addHour(),
        ]);
        $root = DB::table('current_forward_windows')->where('id', $rootId)->first();
        $childId = (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $root->source_id,
            'generation' => 41,
            'state' => 'CHAINED',
            'chain_root_id' => $rootId,
            'parent_window_id' => $rootId,
            'chain_ordinal' => 2,
            'continuation_deadline_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $currentId = (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $root->source_id,
            'generation' => 42,
            'state' => 'ATTRIBUTING',
            'release_baseline' => 100,
            'cursor_postdate' => '2026-07-17 10:05:00',
            'cursor_end_postdate' => '2026-07-17 10:10:00',
            'ingested_at' => now()->subHour(),
            'drain_deadline_at' => now()->subSecond(),
            'chain_root_id' => $rootId,
            'parent_window_id' => $childId,
            'chain_ordinal' => 3,
            'continuation_deadline_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repository = $this->repository(
            releases: 1,
            pendingCollections: 0,
            counts: ['target' => 0, 'non_target' => 1, 'uncategorized' => 0],
            bytes: ['target' => 0, 'non_target' => 1_000, 'uncategorized' => 0],
        );

        $result = (new CurrentForwardRefreshSettlement($repository))->settle($this->safeSnapshot(), time());

        self::assertSame('quarantined', $result['status']);
        self::assertSame('current_forward_wrong_category', $result['reason']);
        foreach ([$rootId, $childId, $currentId] as $windowId) {
            $this->assertDatabaseHas('current_forward_windows', [
                'id' => $windowId,
                'state' => 'QUARANTINED',
                'failure_reason' => 'current_forward_wrong_category',
            ]);
        }
    }

    /** @param array{int,int,int,int}|null $outcomes */
    private function window(
        string $state,
        mixed $ingestedAt = null,
        mixed $updatedAt = null,
        ?array $outcomes = null,
    ): int {
        $sourceId = DB::table('current_forward_sources')->insertGetId([
            'group_name' => 'alt.test',
            'state' => 'READY',
            'strikes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $sourceId,
            'generation' => 42,
            'state' => $state,
            'release_baseline' => 100,
            'cursor_postdate' => '2026-07-17 10:00:00',
            'cursor_end_postdate' => '2026-07-17 10:05:00',
            'outcome_releases' => $outcomes[0] ?? null,
            'outcome_ready_nzbs' => $outcomes[1] ?? null,
            'outcome_target_bytes' => $outcomes[2] ?? null,
            'outcome_non_target_bytes' => $outcomes[3] ?? null,
            'outcome_release_high' => $outcomes === null ? null : 100 + $outcomes[0],
            'outcome_pending_collections' => $outcomes === null ? null : 0,
            'observation_hash' => $outcomes === null ? null : $this->observationHash(
                $outcomes[0],
                0,
                ['target' => $outcomes[1], 'non_target' => 0, 'uncategorized' => 0],
                ['target' => $outcomes[2], 'non_target' => $outcomes[3], 'uncategorized' => 0],
            ),
            'observation_stable_since_at' => $outcomes === null ? null : ($updatedAt ?? now()->subMinute()),
            'ingested_at' => $ingestedAt ?? now()->subMinute(),
            'created_at' => now()->subHour(),
            'updated_at' => $updatedAt ?? now()->subMinute(),
        ]);
    }

    /**
     * @param  array{target:int,non_target:int,uncategorized:int}  $counts
     * @param  array{target:int,non_target:int,uncategorized:int}  $bytes
     */
    private function repository(
        int $releases,
        int $pendingCollections,
        array $counts,
        array $bytes,
    ): PipelineSnapshotRepository {
        $repository = Mockery::mock(PipelineSnapshotRepository::class);
        $repository->shouldReceive('currentForwardCohortObservation')->atLeast()->once()->andReturn([
            'release_count' => $releases,
            'release_high' => 100 + $releases,
            'pending_collections' => $pendingCollections,
            'counts' => $counts,
            'bytes' => $bytes,
            'hash' => $this->observationHash($releases, $pendingCollections, $counts, $bytes),
        ]);

        return $repository;
    }

    /**
     * @param  array{target:int,non_target:int,uncategorized:int}  $counts
     * @param  array{target:int,non_target:int,uncategorized:int}  $bytes
     */
    private function observationHash(int $releases, int $pendingCollections, array $counts, array $bytes): string
    {
        return hash('sha256', (string) json_encode([
            'release_count' => $releases,
            'release_high' => 100 + $releases,
            'pending_collections' => $pendingCollections,
            'counts' => $counts,
            'bytes' => $bytes,
        ], JSON_THROW_ON_ERROR));
    }

    private function safeSnapshot(): PipelineSnapshot
    {
        return new PipelineSnapshot(
            100,
            10,
            2,
            0,
            0,
            lowPressure: true,
            databaseCurrentWaits: 0,
            databaseAdmissionSafe: true,
            eligibleNzbs: 0,
        );
    }
}
