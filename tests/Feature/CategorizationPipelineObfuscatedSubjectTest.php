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
}
