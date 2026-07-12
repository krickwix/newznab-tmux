<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Nzb\NzbBacklogCreationService;
use App\Services\Orchestrator\BodyRecoverySourceCriteria;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\PrometheusSafetySignalProvider;
use App\Services\Orchestrator\WorkerControlStateStore;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

final class PipelineSnapshotRepositoryTest extends TestCase
{
    public function test_database_wait_safety_requires_persistence_but_never_ignores_a_deadlock_delta(): void
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'databaseWaitsSafe');

        $observedAt = time() - 60;
        self::assertTrue($method->invoke($repository, 24, 0, [
            'database_deadlocks' => 24,
            'database_current_waits' => 0,
            'observed_at' => $observedAt,
        ]));
        self::assertTrue($method->invoke($repository, 24, 3, [
            'database_deadlocks' => 24,
            'database_current_waits' => 0,
            'observed_at' => $observedAt,
        ]), 'One transient busy sample must not renew the hard fail-safe cooldown.');
        self::assertFalse($method->invoke($repository, 24, 2, [
            'database_deadlocks' => 24,
            'database_current_waits' => 3,
            'observed_at' => $observedAt,
        ]), 'Two consecutive busy samples represent persistent contention.');
        self::assertTrue($method->invoke($repository, 24, 2, [
            'database_deadlocks' => 24,
            'database_current_waits' => 3,
            'observed_at' => time() - 5,
        ]), 'Rapid manual samples must not manufacture persistence.');
        self::assertTrue($method->invoke($repository, 24, 2, [
            'database_deadlocks' => 24,
            'database_current_waits' => 3,
            'observed_at' => time() - 300,
        ]), 'A stale pre-restart sample must not manufacture persistence.');
        self::assertFalse($method->invoke($repository, 25, 0, [
            'database_deadlocks' => 24,
            'database_current_waits' => 0,
            'observed_at' => $observedAt,
        ]), 'Any deadlock delta remains an immediate hard-safety failure.');
        self::assertFalse($method->invoke($repository, null, 0, null));
        self::assertFalse($method->invoke($repository, 24, null, null));
    }

    public function test_current_database_waits_block_backfill_even_before_persistent_fail_safe(): void
    {
        $snapshot = new PipelineSnapshot(
            partsBacklog: 1,
            binariesBacklog: 1,
            collectionsBacklog: 1,
            releasesBacklog: 0,
            nzbsBacklog: 0,
            databaseWaitsSafe: true,
            databaseCurrentWaits: 1,
            eligibleBackfillSupply: true,
            backfillSafeQuantity: 10_000,
        );

        self::assertFalse($snapshot->backfillGatesPassed());
        self::assertSame(1, $snapshot->withPermitOutcome(true, false)->databaseCurrentWaits);
    }

    public function test_body_recovery_source_backlog_uses_the_exact_bounded_reconciliation_contract(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('COUNT(*)', $sql);
                self::assertStringContainsString('c.filecheck = 0', $sql);
                self::assertStringContainsString('c.releases_id IS NULL', $sql);
                self::assertStringContainsString("c.subject LIKE '[PRiVATE]%[newzNZB]%'", $sql);
                self::assertStringContainsString('c.collection_regexes_id IN (?)', $sql);
                self::assertStringNotContainsString('b.currentparts <= ?', $sql);
                self::assertStringNotContainsString('b.totalparts >= ?', $sql);
                self::assertStringContainsString('c.totalfiles > 1', $sql);
                self::assertStringContainsString('NOT EXISTS', $sql);
                self::assertStringContainsString('b2.collections_id = c.id', $sql);
                self::assertStringNotContainsString('c.dateadded < ?', $sql);
                self::assertStringContainsString('c.groups_id IN (?, ?)', $sql);
                self::assertSame([11, 22, -20], $bindings);

                return true;
            })
            ->andReturn((object) ['backlog' => 4321, 'oldest_age' => 7200]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'bodyRecoverySourceSnapshot');

        self::assertSame(['backlog' => 4321, 'oldest_age' => 7200], $method->invoke(
            $repository,
            new BodyRecoverySourceCriteria([11, 22], [-20], 2, 10, '2026-07-12 16:00:00'),
        ));
    }

    public function test_body_recovery_identity_is_stable_while_work_eligibility_keeps_dynamic_bounds(): void
    {
        $criteria = new BodyRecoverySourceCriteria([11], [-20], 2, 10, '2026-07-12 16:00:00');
        $identity = $criteria->identityPredicate();
        $eligibility = $criteria->eligibilityPredicate();

        self::assertStringNotContainsString('dateadded < ?', $identity['sql']);
        self::assertStringNotContainsString('currentparts <= ?', $identity['sql']);
        self::assertSame([11, -20], $identity['bindings']);
        self::assertStringContainsString('dateadded < ?', $eligibility['sql']);
        self::assertStringContainsString('currentparts <= ?', $eligibility['sql']);
        self::assertStringContainsString('totalparts >= ?', $eligibility['sql']);
        self::assertSame([11, -20, '2026-07-12 16:00:00', 2, 10], $eligibility['bindings']);
    }

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

    public function test_legacy_snapshot_resets_new_collection_split_rates_without_a_fake_drain(): void
    {
        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'rates');
        [$rates, $ewma] = $method->invoke($repository, [
            'parts' => 190_000_000,
            'binaries' => 80_000,
            'collections' => 8_000,
            'collections_total' => 48_000,
            'recovery_sources' => 40_000,
            'releases' => 12,
            'nzbs' => 6_791,
        ], [
            'parts' => 189_999_000,
            'binaries' => 80_100,
            'collections' => 49_000,
            'observed_at' => time() - 60,
            'ewma_collections' => 123.0,
        ]);

        self::assertSame(0.0, $rates['collections']);
        self::assertSame(0.0, $rates['collections_total']);
        self::assertSame(0.0, $rates['recovery_sources']);
        self::assertSame(0.0, $ewma['collections']);
        self::assertEquals(1000.0, $rates['parts']);
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

    public function test_group_cohort_release_count_uses_the_same_exact_attribution_window_without_requiring_an_nzb(): void
    {
        config()->set('nntmux.orchestrator.backfill_cohort_postdate_tolerance_seconds', 3600);

        DB::shouldReceive('scalar')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('r.id > ?', $sql);
                self::assertStringNotContainsString('r.nzbstatus = 1', $sql);
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
            ->andReturn(2);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame(2, $repository->backfillCreatedReleasesForCohort(
            'alt.test',
            123,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        ));
    }

    public function test_body_recovery_queue_excludes_ordinary_and_exhausted_missed_parts(): void
    {
        config()->set('nntmux.body_preamble_deobfuscate_groups', 'alt.test');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
            groups_id INTEGER,
            attempts INTEGER,
            recovery_kind VARCHAR(32)
        )');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        DB::table('missed_parts')->insert([
            ['id' => 1, 'groups_id' => 1, 'attempts' => 0, 'recovery_kind' => 'body_preamble'],
            ['id' => 2, 'groups_id' => 1, 'attempts' => 0, 'recovery_kind' => null],
            ['id' => 3, 'groups_id' => 1, 'attempts' => 3, 'recovery_kind' => 'body_preamble'],
        ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );
        $method = new ReflectionMethod($repository, 'bodyRecoveryQueueBacklog');

        self::assertSame(1, $method->invoke($repository));
    }
}
