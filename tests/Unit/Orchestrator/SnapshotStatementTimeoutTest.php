<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Nzb\NzbBacklogCreationService;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\PrometheusSafetySignalProvider;
use App\Services\Orchestrator\WorkerControlPolicy;
use App\Services\Orchestrator\WorkerControlStateStore;
use App\Services\Orchestrator\WorkerOrchestrator;
use App\Services\Orchestrator\WorkerProfileApplier;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * On 2026-11-29 the snapshot pipeline statement ran 3,801 s on live. Nothing
 * threw, so nothing failed closed: the orchestrator sat inside capture()
 * holding the leader lock while its own lease expired, and every lane dropped
 * to its fail-safe timer.
 *
 * WorkerOrchestrator::decide() already catches a capture that *errors* --
 * failClosed() then releasing the lock in its finally. The uncovered case was
 * a statement that neither returns nor fails, so the fix is to make sure it
 * fails.
 */
final class SnapshotStatementTimeoutTest extends TestCase
{
    public function test_the_bound_is_applied_for_the_duration_and_then_restored(): void
    {
        config(['nntmux.orchestrator.snapshot_statement_timeout_seconds' => 20]);

        $statements = [];
        DB::shouldReceive('connection')->andReturn($connection = Mockery::mock());
        $connection->shouldReceive('getDriverName')->andReturn('mariadb');
        $connection->shouldReceive('scalar')
            ->with('SELECT @@SESSION.max_statement_time')
            ->andReturn(0.0);
        $connection->shouldReceive('statement')->andReturnUsing(
            function (string $sql) use (&$statements): bool {
                $statements[] = $sql;

                return true;
            }
        );

        $result = $this->withTimeout(static fn (): string => 'captured');

        self::assertSame('captured', $result);
        // Set before the work, restored after -- the orchestrator is a
        // long-running process and this connection outlives the cycle.
        self::assertSame([
            'SET SESSION max_statement_time = 20',
            'SET SESSION max_statement_time = 0',
        ], $statements);
    }

    public function test_the_bound_is_restored_even_when_the_capture_throws(): void
    {
        config(['nntmux.orchestrator.snapshot_statement_timeout_seconds' => 20]);

        $statements = [];
        DB::shouldReceive('connection')->andReturn($connection = Mockery::mock());
        $connection->shouldReceive('getDriverName')->andReturn('mariadb');
        $connection->shouldReceive('scalar')->andReturn(5.5);
        $connection->shouldReceive('statement')->andReturnUsing(
            function (string $sql) use (&$statements): bool {
                $statements[] = $sql;

                return true;
            }
        );

        try {
            $this->withTimeout(static fn (): string => throw new RuntimeException('max_statement_time exceeded'));
            self::fail('The timeout must propagate so the cycle fails closed.');
        } catch (RuntimeException $e) {
            self::assertSame('max_statement_time exceeded', $e->getMessage());
        }

        // A leaked bound would silently cap every later query on this
        // connection, including ones that legitimately take longer.
        self::assertSame([
            'SET SESSION max_statement_time = 20',
            'SET SESSION max_statement_time = 5.5',
        ], $statements);
    }

    public function test_zero_disables_the_bound_entirely(): void
    {
        config(['nntmux.orchestrator.snapshot_statement_timeout_seconds' => 0]);

        DB::shouldReceive('connection')->never();

        self::assertSame('captured', $this->withTimeout(static fn (): string => 'captured'));
    }

    public function test_sqlite_is_left_alone(): void
    {
        config(['nntmux.orchestrator.snapshot_statement_timeout_seconds' => 20]);

        DB::shouldReceive('connection')->andReturn($connection = Mockery::mock());
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('statement')->never();
        $connection->shouldReceive('scalar')->never();

        self::assertSame('captured', $this->withTimeout(static fn (): string => 'captured'));
    }

    public function test_a_connection_without_the_variable_runs_unbounded_rather_than_failing(): void
    {
        config(['nntmux.orchestrator.snapshot_statement_timeout_seconds' => 20]);

        DB::shouldReceive('connection')->andReturn($connection = Mockery::mock());
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        // Real MySQL has max_execution_time, in milliseconds, not
        // max_statement_time. Guessing the flavour would be worse than letting
        // the probe answer: an unbounded capture is the behaviour that existed
        // before this guard, so falling back to it cannot regress anything.
        $connection->shouldReceive('scalar')->andThrow(new RuntimeException('Unknown system variable'));
        $connection->shouldReceive('statement')->never();

        self::assertSame('captured', $this->withTimeout(static fn (): string => 'captured'));
    }

    public function test_a_capture_that_throws_runs_exactly_once(): void
    {
        config(['nntmux.orchestrator.snapshot_statement_timeout_seconds' => 20]);

        DB::shouldReceive('connection')->andReturn($connection = Mockery::mock());
        $connection->shouldReceive('getDriverName')->andReturn('mariadb');
        $connection->shouldReceive('scalar')->andReturn(0.0);
        $connection->shouldReceive('statement')->andReturn(true);

        $calls = 0;

        try {
            $this->withTimeout(function () use (&$calls): string {
                $calls++;

                throw new RuntimeException('capture failed');
            });
            self::fail('The capture failure must propagate.');
        } catch (RuntimeException) {
            // Expected.
        }

        // If the capture ran inside the arming try/catch, a failing capture
        // would be caught as a failing probe and then retried unbounded --
        // doubling the work at the worst possible moment.
        self::assertSame(1, $calls);
    }

    public function test_a_failure_while_arming_still_runs_the_capture_once(): void
    {
        config(['nntmux.orchestrator.snapshot_statement_timeout_seconds' => 20]);

        DB::shouldReceive('connection')->andThrow(new RuntimeException('no connection'));

        $calls = 0;
        $result = $this->withTimeout(function () use (&$calls): string {
            $calls++;

            return 'captured';
        });

        // Arming the bound must never break the capture it protects.
        self::assertSame('captured', $result);
        self::assertSame(1, $calls);
    }

    public function test_a_timed_out_capture_fails_closed_and_releases_the_leader_lock(): void
    {
        // The whole point of making the statement fail: this path already
        // existed and already did the right thing. What the 3,801 s statement
        // did was never reach it.
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andReturn(true);

        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);

        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')
            ->once()
            ->andThrow(new RuntimeException('Query execution was interrupted (max_statement_time exceeded)'));

        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('failClosed')->once();

        $orchestrator = new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('max_statement_time exceeded');

        $orchestrator->runOnce(false);
    }

    private function withTimeout(callable $work): mixed
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'withStatementTimeout');

        return $method->invoke($repository, $work);
    }
}
