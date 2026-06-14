<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DistributedWorkerLockCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        foreach ([
            'categorizeforeign' => '0',
            'catwebdl' => '0',
        ] as $name => $value) {
            \DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }
    }

    public function test_it_force_releases_a_known_distributed_worker_lock(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);

        $lockName = 'nntmux:distributed-worker:fixnames';
        $heldLock = Cache::store('array')->lock($lockName, 60);

        $this->assertTrue($heldLock->get());
        $this->assertFalse(Cache::store('array')->lock($lockName, 60)->get());

        $this->artisan('nntmux:release-worker-lock', ['job' => 'fixnames'])
            ->expectsOutputToContain('Released distributed worker lock [fixnames]')
            ->assertExitCode(0);

        $this->assertTrue(Cache::store('array')->lock($lockName, 60)->get());
    }

    public function test_it_rejects_unknown_distributed_worker_jobs(): void
    {
        $this->artisan('nntmux:release-worker-lock', ['job' => 'not-a-job'])
            ->expectsOutputToContain('Unknown distributed job [not-a-job]')
            ->assertExitCode(1);
    }
}
