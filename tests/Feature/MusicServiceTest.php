<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\MusicService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class MusicServiceTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'nntmux-music-service-test-');

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('innerfileblacklist', ''),
            ('showpasswordedrelease', '0')");

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

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'nntmux.cache_expiry_medium' => 5,
            'nntmux_settings.covers_path' => storage_path('covers'),
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();

        $this->createSchema();
        $this->seedCategoryData();
        $this->seedSettings();
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

    public function test_get_music_range_returns_albums_without_downloaded_covers(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.music',
        ]);

        DB::table('musicinfo')->insert([
            'id' => 10,
            'title' => 'No Cover Album',
            'asin' => 'album-10',
            'artist' => 'Test Artist',
            'year' => '2026',
            'cover' => 0,
            'genres_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('releases')->insert([
            'id' => 100,
            'name' => 'no-cover-album',
            'searchname' => 'Test Artist - No Cover Album',
            'groups_id' => 1,
            'size' => 123456,
            'postdate' => now(),
            'adddate' => now(),
            'guid' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'leftguid' => 'a',
            'categories_id' => Category::MUSIC_MP3,
            'musicinfo_id' => 10,
            'passwordstatus' => 0,
            'nzb_guid' => 'test',
        ]);

        $results = (new MusicService)->getMusicRange(1, [Category::MUSIC_ROOT], 0, 20, '', []);

        $this->assertCount(1, $results);
        $this->assertSame('No Cover Album', $results[0]->title);
        $this->assertSame(1, (int) $results[0]->_totalcount);
        $this->assertCount(1, $results[0]->releases);
        $this->assertSame('Test Artist - No Cover Album', $results[0]->releases[0]->searchname);
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
        Schema::dropIfExists('dnzb_failures');
        Schema::dropIfExists('release_nfos');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('usenet_groups');
        Schema::dropIfExists('musicinfo');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('root_categories');
        Schema::dropIfExists('settings');

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
        });

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->integer('status')->default(1);
            $table->boolean('disablepreview')->default(false);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->unsignedInteger('parentid')->nullable();
            $table->integer('status')->default(1);
            $table->text('description')->nullable();
            $table->boolean('disablepreview')->default(false);
            $table->unsignedBigInteger('minsizetoformrelease')->default(0);
            $table->unsignedBigInteger('maxsizetoformrelease')->default(0);
            $table->unsignedInteger('root_categories_id')->nullable();
        });

        Schema::create('musicinfo', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->string('asin')->nullable();
            $table->string('url', 1000)->nullable();
            $table->unsignedInteger('salesrank')->nullable();
            $table->string('artist')->nullable();
            $table->string('publisher')->nullable();
            $table->dateTime('releasedate')->nullable();
            $table->text('review')->nullable();
            $table->string('year', 4);
            $table->integer('genres_id')->nullable();
            $table->text('tracks')->nullable();
            $table->boolean('cover')->default(false);
            $table->timestamps();
        });

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->default('');
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->integer('totalpart')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->unsignedBigInteger('size')->default(0);
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
            $table->string('guid', 40);
            $table->char('leftguid', 1);
            $table->integer('categories_id')->default(10);
            $table->integer('musicinfo_id')->nullable();
            $table->integer('grabs')->default(0);
            $table->integer('comments')->default(0);
            $table->smallInteger('passwordstatus')->default(-1);
            $table->boolean('haspreview')->default(false);
            $table->boolean('nfostatus')->default(false);
            $table->binary('nzb_guid');
        });

        Schema::create('release_nfos', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->binary('nfo')->nullable();
        });

        Schema::create('dnzb_failures', function (Blueprint $table): void {
            $table->unsignedInteger('release_id')->primary();
            $table->integer('failed')->default(0);
        });
    }

    private function seedCategoryData(): void
    {
        DB::table('root_categories')->insert([
            'id' => Category::MUSIC_ROOT,
            'title' => 'Audio',
            'status' => 1,
            'disablepreview' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('categories')->insert([
            'id' => Category::MUSIC_MP3,
            'title' => 'MP3',
            'parentid' => Category::MUSIC_ROOT,
            'status' => 1,
            'description' => 'Music',
            'disablepreview' => 0,
            'minsizetoformrelease' => 0,
            'maxsizetoformrelease' => 0,
            'root_categories_id' => Category::MUSIC_ROOT,
        ]);
    }

    private function seedSettings(): void
    {
        DB::table('settings')->insert([
            'name' => 'showpasswordedrelease',
            'value' => '0',
        ]);
    }
}
