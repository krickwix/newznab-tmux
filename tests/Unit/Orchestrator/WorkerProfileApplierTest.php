<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Models\Settings;
use App\Services\Orchestrator\ControlDecision;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\WorkerControlProfile;
use App\Services\Orchestrator\WorkerProfileApplier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class WorkerProfileApplierTest extends TestCase
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
        Settings::query()->insert([
            ['name' => 'orchestrator_generation', 'value' => '4'],
            ['name' => 'orchestrator_bf_permit', 'value' => '3'],
        ]);
    }

    public function test_it_atomically_advances_generation_and_applies_the_selected_profile(): void
    {
        $generation = (new WorkerProfileApplier)->apply(
            $this->decision(ControlProfile::Balanced, true),
            1_000,
            true,
            'alt.test',
        );

        self::assertSame(5, $generation);
        self::assertSame([
            'orchestrator_generation' => 5,
            'orchestrator_bf_permit' => 5,
            'orchestrator_mode' => 'active',
            'orchestrator_lease_until' => 1_600,
            'orchestrator_bins_timer' => 40,
            'orchestrator_back_timer' => 900,
            'orchestrator_rel_timer' => 60,
            'orchestrator_nzb_timer' => 55,
            'orchestrator_nzb_limit' => 20,
            'orchestrator_bf_paused' => 0,
            'orchestrator_bf_group' => 'alt.test',
            'backfill_groups' => 1,
            'backfillthreads' => 1,
            'backfill_qty' => 10_000,
        ], Settings::query()->pluck('value', 'name')->only([
            'orchestrator_generation',
            'orchestrator_bf_permit',
            'orchestrator_mode',
            'orchestrator_lease_until',
            'orchestrator_bins_timer',
            'orchestrator_back_timer',
            'orchestrator_rel_timer',
            'orchestrator_nzb_timer',
            'orchestrator_nzb_limit',
            'orchestrator_bf_paused',
            'backfill_groups',
            'backfillthreads',
            'backfill_qty',
            'orchestrator_bf_group',
        ])->toArray());
    }

    public function test_it_preserves_an_unconsumed_permit_without_an_explicit_grant(): void
    {
        (new WorkerProfileApplier)->apply($this->decision(ControlProfile::Fill, true), 1_000, false);

        self::assertSame(3, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame(5, Settings::settingValue('orchestrator_generation'));
    }

    public function test_it_revokes_the_permit_when_the_policy_closes_the_backfill_gate(): void
    {
        (new WorkerProfileApplier)->apply($this->decision(ControlProfile::Drain, false), 1_000, true);

        self::assertSame(0, Settings::settingValue('orchestrator_bf_permit'));
        self::assertSame(1, Settings::settingValue('orchestrator_bf_paused'));
        self::assertSame(5, Settings::settingValue('orchestrator_generation'));
    }

    public function test_all_managed_setting_names_fit_the_live_varchar_25_schema(): void
    {
        (new WorkerProfileApplier)->apply($this->decision(ControlProfile::Balanced, true), 1_000, true, 'alt.test');

        $names = Settings::query()->pluck('name')->all();

        self::assertNotEmpty($names);
        foreach ($names as $name) {
            self::assertLessThanOrEqual(25, strlen((string) $name), (string) $name);
        }
    }

    private function decision(ControlProfile $profile, bool $backfillPermitted): ControlDecision
    {
        return new ControlDecision(
            profile: WorkerControlProfile::for($profile),
            backfillPermitted: $backfillPermitted,
            reasons: ['test'],
            nextState: new ControlState(profile: $profile),
            transitioned: false,
        );
    }
}
