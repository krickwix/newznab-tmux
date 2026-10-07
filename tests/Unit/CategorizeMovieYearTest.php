<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Categorizers\MovieCategorizer;
use App\Services\Categorization\ReleaseContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategorizeMovieYearTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function yearShapeProvider(): array
    {
        return [
            'year in parentheses, 1080p' => ['Saving.Private.Ryan.(1998).VFF.1080p.AC3.5.1.HEVC-Serpico', Category::MOVIE_HD],
            'year in parentheses, 2160p' => ['Troy.(2004).VFF.2160p.EAC3.5.1.HEVC-Serpico', Category::MOVIE_UHD],
            'resolution right after the year' => ['Saving.Private.Ryan.1998.1080p.AC3.5.1.HEVC-Serpico', Category::MOVIE_HD],
            'tag between year and resolution' => ['Saving.Private.Ryan.1998.VFF.1080p.AC3.5.1.HEVC-Serpico', Category::MOVIE_HD],
        ];
    }

    #[DataProvider('yearShapeProvider')]
    public function test_a_year_then_a_resolution_is_a_movie(string $name, int $expected): void
    {
        $result = (new MovieCategorizer)->categorize(new ReleaseContext(
            releaseName: $name,
            groupId: 0,
            groupName: 'alt.binaries.moovee',
            poster: '',
            catWebDL: true,
        ));

        $this->assertSame($expected, $result->categoryId, $name);
    }

    public function test_an_episode_is_still_not_a_movie(): void
    {
        $context = new ReleaseContext(
            releaseName: 'Show.Name.(2024).S01E02.1080p.WEB-DL.H.264-GRP',
            groupId: 0,
            groupName: 'alt.binaries.moovee',
            poster: '',
            catWebDL: true,
        );

        $this->assertTrue((new MovieCategorizer)->shouldSkip($context));
    }
}
