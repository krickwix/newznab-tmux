<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Models\Settings;
use App\Services\Distributed\CurrentForwardPermitGate;
use App\Services\Orchestrator\PipelineSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurrentForwardPermitGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'nntmux.distributed_lock_store' => 'array',
            'nntmux.distributed_current_forward_max_run_seconds' => 60,
            'nntmux.orchestrator.current_forward_windows' => 'alt.test:101-10100@20000',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->integer('active');
            $table->integer('backfill');
            $table->unsignedBigInteger('last_record');
        });
        Schema::create('short_groups', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('last_record');
            $table->dateTime('updated');
        });
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.test',
            'active' => 0,
            'backfill' => 1,
            'last_record' => 100,
        ]);
        DB::table('short_groups')->insert([
            'name' => 'alt.test',
            'first_record' => 1,
            'last_record' => 50_100,
            'updated' => now(),
        ]);
        Settings::query()->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_profile', 'value' => 'fill'],
            ['name' => 'orchestrator_recovery_ok', 'value' => '1'],
            ['name' => 'orchestrator_lease_until', 'value' => (string) (time() + 600)],
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
        ]);
    }

    public function test_one_exact_generation_is_issued_claimed_and_completed(): void
    {
        $gate = new CurrentForwardPermitGate;
        $issued = $gate->issue($this->safeSnapshot(), 17);

        self::assertTrue($issued['granted']);
        self::assertSame(17, Settings::settingValue('orchestrator_cf_permit'));
        self::assertSame([
            'generation' => 17,
            'group' => 'alt.test',
            'first' => 101,
            'last' => 10_100,
            'stop' => 20_000,
        ], $gate->claim(17, 'alt.test', 101, 10_100));
        self::assertNull($gate->claim(17, 'alt.test', 101, 10_100));

        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        self::assertTrue($gate->complete(17));
        self::assertSame(17, Settings::settingValue('orchestrator_cf_completed'));
        self::assertFalse($gate->complete(18));
    }

    public function test_a_corridor_issues_separate_sequential_generations(): void
    {
        config()->set('nntmux.orchestrator.current_forward_windows', 'alt.test:101-30100@50100');
        $gate = new CurrentForwardPermitGate;

        self::assertTrue($gate->issue($this->safeSnapshot(), 17)['granted']);
        self::assertNotNull($gate->claim(17, 'alt.test', 101, 10_100));
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        self::assertTrue($gate->complete(17));

        $second = $gate->issue($this->safeSnapshot(), 18);

        self::assertTrue($second['granted']);
        self::assertSame('alt.test', $second['group']);
        self::assertSame(10_101, $second['first']);
        self::assertSame(20_100, $second['last']);
    }

    public function test_ranked_policy_skips_an_exhausted_corridor(): void
    {
        config()->set(
            'nntmux.orchestrator.current_forward_windows',
            'alt.exhausted:101-10100@50100,alt.test:101-10100@50100',
        );
        DB::table('usenet_groups')->insert([
            'id' => 2,
            'name' => 'alt.exhausted',
            'active' => 0,
            'backfill' => 1,
            'last_record' => 10_100,
        ]);
        DB::table('short_groups')->insert([
            'name' => 'alt.exhausted',
            'first_record' => 1,
            'last_record' => 50_100,
            'updated' => now(),
        ]);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 17);

        self::assertTrue($issued['granted']);
        self::assertSame('alt.test', $issued['group']);
    }

    public function test_ranked_policy_fails_closed_on_a_partial_cursor_instead_of_skipping_it(): void
    {
        config()->set(
            'nntmux.orchestrator.current_forward_windows',
            'alt.partial:101-20100@50100,alt.test:101-10100@50100',
        );
        DB::table('usenet_groups')->insert([
            'id' => 2,
            'name' => 'alt.partial',
            'active' => 0,
            'backfill' => 1,
            'last_record' => 5_100,
        ]);
        DB::table('short_groups')->insert([
            'name' => 'alt.partial',
            'first_record' => 1,
            'last_record' => 50_100,
            'updated' => now(),
        ]);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 17);

        self::assertFalse($issued['granted']);
        self::assertSame('cursor_drift', $issued['reason']);
        self::assertSame(0, Settings::settingValue('orchestrator_cf_permit'));
    }

    public function test_failed_exact_window_is_quarantined_in_automatic_mode(): void
    {
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 17)['granted']);
        self::assertNotNull($gate->claim(17, 'alt.test', 101, 10_100));
        self::assertTrue($gate->fail(17, 'provider returned a partial boundary'));

        $retry = $gate->issue($this->safeSnapshot(), 18);

        self::assertFalse($retry['granted']);
        self::assertSame('current_forward_window_quarantined', $retry['reason']);
        self::assertSame('alt.test:101-10100', Settings::settingValue('orchestrator_cf_blocks'));
    }

    public function test_issue_fails_closed_for_unsafe_pipeline_or_group_state(): void
    {
        $gate = new CurrentForwardPermitGate;
        self::assertSame('pipeline_not_drained', $gate->issue($this->safeSnapshot(highPressure: true), 17)['reason']);
        self::assertSame('database_admission_blocked', $gate->issue($this->safeSnapshot(databaseAdmissionSafe: false), 17)['reason']);

        DB::table('usenet_groups')->where('name', 'alt.test')->update(['active' => 1]);
        self::assertSame('group_not_inactive_backfill', $gate->issue($this->safeSnapshot(), 18)['reason']);
        self::assertSame(0, Settings::settingValue('orchestrator_cf_permit'));
    }

    public function test_backfill_permit_conflict_blocks_current_forward_admission(): void
    {
        Settings::query()->where('name', 'orchestrator_bf_permit')->update(['value' => '99']);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 17);

        self::assertFalse($issued['granted']);
        self::assertSame('permit_conflict_or_stale_lease', $issued['reason']);
        self::assertSame(0, Settings::settingValue('orchestrator_cf_permit'));
    }

    public function test_claim_rejects_drift_and_failure_is_generation_fenced(): void
    {
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 17)['granted']);
        DB::table('short_groups')->where('name', 'alt.test')->update(['last_record' => 30_000]);

        self::assertNull($gate->claim(17, 'alt.test', 101, 10_100));
        self::assertFalse($gate->fail(18, 'wrong_generation'));
        self::assertFalse($gate->fail(17, 'provider_range_drift'));
        self::assertSame(0, Settings::settingValue('orchestrator_cf_failed'));
        self::assertSame('', Settings::settingValue('orchestrator_cf_failure'));
        self::assertSame('', Settings::settingValue('orchestrator_cf_blocks'));
    }

    public function test_issue_rejects_fail_safe_or_incomplete_recovery(): void
    {
        $gate = new CurrentForwardPermitGate;
        Settings::query()->where('name', 'orchestrator_profile')->update(['value' => 'fail_safe']);
        Settings::query()->where('name', 'orchestrator_recovery_ok')->update(['value' => '0']);

        self::assertSame('controller_not_recovered', $gate->issue($this->safeSnapshot(), 17)['reason']);

        Settings::query()->where('name', 'orchestrator_profile')->update(['value' => 'fill']);
        self::assertSame('controller_not_recovered', $gate->issue($this->safeSnapshot(), 18)['reason']);

        Settings::query()->where('name', 'orchestrator_recovery_ok')->update(['value' => '1']);
        self::assertTrue($gate->issue($this->safeSnapshot(), 19)['granted']);
    }

    public function test_unresolved_claim_blocks_reissue_and_stale_completion(): void
    {
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 17)['granted']);
        self::assertNotNull($gate->claim(17, 'alt.test', 101, 10_100));

        self::assertSame('current_forward_in_progress', $gate->issue($this->safeSnapshot(), 18)['reason']);

        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        Settings::query()->where('name', 'orchestrator_cf_gen')->update(['value' => '18']);
        self::assertFalse($gate->complete(17));
        self::assertSame(0, Settings::settingValue('orchestrator_cf_completed'));
    }

    public function test_stale_claim_is_quarantined_and_does_not_stall_every_corridor(): void
    {
        config()->set('nntmux.orchestrator.current_forward_claim_timeout_seconds', 300);
        config()->set(
            'nntmux.orchestrator.current_forward_windows',
            'alt.test:101-10100@50100,alt.next:101-10100@50100',
        );
        DB::table('usenet_groups')->insert([
            'id' => 2,
            'name' => 'alt.next',
            'active' => 0,
            'backfill' => 1,
            'last_record' => 100,
        ]);
        DB::table('short_groups')->insert([
            'name' => 'alt.next',
            'first_record' => 1,
            'last_record' => 50_100,
            'updated' => now(),
        ]);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 17)['granted']);
        self::assertNotNull($gate->claim(17, 'alt.test', 101, 10_100));
        Settings::query()->where('name', 'orchestrator_cf_issued_at')->update(['value' => (string) (time() - 301)]);

        $retry = $gate->issue($this->safeSnapshot(), 18);

        self::assertTrue($retry['granted']);
        self::assertSame('alt.next', $retry['group']);
        self::assertSame(17, Settings::settingValue('orchestrator_cf_failed'));
        self::assertSame('', Settings::settingValue('orchestrator_cf_failure'));
        self::assertSame('alt.test:101-10100', Settings::settingValue('orchestrator_cf_blocks'));
    }

    public function test_stale_unclaimed_permit_is_reoffered_without_quarantining_the_window(): void
    {
        config()->set('nntmux.orchestrator.current_forward_claim_timeout_seconds', 300);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 17)['granted']);
        Settings::query()->where('name', 'orchestrator_cf_issued_at')->update(['value' => (string) (time() - 301)]);

        $retry = $gate->issue($this->safeSnapshot(), 18);

        self::assertTrue($retry['granted']);
        self::assertSame(18, Settings::settingValue('orchestrator_cf_permit'));
        self::assertSame(17, Settings::settingValue('orchestrator_cf_failed'));
        self::assertSame('', Settings::settingValue('orchestrator_cf_blocks'));
        self::assertSame('alt.test', $retry['group']);
        self::assertSame(101, $retry['first']);
    }

    public function test_stale_claim_is_not_reaped_while_the_worker_lock_is_held(): void
    {
        config()->set('nntmux.orchestrator.current_forward_claim_timeout_seconds', 300);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 17)['granted']);
        self::assertNotNull($gate->claim(17, 'alt.test', 101, 10_100));
        Settings::query()->where('name', 'orchestrator_cf_issued_at')->update(['value' => (string) (time() - 301)]);
        $lock = Cache::lock('nntmux:distributed-worker:current-forward', 600);
        self::assertTrue($lock->get());

        try {
            $retry = $gate->issue($this->safeSnapshot(), 18);
        } finally {
            $lock->release();
        }

        self::assertFalse($retry['granted']);
        self::assertSame('current_forward_in_progress', $retry['reason']);
        self::assertSame(0, Settings::settingValue('orchestrator_cf_failed'));
    }

    public function test_quarantine_overflow_halts_admission_without_stranding_the_claim(): void
    {
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 17)['granted']);
        self::assertNotNull($gate->claim(17, 'alt.test', 101, 10_100));
        Settings::query()->updateOrCreate([
            'name' => 'orchestrator_cf_blocks',
        ], ['value' => str_repeat('x', 900)]);

        self::assertTrue($gate->fail(17, 'failure after claim'));
        self::assertSame(17, Settings::settingValue('orchestrator_cf_failed'));
        self::assertSame(1, Settings::settingValue('orchestrator_cf_halt'));
        self::assertSame('current_forward_quarantine_full', $gate->issue($this->safeSnapshot(), 18)['reason']);
    }

    public function test_every_current_forward_setting_key_fits_the_live_schema(): void
    {
        $source = (string) file_get_contents(app_path('Services/Distributed/CurrentForwardPermitGate.php'));
        preg_match_all("/'(orchestrator_cf_[a-z_]+)'/", $source, $matches);

        self::assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $key) {
            self::assertLessThanOrEqual(25, strlen($key), $key);
        }
    }

    private function safeSnapshot(bool $highPressure = false, bool $databaseAdmissionSafe = true): PipelineSnapshot
    {
        return new PipelineSnapshot(
            100,
            10,
            2,
            0,
            0,
            highPressure: $highPressure,
            lowPressure: ! $highPressure,
            databaseCurrentWaits: 0,
            databaseAdmissionSafe: $databaseAdmissionSafe,
            eligibleNzbs: 0,
        );
    }
}
