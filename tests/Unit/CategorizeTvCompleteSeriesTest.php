<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Categorizers\TvCategorizer;
use App\Services\Categorization\ReleaseContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategorizeTvCompleteSeriesTest extends TestCase
{
    public function test_explicit_complete_series_in_the_dedicated_source_is_tv_other(): void
    {
        $result = (new TvCategorizer(['alt.binaries.tvseries']))->categorize(new ReleaseContext(
            releaseName: 'S.O.S Charterbox komplett Abenteuerserie 1969 avi by Uwealex01',
            groupId: 0,
            groupName: 'alt.binaries.tvseries',
        ));

        self::assertSame(Category::TV_OTHER, $result->categoryId);
        self::assertSame('tv_dedicated_group_complete_series', $result->matchedBy);
        self::assertGreaterThan(0.65, $result->confidence);
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedNearMatchProvider(): array
    {
        return [
            'wrong source' => [
                'S.O.S Charterbox komplett Abenteuerserie 1969 avi',
                'alt.binaries.warez',
            ],
            'no complete marker' => [
                'S.O.S Charterbox Abenteuerserie 1969 avi',
                'alt.binaries.tvseries',
            ],
            'no series marker' => [
                'S.O.S Charterbox komplett Abenteuer 1969 avi',
                'alt.binaries.tvseries',
            ],
            'no video marker' => [
                'S.O.S Charterbox komplett Abenteuerserie 1969 pdf',
                'alt.binaries.tvseries',
            ],
            'generic complete series is not audited' => [
                'Mystery Show komplett Krimiserie 1972 avi',
                'alt.binaries.tvseries',
            ],
            'reordered title is not audited' => [
                'Abenteuerserie 1969 komplett avi',
                'alt.binaries.tvseries',
            ],
        ];
    }

    #[DataProvider('rejectedNearMatchProvider')]
    public function test_near_matches_do_not_receive_explicit_complete_series_identity(string $name, string $group): void
    {
        $result = (new TvCategorizer(['alt.binaries.tvseries']))->categorize(new ReleaseContext(
            releaseName: $name,
            groupId: 0,
            groupName: $group,
        ));

        self::assertNotSame('tv_dedicated_group_complete_series', $result->matchedBy);
    }

    public function test_adult_markers_still_skip_the_tv_categorizer(): void
    {
        $categorizer = new TvCategorizer(['alt.binaries.tvseries']);
        $context = new ReleaseContext(
            releaseName: 'XXX komplett Abenteuerserie 1969 avi 1080p',
            groupId: 0,
            groupName: 'alt.binaries.tvseries',
        );

        self::assertTrue($categorizer->shouldSkip($context));
    }
}
