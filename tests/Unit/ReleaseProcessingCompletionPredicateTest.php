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
}
