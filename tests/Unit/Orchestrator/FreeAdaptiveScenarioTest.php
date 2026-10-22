<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Models\Settings;
use App\Services\Distributed\BackfillPermitGate;
use App\Services\Orchestrator\FreeAdaptiveBackfillGovernor;
use App\Services\Orchestrator\FreeAdaptiveState;
use App\Services\Orchestrator\FreeAdaptiveStateStore;
use App\Services\Orchestrator\PipelineSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * End to end through the settings-backed state store: a permit completion
 * stamps last_done_at, the governor climbs to a plateau while the backlog
 * grows, decays to the minimum while it shrinks, and the gate opens exactly at
 * last_done_at + delay throughout.
 */
final class FreeAdaptiveScenarioTest extends TestCase
{
    private const int CYCLE = 60;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.high_watermarks' => [
                'parts' => 1_000_000,
                'binaries' => 1_000_000,
                'collections' => 600_000,
            ],
            'nntmux.orchestrator.free_adaptive' => [
                'initial_delay_seconds' => 120,
                'min_delay_seconds' => 0,
                'max_delay_seconds' => 3600,
                'increase_factor' => 1.5,
                'increase_step_seconds' => 30,
                'decrease_factor' => 0.8,
                'snap_to_min_below_seconds' => 10,
                'grow_threshold' => 1.0,
                'shrink_threshold' => 0.9,
                'horizon_minutes' => 30,
                'settle_seconds' => 300,
                'stale_seconds' => 180,
                'stages' => 'parts,binaries,collections',
            ],
        ]);
        DB::purge('sqlite');
        Settings::forgetMemoizedSettings();
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation')->nullable()->unique();
            $table->string('state', 32);
        });
    }

    private function snapshot(int $collections, float $ewma, int $now): PipelineSnapshot
    {
        return new PipelineSnapshot(
            partsBacklog: 0,
            binariesBacklog: 0,
            collectionsBacklog: $collections,
            releasesBacklog: 0,
            nzbsBacklog: 0,
            observedAt: $now,
            backlogEwmaPerMinute: ['parts' => 0.0, 'binaries' => 0.0, 'collections' => $ewma],
        );
    }

    private function completeOnePermit(): int
    {
        Settings::query()->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_profile', 'value' => 'free_adaptive'],
            ['name' => 'orchestrator_lease_until', 'value' => (string) (time() + 600)],
            ['name' => 'orchestrator_bf_paused', 'value' => '0'],
            ['name' => 'orchestrator_bf_permit', 'value' => '17'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '0'],
            ['name' => 'orchestrator_bf_group', 'value' => 'alt.test'],
            ['name' => 'orchestrator_bf_qty', 'value' => '100000'],
        ]);
        $gate = new BackfillPermitGate;
        self::assertSame(17, $gate->claimGeneration());
        $before = time();
        self::assertTrue($gate->complete(17));
        Settings::forgetCachedSettings();

        $stamped = (new FreeAdaptiveStateStore)->read()->lastDoneAt;
        self::assertGreaterThanOrEqual($before, $stamped);
        self::assertLessThanOrEqual(time(), $stamped);

        return $stamped;
    }

    private function tick(FreeAdaptiveBackfillGovernor $governor, int $collections, float $ewma, int $now, bool $entering = false): FreeAdaptiveState
    {
        $state = $governor->tick($this->snapshot($collections, $ewma, $now), $entering, $now);
        Settings::forgetCachedSettings();
        // What the governor returns is what was persisted (display floats are rounded).
        $stored = (new FreeAdaptiveStateStore)->read();
        self::assertSame(
            [$state->delay, $state->updatedAt, $state->checkedAt, $state->lastDoneAt, $state->reason, $state->bindingStage],
            [$stored->delay, $stored->updatedAt, $stored->checkedAt, $stored->lastDoneAt, $stored->reason, $stored->bindingStage],
            'tick at '.$now,
        );
        self::assertEqualsWithDelta($state->projected, $stored->projected, 0.0001);
        self::assertNotSame('governor_error', $state->reason);

        return $state;
    }

    private function assertGateOpensExactlyAt(FreeAdaptiveState $state): void
    {
        $opensAt = $state->lastDoneAt + $state->delay;
        self::assertSame($opensAt, $state->nextEligibleAt());
        if ($state->delay > 0) {
            self::assertFalse($state->gateOpen($opensAt - 1), 'delay '.$state->delay);
        }
        self::assertTrue($state->gateOpen($opensAt), 'delay '.$state->delay);
    }

    public function test_the_delay_climbs_to_a_plateau_then_decays_to_the_minimum(): void
    {
        $lastDone = $this->completeOnePermit();
        $governor = new FreeAdaptiveBackfillGovernor;
        $now = $lastDone;

        $entered = $this->tick($governor, 649_000, 3_400.0, $now, entering: true);
        self::assertSame(120, $entered->delay);
        self::assertSame('entered', $entered->reason);
        self::assertSame($lastDone, $entered->lastDoneAt);
        $this->assertGateOpensExactlyAt($entered);

        // Growing: one change per settle window, x1.5 each time, clamped at max.
        $delays = [$entered->delay];
        $changedAt = [$entered->updatedAt];
        for ($i = 0; $i < 60; $i++) {
            $now += self::CYCLE;
            $state = $this->tick($governor, 649_000, 3_400.0, $now);
            self::assertSame($lastDone, $state->lastDoneAt);
            $this->assertGateOpensExactlyAt($state);
            if ($state->delay !== end($delays)) {
                $delays[] = $state->delay;
                $changedAt[] = $state->updatedAt;
            }
        }
        self::assertSame([120, 180, 270, 405, 608, 912, 1368, 2052, 3078, 3600], $delays);
        for ($i = 1; $i < count($changedAt); $i++) {
            self::assertGreaterThanOrEqual(300, $changedAt[$i] - $changedAt[$i - 1]);
        }
        self::assertSame(3600, $state->delay);
        self::assertSame('collections', $state->bindingStage);

        // A completion during the plateau re-anchors the gate.
        $now += self::CYCLE;
        Settings::query()->updateOrCreate(['name' => FreeAdaptiveStateStore::LAST_DONE_AT], ['value' => (string) $now]);
        Settings::forgetCachedSettings();
        $state = $this->tick($governor, 649_000, 3_400.0, $now);
        self::assertSame($now, $state->lastDoneAt);
        self::assertFalse($state->gateOpen($now + 3_599));
        self::assertTrue($state->gateOpen($now + 3_600));

        // Shrinking: x0.8 per settle window until it snaps to the minimum.
        $previous = $state->delay;
        $reachedMinAt = null;
        for ($i = 0; $i < 200; $i++) {
            $now += self::CYCLE;
            $state = $this->tick($governor, 100_000, -500.0, $now);
            $this->assertGateOpensExactlyAt($state);
            self::assertLessThanOrEqual($previous, $state->delay);
            if ($state->delay < $previous) {
                self::assertSame((int) floor($previous * 0.8) < 10 ? 0 : (int) floor($previous * 0.8), $state->delay);
            }
            $previous = $state->delay;
            if ($state->delay === 0 && $reachedMinAt === null) {
                $reachedMinAt = $now;
            }
        }
        self::assertNotNull($reachedMinAt, 'the delay never decayed to the minimum');
        self::assertSame(0, $state->delay);
        // At the minimum the gate is open from the last completion onward.
        self::assertTrue($state->gateOpen($state->lastDoneAt));
    }

    public function test_a_metrics_outage_only_lengthens_the_delay(): void
    {
        $governor = new FreeAdaptiveBackfillGovernor;
        $now = time();
        $state = $this->tick($governor, 100_000, -500.0, $now, entering: true);
        self::assertSame(120, $state->delay);

        $previous = $state->delay;
        for ($i = 0; $i < 20; $i++) {
            $now += self::CYCLE;
            // A snapshot that stopped updating: observedAt frozen in the past.
            $state = $governor->tick($this->snapshot(100_000, -500.0, $now - 3_600), false, $now);
            self::assertGreaterThanOrEqual($previous, $state->delay);
            $previous = $state->delay;
        }
        self::assertGreaterThan(120, $state->delay);
        self::assertSame('metrics_stale', $state->reason);
    }
}
