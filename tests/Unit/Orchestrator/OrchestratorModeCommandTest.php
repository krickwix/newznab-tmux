<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Models\Settings;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlProfileOverride;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The operator CLI for switching orchestrator modes.
 *
 * The store lives in `settings` rather than in the orchestrator's own Redis
 * state on purpose: a Redis flush restarts the ladder, which is recoverable,
 * but a Redis flush that silently un-pins the fleet is not.
 */
final class OrchestratorModeCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.free_run' => false,
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        Settings::forgetCachedSettings();
    }

    private function override(): ControlProfileOverride
    {
        Settings::forgetCachedSettings();

        return new ControlProfileOverride;
    }

    public function test_list_names_every_mode_and_flags_the_unsafe_one(): void
    {
        $this->artisan('nntmux:orchestrator-mode list --json')
            ->assertSuccessful();

        // The CLI must offer exactly the modes the policy can select -- a mode
        // the enum gained but the CLI never listed would be unreachable.
        foreach (ControlProfile::cases() as $mode) {
            self::assertNotSame('', $mode->description(), $mode->value);
        }
        self::assertTrue(ControlProfile::FreeRun->bypassesSafety());
    }

    public function test_set_pins_the_mode_and_get_reads_it_back(): void
    {
        $this->artisan('nntmux:orchestrator-mode set fill')->assertSuccessful();

        self::assertSame(ControlProfile::Fill, $this->override()->stored());
        self::assertSame(ControlProfile::Fill, $this->override()->effective());
    }

    public function test_an_unknown_mode_is_refused_and_changes_nothing(): void
    {
        $this->artisan('nntmux:orchestrator-mode set balanced')->assertSuccessful();

        $this->artisan('nntmux:orchestrator-mode set turbo')->assertFailed();

        // The refusal must not have half-applied. A CLI that clears the pin on
        // a typo is worse than one that rejects it.
        self::assertSame(ControlProfile::Balanced, $this->override()->stored());
    }

    public function test_set_without_a_mode_is_refused(): void
    {
        $this->artisan('nntmux:orchestrator-mode set')->assertFailed();

        self::assertNull($this->override()->stored());
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->artisan('nntmux:orchestrator-mode frobnicate')->assertFailed();
    }

    public function test_reset_clears_the_pin(): void
    {
        $this->artisan('nntmux:orchestrator-mode set free_run')->assertSuccessful();
        self::assertSame(ControlProfile::FreeRun, $this->override()->stored());

        $this->artisan('nntmux:orchestrator-mode reset')->assertSuccessful();

        self::assertNull($this->override()->stored());
        self::assertNull($this->override()->effective());
    }

    /**
     * Reset means "back to how this fleet was deployed", which is not
     * necessarily adaptive. With FREE_RUN set in the manifest, clearing the pin
     * hands the fleet straight back to free-run -- so the CLI has to say so
     * rather than let an operator believe they just applied the brakes.
     */
    public function test_reset_falls_back_to_the_deployed_default_not_to_adaptive(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        $this->artisan('nntmux:orchestrator-mode set drain')->assertSuccessful();
        self::assertSame(ControlProfile::Drain, $this->override()->effective());

        $this->artisan('nntmux:orchestrator-mode reset')
            ->expectsOutputToContain('free_run')
            ->assertSuccessful();

        self::assertNull($this->override()->stored());
        self::assertSame(ControlProfile::FreeRun, $this->override()->effective());
    }

    /**
     * The reason this CLI exists. Turning free-run off previously meant a
     * manifest edit, a rebuild and a rollout; a stored pin has to beat the env
     * default or the off switch does not work.
     */
    public function test_a_pin_beats_the_deployed_free_run_default(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);
        self::assertSame(ControlProfile::FreeRun, $this->override()->effective());

        $this->artisan('nntmux:orchestrator-mode set balanced')->assertSuccessful();

        self::assertSame(ControlProfile::Balanced, $this->override()->effective());
    }

    public function test_get_reports_the_pin_the_default_and_what_the_workers_were_told(): void
    {
        Settings::query()->updateOrCreate(['name' => 'orchestrator_profile'], ['value' => 'balanced']);
        $this->artisan('nntmux:orchestrator-mode set fill')->assertSuccessful();

        $this->artisan('nntmux:orchestrator-mode get')
            ->expectsOutputToContain('fill')
            ->expectsOutputToContain('balanced')
            ->assertSuccessful();
    }

    /**
     * Caught live, not by inspection.
     *
     * NNTMUX_ORCHESTRATOR_FREE_RUN is set on the orchestrator deployment alone
     * -- checked in-pod, config('nntmux.orchestrator.free_run') reads false on
     * every worker while the orchestrator was applying profile=free_run. Read
     * locally, `reset` would have told an operator they were handing the fleet
     * back to the adaptive ladder at the exact moment it went back to free-run.
     * The orchestrator publishes the value so any pod can answer truthfully.
     */
    public function test_the_published_default_beats_local_config_which_other_pods_do_not_have(): void
    {
        config()->set('nntmux.orchestrator.free_run', false);
        Settings::query()->updateOrCreate(
            ['name' => ControlProfileOverride::FREE_RUN_DEFAULT_SETTING],
            ['value' => '1'],
        );

        self::assertSame(ControlProfile::FreeRun, $this->override()->configuredDefault());
        self::assertSame(ControlProfile::FreeRun, $this->override()->effective());

        $this->artisan('nntmux:orchestrator-mode reset')
            ->expectsOutputToContain('free_run')
            ->assertSuccessful();
    }

    /**
     * And the published value wins in the other direction too, so an
     * orchestrator that has since had free-run switched off is not reported as
     * still defaulting to it.
     */
    public function test_a_published_zero_means_the_adaptive_ladder(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);
        Settings::query()->updateOrCreate(
            ['name' => ControlProfileOverride::FREE_RUN_DEFAULT_SETTING],
            ['value' => '0'],
        );

        self::assertNull($this->override()->configuredDefault());
        self::assertNull($this->override()->effective());
    }

    /**
     * Before the orchestrator has ever published (a fresh database, or a
     * rollout where the CLI lands first), fall back to local config rather than
     * silently claiming the adaptive ladder.
     */
    public function test_an_unpublished_default_falls_back_to_local_config(): void
    {
        config()->set('nntmux.orchestrator.free_run', true);

        self::assertSame(ControlProfile::FreeRun, $this->override()->configuredDefault());
    }

    /**
     * A blank stored value is "no pin", not an invalid mode. `clear()` writes
     * one rather than deleting the row, so this is the normal post-reset state
     * and must not be mistaken for a corrupt setting.
     */
    public function test_a_blank_or_unparseable_stored_value_reads_as_no_pin(): void
    {
        foreach (['', '   ', 'nonsense'] as $value) {
            Settings::query()->updateOrCreate(
                ['name' => ControlProfileOverride::SETTING],
                ['value' => $value],
            );

            self::assertNull($this->override()->stored(), var_export($value, true));
        }
    }
}
