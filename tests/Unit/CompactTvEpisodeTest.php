<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReleaseNames\CompactTvEpisode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompactTvEpisodeTest extends TestCase
{
    public function test_matches_the_observed_compact_scene_episode(): void
    {
        self::assertSame([
            'season' => 1,
            'episode' => 5,
            'quality' => 'hd',
            'source' => 'web',
        ], CompactTvEpisode::match(
            'Afo-Theotherbennetsister-0105-720-Web',
            'alt.binaries.documentaries',
        ));
    }

    public function test_matches_another_strong_compact_scene_episode(): void
    {
        self::assertSame([
            'season' => 2,
            'episode' => 3,
            'quality' => 'hd',
            'source' => 'webdl',
        ], CompactTvEpisode::match(
            'Grp-Readabletitle-0203-1080p-WEB-DL',
            'alt.binaries.hdtv',
        ));
    }

    #[DataProvider('nonEpisodeNames')]
    public function test_rejects_ambiguous_or_out_of_scope_numeric_tokens(string $name, string $group): void
    {
        self::assertNull(CompactTvEpisode::match($name, $group));
    }

    /** @return array<string, array{string,string}> */
    public static function nonEpisodeNames(): array
    {
        return [
            'year' => ['Some-Film-2024-720-Web', 'alt.binaries.documentaries'],
            'date with year' => ['News-2024-0704-720-Web', 'alt.binaries.hdtv'],
            'music group' => ['Artist-Album-0105-720-Web', 'alt.binaries.music'],
            'warez group' => ['Tool-Release-0105-720-Web', 'alt.binaries.warez'],
            'movie group' => ['Some-Film-0105-720-Web', 'alt.binaries.movies'],
            'tv substring is not a group segment' => ['Grp-Readabletitle-0105-720-Web', 'alt.binaries.notv'],
            'another tv substring is not a group segment' => ['Grp-Readabletitle-0105-720-Web', 'alt.binaries.atv'],
            'season zero' => ['Grp-Readabletitle-0005-720-Web', 'alt.binaries.hdtv'],
            'episode zero' => ['Grp-Readabletitle-0100-720-Web', 'alt.binaries.hdtv'],
            'missing resolution' => ['Grp-Readabletitle-0105-Web', 'alt.binaries.hdtv'],
            'missing source' => ['Grp-Readabletitle-0105-720', 'alt.binaries.hdtv'],
            'single prefix token' => ['Show-0105-720-Web', 'alt.binaries.hdtv'],
        ];
    }
}
