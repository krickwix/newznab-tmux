<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\NntmuxWorkerOrchestrator;
use App\Services\Orchestrator\WorkerOrchestrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

final class NntmuxWorkerOrchestratorCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ]);
    }

    public function test_it_rejects_a_repeating_backfill_permit_request(): void
    {
        $this->mock(WorkerOrchestrator::class)
            ->shouldNotReceive('runOnce');

        $this->artisan('nntmux:worker-orchestrator', ['--grant-backfill-permit' => true])
            ->expectsOutputToContain('--grant-backfill-permit requires --once')
            ->assertExitCode(1);
    }

    public function test_observation_sleep_accepts_fifteen_seconds_but_never_less(): void
    {
        $method = new ReflectionMethod(NntmuxWorkerOrchestrator::class, 'normalizedSleepSeconds');
        $command = new NntmuxWorkerOrchestrator;

        self::assertSame(15, $method->invoke($command, 1));
        self::assertSame(15, $method->invoke($command, 15));
        self::assertSame(60, $method->invoke($command, 60));
    }
}
