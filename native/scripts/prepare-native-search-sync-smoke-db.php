<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::purge('mysql');
DB::reconnect('mysql');

if (! Schema::hasTable('settings')) {
    Schema::create('settings', function (Blueprint $table): void {
        $table->string('name')->primary();
        $table->text('value')->nullable();
    });
}

if (! Schema::hasTable('usenet_groups')) {
    Schema::create('usenet_groups', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('name');
    });
}

foreach ([
    'categorizeforeign' => '0',
    'catwebdl' => '0',
    'innerfileblacklist' => '',
] as $name => $value) {
    DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
}

DB::table('usenet_groups')->updateOrInsert(
    ['id' => 1],
    ['name' => 'a.b.multimedia.movies'],
);

if (! Schema::hasTable('movieinfo')) {
    Schema::create('movieinfo', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedInteger('tmdbid')->default(0);
        $table->unsignedInteger('traktid')->default(0);
    });
}

if (! Schema::hasTable('videos')) {
    Schema::create('videos', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedInteger('tvdb')->default(0);
        $table->unsignedInteger('tvmaze')->default(0);
        $table->unsignedInteger('tvrage')->default(0);
    });
}

$releaseColumns = [
    'totalpart' => static fn (Blueprint $table): mixed => $table->integer('totalpart')->default(0),
    'grabs' => static fn (Blueprint $table): mixed => $table->integer('grabs')->default(0),
    'passwordstatus' => static fn (Blueprint $table): mixed => $table->integer('passwordstatus')->default(0),
    'nzbstatus' => static fn (Blueprint $table): mixed => $table->integer('nzbstatus')->default(0),
    'haspreview' => static fn (Blueprint $table): mixed => $table->integer('haspreview')->default(0),
    'movieinfo_id' => static fn (Blueprint $table): mixed => $table->unsignedBigInteger('movieinfo_id')->default(0),
];

foreach ($releaseColumns as $column => $definition) {
    if (! Schema::hasColumn('releases', $column)) {
        Schema::table('releases', function (Blueprint $table) use ($definition): void {
            $definition($table);
        });
    }
}

fwrite(STDOUT, "prepared native search sync smoke schema\n");
