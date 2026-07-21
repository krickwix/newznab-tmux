<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\ItunesService;
use App\Services\ReleaseImageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PDO;
use Tests\TestCase;

class BackfillMusicCoversCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'nntmux-music-cover-command-test-');

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'nntmux_settings.covers_path' => '/tmp/nntmux-test-covers',
        ]);

        DB::purge();
        DB::reconnect();

        Schema::dropIfExists('musicinfo');
        Schema::create('musicinfo', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->string('asin')->nullable();
            $table->string('artist')->nullable();
            $table->boolean('cover')->default(false);
        });
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

    public function test_it_backfills_missing_music_cover_from_stored_itunes_collection_id(): void
    {
        DB::table('musicinfo')->insert([
            'id' => 10,
            'title' => 'No Cover No More',
            'artist' => 'Test Artist',
            'asin' => '123456789',
            'cover' => 0,
        ]);

        $itunes = Mockery::mock(ItunesService::class);
        $itunes->shouldReceive('lookupById')
            ->once()
            ->with(123456789)
            ->andReturn(['artworkUrl100' => 'https://example.test/100x100bb.jpg']);
        $this->app->instance(ItunesService::class, $itunes);

        $images = Mockery::mock(ReleaseImageService::class);
        $images->shouldReceive('saveImage')
            ->once()
            ->with('10', 'https://example.test/800x800bb.jpg', '/tmp/nntmux-test-covers/music/', 250, 250)
            ->andReturnUsing(function (string $id, string $url, string $directory): int {
                if (! is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }

                file_put_contents($directory.$id.'.jpg', 'image');

                return 1;
            });
        $this->app->instance(ReleaseImageService::class, $images);

        $this->artisan('music:backfill-covers', ['--id' => [10], '--limit' => 1])
            ->expectsOutputToContain('Backfilled music covers: 1')
            ->assertExitCode(0);

        $this->assertSame(1, (int) DB::table('musicinfo')->where('id', 10)->value('cover'));
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
}
