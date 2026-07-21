<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardRefreshPlanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurrentForwardRefreshPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.current_forward_refresh_enabled' => true,
            'nntmux.orchestrator.current_forward_windows' => 'alt.test:101-10100@30100',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('current_forward_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('groups_id')->unique();
            $table->string('group_name')->unique();
            $table->string('state', 32);
            $table->unsignedBigInteger('audited_last');
            $table->unsignedTinyInteger('strikes')->default(0);
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
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('generation')->nullable();
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->string('state', 32);
            $table->unsignedSmallInteger('attempt_ordinal')->default(1);
            $table->unsignedBigInteger('retry_of_window_id')->nullable();
            $table->string('failure_reason')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('ingested_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->unsignedBigInteger('chain_root_id')->nullable();
            $table->unsignedInteger('outcome_releases')->nullable();
            $table->unsignedInteger('outcome_ready_nzbs')->nullable();
        });
        Schema::create('current_forward_window_objects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('window_id');
            $table->unsignedBigInteger('chain_root_id');
        });
        Schema::create('current_forward_object_owners', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chain_root_id');
        });
        Schema::create('current_forward_continuation_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chain_root_id');
        });
        Schema::create('current_forward_window_verifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('window_id');
            $table->dateTime('provider_observed_at');
            $table->dateTime('verified_at');
        });

        DB::table('current_forward_sources')->insert([
            'groups_id' => 1,
            'group_name' => 'alt.test',
            'state' => 'READY',
            'audited_last' => 10_100,
        ]);
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
            'updated' => '2026-07-17 12:00:00',
        ]);
    }

    public function test_proposes_only_the_next_exact_window_for_an_explicit_trusted_source(): void
    {
        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertTrue($plan['enabled']);
        self::assertSame('proposal_available', $plan['reason']);
        self::assertSame([[
            'group' => 'alt.test',
            'source_id' => 1,
            'first' => 10_101,
            'last' => 20_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => '2026-07-17 12:00:00',
            'mode' => 'NEW',
            'window_id' => 0,
            'retry_of_window_id' => 0,
            'attempt_ordinal' => 1,
        ]], $plan['proposals']);
        self::assertSame([], $plan['rejections']);
    }

    public function test_does_not_reaudit_an_immutable_window_waiting_for_issuance(): void
    {
        $windowId = DB::table('current_forward_windows')->insertGetId([
            'source_id' => 1,
            'generation' => null,
            'first_article' => 10_101,
            'last_article' => 20_100,
            'state' => 'AUDITED',
        ]);
        DB::table('current_forward_sources')->where('id', 1)->update(['audited_last' => 20_100]);
        DB::table('current_forward_window_verifications')->insert([
            'window_id' => $windowId,
            'provider_observed_at' => '2026-07-17 12:00:00',
            'verified_at' => '2026-07-17 12:04:00',
        ]);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame([], $plan['proposals']);
        self::assertSame(['alt.test' => 'audited_window_pending'], $plan['rejections']);
    }

    public function test_stale_pending_window_is_reverified_before_ledger_cursor_drift(): void
    {
        $windowId = DB::table('current_forward_windows')->insertGetId([
            'source_id' => 1,
            'generation' => null,
            'first_article' => 10_101,
            'last_article' => 20_100,
            'state' => 'AUDITED',
        ]);
        DB::table('current_forward_sources')->where('id', 1)->update(['audited_last' => 20_100]);
        DB::table('current_forward_window_verifications')->insert([
            'window_id' => $windowId,
            'provider_observed_at' => '2026-07-17 11:00:00',
            'verified_at' => '2026-07-17 11:00:00',
        ]);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame('proposal_available', $plan['reason']);
        self::assertSame('REVERIFY', $plan['proposals'][0]['mode']);
        self::assertSame($windowId, $plan['proposals'][0]['window_id']);
        self::assertSame(10_101, $plan['proposals'][0]['first']);
        self::assertSame([], $plan['rejections']);
    }

    public function test_quarantined_pre_ingest_window_is_proposed_for_one_exact_retry(): void
    {
        $windowId = DB::table('current_forward_windows')->insertGetId([
            'source_id' => 1,
            'generation' => 41,
            'first_article' => 10_101,
            'last_article' => 20_100,
            'state' => 'QUARANTINED',
            'failure_reason' => 'Current-forward object is already owned by another lineage root.',
            'claimed_at' => '2026-07-17 12:00:00',
            'settled_at' => '2026-07-17 12:01:00',
        ]);
        DB::table('current_forward_windows')->where('id', $windowId)->update(['chain_root_id' => $windowId]);
        DB::table('current_forward_sources')->where('id', 1)->update([
            'audited_last' => 20_100,
            'strikes' => 1,
        ]);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame('proposal_available', $plan['reason']);
        self::assertSame('RETRY', $plan['proposals'][0]['mode']);
        self::assertSame($windowId, $plan['proposals'][0]['window_id']);
        self::assertSame(10_101, $plan['proposals'][0]['first']);
    }

    public function test_quarantined_window_that_reached_ingested_state_is_not_retryable(): void
    {
        $windowId = DB::table('current_forward_windows')->insertGetId([
            'source_id' => 1,
            'generation' => 41,
            'first_article' => 10_101,
            'last_article' => 20_100,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_pipeline_settlement_timeout',
            'claimed_at' => '2026-07-17 12:00:00',
            'ingested_at' => '2026-07-17 12:00:30',
            'settled_at' => '2026-07-17 12:01:00',
        ]);
        DB::table('current_forward_windows')->where('id', $windowId)->update(['chain_root_id' => $windowId]);
        DB::table('current_forward_sources')->where('id', 1)->update([
            'audited_last' => 20_100,
            'strikes' => 1,
        ]);
        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame([], $plan['proposals']);
        self::assertSame(['alt.test' => 'window_retry_unsafe'], $plan['rejections']);
    }

    public function test_refresh_is_fail_closed_by_default(): void
    {
        config()->set('nntmux.orchestrator.current_forward_refresh_enabled', false);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertFalse($plan['enabled']);
        self::assertSame('refresh_disabled', $plan['reason']);
        self::assertSame([], $plan['proposals']);
    }

    public function test_rejects_provider_coverage_without_the_full_twenty_thousand_reserve(): void
    {
        DB::table('short_groups')->where('name', 'alt.test')->update(['last_record' => 40_099]);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame([], $plan['proposals']);
        self::assertSame(['alt.test' => 'provider_range_drift'], $plan['rejections']);
    }

    public function test_accepts_a_provider_range_at_the_configured_nineteen_thousand_reserve(): void
    {
        config()->set('nntmux.orchestrator.current_forward_provider_reserve', 19_000);
        DB::table('short_groups')->where('name', 'alt.test')->update(['last_record' => 39_100]);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame('proposal_available', $plan['reason']);
        self::assertSame(20_100, $plan['proposals'][0]['last']);
        self::assertSame(39_100, $plan['proposals'][0]['provider_high']);
        self::assertSame([], $plan['rejections']);
    }

    public function test_rejects_one_article_below_the_configured_reserve(): void
    {
        config()->set('nntmux.orchestrator.current_forward_provider_reserve', 19_000);
        DB::table('short_groups')->where('name', 'alt.test')->update(['last_record' => 39_099]);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame([], $plan['proposals']);
        self::assertSame(['alt.test' => 'provider_range_drift'], $plan['rejections']);
    }

    public function test_rejects_a_gap_between_the_durable_ledger_and_live_cursor(): void
    {
        DB::table('current_forward_sources')->where('id', 1)->update(['audited_last' => 100]);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame([], $plan['proposals']);
        self::assertSame(['alt.test' => 'ledger_cursor_drift'], $plan['rejections']);
    }
}
