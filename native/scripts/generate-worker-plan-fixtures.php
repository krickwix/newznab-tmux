<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\NativeWorkerPlanExporter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'cache.prefix' => 'nntmux-cache-',
    'database.redis.options.prefix' => 'nntmux_database_',
]);

$outputDirectory = $argv[1] ?? 'tests/Fixtures/native-worker/catalog';
$generatedAt = $argv[2] ?? '2026-06-15T12:00:00.000000Z';

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
    fwrite(STDERR, sprintf("Unable to create output directory [%s]\n", $outputDirectory));
    exit(1);
}

$catalog = new DistributedJobCatalog;
$exporter = new NativeWorkerPlanExporter;
$jobs = array_keys($catalog->jobs());

foreach ($jobs as $job) {
    $existingFixture = sprintf('%s/%s.json', $outputDirectory, $job);
    if (is_file($existingFixture)) {
        unlink($existingFixture);
    }
}

Carbon::setTestNow(Carbon::parse($generatedAt));

$runVar = [
    'constants' => [
        'run_ircscraper' => 1,
        'sequential' => 0,
    ],
    'settings' => [
        'binaries_run' => 1,
        'backfill' => 1,
        'backfill_days' => 1,
        'releases_run' => 1,
        'fix_names' => 1,
        'fix_crap_opt' => 'Custom',
        'fix_crap' => 'gibberish, executable, hashed, short, installbin, passwordurl, nzb, scr, passworded, sample, size, codec, blfiles, blacklist, par2only',
        'post' => 3,
        'post_non' => 1,
        'post_amazon' => 1,
        'processtvrage' => 1,
        'processanime' => 1,
        'processmovies' => 1,
        'metadata_refresh' => 1,
        'metadata_refresh_postprocess' => 1,
        'metadata_refresh_limit' => 25,
        'metadata_refresh_postprocess_limit' => 7,
        'metadata_refresh_sleep_ms' => 250,
        'bins_timer' => 60,
        'back_timer' => 600,
        'rel_timer' => 60,
        'fix_timer' => 300,
        'crap_timer' => 300,
        'post_timer' => 300,
        'post_timer_non' => 300,
        'post_timer_amazon' => 300,
        'metadata_refresh_timer' => 900,
        'monitor' => 10,
        'seq_timer' => 300,
    ],
    'counts' => [
        'now' => [
            'collections_table' => 1000,
            'other_hashed' => 150,
            'processrenames' => 5,
            'backfill_groups_days' => 3,
            'work' => 5,
            'processnfo' => 4,
            'processtv' => 3,
            'processanime' => 2,
            'processmovies' => 1,
            'processmusic' => 1,
            'processbooks' => 1,
            'processconsole' => 1,
            'processgames' => 1,
        ],
    ],
    'killswitch' => [
        'pp' => false,
        'coll' => false,
    ],
];

foreach ($jobs as $job) {
    $jobRunVar = $runVar;
    if ($job === 'per-group') {
        $jobRunVar['constants']['sequential'] = 2;
    }

    $plan = $catalog->resolve($job, $jobRunVar);
    $fixturePath = sprintf('%s/%s.json', $outputDirectory, $job);

    file_put_contents(
        $fixturePath,
        json_encode(
            $exporter->export($plan, 42),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL,
    );

    fwrite(STDOUT, $fixturePath.PHP_EOL);
}
