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
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->string('state', 32);
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
        ]], $plan['proposals']);
        self::assertSame([], $plan['rejections']);
    }

    public function test_does_not_reaudit_an_immutable_window_waiting_for_issuance(): void
    {
        DB::table('current_forward_windows')->insert([
            'source_id' => 1,
            'first_article' => 10_101,
            'last_article' => 20_100,
            'state' => 'AUDITED',
        ]);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame([], $plan['proposals']);
        self::assertSame(['alt.test' => 'audited_window_pending'], $plan['rejections']);
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

    public function test_rejects_a_gap_between_the_durable_ledger_and_live_cursor(): void
    {
        DB::table('current_forward_sources')->where('id', 1)->update(['audited_last' => 100]);

        $plan = (new CurrentForwardRefreshPlanner)->plan(strtotime('2026-07-17 12:05:00'));

        self::assertSame([], $plan['proposals']);
        self::assertSame(['alt.test' => 'ledger_cursor_drift'], $plan['rejections']);
    }
}
