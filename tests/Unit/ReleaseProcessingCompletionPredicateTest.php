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

    public function test_stage6_selects_a_bounded_eligible_page_without_an_incomplete_prefix(): void
    {
        $stageSource = $this->releaseProcessingMethodSource('runCollectionFileCheckStage6');
        $eligibleSource = $this->releaseProcessingMethodSource('stage6CompleteCollectionIds');

        self::assertStringContainsString(
            '$completeIds = $this->stage6CompleteCollectionIds(',
            $stageSource,
            'Stage 6 should page only eligible collections.'
        );
        self::assertStringContainsString(
            "->join('binaries as existing'",
            $eligibleSource,
            'Stage 6 should prove eligibility in SQL so incomplete prefixes cannot starve later work.'
        );
        self::assertStringContainsString(
            "->leftJoin('binaries as incomplete'",
            $eligibleSource,
            'Stage 6 should anti-join incomplete binaries through the collection index.'
        );
        self::assertStringContainsString(
            "->whereNull('incomplete.id')",
            $eligibleSource,
            'Stage 6 should keep only collections with no incomplete binaries.'
        );
        self::assertStringContainsString(
            "->pluck('collections.id')",
            $eligibleSource,
            'Stage 6 should pluck a qualified ID because the query joins binaries twice.'
        );
        self::assertStringNotContainsString(
            'NOT EXISTS (',
            $eligibleSource,
            'Stage 6 should not regress to MariaDB materialized NOT EXISTS scans.'
        );
        self::assertStringContainsString(
            'COUNT(DISTINCT CASE WHEN existing.filenumber > 0 THEN existing.filenumber ELSE existing.id END)',
            $eligibleSource,
            'Stage 6 must count observed collection files instead of accepting a few complete fragments.'
        );
        self::assertStringContainsString(
            'GREATEST(GREATEST(COALESCE(NULLIF(collections.totalfiles, 0), 0), COALESCE(MAX(NULLIF(existing.filenumber, 0)), 0)), COUNT(DISTINCT existing.id))',
            $eligibleSource,
            'Stage 6 must use the largest advertised or inferred collection file count as its denominator.'
        );
        self::assertStringContainsString(
            '->havingRaw(',
            $eligibleSource,
            'Stage 6 must enforce collection-level coverage after excluding incomplete binaries.'
        );
        self::assertStringContainsString('->limit($this->workBatchSize)', $eligibleSource);
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
        self::assertStringContainsString('foreach ($collectionIds->chunk($this->workBatchSize) as $ids)', $stageSource);
        self::assertStringContainsString("->where('collections.id', '>', \$lastCollectionId)", $stageSource);
        self::assertStringContainsString("->orderBy('collections.id')", $stageSource);
        self::assertStringContainsString('->limit($this->workBatchSize)', $stageSource);
        self::assertStringContainsString('$eligibleCollectionsQuery = Collection::query()', $stageSource);
        self::assertStringContainsString("->whereIn('collections.id', \$ids->all())", $stageSource);
        self::assertStringContainsString('$eligibleCollectionsQuery,', $stageSource);
        self::assertStringNotContainsString('->joinSub($collectionsQuery', $stageSource);
    }

    public function test_collection_stages_use_shared_transient_database_retry_classifier(): void
    {
        $serviceSource = file_get_contents(__DIR__.'/../../app/Services/ReleaseProcessingService.php');

        self::assertIsString($serviceSource);
        self::assertStringContainsString('use App\Support\TransientDatabaseError;', $serviceSource);
        self::assertStringContainsString('retryTransientCollectionOperation', $serviceSource);
        self::assertStringContainsString('TransientDatabaseError::is($e)', $serviceSource);
    }

    public function test_release_filecheck_stages_page_candidate_ids_before_updating(): void
    {
        $stage0Source = $this->releaseProcessingMethodSource('runCollectionFileCheckStage0');
        $stage4Source = $this->releaseProcessingMethodSource('runCollectionFileCheckStage4');
        $stage6CandidateSource = $this->releaseProcessingMethodSource('stage6CompleteCollectionIds');
        $binaryStageSource = $this->releaseProcessingMethodSource('markCompleteBinaries');

        foreach ([
            'stage0' => [$stage0Source, 'collections.id', '$lastCollectionId'],
            'stage4' => [$stage4Source, 'c.id', '$lastCollectionId'],
            'stage6 candidate paging' => [$stage6CandidateSource, 'collections.id', '$lastCollectionId'],
            'binary completion' => [$binaryStageSource, 'b.id', '$lastBinaryId'],
        ] as $label => [$source, $idColumn, $cursorName]) {
            self::assertStringContainsString("->where('{$idColumn}', '>', {$cursorName})", $source, $label.' should page after the last seen id.');
            self::assertStringContainsString("->orderBy('{$idColumn}')", $source, $label.' should use stable id ordering.');
            self::assertStringContainsString('->limit($this->workBatchSize)', $source, $label.' should honor the cooperative page bound.');
            if ($label !== 'stage6 candidate paging') {
                self::assertStringContainsString($cursorName.' = 0', $source, $label.' should initialize a cursor.');
                self::assertStringContainsString($cursorName.' = (int)', $source, $label.' should advance the cursor after each batch.');
                self::assertStringContainsString('} while ($', $source, $label.' should continue only while a full batch is returned.');
            }
        }

        $stage6Source = $this->releaseProcessingMethodSource('runCollectionFileCheckStage6');

        self::assertStringContainsString('$lastCollectionId = 0', $stage6Source);
        self::assertStringContainsString('$lastCollectionId = max($completeIds)', $stage6Source);
        self::assertStringContainsString('} while ($this->shouldContinueStageBatch(\count($completeIds)))', $stage6Source);
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
