<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\MiscCategorizer;
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

class HashedReleaseCategorizationTest extends TestCase
{
    /**
     * The ordered list of pipes that matches CategorizationPipeline::createDefault().
     * Sorted by priority: MiscPipe(1), GroupNamePipe(5), XxxPipe(10), TvPipe(20),
     * MoviePipe(25), BookPipe, MusicPipe, PcPipe, ConsolePipe, MiscSafetyNetPipe.
     *
     * @return list<object>
     */
    private function buildPipes(): array
    {
        return [
            new MiscPipe,
            new GroupNamePipe,
            new XxxPipe,
            new TvPipe,
            new MoviePipe,
            new BookPipe,
            new MusicPipe,
            new PcPipe,
            new ConsolePipe,
            new MiscSafetyNetPipe,
        ];
    }

    /**
     * Run a release name through the full pipe chain and return the passable.
     */
    private function runPipeline(string $releaseName, string $groupName = ''): CategorizationPassable
    {
        $context = new ReleaseContext(
            releaseName: $releaseName,
            groupId: 0,
            groupName: $groupName,
            poster: '',
        );

        $passable = new CategorizationPassable($context, debug: true);
        $pipes = $this->buildPipes();

        // Manually run through each pipe in order (avoids needing Laravel app container)
        foreach ($pipes as $pipe) {
            $passable = $pipe->handle($passable, fn ($p) => $p);
        }

        return $passable;
    }

    // ------------------------------------------------------------------
    // Data providers
    // ------------------------------------------------------------------

    /**
     * Hashed release names that MUST end up in OTHER_HASHED.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hashedNamesProvider(): array
    {
        return [
            'MD5 hash' => ['d41d8cd98f00b204e9800998ecf8427e', 'hash_md5'],
            'MD5 hash with quotes' => ['"d41d8cd98f00b204e9800998ecf8427e"', 'hash_md5'],
            'SHA-1 hash' => ['da39a3ee5e6b4b0d3255bfef95601890afd80709', 'hash_sha1'],
            'SHA-256 hash' => ['e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'hash_sha256'],
            'UUID with dashes' => ['550e8400-e29b-41d4-a716-446655440000', 'hash_uuid'],
            'Pure hex 20 chars' => ['aabbccdd0011223344ff', 'hash_hex'],
            'All uppercase 20 chars' => ['ABCDEFGH1234567890XY', 'obfuscated_uppercase'],
            'Mixed alphanumeric random' => ['AA7Jl2toE8Q53yNZmQ5R6G', 'obfuscated_mixed_alphanumeric'],
            'Usenet obfuscated filename' => ['[01/10] - "xK9mR2pL4qW7nT3vB.part01.rar"', 'obfuscated_usenet_filename'],
            'Base64-like token' => ['VGhpc0lzTm90QVNob3dOYW1lMTIzNDU2Nzg5MA==', 'hash_base64_like'],
        ];
    }

    /**
     * Gibberish release names that MUST end up in OTHER_HASHED.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gibberishNamesProvider(): array
    {
        return [
            // Contains dots and lowercase letters — bypasses obfuscated_uppercase (needs ^[A-Z0-9]),
            // obfuscated_mixed_alphanumeric (needs ^[a-zA-Z0-9]{15,}$ — dots break it),
            // and obfuscated_punctuation (needs all uppercase alpha).
            // After stripping separators, coreName has high character-transition rate.
            'Random transitions' => ['aB3c.D4eF.5gH6i.J7kL8m', 'gibberish_random_transitions'],
            // Lowercase with dots and digits — bypasses all obfuscated checks.
            // After stripping, coreName ≥20 with maxConsecutiveLetters < 5 but low transition rate.
            // Grouped letter/digit runs keep transition rate ≤ 0.35.
            'No word structure long' => ['xyz.1234.wvut.5678.srqp.9012.rar', 'gibberish_no_word_structure'],
            // Lowercase with dots and digits — bypasses all obfuscated checks.
            // After stripping, coreName matches digit-heavy pattern (1-3 letters + 6+ digits).
            'Digit-heavy pattern' => ['xz.123456789012', 'gibberish_random_digits'],
            'Zero vowel core' => ['xkcdqwrtypsdfghjklmnbvcxz', 'gibberish_zero_vowels'],
            'No signal long token' => ['A1b2.C3d4.E5f6.G7h8.I9j0', 'gibberish_no_signal'],
        ];
    }

    /**
     * Legitimate release names that MUST NOT be locked to misc.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function legitimateNamesProvider(): array
    {
        return [
            'Movie release' => ['Some.Movie.2024.1080p.BluRay.x264-GROUP', 'alt.binaries.movies'],
            'TV episode' => ['Show.Name.S03E05.720p.HDTV.x264-GROUP', 'alt.binaries.hdtv'],
            'Music album' => ['Artist.Name-Album.Title-2024-FLAC-GROUP', 'alt.binaries.sounds.mp3'],
            'Game release' => ['Starfield-RUNE', 'alt.binaries.games'],
            'Readable software package' => ['Microsoft Office Suite Installer', 'alt.binaries.warez'],
            'Adobe msix bundle' => ['Adobe Express Photos.Msixbundle', 'alt.binaries.erotica.divx'],
        ];
    }

    /**
     * Readable software-like names that must not be classified as hashed by the misc categorizer.
     *
     * @return array<string, array{0: string}>
     */
    public static function readableSoftwareNamesProvider(): array
    {
        return [
            'Adobe msix bundle' => ['Adobe Express Photos.Msixbundle'],
            'Office installer words' => ['Microsoft Office Suite Installer'],
        ];
    }

