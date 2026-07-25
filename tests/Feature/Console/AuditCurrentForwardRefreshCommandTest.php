<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AuditCurrentForwardRefreshCommandTest extends TestCase
{
    private CurrentForwardAuditNntpFake $nntp;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.current_forward_refresh_enabled' => true,
            'nntmux.orchestrator.current_forward_windows' => 'alt.test:101-10100@30100',
            'nntmux_nntp.use_alternate_nntp_server' => false,
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->unique();
            $table->unsignedTinyInteger('active')->default(0);
            $table->unsignedTinyInteger('backfill')->default(1);
            $table->unsignedBigInteger('last_record');
        });
        Schema::create('short_groups', function (Blueprint $table): void {
            $table->string('name');
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('last_record');
            $table->dateTime('updated');
        });
        $migration = require database_path('migrations/2026_07_17_120000_create_current_forward_refresh_ledger.php');
        $migration->up();

        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.test',
            'active' => 0,
            'backfill' => 1,
            'last_record' => 10_100,
        ]);
        DB::table('short_groups')->insert([
            'name' => 'alt.test',
            'first_record' => 1,
            'last_record' => 50_100,
            'updated' => now(),
        ]);
        DB::table('current_forward_sources')->insert([
            'groups_id' => 1,
            'group_name' => 'alt.test',
            'anchor_first' => 101,
            'audited_last' => 10_100,
            'state' => 'PROBATION',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->nntp = new CurrentForwardAuditNntpFake;
        $this->app->instance(NNTPService::class, $this->nntp);
    }

    public function test_default_shadow_audit_proves_the_window_without_persisting_it(): void
    {
        $exitCode = Artisan::call('orchestrator:audit-current-forward', [
            'group' => 'alt.test',
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('"state": "AUDITED"', $output);
        self::assertStringContainsString('"first": 10101', $output);

        self::assertSame(0, DB::table('current_forward_windows')->count());
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'PROBATION',
            'audited_last' => 10_100,
        ]);
    }

    public function test_record_option_appends_audited_evidence_without_issuing_work(): void
    {
        $exitCode = Artisan::call('orchestrator:audit-current-forward', [
            'group' => 'alt.test',
            '--record' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('"recorded": true', $output);
        $this->assertDatabaseHas('current_forward_windows', [
            'source_id' => 1,
            'first_article' => 10_101,
            'last_article' => 20_100,
            'state' => 'AUDITED',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'id' => 1,
            'state' => 'READY',
            'audited_last' => 20_100,
        ]);
        self::assertSame(0, DB::table('current_forward_windows')->whereNotNull('generation')->count());
    }

    public function test_record_option_uses_the_configured_provider_reserve_end_to_end(): void
    {
        config()->set('nntmux.orchestrator.current_forward_provider_reserve', 19_000);
        DB::table('short_groups')->where('name', 'alt.test')->update(['last_record' => 39_100]);
        $this->nntp->providerHigh = 39_100;

        $exitCode = Artisan::call('orchestrator:audit-current-forward', [
            'group' => 'alt.test',
            '--record' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $exitCode, Artisan::output());
        $this->assertDatabaseHas('current_forward_windows', [
            'first_article' => 10_101,
            'last_article' => 20_100,
            'provider_high' => 39_100,
            'state' => 'AUDITED',
        ]);
    }

    public function test_live_audit_rejects_one_article_below_the_configured_reserve_without_mutation(): void
    {
        config()->set('nntmux.orchestrator.current_forward_provider_reserve', 19_000);
        DB::table('short_groups')->where('name', 'alt.test')->update(['last_record' => 39_100]);
        $this->nntp->providerHigh = 39_099;

        $exitCode = Artisan::call('orchestrator:audit-current-forward', [
            'group' => 'alt.test',
            '--record' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('provider_range_drift', $output);
        self::assertSame(0, DB::table('current_forward_windows')->count());
    }

    public function test_explicit_rejected_group_returns_failure_without_mutation(): void
    {
        DB::table('short_groups')->where('name', 'alt.test')->update(['last_record' => 40_099]);

        $exitCode = Artisan::call('orchestrator:audit-current-forward', [
            'group' => 'alt.test',
            '--record' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('provider_range_drift', $output);
        self::assertSame(0, DB::table('current_forward_windows')->count());
        $this->assertDatabaseHas('current_forward_sources', [
            'id' => 1,
            'state' => 'PROBATION',
            'audited_last' => 10_100,
        ]);
    }

    public function test_global_record_run_with_no_safe_window_is_a_successful_noop(): void
    {
        DB::table('short_groups')->where('name', 'alt.test')->update(['last_record' => 40_099]);

        $exitCode = Artisan::call('orchestrator:audit-current-forward', [
            '--record' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('provider_range_drift', $output);
        self::assertSame(0, DB::table('current_forward_windows')->count());
        $this->assertDatabaseHas('current_forward_sources', [
            'id' => 1,
            'state' => 'PROBATION',
            'audited_last' => 10_100,
        ]);
    }

    public function test_seed_only_registers_a_trusted_source_without_connecting_to_nntp(): void
    {
        DB::table('current_forward_sources')->delete();

        $exitCode = Artisan::call('orchestrator:audit-current-forward', [
            'group' => 'alt.test',
            '--seed-only' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('"reason": "source_seeded"', $output);
        self::assertStringContainsString('"group": "alt.test"', $output);
        self::assertSame(0, $this->nntp->connections);
        self::assertSame(0, DB::table('current_forward_windows')->count());
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'anchor_first' => 101,
            'audited_last' => 10_100,
            'state' => 'PROBATION',
        ]);
    }

    public function test_scheduled_record_run_auto_registers_at_most_one_configured_source_without_issuing_a_permit(): void
    {
        config()->set(
            'nntmux.orchestrator.current_forward_refresh_sources',
            'alt.second:201-10200,alt.third:301-10300',
        );
        DB::table('usenet_groups')->insert([
            [
                'id' => 2,
                'name' => 'alt.second',
                'active' => 0,
                'backfill' => 1,
                'last_record' => 10_200,
            ],
            [
                'id' => 3,
                'name' => 'alt.third',
                'active' => 0,
                'backfill' => 1,
                'last_record' => 10_300,
            ],
        ]);
        DB::table('short_groups')->insert([
            [
                'name' => 'alt.second',
                'first_record' => 1,
                'last_record' => 50_200,
                'updated' => now(),
            ],
            [
                'name' => 'alt.third',
                'first_record' => 1,
                'last_record' => 50_300,
                'updated' => now(),
            ],
        ]);
        DB::table('settings')->insert(['name' => 'orchestrator_cf_permit', 'value' => '0']);

        $exitCode = Artisan::call('orchestrator:audit-current-forward', [
            '--record' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('"registration"', $output);
        self::assertStringContainsString('"group": "alt.second"', $output);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.second',
            'anchor_first' => 201,
        ]);
        $this->assertDatabaseMissing('current_forward_sources', ['group_name' => 'alt.third']);
        self::assertSame('0', DB::table('settings')->where('name', 'orchestrator_cf_permit')->value('value'));
        self::assertSame(0, DB::table('current_forward_windows')->whereNotNull('generation')->count());
    }

    public function test_scheduled_registration_is_idempotent_and_never_registers_an_unconfigured_group(): void
    {
        config()->set('nntmux.orchestrator.current_forward_refresh_sources', 'alt.second:201-10200');
        DB::table('usenet_groups')->insert([
            [
                'id' => 2,
                'name' => 'alt.second',
                'active' => 0,
                'backfill' => 1,
                'last_record' => 10_200,
            ],
            [
                'id' => 3,
                'name' => 'alt.unconfigured',
                'active' => 0,
                'backfill' => 1,
                'last_record' => 10_300,
            ],
        ]);
        DB::table('short_groups')->insert([
            'name' => 'alt.second',
            'first_record' => 1,
            'last_record' => 50_200,
            'updated' => now(),
        ]);

        self::assertSame(0, Artisan::call('orchestrator:audit-current-forward', [
            '--record' => true,
            '--json' => true,
        ]), Artisan::output());
        self::assertSame(0, Artisan::call('orchestrator:audit-current-forward', [
            '--record' => true,
            '--json' => true,
        ]), Artisan::output());

        self::assertSame(1, DB::table('current_forward_sources')->where('group_name', 'alt.second')->count());
        $this->assertDatabaseMissing('current_forward_sources', ['group_name' => 'alt.unconfigured']);
        self::assertSame(2, DB::table('current_forward_sources')->count());
    }

    public function test_disabled_global_shadow_inspection_is_successful_and_performs_zero_writes_or_nntp_calls(): void
    {
        config()->set('nntmux.orchestrator.current_forward_refresh_enabled', false);
        DB::table('settings')->insert(['name' => 'orchestrator_cf_permit', 'value' => '0']);
        $beforeCursor = DB::table('usenet_groups')->where('id', 1)->value('last_record');

        $exitCode = Artisan::call('orchestrator:audit-current-forward', ['--json' => true]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('refresh_disabled', $output);
        self::assertSame(0, $this->nntp->connections);
        self::assertSame(0, DB::table('current_forward_windows')->count());
        self::assertSame($beforeCursor, DB::table('usenet_groups')->where('id', 1)->value('last_record'));
        self::assertSame('0', DB::table('settings')->where('name', 'orchestrator_cf_permit')->value('value'));
    }
}

final class CurrentForwardAuditNntpFake extends NNTPService
{
    public int $connections = 0;

    public int $providerHigh = 50_100;

    public function __construct() {}

    public function __destruct() {}

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        $this->connections++;

        return true;
    }

    public function doQuit(bool $force = false): mixed
    {
        return true;
    }

    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        return ['group' => $group, 'first' => 1, 'last' => $this->providerHigh];
    }

    public function getXOVER(string $range): array|string|NNTPService|NntpError
    {
        [$first, $last] = array_map('intval', explode('-', $range, 2));
        $headers = [];
        for ($number = $first; $number <= $last; $number++) {
            $headers[] = [
                'Number' => $number,
                'Subject' => sprintf('"release.mkv" yEnc (%d/10000)', $number - $first + 1),
                'Message-ID' => sprintf('<%d@example.test>', $number),
            ];
        }

        return $headers;
    }
}
