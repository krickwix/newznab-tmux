<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\Pipes\BookPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\ConsolePipe;
use App\Services\Categorization\Pipes\GroupNamePipe;
use App\Services\Categorization\Pipes\MiscPipe;
use App\Services\Categorization\Pipes\MiscSafetyNetPipe;
use App\Services\Categorization\Pipes\MoviePipe;
use App\Services\Categorization\Pipes\MusicPipe;
use App\Services\Categorization\Pipes\PcPipe;
use App\Services\Categorization\Pipes\TvPipe;
use App\Services\Categorization\Pipes\XxxPipe;
use App\Services\Categorization\ReleaseContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Names that the full pipeline left in Misc, XXX or Music on moovee.
 */
class CategorizeMooveeLeftoversTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function leftoverProvider(): array
    {
        return [
            'studio word in a film title' => ['The.Score.(2001).VFF.2160p.AC3.5.1.HEVC-Serpico', Category::MOVIE_UHD],
            'tour inside a word' => ['The.Tourist.(2010).VFF.1080p.EAC3.5.1.X264-Serpico', Category::MOVIE_HD],
            '576p film' => ['Chicken.Run.(2000).VFF.576p.AAC.2.0.X264-Serpico', Category::MOVIE_SD],
            '480p film with x264' => ['London.Has.Fallen.(2016).VFF.480p.AC3.5.1.X264-Serpico', Category::MOVIE_SD],
            'film with no resolution' => ['8.Mile.(2002).VFF.AC3.5.1.-Serpico', Category::MOVIE_OTHER],
            'film with only a language tag' => ['12.Rounds.(2009).VFF.....-Serpico', Category::MOVIE_OTHER],
            'film with only BluRay' => ['Urban.Legends.Final.Cut.(2000).VFF.BluRay.-Serpico', Category::MOVIE_OTHER],
            'three chained episodes with Toy in the title' => ['Max.And.Ruby.S05E34E35E36.Engineer.Max.Maxs.Toy.Train.Maxs.Train.Ride.1080p.PMTP.WEB-DL.AAC2.0.X264-AndreMor', Category::TV_WEBDL],
            'episode titled Teen Spirit' => ['Grounded.For.Life.S04E09.Smells.Like.Teen.Spirit.1080p.WEBRip.10bit.EAC3.5.1.X265-IVy', Category::TV_WEBDL],
            'year season with Pussy Cat in the title' => ['Tom.And.Jerry.S1950E43.Touch.Pussy.Cat.1080p.BluRay.10bit.EAC3.2.0.X265-IVy', Category::TV_HD],
            'already right: year then 2160p' => ['The.Grinch.(2018).VFF.2160p.AC3.5.1.X265-Serpico', Category::MOVIE_UHD],
        ];
    }

    #[DataProvider('leftoverProvider')]
    public function test_the_pipeline_files_the_leftover(string $name, int $expected): void
    {
        $this->assertSame($expected, $this->categorize($name), $name);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function stillAdultProvider(): array
    {
        return [
            'studio at the start' => ['Score.24.01.15.Jane.Doe.2160p.MP4-GRP', Category::XXX_UHD],
            'explicit marker on an episode name' => ['Some.Site.S01E02.XXX.2160p.MP4-GRP', Category::XXX_UHD],
        ];
    }

    #[DataProvider('stillAdultProvider')]
    public function test_adult_releases_stay_adult(string $name, int $expected): void
    {
        $this->assertSame($expected, $this->categorize($name), $name);
    }

    public function test_a_real_tour_is_still_a_music_video(): void
    {
        $this->assertSame(Category::MUSIC_VIDEO, $this->categorize('Metallica.World.Tour.2024.1080p.BluRay.x264-GRP'));
    }

    private function categorize(string $name): int
    {
        $passable = new CategorizationPassable(new ReleaseContext(
            releaseName: $name,
            groupId: 0,
            groupName: 'alt.binaries.moovee',
            poster: '',
            categorizeForeign: false,
        ));

        foreach ([new MiscPipe, new GroupNamePipe, new XxxPipe, new TvPipe, new MoviePipe, new BookPipe, new MusicPipe, new PcPipe, new ConsolePipe, new MiscSafetyNetPipe] as $pipe) {
            $passable = $pipe->handle($passable, fn ($p) => $p);
        }

        return $passable->bestResult->categoryId;
    }
}
