<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Categorizers\TvCategorizer;
use App\Services\Categorization\ReleaseContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategorizeTvSeriesPackTest extends TestCase
{
    public function test_audited_series_pack_uses_normal_tv_hd_classification(): void
    {
        $result = (new TvCategorizer([], ['alt.binaries.tvseries']))->categorize(new ReleaseContext(
            releaseName: 'Watson S01 Krimiserie 2025 mp4 720p by Uwealex01',
            groupId: 0,
            groupName: 'alt.binaries.tvseries',
        ));

        self::assertSame(Category::TV_HD, $result->categoryId);
        self::assertSame('hd_resolution', $result->matchedBy);
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedNearMatchProvider(): array
    {
        return [
            'wrong source' => ['Watson S01 Krimiserie 2025 mp4 720p', 'alt.binaries.warez'],
            'no season' => ['Watson Krimiserie 2025 mp4 720p', 'alt.binaries.tvseries'],
            'no series marker' => ['Watson S01 Krimi 2025 mp4 720p', 'alt.binaries.tvseries'],
            'no year' => ['Watson S01 Krimiserie mp4 720p', 'alt.binaries.tvseries'],
            'no video marker' => ['Watson S01 Krimiserie 2025 720p', 'alt.binaries.tvseries'],
            'no resolution' => ['Watson S01 Krimiserie 2025 mp4', 'alt.binaries.tvseries'],
            'reordered' => ['Watson Krimiserie 2025 S01 mp4 720p', 'alt.binaries.tvseries'],
        ];
    }

    #[DataProvider('rejectedNearMatchProvider')]
    public function test_near_matches_do_not_gain_series_pack_tv_identity(string $name, string $group): void
    {
        $result = (new TvCategorizer([], ['alt.binaries.tvseries']))->categorize(new ReleaseContext(
            releaseName: $name,
            groupId: 0,
            groupName: $group,
        ));

        self::assertFalse($result->isSuccessful());
    }
}
