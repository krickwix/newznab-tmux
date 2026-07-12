<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Nzb\NzbBacklogCreationService;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\PrometheusSafetySignalProvider;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

final class PipelineSnapshotRepositoryTest extends TestCase
{
    public function test_safe_backfill_quantity_is_zero_when_any_stage_lacks_one_quantum_of_headroom(): void
    {
        config()->set('nntmux.orchestrator.backfill_headroom_fraction', 0.10);
        config()->set('nntmux.orchestrator.high_watermarks', [
            'parts' => 300_000_000,
            'binaries' => 1_000_000,
            'collections' => 100_000,
        ]);
        config()->set('nntmux.orchestrator.backfill_growth_per_10k', [
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ]);
        $state = Mockery::mock(WorkerControlStateStore::class);
        $state->shouldReceive('backfillGrowthFor')->once()->with('')->andReturn([
            'parts' => 10_000,
            'binaries' => 500,
            'collections' => 1_000,
        ]);
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
            state: $state,
        );
        $method = new ReflectionMethod($repository, 'safeBackfillQuantity');

        $quantity = $method->invoke($repository, [
            'parts' => 186_000_000,
            'binaries' => 95_000,
            'collections' => 99_500,
            'releases' => 0,
            'nzbs' => 0,
        ]);

        self::assertSame(0, $quantity);
    }

    public function test_backfill_candidates_are_bounded_to_fresh_current_valid_ranges(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('g.active = 1', $sql);
                self::assertStringContainsString('g.backfill = 1', $sql);
                self::assertStringContainsString('s.updated >= NOW() - INTERVAL 10 MINUTE', $sql);
                self::assertStringContainsString('CAST(s.last_record AS SIGNED) - CAST(g.last_record AS SIGNED) <= 10000', $sql);
                self::assertStringContainsString('CAST(g.first_record AS SIGNED) - CAST(s.first_record AS SIGNED) >= 20000', $sql);
                self::assertStringContainsString("g.first_record_postdate >= '2000-01-01'", $sql);
                self::assertStringContainsString('LIMIT 16', $sql);
                self::assertSame([], $bindings);

                return true;
            })
            ->andReturn([(object) [
                'name' => 'alt.test',
                'backfill_cursor' => 100_000,
                'cursor_postdate' => '2020-01-01 00:00:00',
                'remaining_articles' => 90_000,
            ]]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([[
            'name' => 'alt.test',
            'cursor' => 100_000,
            'cursor_postdate' => '2020-01-01 00:00:00',
            'remaining_articles' => 90_000,
        ]], $repository->backfillCandidates());
    }

    public function test_group_outcome_uses_a_mariadb_safe_cursor_alias(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('AS backfill_cursor', $sql);
                self::assertDoesNotMatchRegularExpression('/\bAS cursor(?:\s|,|$)/i', $sql);
                self::assertSame(['alt.test'], $bindings);

                return true;
            })
            ->andReturn((object) [
                'backfill_cursor' => 12345,
                'cursor_postdate' => '2026-01-02 03:04:05',
                'ready_collections' => 6,
                'releases' => 7,
                'release_high_watermark' => 8,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([
            'cursor' => 12345,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 6,
            'releases' => 7,
            'release_high_watermark' => 8,
        ], $repository->backfillOutcomeForGroup('alt.test'));
    }

    public function test_group_cohort_nzb_count_is_bounded_by_release_id_and_tolerates_provider_date_disorder(): void
    {
        config()->set('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);

        DB::shouldReceive('scalar')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('r.id > ?', $sql);
                self::assertStringContainsString('r.nzbstatus = 1', $sql);
                self::assertStringContainsString('r.postdate BETWEEN DATE_SUB(LEAST(?, ?), INTERVAL ? SECOND)', $sql);
                self::assertStringContainsString('AND DATE_ADD(GREATEST(?, ?), INTERVAL ? SECOND)', $sql);
                self::assertSame([
                    'alt.test',
                    123,
                    '2026-01-02 03:04:05',
                    '2026-01-01 03:04:05',
                    3600,
                    '2026-01-02 03:04:05',
                    '2026-01-01 03:04:05',
                    3600,
                ], $bindings);

                return true;
            })
            ->andReturn(3);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame(3, $repository->backfillCreatedNzbsForCohort(
            'alt.test',
            123,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }
}
