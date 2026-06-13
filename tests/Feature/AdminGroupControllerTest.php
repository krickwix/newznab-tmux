<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminGroupController;
use App\Models\Content;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Mockery;
use PDO;
use ReflectionClass;
use Tests\TestCase;

class AdminGroupControllerTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-admin-group-test.sqlite';

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
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('title', 'NNTmux Test'),
            ('home_link', '/')");

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
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();

        $this->createSchema();
        $this->seedSettings();
        $this->resetGlobalComposerState();
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

    public function test_create_bulk_casts_string_flags_before_calling_add_bulk(): void
    {
        $request = Request::create('/admin/group-bulk', 'POST', [
            'action' => 'submit',
            'groupfilter' => " \n",
            'active' => '1',
            'backfill' => '0',
        ]);

        $response = app(AdminGroupController::class)->createBulk($request);

        $this->assertInstanceOf(View::class, $response);
        $this->assertSame('admin.groups.bulk', $response->name());
        $this->assertSame('Bulk Add Newsgroups', $response->getData()['title']);
        $this->assertSame('No group list provided.', $response->getData()['groupmsglist']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    public function test_create_bulk_persists_requested_backfill_target(): void
    {
        $nntp = Mockery::mock('overload:App\Services\NNTP\NNTPService');
        $nntp->shouldReceive('doConnect')->once()->andReturn(true);
        $nntp->shouldReceive('getGroups')->once()->andReturn([
            ['group' => 'alt.binaries.example'],
            ['group' => 'alt.binaries.other'],
        ]);
        $nntp->shouldReceive('doQuit')->once()->andReturn(true);
        $nntp->shouldReceive('isError')->once()->andReturn(false);

        $request = Request::create('/admin/group-bulk', 'POST', [
            'action' => 'submit',
            'groupfilter' => 'alt\.binaries\.example',
            'active' => '1',
            'backfill' => '1',
            'backfill_target' => '7305',
        ]);

        $response = app(AdminGroupController::class)->createBulk($request);

        $this->assertInstanceOf(View::class, $response);
        $this->assertDatabaseHas('usenet_groups', [
            'name' => 'alt.binaries.example',
            'active' => 1,
            'backfill' => 1,
            'backfill_target' => 7305,
        ]);
        $this->assertDatabaseMissing('usenet_groups', [
            'name' => 'alt.binaries.other',
        ]);
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

        if (! Schema::hasTable('content')) {
            Schema::create('content', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('title')->default('');
                $table->string('url', 2000)->nullable();
                $table->text('body')->nullable();
                $table->string('metadescription', 1000)->default('');
                $table->string('metakeywords', 1000)->default('');
                $table->integer('contenttype')->default(Content::TYPE_USEFUL);
                $table->integer('status')->default(Content::STATUS_ENABLED);
                $table->integer('ordinal')->nullable();
                $table->integer('role')->default(Content::ROLE_EVERYONE);
            });
        }

        if (! Schema::hasTable('usenet_groups')) {
            Schema::create('usenet_groups', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->unique();
                $table->string('description')->default('');
                $table->integer('backfill_target')->default(1);
                $table->bigInteger('first_record')->default(0);
                $table->bigInteger('last_record')->default(0);
                $table->boolean('active')->default(false);
                $table->boolean('backfill')->default(false);
                $table->integer('minsizetoformrelease')->nullable();
                $table->integer('minfilestoformrelease')->nullable();
            });
        }
    }

    private function seedSettings(): void
    {
        DB::table('settings')->upsert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
            ['name' => 'home_link', 'value' => '/'],
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ], ['name'], ['value']);
    }

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setValue(null, null);
    }
}
