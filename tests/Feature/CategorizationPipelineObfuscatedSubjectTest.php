<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Categorization\CategorizationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CategorizationPipelineObfuscatedSubjectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');

        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '1'],
        ]);
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.multimedia.vintage-film.pre-1960'],
            ['id' => 2, 'name' => 'alt.binaries.blu-ray'],
            ['id' => 3, 'name' => 'alt.binaries.dvd.classics'],
            ['id' => 4, 'name' => 'alt.binaries.sounds.lossless'],
            ['id' => 5, 'name' => 'alt.binaries.documentaries'],
        ]);
    }

    public function test_readable_vintage_subject_is_not_replaced_by_archive_stem_before_categorization(): void
    {
        $result = app(CategorizationService::class)->determineCategory(
            1,
            '[East Side Kids - Ghosts on the Loose - Avi Xvid] [01/20] - "GHOSTSONTHELOOSE.part01.rar" yEnc',
            '',
            true,
        );

        $this->assertNotSame(Category::OTHER_HASHED, $result['categories_id']);
        $this->assertFalse($result['debug']['locked_to_misc']);
        $this->assertSame('alt.binaries.multimedia.vintage-film.pre-1960', $result['debug']['group_name']);
    }

    public function test_random_blu_ray_archive_stem_stays_hashed(): void
    {
        $result = app(CategorizationService::class)->determineCategory(
            2,
            '[02/12] - "x8aoZzoX_HQwuy8kKEb6.part01.rar" - 136,50 MB yEnc',
            '',
            true,
        );

        $this->assertSame(Category::OTHER_HASHED, $result['categories_id']);
        $this->assertTrue($result['debug']['locked_to_misc']);
    }

    public function test_readable_classic_dvd_subject_is_not_locked_as_base64_hash(): void
    {
        $result = app(CategorizationService::class)->determineCategory(
            3,
            '(01/53) - Attack of the Muppet People (1958) NTSC DVD5 Midnite Movies [repopo] - "ATTACK_OF_THE_PUPPET_PEOPLE_NTSC_DVD5_repopo.nfo" - 4,10 GB - yEnc',
            '',
            true,
        );

        $this->assertSame(Category::MOVIE_DVD, $result['categories_id']);
        $this->assertFalse($result['debug']['locked_to_misc']);
    }

    public function test_vintage_film_par2_sidecar_with_readable_title_without_year_is_not_hashed(): void
    {
        $result = app(CategorizationService::class)->determineCategory(
            1,
            '[East Side Kids - Pride of the Bowery- Avi Xvid] [01/39] yEnc - "PRIDEOFTHEBOWERY.par2" yEnc',
            '',
            true,
        );

        $this->assertSame(Category::MOVIE_OTHER, $result['categories_id']);
        $this->assertFalse($result['debug']['locked_to_misc']);
        $this->assertSame('vintage_film_file', $result['debug']['matched_by']);
    }

    public function test_vintage_film_compact_title_archive_subject_is_not_hashed(): void
    {
        $result = app(CategorizationService::class)->determineCategory(
            1,
            '[210/333] - "Airport77.part36.rar" yEnc',
            '',
            true,
        );

        $this->assertSame(Category::MOVIE_OTHER, $result['categories_id']);
        $this->assertFalse($result['debug']['locked_to_misc']);
        $this->assertSame('vintage_film_file', $result['debug']['matched_by']);
    }

    public function test_vintage_film_repeated_title_archive_subject_is_not_hashed(): void
    {
        $result = app(CategorizationService::class)->determineCategory(
            1,
            'JOE.McDOAKES.(4of6) [03/32] - "Joe McDoakes D4of6.part01.rar" yEnc',
            '',
            true,
        );

        $this->assertSame(Category::MOVIE_OTHER, $result['categories_id']);
        $this->assertFalse($result['debug']['locked_to_misc']);
        $this->assertSame('vintage_film_file', $result['debug']['matched_by']);
    }

    public function test_lossless_album_subject_keeps_context_around_quoted_track_filename(): void
    {
        foreach ([
            'Eric Clapton - Journeyman (1989): "05 Hard Times.flac" yEnc',
            'Gilmour, David - (2006) On An Island [NMR] [05/22] "03 The Blue.flac" yEnc',
        ] as $releaseName) {
            $result = app(CategorizationService::class)->determineCategory(4, $releaseName, '', true);

            $this->assertSame(Category::MUSIC_LOSSLESS, $result['categories_id'], $releaseName);
            $this->assertFalse($result['debug']['locked_to_misc'], $releaseName);
        }
    }

    public function test_readable_office_filename_is_not_locked_as_extracted_obfuscation(): void
    {
        $result = app(CategorizationService::class)->determineCategory(
            2,
            '(Rick Moranis randomly punched in the head by a man in NYC) [19/80] - "Office.2021.18129.20158.64Bit.part18.rar" yEnc',
            '',
            true,
        );

        $this->assertSame(Category::PC_0DAY, $result['categories_id']);
        $this->assertFalse($result['debug']['locked_to_misc']);
    }

    public function test_compact_scene_tv_subject_is_unwrapped_without_a_base64_lock(): void
    {
        $result = app(CategorizationService::class)->determineCategory(
            5,
            '[01/11] - "afo-theotherbennetsister-0105-720-web.mkv" yEnc',
            '',
            true,
        );

        $this->assertSame(Category::TV_WEBDL, $result['categories_id']);
        $this->assertFalse($result['debug']['locked_to_misc']);
        $this->assertSame('tv_compact_scene_episode_web', $result['debug']['matched_by']);
    }
}
