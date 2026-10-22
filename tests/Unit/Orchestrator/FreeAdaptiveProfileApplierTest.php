<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Models\Settings;
use App\Services\Orchestrator\ControlDecision;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\FreeAdaptiveStateStore;
use App\Services\Orchestrator\WorkerControlProfile;
use App\Services\Orchestrator\WorkerProfileApplier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * free_adaptive applies like free_run except for the two paths that would
 * start a permit with no delay: queueing behind a running claim, and the
 * completion-time refill budget.
 */
final class FreeAdaptiveProfileApplierTest extends TestCase
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
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('generation')->nullable()->unique();
            $table->string('state', 32);
        });
        Settings::query()->insert([
            ['name' => 'orchestrator_generation', 'value' => '4'],
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '0'],
            ['name' => 'orchestrator_bf_completed', 'value' => '4'],
        ]);
    }

    private function decision(ControlProfile $profile): ControlDecision
    {
        return new ControlDecision(
            profile: WorkerControlProfile::for($profile),
            backfillPermitted: true,
            reasons: ['test'],
            nextState: new ControlState(profile: $profile),
            transitioned: false,
        );
    }

    private function claimInFlight(): void
    {
        Settings::query()->where('name', 'orchestrator_bf_claimed')->update(['value' => '4']);
        Settings::query()->where('name', 'orchestrator_bf_completed')->update(['value' => '3']);
        Settings::query()->insert([
            ['name' => 'orchestrator_bf_failed', 'value' => '0'],
            ['name' => 'orchestrator_bf_group', 'value' => 'alt.in-flight'],
            ['name' => 'orchestrator_bf_qty', 'value' => '100000'],
        ]);
    }

    private function apply(ControlProfile $profile, string $group = 'alt.next'): int
    {
        return (new WorkerProfileApplier)->apply($this->decision($profile), 1_000, true, $group, false, 100_000, 500_000);
    }

    public function test_free_adaptive_never_queues_a_permit_behind_a_running_claim(): void
    {
        $this->claimInFlight();

        $this->apply(ControlProfile::FreeAdaptive);

        self::assertSame(0, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame(4, Settings::settingValue('orchestrator_bf_claimed'));
        self::assertSame('alt.in-flight', Settings::settingValue('orchestrator_bf_group'));
        self::assertSame(100_000, Settings::settingValue('orchestrator_bf_qty'));
        self::assertSame(0, Settings::settingValue('orchestrator_bf_budget'));
        self::assertSame('free_adaptive', Settings::settingValue('orchestrator_profile'));
    }

    public function test_free_run_still_queues_behind_a_running_claim(): void
    {
        $this->claimInFlight();

        $generation = $this->apply(ControlProfile::FreeRun);

        self::assertSame(5, $generation);
        self::assertSame(5, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame('alt.next', Settings::settingValue('orchestrator_bf_group'));
    }

    public function test_an_idle_lane_free_adaptive_grant_publishes_no_refill_budget(): void
    {
        $generation = $this->apply(ControlProfile::FreeAdaptive);

        self::assertSame(5, $generation);
        self::assertSame(5, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame('alt.next', Settings::settingValue('orchestrator_bf_group'));
        self::assertSame(100_000, Settings::settingValue('orchestrator_bf_qty'));
        self::assertSame(0, Settings::settingValue('orchestrator_bf_budget'));
        self::assertSame(0, Settings::settingValue('orchestrator_back_timer'));
    }

    public function test_free_run_still_publishes_the_residual_refill_budget(): void
    {
        $this->apply(ControlProfile::FreeRun);

        self::assertSame(400_000, Settings::settingValue('orchestrator_bf_budget'));
    }

    /** A budget left over from free_run must not survive the switch, or it would chain. */
    public function test_switching_from_free_run_clears_a_leftover_refill_budget(): void
    {
        Settings::query()->where('name', 'orchestrator_bf_permit')->update(['value' => '4']);
        Settings::query()->insert(['name' => 'orchestrator_bf_budget', 'value' => '300000']);

        (new WorkerProfileApplier)->apply($this->decision(ControlProfile::FreeAdaptive), 1_000, false, 'alt.test');

        self::assertSame(4, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame(0, Settings::settingValue('orchestrator_bf_budget'));
    }

    /**
     * The live `settings.name` is varchar(25) (database/schema/mariadb-schema.sql),
     * and SQLite does not enforce it. A longer name throws "Data too long" on
     * strict MariaDB -- inside BackfillPermitGate::complete()/fail() for every
     * profile, and in the governor's store write.
     */
    public function test_free_adaptive_setting_names_fit_the_live_varchar_25_schema(): void
    {
        foreach (FreeAdaptiveStateStore::ALL as $name) {
            self::assertLessThanOrEqual(25, strlen($name), $name);
        }
    }
}
