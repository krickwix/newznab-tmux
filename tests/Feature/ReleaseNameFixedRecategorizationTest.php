<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReleaseNameFixedRecategorizationTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-release-name-fixed-test.sqlite';

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES ('categorizeforeign', '0'), ('catwebdl', '1')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('settings')->upsert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '1'],
        ], ['name'], ['value']);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);

        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction(
            'REGEXP',
            static function (?string $pattern, ?string $subject): int {
                if ($pattern === null || $subject === null || $pattern === '') {
                    return 0;
                }

                set_error_handler(static fn (): true => true);
                $matched = @preg_match('~'.$pattern.'~i', $subject);
                restore_error_handler();

                return $matched === 1 ? 1 : 0;
            },
            2
        );

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_renaming_hashed_release_recategorizes_it_synchronously(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.hdtv',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'd41d8cd98f00b204e9800998ecf8427e',
            'searchname' => 'd41d8cd98f00b204e9800998ecf8427e',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('a', 40),
            'leftguid' => 'a',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'Show.Name.S03E05.720p.HDTV.x264-GROUP',
            'nfoCheck: Title Match',
            true,
            'NFO, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame('Show.Name.S03E05.720p.HDTV.x264-GROUP', $release->searchname);
        $this->assertSame(Category::TV_HD, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_renaming_hashed_classic_movie_release_recategorizes_it_to_movie(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.dvd.classic.movies',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'rxeW3D5GEkKf5f9e4Nm - File 32 of 76: "EaytKMjYT7.part32.rar" yEnc',
            'searchname' => 'rxeW3D5GEkKf5f9e4Nm - File 32 of 76: "EaytKMjYT7.part32.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('f', 40),
            'leftguid' => 'f',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'West.of.Cheyenne.1931.DVDRip.XviD-NoGroup',
            'nfoCheck: Title Match',
            true,
            'NFO, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame('West.of.Cheyenne.1931.DVDRip.XviD-NoGroup', $release->searchname);
        $this->assertSame(Category::MOVIE_SD, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_movie_fix_name_lane_includes_movie_other_category(): void
    {
        $service = app(NameFixingService::class);
        $property = new \ReflectionProperty($service, 'movieCategoryIds');
        $property->setAccessible(true);

        $this->assertContains(Category::MOVIE_OTHER, $property->getValue($service));
    }

    public function test_regular_other_fix_name_lane_excludes_hashed_category(): void
    {
        $service = app(NameFixingService::class);
        $property = new \ReflectionProperty($service, 'othercats');
        $property->setAccessible(true);

        $categoryIds = array_map('intval', explode(',', $property->getValue($service)));

        $this->assertNotContains(Category::OTHER_HASHED, $categoryIds);
        $this->assertContains(Category::OTHER_MISC, $categoryIds);
        $this->assertContains(Category::MOVIE_OTHER, $categoryIds);
    }

    public function test_hashed_fix_name_lane_targets_only_hashed_category(): void
    {
        $service = app(NameFixingService::class);

        $fullHashed = new \ReflectionProperty($service, 'fullhashed');
        $fullHashed->setAccessible(true);

        $this->assertStringContainsString('rel.categories_id = '.Category::OTHER_HASHED, $fullHashed->getValue($service));
        $this->assertStringNotContainsString('rel.categories_id IN', $fullHashed->getValue($service));
    }

    public function test_renaming_olympic_webdl_release_recategorizes_it_from_movie_webdl_to_tv_sport(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.hdtv',
            'active' => 1,
            'backfill' => 0,
        ]);

        $oldName = 'WinterOlympics2026__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Winter_Olympic_Games_Milano_Cortina_2026_Closing_Ceremony_1080p25_WEB-DL_(MultiAudio).7z.065';
        $newName = 'Winter.Olympic.Games.Milano.Cortina.2026.Closing.Ceremony.1080p25.WEB-DL.(MultiAudio)';

        $release = Release::factory()->create([
            'name' => $oldName,
            'searchname' => $oldName,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_WEBDL,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            $newName,
            'NZBSPLIT wrapper',
            true,
            'Filenames, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame($newName, $release->searchname);
        $this->assertSame(Category::TV_SPORT, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_renaming_space_separated_scene_title_does_not_overwrite_dotted_searchname(): void
    {
        Search::shouldReceive('updateRelease')->once();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.multimedia',
            'active' => 1,
            'backfill' => 0,
        ]);

        $oldName = 'Southern.Charm.S11E12.Even.Further.South.720p.AMZN.WEB-DL.DDP2.0.H.264-NTb';
        $release = Release::factory()->create([
            'name' => '[1/25] - "Southern.Charm.S11E12.Even.Further.South.720p.AMZN.WEB-DL.DDP2.0.H.264-NTb.par2" yEnc',
            'searchname' => $oldName,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::TV_HD,
            'iscategorized' => 1,
            'isrenamed' => 1,
            'guid' => str_repeat('c', 40),
            'leftguid' => 'c',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'Southern Charm S11E12 Even Further South 720p AMZN WEB-DL DDP2 0 H 264-NTb',
            'RarInfo FileName Match',
            true,
            'Filenames, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame($oldName, $release->searchname);
        $this->assertSame(Category::TV_HD, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_classic_movie_filename_stem_prefers_readable_subject_title_for_recategorization(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.dvd.classics',
            'active' => 1,
            'backfill' => 1,
        ]);

        $subject = 'A Letter to Three Wives (1949) 1:1 - [18/50] - "ALETTERTOTHREEWIVES.part16.rar" yEnc';
        $release = Release::factory()->create([
            'name' => $subject,
            'searchname' => $subject,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('f', 40),
            'leftguid' => 'f',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'ALETTERTOTHREEWIVES',
            'RarInfo FileName Match',
            true,
            'Filenames, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame('A Letter to Three Wives 1949', $release->searchname);
        $this->assertSame(Category::MOVIE_OTHER, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_one_word_classic_movie_filename_stem_prefers_subject_title_with_year(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.dvd.classics',
            'active' => 1,
            'backfill' => 1,
        ]);

        $subject = 'Humoresque (1946) 1:1 - [03/42] - "HUMORESQUE.par2" yEnc';
        $release = Release::factory()->create([
            'name' => $subject,
            'searchname' => $subject,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('0', 40),
            'leftguid' => '0',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'HUMORESQUE',
            'RarInfo FileName Match',
            true,
            'Filenames, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame('Humoresque 1946', $release->searchname);
        $this->assertSame(Category::MOVIE_OTHER, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_par2_archive_part_name_prefers_subject_title_and_year(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.multimedia.vintage-film.post-1960',
            'active' => 1,
            'backfill' => 0,
        ]);

        $subject = 'A.Boy.And.His.Dog -1975 - [01/32] - 720x480 (Letterbox) - H264/AC3->MP4 - "A.Boy.And.His.Dog.par2" yEnc';
        $release = Release::factory()->create([
            'name' => $subject,
            'searchname' => $subject,
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('d', 40),
            'leftguid' => 'd',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'A.Boy.And.His.Dog.part01',
            'fileCheck: Folder name',
            true,
            'PAR2, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame('A.Boy.And.His.Dog.1975', $release->searchname);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_par2_folder_name_without_subject_rescue_does_not_rename(): void
    {
        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'random.par2 yEnc',
            'searchname' => 'random.par2 yEnc',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('e', 40),
            'leftguid' => 'e',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        Search::shouldReceive('updateRelease')->never();

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'Random.Folder.Name.part01',
            'fileCheck: Folder name',
            true,
            'PAR2, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame('random.par2 yEnc', $release->searchname);
        $this->assertSame(Category::OTHER_HASHED, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(0, (int) $release->isrenamed);
    }

    public function test_explicit_tv_episode_overrides_weak_movie_group_hint(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movie.classics',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => '[2/10] "Ma Sorciere Bien-Aimee S01E11-Transfert de pouvoir.rar" yEnc',
            'searchname' => '[2/10] "Ma Sorciere Bien-Aimee S01E11-Transfert de pouvoir.rar" yEnc',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_OTHER,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $service = app(ReleaseUpdateService::class);
        $service->updateRelease(
            $release->fresh(),
            'Ma Sorciere Bien-Aimee S01E11-Transfert de pouvoir',
            'fileCheck: Subject',
            true,
            'PAR2, ',
            true,
            false,
        );

        $release->refresh();

        $this->assertSame('Ma Sorciere Bien-Aimee S01E11-Transfert De Pouvoir', $release->searchname);
        $this->assertSame(Category::TV_OTHER, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(1, (int) $release->isrenamed);
    }

    public function test_recategorize_releases_test_mode_with_category_selector_does_not_update(): void
    {
        Search::shouldReceive('updateRelease')->once();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies.classic',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar" yEnc',
            'searchname' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('c', 40),
            'leftguid' => 'c',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('nntmux:recategorize-releases', [
            '--category' => (string) Category::OTHER_HASHED,
            '--test' => true,
        ])->expectsOutputToContain('Would have changed')
            ->assertSuccessful();

        $release->refresh();

        $this->assertSame(Category::OTHER_HASHED, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
    }

    public function test_recategorize_releases_combines_category_and_group_selectors(): void
    {
        Search::shouldReceive('updateRelease')->times(3);

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies.classic',
            'active' => 1,
            'backfill' => 0,
        ]);

        $selected = Release::factory()->create([
            'name' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar" yEnc',
            'searchname' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('6', 40),
            'leftguid' => '6',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $alreadyMovie = Release::factory()->create([
            'name' => 'Already Movie 1973',
            'searchname' => 'Already Movie 1973',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::MOVIE_OTHER,
            'iscategorized' => 1,
            'guid' => str_repeat('7', 40),
            'leftguid' => '7',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('nntmux:recategorize-releases', [
            '--category' => (string) Category::OTHER_HASHED,
            '--groups' => (string) $group->id,
        ])->assertSuccessful();

        $selected->refresh();
        $alreadyMovie->refresh();

        $this->assertSame(Category::MOVIE_OTHER, $selected->categories_id);
        $this->assertSame(1, (int) $selected->iscategorized);
        $this->assertSame(Category::MOVIE_OTHER, $alreadyMovie->categories_id);
    }

    public function test_recategorize_releases_limit_bounds_selected_updates(): void
    {
        Search::shouldReceive('updateRelease')->times(3);

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies.classic',
            'active' => 1,
            'backfill' => 0,
        ]);

        $first = Release::factory()->create([
            'name' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar" yEnc',
            'searchname' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('a', 40),
            'leftguid' => 'a',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $second = Release::factory()->create([
            'name' => 'The Proud Rebel - [02/10] - "The Proud Rebel 1958.part001.rar" yEnc',
            'searchname' => 'The Proud Rebel - [02/10] - "The Proud Rebel 1958.part001.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('b', 40),
            'leftguid' => 'b',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('nntmux:recategorize-releases', [
            '--category' => (string) Category::OTHER_HASHED,
            '--groups' => (string) $group->id,
            '--limit' => '1',
        ])->assertSuccessful();

        $first->refresh();
        $second->refresh();

        $this->assertSame(Category::MOVIE_OTHER, $first->categories_id);
        $this->assertSame(Category::OTHER_HASHED, $second->categories_id);
    }

    public function test_recategorize_releases_ids_selects_specific_releases(): void
    {
        Search::shouldReceive('updateRelease')->times(3);

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies.classic',
            'active' => 1,
            'backfill' => 0,
        ]);

        $selected = Release::factory()->create([
            'name' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar" yEnc',
            'searchname' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('d', 40),
            'leftguid' => 'd',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $unselected = Release::factory()->create([
            'name' => 'The Proud Rebel - [02/10] - "The Proud Rebel 1958.part001.rar" yEnc',
            'searchname' => 'The Proud Rebel - [02/10] - "The Proud Rebel 1958.part001.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('e', 40),
            'leftguid' => 'e',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('nntmux:recategorize-releases', [
            '--category' => (string) Category::OTHER_HASHED,
            '--ids' => (string) $selected->id,
        ])->assertSuccessful();

        $selected->refresh();
        $unselected->refresh();

        $this->assertSame(Category::MOVIE_OTHER, $selected->categories_id);
        $this->assertSame(Category::OTHER_HASHED, $unselected->categories_id);
    }

    public function test_recategorize_releases_rejects_all_with_limit_without_resetting_unselected_rows(): void
    {
        Search::shouldReceive('updateRelease')->once();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies.classic',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar" yEnc',
            'searchname' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('f', 40),
            'leftguid' => 'f',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('nntmux:recategorize-releases', [
            '--all' => true,
            '--limit' => '1',
        ])->expectsOutputToContain('Cannot combine --all')
            ->assertFailed();

        $release->refresh();

        $this->assertSame(1, (int) $release->iscategorized);
        $this->assertSame(Category::OTHER_HASHED, $release->categories_id);
    }

    #[DataProvider('invalidRecategorizeLimitProvider')]
    public function test_recategorize_releases_rejects_invalid_limit(string $limit): void
    {
        Search::shouldReceive('updateRelease')->once();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.movies.classic',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar" yEnc',
            'searchname' => 'The Man Called Noon - [41/97] - "The Man Called Noon 1973.part040.rar"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('1', 40),
            'leftguid' => '1',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('nntmux:recategorize-releases', [
            '--category' => (string) Category::OTHER_HASHED,
            '--limit' => $limit,
        ])->expectsOutputToContain('--limit must be a positive integer')
            ->assertFailed();

        $release->refresh();

        $this->assertSame(Category::OTHER_HASHED, $release->categories_id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidRecategorizeLimitProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
            'nonnumeric' => ['abc'],
        ];
    }

    public function test_recategorize_releases_uses_original_subject_when_searchname_lost_category_evidence(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.dvd.classics',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'DVDFab v12.4.2.5 (x64) + Fix - [02/15] - "DVDFab v12.4.2.5 (x64) + Fix.par2" yEnc',
            'searchname' => 'DVDFabActivator20221206.Cmd',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('9', 40),
            'leftguid' => '9',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('nntmux:recategorize-releases', [
            '--category' => (string) Category::OTHER_HASHED,
        ])->assertSuccessful();

        $release->refresh();

        $this->assertSame(Category::PC_0DAY, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
    }

    public function test_hashed_subject_fix_revisits_readable_software_subject_after_file_pass_miss(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.sounds.lossless',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => '(PotPlayerSetup64 Pro Installer) [2/7] - "PotPlayerSetup64.rar.par2" yEnc',
            'searchname' => '(PotPlayerSetup64 Pro Installer) [2/7] - "PotPlayerSetup64.rar.par2"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 0,
            'proc_files' => 1,
            'proc_par2' => 1,
            'guid' => str_repeat('6', 40),
            'leftguid' => '6',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('releases:fix-names', [
            'method' => 21,
            '--category' => 'hashed',
            '--update' => true,
            '--set-status' => true,
        ])->assertSuccessful();

        $release->refresh();

        $this->assertSame('PotPlayerSetup64 Pro Installer', $release->searchname);
        $this->assertSame(Category::PC_0DAY, $release->categories_id);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->iscategorized);
    }

    public function test_hashed_subject_fix_uses_original_software_subject_when_searchname_lost_evidence(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.sounds.lossless',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => '(Microsoft Office Professional Plus (2024) - x64 - Multilingual [Latest Package] v24.05 64) [19/34] - "Microsoft Office (2024) - Professional x64 - German Latest Package English Plus.part18.rar" yEnc',
            'searchname' => 'Configurationx64.Xml',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 1,
            'proc_files' => 1,
            'proc_par2' => 1,
            'guid' => str_repeat('5', 40),
            'leftguid' => '5',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('releases:fix-names', [
            'method' => 21,
            '--category' => 'hashed',
            '--update' => true,
            '--set-status' => true,
        ])->assertSuccessful();

        $release->refresh();

        $this->assertStringContainsString('Microsoft Office Professional Plus', $release->searchname);
        $this->assertSame(Category::PC_0DAY, $release->categories_id);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->iscategorized);
    }

    public function test_hashed_subject_fix_recategorizes_when_searchname_was_already_repaired(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.sounds.lossless',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => '(PotPlayerSetup64 Pro Installer) [2/7] - "PotPlayerSetup64.rar.par2" yEnc',
            'searchname' => 'PotPlayerSetup64 Pro Installer',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'isrenamed' => 1,
            'proc_files' => 1,
            'proc_par2' => 1,
            'guid' => str_repeat('4', 40),
            'leftguid' => '4',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('releases:fix-names', [
            'method' => 21,
            '--category' => 'hashed',
            '--update' => true,
            '--set-status' => true,
        ])->assertSuccessful();

        $release->refresh();

        $this->assertSame('PotPlayerSetup64 Pro Installer', $release->searchname);
        $this->assertSame(Category::PC_0DAY, $release->categories_id);
        $this->assertSame(1, (int) $release->isrenamed);
        $this->assertSame(1, (int) $release->iscategorized);
    }

    public function test_recategorize_releases_moves_readable_vintage_subject_out_of_hashed(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.multimedia.vintage-film.post-1960',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'Hellfighters [0769/1127] "hellfighters.1080.vol085+2.PAR2.bad" yEnc',
            'searchname' => 'hellfighters.1080',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('8', 40),
            'leftguid' => '8',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('nntmux:recategorize-releases', [
            '--category' => (string) Category::OTHER_HASHED,
        ])->assertSuccessful();

        $release->refresh();

        $this->assertSame(Category::MOVIE_OTHER, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
    }

    public function test_recategorize_releases_handles_vintage_video_image_sidecar_subject(): void
    {
        Search::shouldReceive('updateRelease')->twice();

        $group = UsenetGroup::query()->create([
            'name' => 'alt.binaries.multimedia.vintage-film.post-1960',
            'active' => 1,
            'backfill' => 0,
        ]);

        $release = Release::factory()->create([
            'name' => 'THE WRONG BOX.1966.Xvid-KG.[02/87] - "WrongBox.avi.2.jpg" yEnc',
            'searchname' => 'THE WRONG BOX.1966.Xvid-KG.[02/87] - "WrongBox.avi.2.jpg"',
            'fromname' => 'poster@example.com',
            'groups_id' => $group->id,
            'categories_id' => Category::OTHER_HASHED,
            'iscategorized' => 1,
            'guid' => str_repeat('7', 40),
            'leftguid' => '7',
            'size' => 1,
            'postdate' => now(),
            'adddate' => now(),
        ]);

        $this->artisan('nntmux:recategorize-releases', [
            '--category' => (string) Category::OTHER_HASHED,
        ])->assertSuccessful();

        $release->refresh();

        $this->assertSame(Category::MOVIE_SD, $release->categories_id);
        $this->assertSame(1, (int) $release->iscategorized);
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        if (! Schema::hasTable('root_categories')) {
            Schema::create('root_categories', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title');
                $table->integer('status')->default(1);
                $table->boolean('disablepreview')->default(false);
            });

            DB::table('root_categories')->insert([
                ['id' => Category::OTHER_ROOT, 'title' => 'Other', 'status' => 1, 'disablepreview' => 0],
                ['id' => Category::MOVIE_ROOT, 'title' => 'Movies', 'status' => 1, 'disablepreview' => 0],
            ]);
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table): void {
                $table->integer('id')->primary();
                $table->string('title');
                $table->integer('root_categories_id')->nullable();
                $table->integer('status')->default(1);
                $table->boolean('disablepreview')->default(false);
                $table->integer('minsizetoformrelease')->default(0);
                $table->integer('maxsizetoformrelease')->default(0);
            });

            DB::table('categories')->insert([
                ['id' => Category::OTHER_MISC, 'title' => 'Misc', 'root_categories_id' => Category::OTHER_ROOT],
                ['id' => Category::OTHER_HASHED, 'title' => 'Hashed', 'root_categories_id' => Category::OTHER_ROOT],
                ['id' => Category::MOVIE_OTHER, 'title' => 'Other', 'root_categories_id' => Category::MOVIE_ROOT],
                ['id' => Category::MOVIE_SD, 'title' => 'SD', 'root_categories_id' => Category::MOVIE_ROOT],
                ['id' => Category::PC_0DAY, 'title' => '0day', 'root_categories_id' => Category::PC_ROOT],
            ]);
        }

        if (! Schema::hasTable('predb')) {
            Schema::create('predb', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title')->default('');
                $table->dateTime('predate')->nullable();
                $table->tinyInteger('searched')->default(0);
            });
        }

        if (! Schema::hasTable('usenet_groups')) {
            Schema::create('usenet_groups', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->unique();
                $table->integer('backfill_target')->default(1);
                $table->unsignedBigInteger('first_record')->default(0);
                $table->dateTime('first_record_postdate')->nullable();
                $table->unsignedBigInteger('last_record')->default(0);
                $table->dateTime('last_record_postdate')->nullable();
                $table->dateTime('last_updated')->nullable();
                $table->integer('minfilestoformrelease')->nullable();
                $table->bigInteger('minsizetoformrelease')->nullable();
                $table->boolean('active')->default(false);
                $table->boolean('backfill')->default(false);
                $table->string('description')->nullable();
            });
        }

        if (! Schema::hasTable('releases')) {
            Schema::create('releases', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->default('');
                $table->string('searchname')->default('');
                $table->unsignedInteger('groups_id')->default(0);
                $table->unsignedBigInteger('size')->default(0);
                $table->dateTime('postdate')->nullable();
                $table->dateTime('adddate')->nullable();
                $table->string('guid', 40);
                $table->char('leftguid', 1);
                $table->string('fromname')->nullable();
                $table->integer('categories_id')->default(Category::OTHER_MISC);
                $table->unsignedInteger('videos_id')->default(0);
                $table->integer('tv_episodes_id')->default(0);
                $table->string('imdbid')->nullable();
                $table->integer('musicinfo_id')->nullable();
                $table->integer('consoleinfo_id')->nullable();
                $table->integer('gamesinfo_id')->default(0);
                $table->integer('bookinfo_id')->nullable();
                $table->integer('anidbid')->nullable();
                $table->unsignedInteger('predb_id')->default(0);
                $table->tinyInteger('iscategorized')->default(0);
                $table->tinyInteger('isrenamed')->default(0);
                $table->tinyInteger('proc_nfo')->default(0);
                $table->tinyInteger('proc_files')->default(0);
                $table->tinyInteger('proc_par2')->default(0);
                $table->tinyInteger('proc_uid')->default(0);
                $table->tinyInteger('proc_hash16k')->default(0);
                $table->tinyInteger('proc_srr')->default(0);
                $table->tinyInteger('proc_crc32')->default(0);
                $table->tinyInteger('passwordstatus')->default(0);
                $table->tinyInteger('nzbstatus')->default(0);
            });
        }
    }
}
