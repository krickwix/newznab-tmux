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
            'nntmux.orchestrator.current_forward_ledger_issuance_enabled' => false,
            'nntmux.orchestrator.current_forward_audit_max_age_seconds' => 900,
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
            $table->dateTime('last_record_postdate')->nullable();
        });
        Schema::create('short_groups', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('first_record');
            $table->unsignedBigInteger('last_record');
            $table->dateTime('updated');
        });
        Schema::create('current_forward_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('groups_id')->unique();
            $table->string('group_name')->unique();
            $table->unsignedBigInteger('anchor_first');
            $table->unsignedBigInteger('audited_last');
            $table->string('state', 32);
            $table->unsignedTinyInteger('strikes')->default(0);
            $table->dateTime('last_audited_at')->nullable();
            $table->timestamps();
        });
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->unsignedSmallInteger('attempt_ordinal')->default(1);
            $table->unsignedBigInteger('retry_of_window_id')->nullable();
            $table->unsignedBigInteger('generation')->nullable()->unique();
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->unsignedBigInteger('provider_first');
            $table->unsignedBigInteger('provider_high');
            $table->dateTime('provider_observed_at');
            $table->unsignedInteger('headers')->default(10_000);
            $table->unsignedInteger('yenc_headers')->default(10_000);
            $table->unsignedInteger('multipart_headers')->default(10_000);
            $table->unsignedInteger('complete_binary_files')->default(1);
            $table->string('state', 32);
            $table->unsignedBigInteger('release_baseline')->nullable();
            $table->dateTime('cursor_postdate')->nullable();
            $table->dateTime('cursor_end_postdate')->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->dateTime('offered_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('ingested_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->unsignedInteger('outcome_releases')->nullable();
            $table->unsignedInteger('outcome_ready_nzbs')->nullable();
            $table->unsignedBigInteger('issued_verification_id')->nullable();
            $table->unsignedBigInteger('chain_root_id')->nullable();
            $table->unsignedBigInteger('parent_window_id')->nullable();
            $table->unsignedTinyInteger('chain_ordinal')->nullable()->default(1);
            $table->dateTime('continuation_deadline_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['source_id', 'first_article', 'last_article', 'attempt_ordinal'],
                'cf_windows_source_range_attempt_uq',
            );
            $table->unique('retry_of_window_id', 'cf_windows_retry_parent_uq');
        });
        DB::statement(
            "CREATE UNIQUE INDEX cf_windows_source_range_live_uq
             ON current_forward_windows (source_id, first_article, last_article)
             WHERE state <> 'QUARANTINED'",
        );
        $this->createVerificationTable();
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

    public function test_unsettled_backfill_claim_blocks_current_forward_admission(): void
    {
        Settings::query()->updateOrCreate(['name' => 'orchestrator_bf_claimed'], ['value' => '99']);
        Settings::query()->updateOrCreate(['name' => 'orchestrator_bf_completed'], ['value' => '98']);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 17);

        self::assertFalse($issued['granted']);
        self::assertSame('permit_conflict_or_stale_lease', $issued['reason']);
        self::assertSame(0, Settings::settingValue('orchestrator_cf_permit'));
    }

    public function test_failed_backfill_claim_does_not_block_current_forward_admission(): void
    {
        Settings::query()->updateOrCreate(['name' => 'orchestrator_bf_claimed'], ['value' => '99']);
        Settings::query()->updateOrCreate(['name' => 'orchestrator_bf_completed'], ['value' => '98']);
        Settings::query()->updateOrCreate(['name' => 'orchestrator_bf_failed'], ['value' => '99']);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 17);

        self::assertTrue($issued['granted']);
        self::assertSame(17, Settings::settingValue('orchestrator_cf_permit'));
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

    public function test_ledger_issuance_is_default_off_and_does_not_change_static_exhaustion(): void
    {
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertFalse($issued['granted']);
        self::assertSame('current_forward_corridors_exhausted', $issued['reason']);
        self::assertSame(0, Settings::settingValue('orchestrator_cf_permit'));
        self::assertSame('AUDITED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
        self::assertNull(DB::table('current_forward_windows')->where('id', $windowId)->value('generation'));
    }

    public function test_enabled_ledger_issuance_offers_claims_and_ingests_one_exact_audited_window(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update([
            'last_record' => 10_100,
            'last_record_postdate' => '2026-07-17 10:00:00',
        ]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;

        $issued = $gate->issue($this->safeSnapshot(), 42);

        self::assertTrue($issued['granted']);
        self::assertSame('current_forward_audited_permit_granted', $issued['reason']);
        self::assertSame('alt.test', $issued['group']);
        self::assertSame(10_101, $issued['first']);
        self::assertSame(20_100, $issued['last']);
        self::assertSame(42, Settings::settingValue('orchestrator_cf_permit'));
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'generation' => 42,
            'state' => 'OFFERED',
            'cursor_postdate' => '2026-07-17 10:00:00',
        ]);

        self::assertSame([
            'generation' => 42,
            'group' => 'alt.test',
            'first' => 10_101,
            'last' => 20_100,
            'stop' => 20_100,
        ], $gate->claim(42, 'alt.test', 10_101, 20_100));
        self::assertSame('CLAIMED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));

        DB::table('usenet_groups')->where('name', 'alt.test')->update([
            'last_record' => 20_100,
            'last_record_postdate' => '2026-07-17 10:05:00',
        ]);
        self::assertTrue($gate->complete(42));
        self::assertSame('INGESTED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
        self::assertNotNull(DB::table('current_forward_windows')->where('id', $windowId)->value('ingested_at'));
        self::assertSame(
            '2026-07-17 10:05:00',
            DB::table('current_forward_windows')->where('id', $windowId)->value('cursor_end_postdate'),
        );
    }

    public function test_normal_ledger_issuance_rejects_continuation_only_partial_evidence(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $this->createVerificationTable();
        $this->verification($windowId, 'exact-xover-continuation-v1', 0);

        $rejected = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertFalse($rejected['granted']);
        self::assertSame('audited_window_partial_only', $rejected['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'AUDITED',
            'generation' => null,
        ]);

        $this->verification($windowId, 'exact-xover-v1', 1);
        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 43);

        self::assertTrue($issued['granted']);
        self::assertSame('current_forward_audited_permit_granted', $issued['reason']);
    }

    public function test_ledger_offer_pins_the_latest_append_only_verification(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $firstVerification = DB::table('current_forward_window_verifications')->insertGetId([
            'window_id' => $windowId,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => now()->subMinute(),
            'verified_at' => now()->subMinute(),
        ]);
        $latestVerification = DB::table('current_forward_window_verifications')->insertGetId([
            'window_id' => $windowId,
            'provider_first' => 1,
            'provider_high' => 60_100,
            'provider_observed_at' => now(),
            'verified_at' => now(),
        ]);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertTrue($issued['granted']);
        self::assertNotSame($firstVerification, $latestVerification);
        self::assertSame(
            $latestVerification,
            DB::table('current_forward_windows')->where('id', $windowId)->value('issued_verification_id'),
        );
    }

    public function test_ledger_offer_rejects_a_future_dated_append_only_verification(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        DB::table('current_forward_window_verifications')->insert([
            'window_id' => $windowId,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => now()->addMinutes(2),
            'verified_at' => now()->addMinutes(2),
        ]);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertFalse($issued['granted']);
        self::assertSame('audited_window_stale', $issued['reason']);
    }

    public function test_ledger_offer_rejects_a_future_dated_provider_snapshot(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $this->auditedWindow(10_101, 20_100);
        DB::table('short_groups')->where('name', 'alt.test')->update([
            'updated' => now()->addMinutes(2),
        ]);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertFalse($issued['granted']);
        self::assertSame('provider_range_drift', $issued['reason']);
    }

    public function test_disabling_ledger_issuance_after_offer_denies_the_claim(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);

        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', false);

        self::assertNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'generation' => 42,
            'state' => 'OFFERED',
        ]);
        self::assertSame(42, Settings::settingValue('orchestrator_cf_permit'));
        self::assertSame(0, Settings::settingValue('orchestrator_cf_claimed'));
    }

    public function test_claim_denies_an_offer_whose_pinned_verification_became_stale(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        $verificationId = DB::table('current_forward_windows')->where('id', $windowId)->value('issued_verification_id');
        DB::table('current_forward_window_verifications')->where('id', $verificationId)->update([
            'provider_observed_at' => now()->subSeconds(901),
        ]);

        self::assertNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        self::assertSame('OFFERED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
    }

    public function test_claim_denies_an_offer_whose_source_audit_became_stale(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        DB::table('current_forward_sources')->update([
            'last_audited_at' => now()->subSeconds(901),
        ]);

        self::assertNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        self::assertSame('OFFERED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
    }

    public function test_claim_denies_an_offer_when_the_provider_snapshot_became_stale(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        DB::table('short_groups')->where('name', 'alt.test')->update([
            'updated' => now()->subSeconds(601),
        ]);

        self::assertNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        self::assertSame('OFFERED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
    }

    public function test_claim_denies_an_offer_when_provider_retention_no_longer_covers_it(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        DB::table('short_groups')->where('name', 'alt.test')->update([
            'first_record' => 10_102,
            'updated' => now(),
        ]);

        self::assertNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        self::assertSame('OFFERED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
    }

    public function test_claim_denies_an_offer_when_the_group_cursor_moved(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_101]);

        self::assertNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        self::assertSame('OFFERED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
    }

    public function test_claim_denies_an_offer_when_the_source_is_operator_halted(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        DB::table('current_forward_sources')->update(['state' => 'HALTED']);

        self::assertNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        self::assertSame('OFFERED', DB::table('current_forward_windows')->where('id', $windowId)->value('state'));
    }

    public function test_enabled_ledger_issuance_fails_closed_without_a_fresh_audited_window(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertFalse($issued['granted']);
        self::assertSame('audited_window_unavailable', $issued['reason']);
        self::assertSame(0, Settings::settingValue('orchestrator_cf_permit'));
    }

    public function test_enabled_ledger_issuance_fails_closed_without_append_only_verification_schema(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        Schema::drop('current_forward_window_verifications');

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertFalse($issued['granted']);
        self::assertSame('audited_window_partial_only', $issued['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'AUDITED',
            'generation' => null,
        ]);
    }

    public function test_enabled_ledger_issuance_never_falls_back_to_an_unaudited_static_window(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertFalse($issued['granted']);
        self::assertSame('audited_window_unavailable', $issued['reason']);
        self::assertSame(0, Settings::settingValue('orchestrator_cf_permit'));
    }

    public function test_more_than_sixteen_invalid_audits_cannot_hide_one_eligible_window(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        $entries = [];
        for ($index = 1; $index <= 17; $index++) {
            $name = 'alt.rank'.$index;
            $entries[] = $name.':101-10100@30100';
            DB::table('usenet_groups')->insert([
                'id' => $index + 1,
                'name' => $name,
                'active' => 0,
                'backfill' => 1,
                'last_record' => 100,
            ]);
            DB::table('short_groups')->insert([
                'name' => $name,
                'first_record' => 1,
                'last_record' => 50_100,
                'updated' => now(),
            ]);
            $sourceId = DB::table('current_forward_sources')->insertGetId([
                'groups_id' => $index + 1,
                'group_name' => $name,
                'anchor_first' => 101,
                'audited_last' => 10_100,
                'state' => $index < 17 ? 'HALTED' : 'READY',
                'strikes' => $index < 17 ? 2 : 0,
                'last_audited_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $windowId = (int) DB::table('current_forward_windows')->insertGetId([
                'source_id' => $sourceId,
                'first_article' => 101,
                'last_article' => 10_100,
                'provider_first' => 1,
                'provider_high' => 50_100,
                'provider_observed_at' => now(),
                'state' => 'AUDITED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->verification($windowId, 'exact-xover-v1', 1);
        }
        config()->set('nntmux.orchestrator.current_forward_windows', implode(',', $entries));

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertTrue($issued['granted']);
        self::assertSame('alt.rank17', $issued['group']);
    }

    public function test_audited_issuance_prefers_proven_productive_source_over_lower_window_id(): void
    {
        config([
            'nntmux.orchestrator.current_forward_ledger_issuance_enabled' => true,
            'nntmux.orchestrator.current_forward_windows' => 'alt.low:101-10100@30100,alt.proven:101-10100@30100',
        ]);
        foreach ([2 => 'alt.low', 3 => 'alt.proven'] as $groupId => $name) {
            DB::table('usenet_groups')->insert([
                'id' => $groupId,
                'name' => $name,
                'active' => 0,
                'backfill' => 1,
                'last_record' => 100,
            ]);
            DB::table('short_groups')->insert([
                'name' => $name,
                'first_record' => 1,
                'last_record' => 50_100,
                'updated' => now(),
            ]);
            $sourceId = DB::table('current_forward_sources')->insertGetId([
                'groups_id' => $groupId,
                'group_name' => $name,
                'anchor_first' => 101,
                'audited_last' => 10_100,
                'state' => 'READY',
                'strikes' => 0,
                'last_audited_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $windowId = (int) DB::table('current_forward_windows')->insertGetId([
                'source_id' => $sourceId,
                'first_article' => 101,
                'last_article' => 10_100,
                'provider_first' => 1,
                'provider_high' => 50_100,
                'provider_observed_at' => now(),
                'complete_binary_files' => 5,
                'state' => 'AUDITED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->verification($windowId, 'exact-xover-v1', 5);
            if ($name === 'alt.proven') {
                DB::table('current_forward_windows')->insert([
                    'source_id' => $sourceId,
                    'generation' => 17,
                    'first_article' => 20_101,
                    'last_article' => 30_100,
                    'provider_first' => 1,
                    'provider_high' => 60_100,
                    'provider_observed_at' => now()->subHour(),
                    'state' => 'PRODUCTIVE',
                    'settled_at' => now()->subHour(),
                    'outcome_releases' => 9,
                    'outcome_ready_nzbs' => 9,
                    'created_at' => now()->subHour(),
                    'updated_at' => now()->subHour(),
                ]);
            }
        }

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertTrue($issued['granted']);
        self::assertSame('alt.proven', $issued['group']);
    }

    public function test_audited_ranking_uses_the_latest_append_only_verification(): void
    {
        config([
            'nntmux.orchestrator.current_forward_ledger_issuance_enabled' => true,
            'nntmux.orchestrator.current_forward_windows' => 'alt.steady:101-10100@30100,alt.degraded:101-10100@30100',
        ]);
        $steadyId = $this->auditedRankCandidate(2, 'alt.steady');
        $degradedId = $this->auditedRankCandidate(3, 'alt.degraded');
        $this->verification($steadyId, 'exact-xover-v1', 5, now()->subSeconds(30));
        $this->verification($degradedId, 'exact-xover-v1', 20, now()->subMinutes(2));
        $this->verification($degradedId, 'exact-xover-v1', 1, now());

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertTrue($issued['granted']);
        self::assertSame('alt.steady', $issued['group']);
        self::assertSame($steadyId, (int) DB::table('current_forward_windows')
            ->where('generation', 42)
            ->value('id'));
    }

    public function test_audited_issuance_accepts_one_well_formed_immutable_retry_attempt(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        [$predecessorId, $retryId] = $this->auditedRetryWindow();

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertTrue($issued['granted']);
        self::assertSame('current_forward_audited_permit_granted', $issued['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $predecessorId,
            'state' => 'QUARANTINED',
            'attempt_ordinal' => 1,
        ]);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $retryId,
            'state' => 'OFFERED',
            'generation' => 42,
            'attempt_ordinal' => 2,
            'retry_of_window_id' => $predecessorId,
        ]);
    }

    public function test_audited_issuance_rejects_retry_when_the_parent_reached_ingested_state(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        [$predecessorId, $retryId] = $this->auditedRetryWindow();
        DB::table('current_forward_windows')->where('id', $predecessorId)->update([
            'ingested_at' => now()->subMinute(),
        ]);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertFalse($issued['granted']);
        self::assertSame('audited_window_retry_invalid', $issued['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $retryId,
            'state' => 'AUDITED',
            'generation' => null,
        ]);
    }

    public function test_pending_exact_lineage_root_prioritizes_one_adjacent_audited_continuation(): void
    {
        config([
            'nntmux.orchestrator.current_forward_ledger_issuance_enabled' => true,
            'nntmux.orchestrator.current_forward_continuation_enabled' => true,
            'nntmux.orchestrator.current_forward_continuation_max_windows' => 3,
            'nntmux.orchestrator.current_forward_continuation_max_parts' => 30_000,
            'nntmux.orchestrator.current_forward_continuation_max_binaries' => 1_500,
            'nntmux.orchestrator.current_forward_continuation_max_collections' => 300,
            'nntmux.orchestrator.current_forward_continuation_projected_binaries' => 500,
            'nntmux.orchestrator.current_forward_continuation_projected_collections' => 100,
            'nntmux.orchestrator.backfill_growth_per_10k.collections' => 1_000,
        ]);
        $this->createContinuationObservationTables();
        $this->createVerificationTable();
        DB::table('usenet_groups')->where('name', 'alt.test')->update([
            'last_record' => 10_100,
            'last_record_postdate' => '2026-07-17 10:00:00',
        ]);
        $sourceId = (int) DB::table('current_forward_sources')->insertGetId([
            'groups_id' => 1,
            'group_name' => 'alt.test',
            'anchor_first' => 101,
            'audited_last' => 20_100,
            'state' => 'READY',
            'strikes' => 0,
            'last_audited_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rootId = (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $sourceId,
            'generation' => 41,
            'first_article' => 101,
            'last_article' => 10_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => now(),
            'state' => 'CONTINUATION_PENDING',
            'chain_root_id' => null,
            'chain_ordinal' => 1,
            'continuation_deadline_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('current_forward_windows')->where('id', $rootId)->update(['chain_root_id' => $rootId]);
        DB::table('current_forward_continuation_observations')->insert([
            'window_id' => $rootId,
            'chain_root_id' => $rootId,
            'chain_ordinal' => 1,
            'observed_at' => now()->subMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $childId = (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $sourceId,
            'generation' => null,
            'first_article' => 10_101,
            'last_article' => 20_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => now(),
            'state' => 'AUDITED',
            'chain_root_id' => null,
            'chain_ordinal' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('current_forward_windows')->where('id', $childId)->update(['chain_root_id' => $childId]);
        $this->verification(
            $childId,
            'exact-xover-v1',
            1,
            now()->subMinutes(20),
        );
        DB::table('collections')->insert([
            'id' => 1,
            'totalfiles' => 2,
            'releases_id' => null,
        ]);
        DB::table('binaries')->insert([
            'id' => 1,
            'collections_id' => 1,
            'totalparts' => 200,
            'currentparts' => 100,
        ]);
        DB::table('current_forward_window_objects')->insert([
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => 'COLLECTION',
                'object_id' => 1,
                'parent_object_id' => null,
                'inserted_parts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'window_id' => $rootId,
                'chain_root_id' => $rootId,
                'object_type' => 'BINARY',
                'object_id' => 1,
                'parent_object_id' => 1,
                'inserted_parts' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertTrue($issued['granted']);
        self::assertSame('current_forward_continuation_permit_granted', $issued['reason']);
        self::assertSame(10_101, $issued['first']);
        self::assertSame(20_100, $issued['last']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $childId,
            'generation' => 42,
            'state' => 'OFFERED',
            'chain_root_id' => $rootId,
            'parent_window_id' => $rootId,
            'chain_ordinal' => 2,
        ]);
        self::assertSame('CONTINUATION_PENDING', DB::table('current_forward_windows')
            ->where('id', $rootId)->value('state'));
        self::assertNotNull((new CurrentForwardPermitGate)->claim(
            42,
            'alt.test',
            10_101,
            20_100,
        ));
    }

    public function test_enabled_ledger_issuance_quarantines_a_failed_claim_and_strikes_the_source(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        self::assertNotNull($gate->claim(42, 'alt.test', 10_101, 20_100));

        self::assertTrue($gate->fail(42, 'provider returned a partial boundary'));

        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'generation' => 42,
            'state' => 'QUARANTINED',
            'failure_reason' => 'provider returned a partial boundary',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'READY',
            'strikes' => 1,
        ]);
    }

    public function test_stale_unclaimed_ledger_offer_returns_to_audited_for_reverification_without_a_source_strike(): void
    {
        config([
            'nntmux.orchestrator.current_forward_ledger_issuance_enabled' => true,
            'nntmux.orchestrator.current_forward_claim_timeout_seconds' => 300,
        ]);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        Settings::query()->where('name', 'orchestrator_cf_issued_at')->update([
            'value' => (string) (time() - 301),
        ]);

        $retry = $gate->issue($this->safeSnapshot(), 43);

        self::assertFalse($retry['granted']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'AUDITED',
            'generation' => null,
            'failure_reason' => 'unclaimed_timeout_reaudit_required',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'READY',
            'strikes' => 0,
        ]);
        self::assertSame(42, Settings::settingValue('orchestrator_cf_failed'));
    }

    public function test_stale_claim_with_completed_cursor_reconciles_to_ingested(): void
    {
        config([
            'nntmux.orchestrator.current_forward_ledger_issuance_enabled' => true,
            'nntmux.orchestrator.current_forward_claim_timeout_seconds' => 300,
        ]);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        self::assertNotNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 20_100]);
        Settings::query()->where('name', 'orchestrator_cf_issued_at')->update([
            'value' => (string) (time() - 301),
        ]);

        $retry = $gate->issue($this->safeSnapshot(), 43);

        self::assertFalse($retry['granted']);
        self::assertSame('current_forward_refresh_in_progress', $retry['reason']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'INGESTED',
        ]);
        self::assertSame(42, Settings::settingValue('orchestrator_cf_completed'));
        self::assertSame(0, Settings::settingValue('orchestrator_cf_failed'));
    }

    public function test_stale_claim_without_cursor_movement_quarantines_and_strikes_source(): void
    {
        config([
            'nntmux.orchestrator.current_forward_ledger_issuance_enabled' => true,
            'nntmux.orchestrator.current_forward_claim_timeout_seconds' => 300,
        ]);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        self::assertNotNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        Settings::query()->where('name', 'orchestrator_cf_issued_at')->update([
            'value' => (string) (time() - 301),
        ]);

        $retry = $gate->issue($this->safeSnapshot(), 43);

        self::assertFalse($retry['granted']);
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'QUARANTINED',
            'failure_reason' => 'claim_timeout',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'READY',
            'strikes' => 1,
        ]);
        self::assertSame(42, Settings::settingValue('orchestrator_cf_failed'));
    }

    public function test_failure_after_cursor_commit_reconciles_claim_to_ingested_without_a_strike(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        $gate = new CurrentForwardPermitGate;
        self::assertTrue($gate->issue($this->safeSnapshot(), 42)['granted']);
        self::assertNotNull($gate->claim(42, 'alt.test', 10_101, 20_100));
        DB::table('usenet_groups')->where('name', 'alt.test')->update([
            'last_record' => 20_100,
            'last_record_postdate' => '2026-07-17 10:05:00',
        ]);

        self::assertTrue($gate->fail(42, 'completion receipt could not be recorded'));

        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'INGESTED',
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'group_name' => 'alt.test',
            'state' => 'READY',
            'strikes' => 0,
        ]);
        self::assertSame(42, Settings::settingValue('orchestrator_cf_completed'));
        self::assertSame(0, Settings::settingValue('orchestrator_cf_failed'));
    }

    public function test_ledger_permit_uses_the_configured_provider_reserve(): void
    {
        config()->set('nntmux.orchestrator.current_forward_ledger_issuance_enabled', true);
        config()->set('nntmux.orchestrator.current_forward_provider_reserve', 19_000);
        DB::table('usenet_groups')->where('name', 'alt.test')->update(['last_record' => 10_100]);
        $windowId = $this->auditedWindow(10_101, 20_100);
        DB::table('current_forward_windows')->where('id', $windowId)->update([
            'provider_high' => 39_100,
        ]);
        DB::table('current_forward_window_verifications')->where('window_id', $windowId)->update([
            'provider_high' => 39_100,
        ]);
        DB::table('short_groups')->where('name', 'alt.test')->update([
            'last_record' => 39_100,
        ]);

        $issued = (new CurrentForwardPermitGate)->issue($this->safeSnapshot(), 42);

        self::assertTrue($issued['granted']);
        self::assertSame('current_forward_audited_permit_granted', $issued['reason']);
        self::assertNotNull((new CurrentForwardPermitGate)->claim(42, 'alt.test', 10_101, 20_100));
    }

    private function auditedWindow(int $first, int $last): int
    {
        $sourceId = (int) DB::table('current_forward_sources')->insertGetId([
            'groups_id' => 1,
            'group_name' => 'alt.test',
            'anchor_first' => 101,
            'audited_last' => $last,
            'state' => 'READY',
            'strikes' => 0,
            'last_audited_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $windowId = (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $sourceId,
            'generation' => null,
            'first_article' => $first,
            'last_article' => $last,
            'provider_first' => 1,
            'provider_high' => $last + 30_000,
            'provider_observed_at' => now(),
            'state' => 'AUDITED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->verification($windowId, 'exact-xover-v1', 1);

        return $windowId;
    }

    private function auditedRankCandidate(int $groupId, string $name): int
    {
        DB::table('usenet_groups')->insert([
            'id' => $groupId,
            'name' => $name,
            'active' => 0,
            'backfill' => 1,
            'last_record' => 100,
        ]);
        DB::table('short_groups')->insert([
            'name' => $name,
            'first_record' => 1,
            'last_record' => 50_100,
            'updated' => now(),
        ]);
        $sourceId = (int) DB::table('current_forward_sources')->insertGetId([
            'groups_id' => $groupId,
            'group_name' => $name,
            'anchor_first' => 101,
            'audited_last' => 10_100,
            'state' => 'READY',
            'strikes' => 0,
            'last_audited_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $sourceId,
            'first_article' => 101,
            'last_article' => 10_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => now(),
            'state' => 'AUDITED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{int,int} */
    private function auditedRetryWindow(): array
    {
        $sourceId = (int) DB::table('current_forward_sources')->insertGetId([
            'groups_id' => 1,
            'group_name' => 'alt.test',
            'anchor_first' => 101,
            'audited_last' => 20_100,
            'state' => 'READY',
            'strikes' => 1,
            'last_audited_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $base = [
            'source_id' => $sourceId,
            'first_article' => 10_101,
            'last_article' => 20_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $predecessorId = (int) DB::table('current_forward_windows')->insertGetId($base + [
            'attempt_ordinal' => 1,
            'generation' => 41,
            'state' => 'QUARANTINED',
            'failure_reason' => 'object ownership handoff failed before ingest',
            'claimed_at' => now()->subMinutes(2),
            'settled_at' => now()->subMinute(),
        ]);
        DB::table('current_forward_windows')->where('id', $predecessorId)->update([
            'chain_root_id' => $predecessorId,
        ]);
        $retryId = (int) DB::table('current_forward_windows')->insertGetId($base + [
            'attempt_ordinal' => 2,
            'retry_of_window_id' => $predecessorId,
            'state' => 'AUDITED',
        ]);
        DB::table('current_forward_windows')->where('id', $retryId)->update([
            'chain_root_id' => $retryId,
        ]);
        $this->verification($retryId, 'exact-xover-v1', 1);

        return [$predecessorId, $retryId];
    }

    private function createContinuationObservationTables(): void
    {
        Schema::create('current_forward_object_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('object_type', 16);
            $table->unsignedBigInteger('object_id');
            $table->unsignedBigInteger('chain_root_id');
            $table->timestamps();
            $table->unique(['object_type', 'object_id']);
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
        Schema::create('current_forward_continuation_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('window_id');
            $table->unsignedBigInteger('chain_root_id');
            $table->unsignedTinyInteger('chain_ordinal');
            $table->dateTime('observed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('totalfiles');
            $table->unsignedBigInteger('releases_id')->nullable();
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collections_id');
            $table->unsignedInteger('totalparts');
            $table->unsignedInteger('currentparts');
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('categories_id');
            $table->unsignedTinyInteger('nzbstatus');
            $table->unsignedBigInteger('size');
        });
    }

    private function createVerificationTable(): void
    {
        if (Schema::hasTable('current_forward_window_verifications')) {
            return;
        }
        Schema::create('current_forward_window_verifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('window_id');
            $table->unsignedBigInteger('provider_first');
            $table->unsignedBigInteger('provider_high');
            $table->dateTime('provider_observed_at');
            $table->unsignedInteger('headers')->default(10_000);
            $table->unsignedInteger('yenc_headers')->default(10_000);
            $table->unsignedInteger('multipart_headers')->default(10_000);
            $table->unsignedInteger('complete_binary_files')->default(1);
            $table->string('policy_version', 32)->default('exact-xover-v1');
            $table->dateTime('verified_at');
        });
    }

    private function verification(
        int $windowId,
        string $policy,
        int $completeFiles,
        mixed $observedAt = null,
    ): void {
        $observedAt ??= now();
        DB::table('current_forward_window_verifications')->insert([
            'window_id' => $windowId,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => $observedAt,
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => $completeFiles,
            'policy_version' => $policy,
            'verified_at' => $observedAt,
        ]);
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