    /**
     * Hashed names paired with group names that would otherwise
     * categorize the release into a content category.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hashedWithGroupProvider(): array
    {
        return [
            'MD5 in movies group' => ['d41d8cd98f00b204e9800998ecf8427e', 'alt.binaries.movies'],
            'Random in hdtv group' => ['AA7Jl2toE8Q53yNZmQ5R6G', 'alt.binaries.hdtv'],
            'SHA1 in music group' => ['da39a3ee5e6b4b0d3255bfef95601890afd80709', 'alt.binaries.sounds.mp3'],
            'Uppercase in xxx group' => ['ABCDEFGH1234567890XY', 'alt.binaries.erotica'],
            'Gibberish in games group' => ['aB3cD4eF5gH6iJ7kL8m', 'alt.binaries.games'],
            'Hex in warez group' => ['aabbccdd0011223344ff', 'alt.binaries.warez'],
            'Random digits in ebook group' => ['ab12345678901234', 'alt.binaries.e-book'],
            'Base64-like token in TV group' => ['VGhpc0lzTm90QVNob3dOYW1lMTIzNDU2Nzg5MA==', 'alt.binaries.hdtv'],
            'Zero vowel token in movie group' => ['xkcdqwrtypsdfghjklmnbvcxz', 'alt.binaries.movies'],
        ];
    }

    /**
     * Low-signal names that are not strong enough for an early lock but should
     * still be kept out of content categories when only the group matched.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lowSignalGroupOnlyProvider(): array
    {
        return [
            'Short low-signal token in TV group' => ['ab12.cd34.ef56', 'alt.binaries.hdtv'],
            'Short low-signal token in movies group' => ['zx90.cv78.bn56', 'alt.binaries.movies'],
        ];
    }

    // ------------------------------------------------------------------
    // Tests: MiscCategorizer (unit-level)
    // ------------------------------------------------------------------

    #[DataProvider('hashedNamesProvider')]
    public function test_misc_categorizer_detects_hashed_names(string $name, string $expectedMatchedBy): void
    {
        $categorizer = new MiscCategorizer;
        $context = new ReleaseContext(releaseName: $name, groupId: 0);
        $result = $categorizer->categorize($context);

        $this->assertTrue($result->isSuccessful(), "Expected successful match for: $name");
        $this->assertSame($expectedMatchedBy, $result->matchedBy, "Wrong matchedBy tag for: $name");
        $this->assertContains(
            $result->categoryId,
            [Category::OTHER_HASHED, Category::OTHER_MISC],
            "Expected misc category for: $name"
        );
    }

    #[DataProvider('gibberishNamesProvider')]
    public function test_misc_categorizer_detects_gibberish_names(string $name, string $expectedMatchedBy): void
    {
        $categorizer = new MiscCategorizer;
        $context = new ReleaseContext(releaseName: $name, groupId: 0);
        $result = $categorizer->categorize($context);

        $this->assertTrue($result->isSuccessful(), "Expected successful match for: $name");
        $this->assertSame($expectedMatchedBy, $result->matchedBy, "Wrong matchedBy tag for: $name");
        $this->assertSame(Category::OTHER_HASHED, $result->categoryId, "Expected OTHER_HASHED for: $name");
    }

    #[DataProvider('readableSoftwareNamesProvider')]
    public function test_misc_categorizer_does_not_hash_readable_software_names(string $name): void
    {
        $categorizer = new MiscCategorizer;
        $context = new ReleaseContext(releaseName: $name, groupId: 0);
        $result = $categorizer->categorize($context);

        $this->assertFalse($result->isSuccessful(), "Readable software name '$name' should not be matched by misc hash heuristics");
        $this->assertSame(Category::OTHER_MISC, $result->categoryId);
        $this->assertSame('no_match', $result->matchedBy);
    }

    // ------------------------------------------------------------------
    // Tests: MiscPipe lock mechanism
    // ------------------------------------------------------------------

    #[DataProvider('hashedNamesProvider')]
    public function test_misc_pipe_locks_hashed_releases(string $name, string $expectedMatchedBy): void
    {
        $passable = $this->runPipeline($name);

        $this->assertTrue($passable->lockedToMisc, "Expected lockedToMisc for: $name");
        $this->assertContains(
            $passable->bestResult->categoryId,
            [Category::OTHER_HASHED, Category::OTHER_MISC],
            "Expected misc category for locked release: $name"
        );
    }

    #[DataProvider('gibberishNamesProvider')]
    public function test_misc_pipe_locks_gibberish_releases(string $name, string $expectedMatchedBy): void
    {
        $passable = $this->runPipeline($name);

        $this->assertTrue($passable->lockedToMisc, "Expected lockedToMisc for: $name");
        $this->assertSame(
            Category::OTHER_HASHED,
            $passable->bestResult->categoryId,
            "Expected OTHER_HASHED for locked release: $name"
        );
    }

    // ------------------------------------------------------------------
    // Tests: Hashed releases are NOT overridden by group-based categorization
    // ------------------------------------------------------------------

    #[DataProvider('hashedWithGroupProvider')]
    public function test_hashed_releases_not_overridden_by_group(string $name, string $groupName): void
    {
        $passable = $this->runPipeline($name, $groupName);

        $this->assertTrue($passable->lockedToMisc, "Expected lockedToMisc for: $name (group: $groupName)");
        $this->assertContains(
            $passable->bestResult->categoryId,
            [Category::OTHER_HASHED, Category::OTHER_MISC],
            "Hashed release '$name' in group '$groupName' should stay in misc, got category: {$passable->bestResult->categoryId}"
        );

        // Verify it was NOT assigned a content category
        $this->assertNotContains(
            $passable->bestResult->categoryId,
            [
                Category::TV_OTHER, Category::MOVIE_OTHER, Category::XXX_OTHER,
                Category::MUSIC_OTHER, Category::GAME_OTHER, Category::PC_0DAY,
                Category::BOOKS_EBOOK,
            ],
            "Hashed release '$name' should NOT be in content category, but got: {$passable->bestResult->categoryId}"
        );
    }

    #[DataProvider('lowSignalGroupOnlyProvider')]
    public function test_group_only_low_signal_releases_fall_back_to_other_misc(string $name, string $groupName): void
    {
        $passable = $this->runPipeline($name, $groupName);

        $this->assertTrue($passable->lockedToMisc, "Expected lockedToMisc for low-signal release: $name");
        $this->assertSame(Category::OTHER_MISC, $passable->bestResult->categoryId);
        $this->assertSame('group_only_low_signal', $passable->bestResult->matchedBy);
    }

    // ------------------------------------------------------------------
    // Tests: Legitimate releases still categorize normally
    // ------------------------------------------------------------------

    #[DataProvider('legitimateNamesProvider')]
    public function test_legitimate_releases_are_not_locked(string $name, string $groupName): void
    {
        $passable = $this->runPipeline($name, $groupName);

        $this->assertFalse($passable->lockedToMisc, "Legitimate release '$name' should NOT be locked to misc");

        // They should NOT end up in OTHER_HASHED
        $this->assertNotSame(
            Category::OTHER_HASHED,
            $passable->bestResult->categoryId,
            "Legitimate release '$name' should NOT be in OTHER_HASHED"
        );
    }

    public function test_adobe_msix_bundle_reaches_pc_0day_in_full_pipeline(): void
    {
        $passable = $this->runPipeline('Adobe Express Photos.Msixbundle', 'alt.binaries.erotica.divx');

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::PC_0DAY, $passable->bestResult->categoryId);
        $this->assertSame('0day_msix_installer', $passable->bestResult->matchedBy);
    }

    public function test_readable_tv_title_with_season_episode_is_not_hashed(): void
    {
        $passable = $this->runPipeline('Filing for Love S01E12', 'alt.binaries.hdtv.x264');

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::TV_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('group_name_tv', $passable->bestResult->matchedBy);
    }

    public function test_readable_vintage_movie_title_without_year_is_not_hashed(): void
    {
        $passable = $this->runPipeline('G Men Vs The Black Dragon', 'alt.binaries.multimedia.vintage-film');

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('group_name_movie', $passable->bestResult->matchedBy);
    }

    public function test_readable_classic_movie_series_title_is_not_hashed(): void
    {
        $passable = $this->runPipeline('Torchy Blane Movies 2', 'alt.binaries.multimedia.vintage-film.pre-1960');

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('group_name_movie', $passable->bestResult->matchedBy);
    }

    public function test_dvd_r_group_alone_does_not_override_software_evidence(): void
    {
        $passable = $this->runPipeline(
            'Topaz Video AI Pro 2026.8.1.6 (x64).rar.par2',
            'alt.binaries.dvd-r',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::PC_0DAY, $passable->bestResult->categoryId);
        $this->assertSame('0day_software', $passable->bestResult->matchedBy);
    }

    public function test_dvd_classic_movie_group_still_categorizes_movie_sidecar(): void
    {
        $passable = $this->runPipeline(
            'The Night My Number Came Up (1955) [01/62] - "The Night My Number Came Up (1955).par2" yEnc',
            'alt.binaries.dvd.classic.movies',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('vintage_film_file', $passable->bestResult->matchedBy);
    }

    public function test_vintage_film_nfo_subject_categorizes_as_movie_not_audio(): void
    {
        $passable = $this->runPipeline(
            '(NMR) [03/34] - "West of Cheyenne (Tom Tyler) (1931).nfo" yEnc',
            'alt.binaries.multimedia.vintage-film.pre-1960',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('vintage_film_file', $passable->bestResult->matchedBy);
    }

    public function test_normalized_vintage_film_nfo_subject_categorizes_as_movie_not_audio(): void
    {
        $passable = $this->runPipeline(
            'West of Cheyenne (Tom Tyler) (1931) nfo',
            'alt.binaries.multimedia.vintage-film.pre-1960',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('vintage_film_file', $passable->bestResult->matchedBy);
    }

    public function test_normalized_classic_movie_title_beats_audio_group_name(): void
    {
        $passable = $this->runPipeline(
            'West of Cheyenne (Tom Tyler) (1931)',
            'alt.binaries.sounds.movies',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('classic_movie_title', $passable->bestResult->matchedBy);
    }

    public function test_music_album_with_single_trailing_year_stays_audio(): void
    {
        $passable = $this->runPipeline(
            'Miles Davis Kind of Blue (1959)',
            'alt.binaries.sounds.mp3',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MUSIC_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('group_name_music', $passable->bestResult->matchedBy);
    }

    public function test_classic_movie_with_mp3_audio_token_beats_audio_category(): void
    {
        $passable = $this->runPipeline(
            '[4/28] "Ma and Pa Kettle On Old MacDonald\'s Farm (1957) 480p TVrip MPEG4 MP3 AOS.part03.rar" yEnc',
            'alt.binaries.dvd.classic.movies',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_SD, $passable->bestResult->categoryId);
        $this->assertSame('vintage_film_sd', $passable->bestResult->matchedBy);
    }

    public function test_classic_movie_nfo_sidecar_is_not_audio_other(): void
    {
        $passable = $this->runPipeline(
            '(NMR) [03/34] - "West of Cheyenne (Tom Tyler) (1931).nfo" yEnc',
            'alt.binaries.multimedia.vintage-film.pre-1960',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('vintage_film_file', $passable->bestResult->matchedBy);
    }

    public function test_vintage_movie_sidecar_with_music_words_is_not_audio_lossless(): void
    {
        $passable = $this->runPipeline(
            'The GAZEBO - 1959 - [WS] - AVC - [02/59] - "gazebo.part01.rar" yEnc',
            'alt.binaries.multimedia.vintage-film',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_HD, $passable->bestResult->categoryId);
        $this->assertSame('hd', $passable->bestResult->matchedBy);
    }

    public function test_hdtv_encoded_episode_is_not_music_video(): void
    {
        $passable = $this->runPipeline(
            '[YE] Pocket Monsters (2023) - 138 (TVO 1280x720 x265 10bit AAC).7z',
            'alt.binaries.hdtv.x264',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::TV_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('group_name_tv', $passable->bestResult->matchedBy);
    }

    public function test_generic_x264_release_does_not_become_mp3_from_embedded_scene_token(): void
    {
        $passable = $this->runPipeline(
            'Britney_Spears-Circus-x264-2008-FRAY_INT.rar.vol0+1',
            'alt.binaries.x264',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_OTHER, $passable->bestResult->categoryId);
        $this->assertSame('group_name_movie', $passable->bestResult->matchedBy);
    }

    public function test_vintage_film_vcd_collection_is_movie_not_audio_album(): void
    {
        $passable = $this->runPipeline(
            'Even More Offerings - REQ:Decasia [07/27] - "Assorted Spike Jones VCD Collection.part06.rar" yEnc',
            'alt.binaries.multimedia.vintage-film.pre-1960',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_SD, $passable->bestResult->categoryId);
        $this->assertSame('vintage_film_sd', $passable->bestResult->matchedBy);
    }

    public function test_segmented_vintage_film_avi_beats_mp3_scene_pattern(): void
    {
        $passable = $this->runPipeline(
            '[Pierre Etaix - Le grand amour)[08/57] - "Le grand amour-Pierre Etaix-1969-87mn.avi.008" yEnc',
            'alt.binaries.multimedia.vintage-film',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_SD, $passable->bestResult->categoryId);
        $this->assertSame('vintage_film_sd', $passable->bestResult->matchedBy);
    }

    public function test_vintage_film_archive_disc_pattern_beats_music_anthology(): void
    {
        $passable = $this->runPipeline(
            'EnJoY! =>PLEASE READ "0" FILE (Day3/?) [034/198] - "Brakhage - An Anthology 2010 - Vol2-Disc1 - Program I - The Dead - 1960 (SVCD).part.PAR2" yEnc',
            'alt.binaries.multimedia.vintage-film.pre-1960',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_SD, $passable->bestResult->categoryId);
        $this->assertSame('vintage_film_sd', $passable->bestResult->matchedBy);
    }

    public function test_vintage_film_without_year_but_video_evidence_beats_audio_video(): void
    {
        $passable = $this->runPipeline(
            'Joyeux Noel! =>Req:READ "0" FILE=>EnJoY! [17/61] - "Alexandre Alexeieff - Assorted Advertising Films (480p,x264).nfo" yEnc',
            'alt.binaries.multimedia.vintage-film.pre-1960',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_HD, $passable->bestResult->categoryId);
        $this->assertSame('hd', $passable->bestResult->matchedBy);
    }

    public function test_movie_video_par2_sidecar_beats_mp3_scene_pattern(): void
    {
        $passable = $this->runPipeline(
            'Gallipoli2014 [57/71]  "Gallipoli01-2014-Movie.mkv.par2"',
            'alt.binaries.documentaries.mkv',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_HD, $passable->bestResult->categoryId);
        $this->assertSame('documentary_video_hd', $passable->bestResult->matchedBy);
    }

    public function test_cleaned_movie_video_sidecar_stem_beats_mp3_scene_pattern(): void
    {
        $passable = $this->runPipeline(
            'Gallipoli01-2014-Movie mkv',
            'alt.binaries.documentaries.mkv',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_HD, $passable->bestResult->categoryId);
        $this->assertSame('documentary_video_hd', $passable->bestResult->matchedBy);
    }

    public function test_preprocessed_vintage_video_title_beats_music_video_artist(): void
    {
        $passable = $this->runPipeline(
            'Ray Harryhausen - Tests and Experiments - A Collection, Hosted by Their Creator (480p,x264)',
            'alt.binaries.multimedia.vintage-film.pre-1960',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_HD, $passable->bestResult->categoryId);
        $this->assertSame('hd', $passable->bestResult->matchedBy);
    }

    public function test_vintage_runtime_title_beats_mp3_scene_pattern(): void
    {
        $passable = $this->runPipeline(
            'Heureux Anniversaire-Pierre Etaix-1962-12mn',
            'alt.binaries.multimedia.vintage-film',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_SD, $passable->bestResult->categoryId);
        $this->assertSame('vintage_film_sd', $passable->bestResult->matchedBy);
    }

    public function test_documentary_webrip_beats_lossless_audio_pattern(): void
    {
        $passable = $this->runPipeline(
            'Apex 2026 1080p WEBRip DD5 1 x265-iND',
            'alt.binaries.documentaries',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_WEBDL, $passable->bestResult->categoryId);
        $this->assertSame('documentary_video_web', $passable->bestResult->matchedBy);
    }

    public function test_documentary_web_video_is_movie_not_audio_or_misc(): void
    {
        $passable = $this->runPipeline(
            'Crime 101 2026 1080p WEB x265-iND',
            'alt.binaries.documentaries',
        );

        $this->assertFalse($passable->lockedToMisc);
        $this->assertSame(Category::MOVIE_WEBDL, $passable->bestResult->categoryId);
        $this->assertSame('documentary_video_web', $passable->bestResult->matchedBy);
    }

    // ------------------------------------------------------------------
    // Tests: shouldStopProcessing() respects the lock
    // ------------------------------------------------------------------

    public function test_should_stop_processing_returns_true_when_locked(): void
    {
        $context = new ReleaseContext(releaseName: 'test', groupId: 0);
        $passable = new CategorizationPassable($context);

        $this->assertFalse($passable->shouldStopProcessing());

        $passable->lockToMisc();

        $this->assertTrue($passable->shouldStopProcessing());
    }

    public function test_locked_passable_prevents_downstream_pipes(): void
    {
        $context = new ReleaseContext(
            releaseName: 'd41d8cd98f00b204e9800998ecf8427e',
            groupId: 0,
            groupName: 'alt.binaries.movies',
        );

        $passable = new CategorizationPassable($context, debug: true);

        // Run MiscPipe first
        $miscPipe = new MiscPipe;
        $passable = $miscPipe->handle($passable, fn ($p) => $p);

        $this->assertTrue($passable->lockedToMisc);
        $this->assertSame(Category::OTHER_HASHED, $passable->bestResult->categoryId);

        // Now run GroupNamePipe — it should skip because of the lock
        $groupPipe = new GroupNamePipe;
        $passable = $groupPipe->handle($passable, fn ($p) => $p);

        // Category should still be OTHER_HASHED, not MOVIE_OTHER
        $this->assertSame(Category::OTHER_HASHED, $passable->bestResult->categoryId);
        $this->assertTrue($passable->lockedToMisc);
    }

    // ------------------------------------------------------------------
    // Tests: CategorizationResult::isSuccessful() changes
    // ------------------------------------------------------------------

    public function test_other_misc_with_matched_by_is_successful(): void
    {
        $result = new CategorizationResult(
            Category::OTHER_MISC, 0.5, 'obfuscated_pattern'
        );

        $this->assertTrue($result->isSuccessful());
    }

    public function test_no_match_sentinel_is_not_successful(): void
    {
        $result = CategorizationResult::noMatch();

        $this->assertFalse($result->isSuccessful());
    }

    public function test_other_hashed_is_successful(): void
    {
        $result = new CategorizationResult(
            Category::OTHER_HASHED, 0.95, 'hash_md5'
        );

        $this->assertTrue($result->isSuccessful());
    }

    // ------------------------------------------------------------------
    // Tests: Debug output includes locked_to_misc
    // ------------------------------------------------------------------

    public function test_debug_output_includes_locked_to_misc(): void
    {
        $passable = $this->runPipeline('d41d8cd98f00b204e9800998ecf8427e');
        $output = $passable->toArray();

        $this->assertArrayHasKey('debug', $output);
        $this->assertArrayHasKey('locked_to_misc', $output['debug']);
        $this->assertTrue($output['debug']['locked_to_misc']);
    }

    public function test_debug_output_not_locked_for_legitimate(): void
    {
        $context = new ReleaseContext(
            releaseName: 'Some.Movie.2024.1080p.BluRay.x264-GROUP',
            groupId: 0,
            groupName: '',
        );
        $passable = new CategorizationPassable($context, debug: true);

        $miscPipe = new MiscPipe;
        $passable = $miscPipe->handle($passable, fn ($p) => $p);

        $output = $passable->toArray();
        $this->assertArrayHasKey('debug', $output);
        $this->assertArrayHasKey('locked_to_misc', $output['debug']);
        $this->assertFalse($output['debug']['locked_to_misc']);
    }
}
