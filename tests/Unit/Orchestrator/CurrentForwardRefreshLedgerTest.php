<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\CurrentForwardRefreshLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
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
        });
        Schema::create('current_forward_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('groups_id')->unique();
            $table->string('group_name')->unique();
            $table->unsignedBigInteger('anchor_first');
            $table->unsignedBigInteger('audited_last');
            $table->string('state', 32);
            $table->dateTime('last_audited_at')->nullable();
            $table->timestamps();
        });
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
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
            $table->timestamps();
            $table->unique(['source_id', 'first_article', 'last_article']);
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
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

    public function test_refuses_to_rewrite_recorded_provider_evidence(): void
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable and cannot be rewritten');

        $ledger->recordAudit(
            array_replace($proposal, ['provider_high' => 50_101]),
            $audit,
            'exact-xover-v1',
        );
    }
}
