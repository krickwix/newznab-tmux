<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Nzb\NzbBacklogCreationService;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\PrometheusSafetySignalProvider;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PipelineSnapshotRepositoryTest extends TestCase
{
    public function test_group_outcome_uses_a_mariadb_safe_cursor_alias(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                self::assertStringContainsString('AS backfill_cursor', $sql);
                self::assertStringNotContainsString(' AS cursor', $sql);
                self::assertSame(['alt.test'], $bindings);

                return true;
            })
            ->andReturn((object) [
                'backfill_cursor' => 12345,
                'ready_collections' => 6,
                'releases' => 7,
            ]);

        $repository = new PipelineSnapshotRepository(
            new PrometheusSafetySignalProvider,
            app(NzbBacklogCreationService::class),
        );

        self::assertSame([
            'cursor' => 12345,
            'ready_collections' => 6,
            'releases' => 7,
        ], $repository->backfillOutcomeForGroup('alt.test'));
    }
}
