<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminTmuxController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

final class AdminTmuxControllerTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-admin-tmux-test.sqlite';

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
            ('title', 'NNTmux Test'),
            ('home_link', '/'),
            ('backfill_days', '1'),
            ('backfill_qty', '75000'),
            ('fix_crap', '')");

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

    public function test_large_backfill_days_updates_enabled_or_backfill_group_targets(): void
    {
        DB::table('usenet_groups')->insert([
            ['name' => 'alt.binaries.active-only', 'active' => 1, 'backfill' => 0, 'backfill_target' => 1],
            ['name' => 'alt.binaries.backfill-only', 'active' => 0, 'backfill' => 1, 'backfill_target' => 2],
            ['name' => 'alt.binaries.both', 'active' => 1, 'backfill' => 1, 'backfill_target' => 3],
            ['name' => 'alt.binaries.disabled', 'active' => 0, 'backfill' => 0, 'backfill_target' => 4],
        ]);

        $request = Request::create('/admin/tmux-edit', 'POST', [
            'action' => 'submit',
            'backfill_days' => '7305',
            'backfill_qty' => '75000',
        ]);

        $response = app(AdminTmuxController::class)->edit($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('1', (string) DB::table('settings')->where('name', 'backfill_days')->value('value'));
        $this->assertSame('75000', (string) DB::table('settings')->where('name', 'backfill_qty')->value('value'));
        $this->assertSame(7305, (int) DB::table('usenet_groups')->where('name', 'alt.binaries.active-only')->value('backfill_target'));
        $this->assertSame(7305, (int) DB::table('usenet_groups')->where('name', 'alt.binaries.backfill-only')->value('backfill_target'));
        $this->assertSame(7305, (int) DB::table('usenet_groups')->where('name', 'alt.binaries.both')->value('backfill_target'));
        $this->assertSame(4, (int) DB::table('usenet_groups')->where('name', 'alt.binaries.disabled')->value('backfill_target'));
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

        if (! Schema::hasTable('usenet_groups')) {
            Schema::create('usenet_groups', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name')->unique();
                $table->boolean('active')->default(false);
                $table->boolean('backfill')->default(false);
                $table->integer('backfill_target')->default(1);
            });
        }
    }

    private function seedSettings(): void
    {
        DB::table('settings')->upsert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
            ['name' => 'home_link', 'value' => '/'],
            ['name' => 'backfill_days', 'value' => '1'],
            ['name' => 'backfill_qty', 'value' => '75000'],
            ['name' => 'fix_crap', 'value' => ''],
        ], ['name'], ['value']);
    }
}
