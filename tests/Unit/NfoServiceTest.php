<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\NfoService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NfoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(255) NULL,
            nfostatus INTEGER DEFAULT -1,
            categories_id INTEGER DEFAULT 10
        )');

        DB::statement('CREATE TABLE release_nfos (
            releases_id INTEGER PRIMARY KEY,
            nfo BLOB NULL,
            FOREIGN KEY (releases_id) REFERENCES releases(id) ON DELETE CASCADE
        )');

        DB::statement('CREATE TABLE settings (
            name VARCHAR(255) PRIMARY KEY,
            value TEXT NULL
        )');

        DB::table('settings')->insert(['name' => 'timeoutseconds', 'value' => '60']);
    }

    public function test_store_nfo_content_skips_missing_release_without_fk_error(): void
    {
        $service = new NfoService;

        $stored = $service->storeNfoContent(69513, 'classic movie nfo', compress: false);

        $this->assertFalse($stored);
        $this->assertSame(0, DB::table('release_nfos')->where('releases_id', 69513)->count());
    }

    public function test_store_nfo_content_persists_existing_release_and_marks_found(): void
    {
        DB::table('releases')->insert([
            'id' => 70001,
            'guid' => 'test-guid',
            'nfostatus' => NfoService::NFO_UNPROC,
            'categories_id' => Category::MOVIE_OTHER,
        ]);

        $service = new NfoService;

        $stored = $service->storeNfoContent(70001, 'classic movie nfo', compress: false);

        $this->assertTrue($stored);
        $this->assertSame('classic movie nfo', DB::table('release_nfos')->where('releases_id', 70001)->value('nfo'));
        $this->assertSame(NfoService::NFO_FOUND, (int) DB::table('releases')->where('id', 70001)->value('nfostatus'));
    }
}
