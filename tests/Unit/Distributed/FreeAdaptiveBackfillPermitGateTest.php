<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Models\Settings;
use App\Services\Distributed\BackfillPermitGate;
use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Orchestrator\FreeAdaptiveStateStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The worker side of free_adaptive: completion and failure anchor the delay,
 * no successor is ever chained, and the free-run kill-switch bypass applies.
 */
class FreeAdaptiveBackfillPermitGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
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

    private function activePermit(string $profile, int $permit): void
    {
        Settings::query()->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_profile', 'value' => $profile],
            ['name' => 'orchestrator_lease_until', 'value' => (string) (time() + 600)],
            ['name' => 'orchestrator_bf_paused', 'value' => '0'],
            ['name' => 'orchestrator_bf_permit', 'value' => (string) $permit],
            ['name' => 'orchestrator_bf_claimed', 'value' => '0'],
            ['name' => 'orchestrator_bf_group', 'value' => 'alt.test'],
            ['name' => 'orchestrator_bf_qty', 'value' => '100000'],
        ]);
    }

    private function lastDoneAt(): ?int
    {
        Settings::forgetCachedSettings();
        $value = Settings::settingValue(FreeAdaptiveStateStore::LAST_DONE_AT);

        return $value === null ? null : (int) $value;
    }

    public function test_complete_stamps_the_free_adaptive_anchor(): void
    {
        $this->activePermit('free_adaptive', 17);
        $gate = new BackfillPermitGate;
        self::assertSame(17, $gate->claimGeneration());
        self::assertSame('free_adaptive', Settings::settingValue('orchestrator_bfc_profile'));
        self::assertNull($this->lastDoneAt());

        $before = time();
        self::assertTrue($gate->complete(17));

        self::assertGreaterThanOrEqual($before, $this->lastDoneAt());
        self::assertLessThanOrEqual(time(), $this->lastDoneAt());
    }

    /** Stamped on failure too, so a failing permit cannot hot-loop at zero delay. */
    public function test_fail_stamps_the_free_adaptive_anchor(): void
    {
        $this->activePermit('free_adaptive', 17);
        $gate = new BackfillPermitGate;
        self::assertSame(17, $gate->claimGeneration());

        $before = time();
        self::assertTrue($gate->fail(17, 'provider failed'));

        self::assertGreaterThanOrEqual($before, $this->lastDoneAt());
    }

    public function test_a_stale_completion_does_not_move_the_anchor(): void
    {
        $this->activePermit('free_adaptive', 17);
        Settings::query()->insert(['name' => FreeAdaptiveStateStore::LAST_DONE_AT, 'value' => '123']);
        $gate = new BackfillPermitGate;
        self::assertSame(17, $gate->claimGeneration());

        self::assertFalse($gate->complete(18));
        self::assertFalse($gate->fail(18, 'not mine'));

        self::assertSame(123, $this->lastDoneAt());
    }

    public function test_the_anchor_is_written_for_every_profile(): void
    {
        $this->activePermit('balanced', 17);
        $gate = new BackfillPermitGate;
        self::assertSame(17, $gate->claimGeneration());

        self::assertTrue($gate->complete(17));

        self::assertNotNull($this->lastDoneAt());
    }

    /**
     * Same fixture, two profiles: free_run chains (positive control, so the
     * free_adaptive null is not vacuous) and free_adaptive never does.
     */
    public function test_no_successor_is_chained_under_free_adaptive_while_free_run_still_chains(): void
    {
        config()->set('nntmux.orchestrator.backfill_days_override', 1);
        config()->set('nntmux.orchestrator.backfill_probe_groups', ['alt.test']);
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active');
            $table->boolean('backfill');
            $table->unsignedInteger('backfill_target');
            $table->unsignedBigInteger('first_record');
            $table->dateTime('first_record_postdate');
            $table->unsignedBigInteger('last_record');
            $table->dateTime('last_record_postdate');
        });
        Schema::create('short_groups', function (Blueprint $table): void {
            $table->string('name')->unique();
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('last_record');
            $table->dateTime('updated');
        });
        DB::table('usenet_groups')->insert([
            'name' => 'alt.test',
            'active' => 1,
            'backfill' => 0,
            'backfill_target' => 30,
            'first_record' => 200_000,
            'first_record_postdate' => now()->subDays(12)->format('Y-m-d H:i:s'),
            'last_record' => 400_000,
            'last_record_postdate' => now()->subMinute()->format('Y-m-d H:i:s'),
        ]);
        DB::table('short_groups')->insert([
            'name' => 'alt.test',
            'first_record' => 50_000,
            'last_record' => 400_000,
            'updated' => now()->format('Y-m-d H:i:s'),
        ]);

        foreach (['free_adaptive' => null, 'free_run' => 18] as $profile => $expected) {
            Settings::query()->delete();
            $this->activePermit($profile, 0);
            Settings::query()->where('name', 'orchestrator_bf_claimed')->update(['value' => '17']);
            Settings::query()->insert([
                ['name' => 'orchestrator_generation', 'value' => '17'],
                ['name' => 'orchestrator_free_run', 'value' => '1'],
                ['name' => 'orchestrator_bf_budget', 'value' => '200000'],
                ['name' => 'orchestrator_bf_completed', 'value' => '17'],
                ['name' => 'orchestrator_bf_failed', 'value' => '0'],
                ['name' => 'orchestrator_bfc_group', 'value' => 'alt.test'],
                ['name' => 'orchestrator_bfc_profile', 'value' => $profile],
                ['name' => 'orchestrator_bfc_qty', 'value' => '100000'],
                ['name' => 'orchestrator_bfc_stop', 'value' => '0'],
                ['name' => 'orchestrator_bfc_first', 'value' => '200000'],
                ['name' => 'orchestrator_bfc_last', 'value' => '299999'],
            ]);
            Settings::forgetCachedSettings();

            self::assertSame($expected, (new BackfillPermitGate)->queueFreeRunSuccessor(17), $profile);
            Settings::forgetCachedSettings();
            self::assertSame($expected ?? 0, Settings::settingValue('orchestrator_bf_permit'), $profile);
        }
    }

    /** A claim taken under free_run must not chain once the profile is free_adaptive. */
    public function test_a_free_run_claim_does_not_chain_after_switching_to_free_adaptive(): void
    {
        $this->activePermit('free_adaptive', 0);
        Settings::query()->insert([
            ['name' => 'orchestrator_free_run', 'value' => '1'],
            ['name' => 'orchestrator_bf_budget', 'value' => '200000'],
            ['name' => 'orchestrator_bf_completed', 'value' => '17'],
            ['name' => 'orchestrator_bfc_group', 'value' => 'alt.test'],
            ['name' => 'orchestrator_bfc_profile', 'value' => 'free_run'],
            ['name' => 'orchestrator_bfc_qty', 'value' => '100000'],
        ]);
        Settings::query()->where('name', 'orchestrator_bf_claimed')->update(['value' => '17']);

        self::assertNull((new BackfillPermitGate)->queueFreeRunSuccessor(17));
    }

    public function test_a_free_adaptive_permit_bypasses_the_collection_kill_switch_and_zero_group_work(): void
    {
        $plan = (new DistributedJobCatalog)->resolve('backfill', $this->runVar([
            'orchestrator_profile' => 'free_adaptive',
            'orchestrator_bf_group' => 'alt.active',
            'orchestrator_bf_qty' => 10_000,
        ], ['pp' => false, 'coll' => true]));

        self::assertTrue($plan['enabled']);
    }

    public function test_a_free_adaptive_permit_still_obeys_the_postprocessing_kill_switch(): void
    {
        $plan = (new DistributedJobCatalog)->resolve('backfill', $this->runVar([
            'orchestrator_profile' => 'free_adaptive',
            'orchestrator_bf_group' => 'alt.active',
            'orchestrator_bf_qty' => 10_000,
        ], ['pp' => true, 'coll' => true]));

        self::assertFalse($plan['enabled']);
        self::assertSame('kill limit exceeded', $plan['disabled_reason']);
    }

    public function test_a_ladder_profile_permit_does_not_bypass_the_collection_kill_switch(): void
    {
        $plan = (new DistributedJobCatalog)->resolve('backfill', $this->runVar([
            'orchestrator_profile' => 'fill',
            'orchestrator_bf_group' => 'alt.active',
            'orchestrator_bf_qty' => 10_000,
        ], ['pp' => false, 'coll' => true]));

        self::assertFalse($plan['enabled']);
        self::assertSame('kill limit exceeded', $plan['disabled_reason']);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array{pp: bool, coll: bool}  $killswitch
     * @return array<string, mixed>
     */
    private function runVar(array $settings, array $killswitch): array
    {
        return [
            'constants' => ['run_ircscraper' => 0, 'sequential' => 0],
            'settings' => array_merge([
                'bins_timer' => 60,
                'back_timer' => 600,
                'rel_timer' => 60,
                'fix_timer' => 300,
                'crap_timer' => 300,
                'post_timer' => 300,
                'post_timer_non' => 300,
                'post_timer_amazon' => 300,
                'backfill' => 4,
                'orchestrator_mode' => 'active',
                'orchestrator_lease_until' => time() + 300,
                'orchestrator_bf_permit' => 9,
                'orchestrator_bf_paused' => 0,
            ], $settings),
            'counts' => [
                'now' => [
                    'collections_table' => 0,
                    'other_hashed' => 0,
                    'processrenames' => 0,
                    'work' => 0,
                    'processnfo' => 0,
                    'processtv' => 0,
                    'processanime' => 0,
                    'processmovies' => 0,
                    'processmusic' => 0,
                    'processbooks' => 0,
                    'processconsole' => 0,
                    'processgames' => 0,
                    'backfill_groups_days' => 0,
                ],
            ],
            'killswitch' => $killswitch,
        ];
    }
}
