<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class RecoverCurrentForwardContinuationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.orchestrator.current_forward_continuation_enabled' => true,
            'nntmux.orchestrator.current_forward_continuation_deadline_seconds' => 7_200,
        ]);
        DB::purge('sqlite');
        $this->createSchema();
        $this->seedLegacyCanary();
    }

    public function test_dry_run_is_deterministic_exact_to_the_range_and_read_only(): void
    {
        $first = $this->runRecovery();
        self::assertSame(0, $first['exit']);
        $hash = $this->evidenceHash($first['output']);
        self::assertNotSame('', $hash);

        $second = $this->runRecovery();
        self::assertSame(0, $second['exit']);
        self::assertSame($hash, $this->evidenceHash($second['output']));
        $this->assertDatabaseHas('current_forward_windows', [
            'generation' => 21_860,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_zero_output',
        ]);
        self::assertSame(0, DB::table('current_forward_window_objects')->count());
        self::assertSame(0, DB::table('current_forward_object_owners')->count());
        self::assertSame(0, DB::table('current_forward_continuation_observations')->count());

        $wrong = $this->runRecovery(collections: '10,11,12');
        self::assertSame(1, $wrong['exit']);
        self::assertStringContainsString(
            'Pinned collections do not exactly match the article-range lineage.',
            $wrong['output'],
        );
    }

    public function test_apply_creates_one_bounded_chain_from_an_old_legacy_quarantine(): void
    {
        $dryRun = $this->runRecovery();
        $hash = $this->evidenceHash($dryRun['output']);
        $snapshotRepository = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshotRepository->shouldReceive('capture')->once()->with(Mockery::type('array'))->andReturn(new PipelineSnapshot(
            100,
            10,
            2,
            0,
            0,
            lowPressure: true,
            databaseCurrentWaits: 0,
            databaseAdmissionSafe: true,
            eligibleNzbs: 0,
        ));
        $this->app->instance(PipelineSnapshotRepository::class, $snapshotRepository);
        $state = Mockery::mock(WorkerControlStateStore::class);
        $state->shouldReceive('previousSnapshot')->once()->andReturn(['observed_at' => time()]);
        $this->app->instance(WorkerControlStateStore::class, $state);

        $applied = $this->runRecovery($hash, apply: true);

        self::assertSame(0, $applied['exit'], $applied['output']);
        $window = DB::table('current_forward_windows')->where('generation', 21_860)->first();
        self::assertNotNull($window);
        self::assertSame('CONTINUATION_PENDING', $window->state);
        self::assertSame((int) $window->id, (int) $window->chain_root_id);
        self::assertSame(1, (int) $window->chain_ordinal);
        self::assertGreaterThanOrEqual(time() + 7_100, strtotime((string) $window->continuation_deadline_at));
        self::assertSame(2, DB::table('current_forward_window_objects')
            ->where('object_type', 'COLLECTION')->count());
        self::assertSame(2, DB::table('current_forward_window_objects')
            ->where('object_type', 'BINARY')->count());
        self::assertSame(2, (int) DB::table('current_forward_window_objects')
            ->where('object_type', 'BINARY')->sum('inserted_parts'));
        self::assertSame(4, DB::table('current_forward_object_owners')->count());
        self::assertSame(1, DB::table('current_forward_continuation_observations')->count());
        $this->assertDatabaseHas('current_forward_sources', [
            'id' => 1,
            'state' => 'READY',
            'strikes' => 0,
            'last_reason' => 'operator_recovered_exact_zero_output',
        ]);

        $replay = $this->runRecovery($hash, apply: true);
        self::assertSame(1, $replay['exit']);
        self::assertSame(4, DB::table('current_forward_window_objects')->count());
        self::assertSame(1, DB::table('current_forward_continuation_observations')->count());
    }

    /** @return array{exit:int,output:string} */
    private function runRecovery(
        string $hash = '',
        bool $apply = false,
        string $collections = '10,11',
    ): array {
        $arguments = [
            'generation' => 21_860,
            '--group-id' => 1,
            '--expected-first' => 101,
            '--expected-last' => 10_100,
            '--expected-cursor' => 10_100,
            '--collections' => $collections,
            '--expected-parts' => 2,
            '--expected-binaries' => 2,
        ];
        if ($hash !== '') {
            $arguments['--evidence-hash'] = $hash;
        }
        if ($apply) {
            $arguments['--apply'] = true;
        }

        return [
            'exit' => Artisan::call('nntmux:current-forward-recover-continuation', $arguments),
            'output' => Artisan::output(),
        ];
    }

    private function evidenceHash(string $output): string
    {
        preg_match('/"evidence_hash":\s*"([a-f0-9]{64})"/', $output, $match);

        return (string) ($match[1] ?? '');
    }

    private function createSchema(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        Schema::create('current_forward_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('groups_id');
            $table->string('state', 32);
            $table->unsignedTinyInteger('strikes');
            $table->string('last_reason', 120)->nullable();
            $table->timestamps();
        });
        Schema::create('current_forward_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('generation')->nullable()->unique();
            $table->unsignedBigInteger('first_article');
            $table->unsignedBigInteger('last_article');
            $table->string('state', 32);
            $table->string('failure_reason', 120)->nullable();
            $table->dateTime('ingested_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->unsignedBigInteger('chain_root_id')->nullable();
            $table->unsignedBigInteger('parent_window_id')->nullable();
            $table->unsignedTinyInteger('chain_ordinal')->default(1);
            $table->dateTime('continuation_deadline_at')->nullable();
            $table->timestamps();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('last_record');
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('groups_id');
            $table->unsignedInteger('totalfiles');
            $table->unsignedTinyInteger('filecheck');
            $table->unsignedBigInteger('releases_id')->nullable();
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collections_id');
            $table->unsignedInteger('filenumber');
            $table->unsignedInteger('totalparts');
            $table->unsignedInteger('currentparts');
            $table->unsignedBigInteger('partsize');
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('binaries_id');
            $table->unsignedBigInteger('number');
            $table->unsignedInteger('partnumber');
            $table->unsignedBigInteger('size');
            $table->string('messageid');
            $table->primary(['binaries_id', 'number']);
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('categories_id');
            $table->unsignedTinyInteger('nzbstatus');
            $table->unsignedBigInteger('size');
        });
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
            $table->unique(['window_id', 'object_type', 'object_id']);
        });
        Schema::create('current_forward_continuation_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('window_id')->unique();
            $table->unsignedBigInteger('chain_root_id');
            $table->unsignedTinyInteger('chain_ordinal');
            $table->unsignedBigInteger('baseline_present_parts');
            $table->unsignedBigInteger('current_present_parts');
            $table->unsignedBigInteger('useful_progress_parts');
            $table->unsignedBigInteger('expected_parts');
            $table->unsignedInteger('observed_files');
            $table->unsignedInteger('complete_files');
            $table->unsignedInteger('unresolved_collections');
            $table->unsignedBigInteger('cumulative_parts');
            $table->unsignedInteger('cumulative_binaries');
            $table->unsignedInteger('cumulative_collections');
            $table->unsignedInteger('cumulative_releases');
            $table->unsignedInteger('cumulative_ready_nzbs');
            $table->string('decision', 32);
            $table->string('reason', 120);
            $table->string('pipeline_hash', 64);
            $table->string('cohort_hash', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->dateTime('observed_at');
            $table->timestamps();
        });
    }

    private function seedLegacyCanary(): void
    {
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '0'],
            ['name' => 'orchestrator_bf_completed', 'value' => '0'],
            ['name' => 'orchestrator_cf_permit', 'value' => '0'],
            ['name' => 'orchestrator_cf_claimed', 'value' => '21860'],
            ['name' => 'orchestrator_cf_completed', 'value' => '21860'],
            ['name' => 'orchestrator_cf_failed', 'value' => '0'],
        ]);
        DB::table('current_forward_sources')->insert([
            'id' => 1,
            'groups_id' => 1,
            'state' => 'READY',
            'strikes' => 1,
            'last_reason' => 'current_forward_zero_output',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('current_forward_windows')->insert([
            'id' => 1,
            'source_id' => 1,
            'generation' => 21_860,
            'first_article' => 101,
            'last_article' => 10_100,
            'state' => 'QUARANTINED',
            'failure_reason' => 'current_forward_zero_output',
            'ingested_at' => now()->subHours(8),
            'settled_at' => now()->subHours(7),
            'chain_root_id' => 1,
            'chain_ordinal' => 1,
            'created_at' => now()->subHours(8),
            'updated_at' => now()->subHours(7),
        ]);
        DB::table('usenet_groups')->insert(['id' => 1, 'last_record' => 10_100]);
        DB::table('collections')->insert([
            ['id' => 10, 'groups_id' => 1, 'totalfiles' => 2, 'filecheck' => 0, 'releases_id' => null],
            ['id' => 11, 'groups_id' => 1, 'totalfiles' => 2, 'filecheck' => 0, 'releases_id' => null],
            ['id' => 12, 'groups_id' => 1, 'totalfiles' => 1, 'filecheck' => 0, 'releases_id' => null],
        ]);
        DB::table('binaries')->insert([
            ['id' => 20, 'collections_id' => 10, 'filenumber' => 1, 'totalparts' => 2, 'currentparts' => 1, 'partsize' => 100],
            ['id' => 21, 'collections_id' => 11, 'filenumber' => 1, 'totalparts' => 2, 'currentparts' => 1, 'partsize' => 100],
            ['id' => 22, 'collections_id' => 12, 'filenumber' => 1, 'totalparts' => 1, 'currentparts' => 1, 'partsize' => 100],
        ]);
        DB::table('parts')->insert([
            ['binaries_id' => 20, 'number' => 101, 'partnumber' => 1, 'size' => 100, 'messageid' => '<101@test>'],
            ['binaries_id' => 21, 'number' => 102, 'partnumber' => 1, 'size' => 100, 'messageid' => '<102@test>'],
            ['binaries_id' => 22, 'number' => 20_000, 'partnumber' => 1, 'size' => 100, 'messageid' => '<20000@test>'],
        ]);
    }
}
