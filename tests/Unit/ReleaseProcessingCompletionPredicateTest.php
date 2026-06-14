<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReleaseProcessingCompletionPredicateTest extends TestCase
{
    public function test_normal_collection_binary_completion_uses_configured_part_completion(): void
    {
        $serviceSource = file_get_contents(__DIR__.'/../../app/Services/ReleaseProcessingService.php');

        self::assertIsString($serviceSource);
        self::assertStringContainsString(
            'b.currentparts >= CEIL(b.totalparts * ? / 100)',
            $serviceSource,
            'Binary completion should honor completionpercent instead of requiring every expected part.'
        );
    }

    public function test_collection_promotion_uses_configured_completion_percent(): void
    {
        $serviceSource = file_get_contents(__DIR__.'/../../app/Services/ReleaseProcessingService.php');

        self::assertIsString($serviceSource);
        self::assertStringContainsString(
            'requiredCompletionPercent()',
            $serviceSource,
            'Release processing should route collection promotion through the configured completion threshold.'
        );
        self::assertStringContainsString(
            'GREATEST(1, CEIL(collections.totalfiles * ? / 100))',
            $serviceSource,
            'Stage 1 should use completionpercent instead of waiting for every expected file.'
        );
        self::assertStringContainsString(
            'GREATEST(1, CEIL(c.totalfiles * ? / 100))',
            $serviceSource,
            'Stage 4 should use completionpercent when complete binary parts are present.'
        );
        self::assertStringContainsString(
            'MAX(binaries.filenumber) * ? / 100',
            $serviceSource,
            'Stage 0 should infer dense deobfuscated collections with totalfiles=0 from observed file numbers.'
        );
    }

    public function test_stage6_uses_join_anti_join_instead_of_materialized_subqueries(): void
    {
        $serviceSource = file_get_contents(__DIR__.'/../../app/Services/ReleaseProcessingService.php');

        self::assertIsString($serviceSource);
        self::assertStringContainsString(
            "->join('binaries as existing'",
            $serviceSource,
            'Stage 6 should prove a collection has binaries without materializing all binary collection IDs.'
        );
        self::assertStringContainsString(
            "->leftJoin('binaries as incomplete'",
            $serviceSource,
            'Stage 6 should anti-join incomplete binaries through the collection index.'
        );
        self::assertStringContainsString(
            "->whereNull('incomplete.id')",
            $serviceSource,
            'Stage 6 should keep only collections with no incomplete binaries.'
        );
        self::assertStringContainsString(
            "->pluck('collections.id')",
            $serviceSource,
            'Stage 6 should pluck a qualified ID because the query joins binaries twice.'
        );
        self::assertStringNotContainsString(
            'NOT EXISTS (',
            $serviceSource,
            'Stage 6 should not regress to MariaDB materialized NOT EXISTS scans.'
        );
    }

    public function test_stage6_selection_index_is_declared(): void
    {
        $migrationSource = file_get_contents(__DIR__.'/../../database/migrations/2026_06_13_010000_add_release_stage6_selection_index.php');

        self::assertIsString($migrationSource);
        self::assertStringContainsString('ix_collections_release_stage6', $migrationSource);
        self::assertStringContainsString("['groups_id', 'filecheck', 'dateadded', 'id']", $migrationSource);
    }

    public function test_stage1_selection_index_is_declared(): void
    {
        $migrationSource = file_get_contents(__DIR__.'/../../database/migrations/2026_06_14_010000_add_release_stage1_selection_index.php');

        self::assertIsString($migrationSource);
        self::assertStringContainsString('ix_collections_release_stage1', $migrationSource);
        self::assertStringContainsString("['groups_id', 'filecheck', 'id', 'totalfiles']", $migrationSource);
    }

    public function test_stage1_collects_candidate_ids_before_updating_filecheck(): void
    {
        $stageSource = $this->releaseProcessingMethodSource('runCollectionFileCheckStage1');

        self::assertStringContainsString("->pluck('collections.id')", $stageSource);
        self::assertStringContainsString('foreach ($collectionIds->chunk(self::BATCH_SIZE) as $ids)', $stageSource);
        self::assertStringContainsString("->where('collections.id', '>', \$lastCollectionId)", $stageSource);
        self::assertStringContainsString("->orderBy('collections.id')", $stageSource);
        self::assertStringContainsString('->limit(self::BATCH_SIZE)', $stageSource);
        self::assertStringContainsString('$eligibleCollectionsQuery = Collection::query()', $stageSource);
        self::assertStringContainsString("->whereIn('collections.id', \$ids->all())", $stageSource);
        self::assertStringContainsString("->joinSub(\n                            \$eligibleCollectionsQuery", $stageSource);
        self::assertStringNotContainsString('->joinSub($collectionsQuery', $stageSource);
    }

    private function releaseProcessingMethodSource(string $methodName): string
    {
        $serviceSource = file_get_contents(__DIR__.'/../../app/Services/ReleaseProcessingService.php');

        self::assertIsString($serviceSource);

        $start = strpos($serviceSource, 'private function '.$methodName);
        self::assertIsInt($start);

        $nextMethod = strpos($serviceSource, "\n    /**", $start + 1);
        self::assertIsInt($nextMethod);

        return substr($serviceSource, $start, $nextMethod - $start);
    }
}
