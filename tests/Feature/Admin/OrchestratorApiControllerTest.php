<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Settings;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlProfileOverride;
use App\Services\Orchestrator\FreeAdaptiveStateStore;
use App\Services\Orchestrator\WorkerControlStateStore;
use App\Services\OrchestratorStatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The nntmux-control webui's only server-side surface: a read-only status
 * endpoint and a control endpoint that must never become a second way to
 * write orchestrator state.
 *
 * The core invariant under test: OrchestratorApiController::mode() may only
 * ever reach orchestrator state through the `nntmux:orchestrator-mode`
 * Artisan command. See docs/plans/2026-10-20-nntmux-control-webui.md.
 */
final class OrchestratorApiControllerTest extends TestCase
{
    private const CONTROL_TOKEN = 'test-orchestrator-control-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.free_run' => false,
            'nntmux_orchestrator.control_token' => self::CONTROL_TOKEN,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedInteger('roles_id')->default(1);
            $table->string('api_token')->nullable()->index();
            $table->boolean('verified')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->integer('rate_limit')->default(60);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });

        Settings::forgetCachedSettings();
    }

    private function createUser(UserRole $role, string $token): void
    {
        DB::table('users')->insert([
            'username' => 'user-'.$token,
            'email' => $token.'@example.test',
            'password' => bcrypt('secret'),
            'roles_id' => $role->value,
            'api_token' => $token,
            'verified' => 1,
            'email_verified_at' => now(),
            'rate_limit' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function override(): ControlProfileOverride
    {
        Settings::forgetCachedSettings();

        return new ControlProfileOverride;
    }

    // ---- status() ----------------------------------------------------

    public function test_status_rejects_an_unauthenticated_caller(): void
    {
        $this->getJson('/api/v2/admin/orchestrator/status')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'Unauthorized');
    }

    public function test_status_rejects_a_non_admin_user(): void
    {
        $this->createUser(UserRole::USER, 'plain-user-token');

        $this->getJson('/api/v2/admin/orchestrator/status', [
            'Authorization' => 'Bearer plain-user-token',
        ])->assertUnauthorized();
    }

    public function test_status_returns_the_orchestrator_status_service_report_shape(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        $expected = app(OrchestratorStatusService::class)->report();

        $response = $this->getJson('/api/v2/admin/orchestrator/status', [
            'Authorization' => 'Bearer admin-token',
        ])->assertOk();

        // Same top-level keys OrchestratorStatusService::report() produces --
        // this is the contract the nntmux-control dashboard's DTOs decode.
        self::assertSame(array_keys($expected), array_keys($response->json()));
        foreach (['sources', 'freshness', 'profile', 'lease', 'fail_safe', 'pressure', 'backfill', 'denial', 'current_forward', 'yield', 'safety', 'lanes'] as $section) {
            self::assertArrayHasKey($section, $response->json());
        }
    }

    public function test_status_reports_each_yield_groups_oldest_backfilled_post_date(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->dateTime('first_record_postdate')->nullable();
        });
        DB::table('usenet_groups')->insert([
            ['name' => 'alt.binaries.moovee', 'first_record_postdate' => '2024-03-01 12:00:00'],
            ['name' => 'alt.binaries.teevee', 'first_record_postdate' => null],
        ]);
        $now = time();
        $store = app(WorkerControlStateStore::class);
        foreach (['alt.binaries.moovee', 'alt.binaries.teevee', 'alt.binaries.unknown'] as $group) {
            $store->recordBackfillYield($group, 10_000, 1, $now);
        }

        $response = $this->getJson('/api/v2/admin/orchestrator/status', [
            'Authorization' => 'Bearer admin-token',
        ])->assertOk();
        $groups = collect($response->json('yield.groups'))->keyBy('group');

        self::assertTrue($response->json('sources.group_records'));
        self::assertSame(strtotime('2024-03-01 12:00:00'), $groups['alt.binaries.moovee']['oldest_post_at']);
        self::assertGreaterThan(0, $groups['alt.binaries.moovee']['oldest_post_age_seconds']);
        // Never backfilled, and not a known group: both report null rather than 0.
        self::assertNull($groups['alt.binaries.teevee']['oldest_post_at']);
        self::assertNull($groups['alt.binaries.unknown']['oldest_post_age_seconds']);
    }

    public function test_status_still_reports_yield_when_usenet_groups_is_unreadable(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');
        app(WorkerControlStateStore::class)->recordBackfillYield('alt.binaries.moovee', 10_000, 1, time());

        $response = $this->getJson('/api/v2/admin/orchestrator/status', [
            'Authorization' => 'Bearer admin-token',
        ])->assertOk();

        self::assertFalse($response->json('sources.group_records'));
        self::assertNull($response->json('yield.groups.0.oldest_post_at'));
        self::assertSame('alt.binaries.moovee', $response->json('yield.groups.0.group'));
    }

    // ---- mode() auth/validation ---------------------------------------

    public function test_mode_rejects_an_unauthenticated_caller(): void
    {
        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'drain'], [
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertUnauthorized();
    }

    public function test_mode_rejects_a_non_admin_user_even_with_a_valid_control_token(): void
    {
        $this->createUser(UserRole::USER, 'plain-user-token');

        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'drain'], [
            'Authorization' => 'Bearer plain-user-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertUnauthorized();
    }

    public function test_mode_rejects_an_admin_without_the_control_token(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'drain'], [
            'Authorization' => 'Bearer admin-token',
        ])->assertForbidden();

        self::assertNull($this->override()->stored(), 'an admin-only request must never reach the write path');
    }

    public function test_mode_rejects_an_admin_with_the_wrong_control_token(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'drain'], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => 'wrong-token',
        ])->assertForbidden();

        self::assertNull($this->override()->stored());
    }

    public function test_mode_rejects_an_invalid_mode_name(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'turbo'], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertStatus(422);

        self::assertNull($this->override()->stored(), 'an invalid mode must not have pinned anything');
    }

    public function test_mode_requires_explicit_confirmation_for_free_run(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'free_run'], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertStatus(422);

        self::assertNull($this->override()->stored(), 'free_run without confirm must never be pinned');
    }

    public function test_mode_rejects_free_run_with_confirm_false(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        $this->postJson('/api/v2/admin/orchestrator/mode', [
            'mode' => 'free_run',
            'confirm' => false,
        ], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertStatus(422);

        self::assertNull($this->override()->stored());
    }

    // ---- mode() end-to-end through the real Artisan command -----------

    public function test_mode_set_reaches_the_pin_only_via_the_artisan_command(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        $response = $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'drain'], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertOk();

        self::assertSame('drain', $response->json('pinned'));
        self::assertSame(ControlProfile::Drain, $this->override()->stored());
    }

    public function test_mode_free_run_with_confirm_true_is_accepted(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        $response = $this->postJson('/api/v2/admin/orchestrator/mode', [
            'mode' => 'free_run',
            'confirm' => true,
        ], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertOk();

        self::assertSame('free_run', $response->json('pinned'));
        self::assertSame(ControlProfile::FreeRun, $this->override()->stored());
    }

    public function test_mode_reset_clears_the_pin(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');
        $this->override()->set(ControlProfile::Fill);

        $this->postJson('/api/v2/admin/orchestrator/mode', ['action' => 'reset'], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertOk();

        self::assertNull($this->override()->stored());
    }

    // ---- the one-writer invariant --------------------------------------

    /**
     * The headline safety test: with Artisan::call() replaced by a mock that
     * performs no real work, the controller must have no other path left to
     * mutate orchestrator state. If `mode()` ever grew a direct write to
     * `settings`, `ControlProfileOverride`, or Redis/WorkerControlStateStore
     * alongside (or instead of) the Artisan call, this test would see the
     * `settings` table change despite the real command never having run.
     */
    public function test_mode_never_writes_settings_directly_bypassing_the_artisan_command(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        Artisan::shouldReceive('call')
            ->once()
            ->with('nntmux:orchestrator-mode', ['action' => 'set', '--json' => true, 'mode' => 'fill'])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn(json_encode(['pinned' => 'fill', 'previous' => null], JSON_THROW_ON_ERROR));

        self::assertSame(0, DB::table('settings')->count(), 'precondition: settings table starts empty');

        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'fill'], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertOk();

        self::assertSame(
            0,
            DB::table('settings')->count(),
            'the controller must not write to `settings` itself -- only the mocked-out Artisan command may, and it never ran for real here'
        );
    }

    public function test_mode_never_calls_artisan_for_an_invalid_mode_or_unconfirmed_free_run(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        Artisan::shouldReceive('call')->never();

        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'turbo'], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertStatus(422);

        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'free_run'], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertStatus(422);
    }

    public function test_mode_requires_explicit_confirmation_for_free_adaptive(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');
        $headers = [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ];

        foreach ([['mode' => 'free_adaptive'], ['mode' => 'free_adaptive', 'confirm' => false]] as $body) {
            $response = $this->postJson('/api/v2/admin/orchestrator/mode', $body, $headers)->assertStatus(422);
            self::assertStringContainsString('free_adaptive', (string) $response->json('error'));
            self::assertNull($this->override()->stored(), 'free_adaptive without confirm must never be pinned');
        }
    }

    public function test_mode_never_calls_artisan_for_an_unconfirmed_free_adaptive(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        Artisan::shouldReceive('call')->never();

        $this->postJson('/api/v2/admin/orchestrator/mode', ['mode' => 'free_adaptive'], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertStatus(422);
    }

    public function test_mode_free_adaptive_with_confirm_true_is_accepted(): void
    {
        $this->createUser(UserRole::ADMIN, 'admin-token');

        $response = $this->postJson('/api/v2/admin/orchestrator/mode', [
            'mode' => 'free_adaptive',
            'confirm' => true,
        ], [
            'Authorization' => 'Bearer admin-token',
            'X-Orchestrator-Control-Token' => self::CONTROL_TOKEN,
        ])->assertOk();

        self::assertSame('free_adaptive', $response->json('pinned'));
        self::assertTrue($response->json('bypasses_safety'));
        self::assertSame(ControlProfile::FreeAdaptive, $this->override()->stored());
    }

    public function test_status_reports_the_free_adaptive_governor_only_under_free_adaptive(): void
    {
        $now = time();
        foreach ([
            'orchestrator_mode' => 'active',
            'orchestrator_profile' => 'free_run',
            FreeAdaptiveStateStore::DELAY => '300',
            FreeAdaptiveStateStore::LAST_DONE_AT => (string) ($now - 60),
            FreeAdaptiveStateStore::REASON => json_encode(['reason' => 'hold', 'stage' => 'collections', 'p' => 0.95, 'ewma' => 0.0]),
        ] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
        Settings::forgetCachedSettings();

        self::assertNull(app(OrchestratorStatusService::class)->report()['profile']['free_adaptive']);

        DB::table('settings')->where('name', 'orchestrator_profile')->update(['value' => 'free_adaptive']);
        Settings::forgetCachedSettings();
        $profile = app(OrchestratorStatusService::class)->report()['profile'];

        self::assertSame('free_adaptive', $profile['profile']);
        self::assertTrue($profile['bypasses_safety']);
        self::assertSame(300, $profile['free_adaptive']['delay_seconds']);
        self::assertSame($now + 240, $profile['free_adaptive']['next_eligible_at']);
        self::assertSame('hold', $profile['free_adaptive']['reason']);
    }
}
