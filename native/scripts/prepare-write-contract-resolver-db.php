<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';

$databasePath = $argv[1] ?? getenv('DB_DATABASE') ?: '';
if ($databasePath === '' || $databasePath === ':memory:') {
    fwrite(STDERR, "A file-backed SQLite database path is required.\n");
    exit(1);
}

$directory = dirname($databasePath);
if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Unable to create SQLite directory [{$directory}].\n");
    exit(1);
}

if (! is_file($databasePath)) {
    touch($databasePath);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $databasePath,
]);

DB::purge('sqlite');
DB::reconnect('sqlite');

Schema::dropIfExists('settings');
Schema::dropIfExists('usenet_groups');

Schema::create('settings', function (Blueprint $table): void {
    $table->string('name')->primary();
    $table->text('value')->nullable();
});

Schema::create('usenet_groups', function (Blueprint $table): void {
    $table->unsignedBigInteger('id')->primary();
    $table->string('name');
});

DB::table('settings')->insert([
    ['name' => 'categorizeforeign', 'value' => '0'],
    ['name' => 'catwebdl', 'value' => '0'],
    ['name' => 'innerfileblacklist', 'value' => ''],
]);

DB::table('usenet_groups')->insert([
    ['id' => 1, 'name' => 'alt.binaries.movies'],
]);

fwrite(STDOUT, "prepared resolver sqlite={$databasePath}\n");
