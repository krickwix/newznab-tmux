<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class WorkerControlStateStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
        ]);
        Cache::store('array')->flush();
    }

    public function test_it_round_trips_all_control_state_fields(): void
    {
        $store = new WorkerControlStateStore;
        $state = new ControlState(
            profile: ControlProfile::Fill,
            consecutiveHigh: 3,
            consecutiveLow: 5,
            lastTransitionAt: 1_000,
            cooldownUntil: 2_000,
            consecutiveIneffectiveBackfillPermits: 1,
            backfillLocked: true,
        );

        $store->storeState($state);

        self::assertEquals($state, $store->loadState());
    }

    public function test_it_round_trips_the_snapshot_projection_used_for_delta_calculation(): void
    {
        $store = new WorkerControlStateStore;
        $store->storeSnapshot(new PipelineSnapshot(
            partsBacklog: 11,
            binariesBacklog: 22,
            collectionsBacklog: 33,
            releasesBacklog: 44,
            nzbsBacklog: 55,
            databaseDeadlocks: 6,
            observedAt: 7,
        ));

        self::assertSame([
            'parts' => 11,
            'binaries' => 22,
            'collections' => 33,
            'releases' => 44,
            'nzbs' => 55,
            'database_deadlocks' => 6,
            'observed_at' => 7,
        ], $store->previousSnapshot());
    }

    public function test_it_round_trips_and_clears_a_backfill_permit_observation(): void
    {
        $store = new WorkerControlStateStore;
        $store->beginPermitObservation(new PipelineSnapshot(
            partsBacklog: 11,
            binariesBacklog: 22,
            collectionsBacklog: 33,
            releasesBacklog: 44,
            nzbsBacklog: 55,
            readyCollections: 66,
            releaseTotal: 77,
            backfillGroup: 'alt.test',
            backfillCursor: 12345,
        ), generation: 8, now: 9, outcome: [
            'cursor' => 12345,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 66,
            'releases' => 77,
            'release_high_watermark' => 88,
        ]);

        self::assertSame([
            'generation' => 8,
            'issued_at' => 9,
            'parts' => 11,
            'binaries' => 22,
            'ready_collections' => 66,
            'release_total' => 77,
            'release_high_watermark' => 88,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 12345,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], $store->permitObservation());

        $store->clearPermitObservation();

        self::assertNull($store->permitObservation());
    }

    public function test_missing_state_uses_the_conservative_initial_profile(): void
    {
        self::assertEquals(ControlState::initial(), (new WorkerControlStateStore)->loadState());
        self::assertNull((new WorkerControlStateStore)->previousSnapshot());
    }

    public function test_it_publishes_the_last_decision_for_metrics(): void
    {
        $decision = ['mode' => 'shadow', 'profile' => 'drain', 'observed_at' => 123];

        (new WorkerControlStateStore)->storeDecision($decision);

        self::assertSame($decision, Cache::store('array')->get(WorkerControlStateStore::DECISION_KEY));
    }

    public function test_it_records_cursor_normalized_target_nzb_yield(): void
    {
        $store = new WorkerControlStateStore;

        $store->recordBackfillYield('alt.test', cursorDelta: 10_000, nzbCreatedDelta: 5, now: 1_000);
        $store->recordBackfillYield('alt.test', cursorDelta: 10_000, nzbCreatedDelta: 0, now: 2_000);

        self::assertSame([
            'alt.test' => [
                'attempts' => 2,
                'ewma_nzbs_per_10k' => 2.5,
                'last_attempt_at' => 2_000,
                'last_effective_at' => 1_000,
            ],
        ], $store->backfillYieldHistory());
    }

    public function test_zero_cursor_movement_never_scores_output(): void
    {
        $store = new WorkerControlStateStore;

        $store->recordBackfillYield('alt.test', cursorDelta: 0, nzbCreatedDelta: 5, now: 1_000);

        self::assertSame(0.0, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k']);
        self::assertSame(0, $store->backfillYieldHistory()['alt.test']['last_effective_at']);
    }

    public function test_yield_history_is_bounded_to_the_sixteen_most_recent_groups(): void
    {
        $store = new WorkerControlStateStore;

        for ($group = 1; $group <= 17; $group++) {
            $store->recordBackfillYield('alt.test.'.$group, 10_000, 1, $group);
        }

        $history = $store->backfillYieldHistory();
        self::assertCount(16, $history);
        self::assertArrayNotHasKey('alt.test.1', $history);
        self::assertArrayHasKey('alt.test.17', $history);
    }
}
