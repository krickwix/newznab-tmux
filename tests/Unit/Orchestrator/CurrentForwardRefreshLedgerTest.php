<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardRefreshLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurrentForwardRefreshLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.current_forward_windows' => 'alt.test:101-10100@30100',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->unique();
            $table->unsignedBigInteger('last_record')->default(10_100);
        });
        Schema::create('current_forward_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('groups_id')->unique();
            $table->string('group_name')->unique();
            $table->unsignedBigInteger('anchor_first');
            $table->unsignedBigInteger('audited_last');
            $table->string('state', 32);
            $table->unsignedTinyInteger('strikes')->default(0);
            $table->dateTime('last_audited_at')->nullable();
            $table->string('last_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('current_forward_window_verifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('window_id');
            $table->unsignedBigInteger('provider_first');
            $table->unsignedBigInteger('provider_high');
            $table->dateTime('provider_observed_at');
            $table->unsignedInteger('headers');
            $table->unsignedInteger('yenc_headers');
            $table->unsignedInteger('multipart_headers');
            $table->unsignedInteger('complete_binary_files');
            $table->string('evidence_hash', 64);
            $table->string('policy_version', 32);
            $table->string('idempotency_key', 64);
            $table->dateTime('verified_at');
            $table->timestamps();
            $table->unique(['window_id', 'idempotency_key']);
        });
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('generation')->nullable();
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->unsignedSmallInteger('attempt_ordinal')->default(1);
            $table->unsignedBigInteger('retry_of_window_id')->nullable();
            $table->unsignedBigInteger('provider_first');
            $table->unsignedBigInteger('provider_high');
            $table->dateTime('provider_observed_at');
            $table->unsignedInteger('headers');
            $table->unsignedInteger('yenc_headers');
            $table->unsignedInteger('multipart_headers');
            $table->unsignedInteger('complete_binary_files');
            $table->string('evidence_hash', 64);
            $table->string('policy_version', 32);
            $table->string('state', 32);
            $table->string('failure_reason')->nullable();
            $table->unsignedBigInteger('release_baseline')->nullable();
            $table->dateTime('cursor_postdate')->nullable();
            $table->dateTime('cursor_end_postdate')->nullable();
            $table->unsignedInteger('outcome_releases')->nullable();
            $table->unsignedInteger('outcome_ready_nzbs')->nullable();
            $table->unsignedBigInteger('outcome_target_bytes')->nullable();
            $table->unsignedBigInteger('outcome_non_target_bytes')->nullable();
            $table->dateTime('offered_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('ingested_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->unsignedBigInteger('issued_verification_id')->nullable();
            $table->dateTime('attribution_started_at')->nullable();
            $table->dateTime('zero_output_deadline_at')->nullable();
            $table->dateTime('drain_deadline_at')->nullable();
            $table->string('observation_hash', 64)->nullable();
            $table->dateTime('observation_stable_since_at')->nullable();
            $table->dateTime('last_observed_at')->nullable();
            $table->unsignedBigInteger('outcome_release_high')->nullable();
            $table->unsignedInteger('outcome_pending_collections')->nullable();
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
            "CREATE UNIQUE INDEX cf_windows_live_range_uq
             ON current_forward_windows (source_id, first_article, last_article)
             WHERE state <> 'QUARANTINED'",
        );
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
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test', 'last_record' => 10_100]);
    }

    public function test_records_one_immutable_audited_window_idempotently(): void
    {
        $ledger = new CurrentForwardRefreshLedger;
        $sourceId = $ledger->seedSource('alt.test');
        $proposal = [
            'group' => 'alt.test',
            'source_id' => $sourceId,
            'first' => 10_101,
            'last' => 20_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => '2026-07-17 12:00:00',
        ];
        $audit = [
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 1,
            'evidence_hash' => str_repeat('a', 64),
        ];

        $firstId = $ledger->recordAudit($proposal, $audit, 'exact-xover-v1');
        $secondId = $ledger->recordAudit($proposal, $audit, 'exact-xover-v1');

        self::assertSame($firstId, $secondId);
        self::assertSame(1, DB::table('current_forward_windows')->count());
        self::assertSame(1, DB::table('current_forward_window_verifications')->count());
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $firstId,
            'state' => 'AUDITED',
            'first_article' => 10_101,
            'last_article' => 20_100,
            'evidence_hash' => str_repeat('a', 64),
        ]);
        $this->assertDatabaseHas('current_forward_sources', [
            'id' => $sourceId,
            'state' => 'READY',
            'anchor_first' => 101,
            'audited_last' => 20_100,
        ]);
    }

    public function test_rejects_a_direct_audit_one_article_below_the_configured_provider_reserve(): void
    {
        config()->set('nntmux.orchestrator.current_forward_provider_reserve', 19_000);
        $ledger = new CurrentForwardRefreshLedger;
        $sourceId = $ledger->seedSource('alt.test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exact-window provider contract');

        $ledger->recordAudit([
            'group' => 'alt.test',
            'source_id' => $sourceId,
            'first' => 10_101,
            'last' => 20_100,
            'provider_first' => 1,
            'provider_high' => 39_099,
            'provider_observed_at' => '2026-07-17 12:00:00',
        ], [
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 1,
            'evidence_hash' => str_repeat('a', 64),
        ], 'exact-xover-v1');
    }

    public function test_reverification_appends_evidence_without_rewriting_the_window(): void
    {
        $ledger = new CurrentForwardRefreshLedger;
        $sourceId = $ledger->seedSource('alt.test');
        $proposal = [
            'group' => 'alt.test',
            'source_id' => $sourceId,
            'first' => 10_101,
            'last' => 20_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => '2026-07-17 12:00:00',
        ];
        $audit = [
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 1,
            'evidence_hash' => str_repeat('a', 64),
        ];
        $ledger->recordAudit($proposal, $audit, 'exact-xover-v1');

        $windowId = $ledger->recordAudit(
            array_replace($proposal, [
                'provider_high' => 60_100,
                'provider_observed_at' => '2026-07-17 12:20:00',
            ]),
            array_replace($audit, ['evidence_hash' => str_repeat('b', 64)]),
            'exact-xover-v1',
        );

        self::assertSame(2, DB::table('current_forward_window_verifications')->count());
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'provider_high' => 50_100,
            'provider_observed_at' => '2026-07-17 12:00:00',
            'evidence_hash' => str_repeat('a', 64),
        ]);
        $this->assertDatabaseHas('current_forward_window_verifications', [
            'window_id' => $windowId,
            'provider_high' => 60_100,
            'evidence_hash' => str_repeat('b', 64),
        ]);
    }

    public function test_records_zero_complete_file_evidence_only_for_an_adjacent_open_continuation(): void
    {
        config()->set('nntmux.orchestrator.current_forward_continuation_enabled', true);
        $ledger = new CurrentForwardRefreshLedger;
        $sourceId = $ledger->seedSource('alt.test');
        DB::table('current_forward_sources')->where('id', $sourceId)->update(['state' => 'READY']);
        $rootId = (int) DB::table('current_forward_windows')->insertGetId([
            'source_id' => $sourceId,
            'generation' => 41,
            'first_article' => 101,
            'last_article' => 10_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => now(),
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 1,
            'evidence_hash' => str_repeat('a', 64),
            'policy_version' => 'exact-xover-v1',
            'state' => 'CONTINUATION_PENDING',
            'chain_ordinal' => 1,
            'continuation_deadline_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('current_forward_windows')->where('id', $rootId)->update(['chain_root_id' => $rootId]);

        $windowId = $ledger->recordAudit([
            'group' => 'alt.test',
            'source_id' => $sourceId,
            'first' => 10_101,
            'last' => 20_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => '2026-07-17 12:00:00',
        ], [
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 0,
            'evidence_hash' => str_repeat('b', 64),
        ], 'exact-xover-continuation-v1');

        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $windowId,
            'state' => 'AUDITED',
            'first_article' => 10_101,
            'complete_binary_files' => 0,
            'policy_version' => 'exact-xover-continuation-v1',
        ]);
    }

    public function test_creates_one_immutable_retry_for_a_quarantined_pre_ingest_attempt(): void
    {
        $ledger = new CurrentForwardRefreshLedger;
        $sourceId = $ledger->seedSource('alt.test');
        $proposal = [
            'group' => 'alt.test',
            'source_id' => $sourceId,
            'first' => 10_101,
            'last' => 20_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => '2026-07-17 12:00:00',
        ];
        $audit = [
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 1,
            'evidence_hash' => str_repeat('a', 64),
        ];
        $windowId = $ledger->recordAudit($proposal, $audit, 'exact-xover-v1');
        DB::table('current_forward_windows')->where('id', $windowId)->update([
            'generation' => 41,
            'state' => 'QUARANTINED',
            'failure_reason' => 'Current-forward object is already owned by another lineage root.',
            'claimed_at' => '2026-07-17 12:00:00',
            'settled_at' => '2026-07-17 12:01:00',
        ]);
        DB::table('current_forward_sources')->where('id', $sourceId)->update(['strikes' => 1]);

        $before = (array) DB::table('current_forward_windows')->where('id', $windowId)->first();
        $retriedId = $ledger->recordAudit(
            array_replace($proposal, [
                'provider_high' => 60_100,
                'provider_observed_at' => '2026-07-17 12:05:00',
                'mode' => 'RETRY',
                'window_id' => $windowId,
                'retry_of_window_id' => $windowId,
                'attempt_ordinal' => 2,
            ]),
            array_replace($audit, ['evidence_hash' => str_repeat('b', 64)]),
            'exact-xover-v1',
        );

        self::assertNotSame($windowId, $retriedId);
        self::assertSame($before, (array) DB::table('current_forward_windows')->where('id', $windowId)->first());
        $this->assertDatabaseHas('current_forward_windows', [
            'id' => $retriedId,
            'generation' => null,
            'state' => 'AUDITED',
            'failure_reason' => null,
            'attempt_ordinal' => 2,
            'retry_of_window_id' => $windowId,
            'chain_root_id' => $retriedId,
        ]);
        self::assertSame(2, DB::table('current_forward_window_verifications')->count());
        self::assertSame([$windowId, $retriedId], DB::table('current_forward_window_verifications')
            ->orderBy('id')->pluck('window_id')->map(static fn (mixed $id): int => (int) $id)->all());
        $this->assertDatabaseHas('current_forward_sources', [
            'id' => $sourceId,
            'audited_last' => 20_100,
            'strikes' => 1,
        ]);
    }

    public function test_refuses_to_retry_a_quarantined_attempt_that_was_ingested(): void
    {
        $ledger = new CurrentForwardRefreshLedger;
        $sourceId = $ledger->seedSource('alt.test');
        $proposal = [
            'group' => 'alt.test',
            'source_id' => $sourceId,
            'first' => 10_101,
            'last' => 20_100,
            'provider_first' => 1,
            'provider_high' => 50_100,
            'provider_observed_at' => '2026-07-17 12:00:00',
        ];
        $audit = [
            'headers' => 10_000,
            'yenc_headers' => 10_000,
            'multipart_headers' => 10_000,
            'complete_binary_files' => 1,
            'evidence_hash' => str_repeat('a', 64),
        ];
        $windowId = $ledger->recordAudit($proposal, $audit, 'exact-xover-v1');
        DB::table('current_forward_windows')->where('id', $windowId)->update([
            'generation' => 41,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_pipeline_settlement_timeout',
            'claimed_at' => '2026-07-17 12:00:00',
            'ingested_at' => '2026-07-17 12:00:30',
            'settled_at' => '2026-07-17 12:01:00',
        ]);
        DB::table('current_forward_sources')->where('id', $sourceId)->update(['strikes' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Current-forward terminal window is not safe to retry.');
        $ledger->recordAudit(
            array_replace($proposal, [
                'provider_observed_at' => '2026-07-17 12:05:00',
                'mode' => 'RETRY',
                'window_id' => $windowId,
                'retry_of_window_id' => $windowId,
                'attempt_ordinal' => 2,
            ]),
            array_replace($audit, ['evidence_hash' => str_repeat('b', 64)]),
            'exact-xover-v1',
        );
    }
}
