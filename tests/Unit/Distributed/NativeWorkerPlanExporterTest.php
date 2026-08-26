<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\DistributedJobCatalog;
use App\Services\Distributed\NativeWorkerPlanExporter;
use Tests\TestCase;

class NativeWorkerPlanExporterTest extends TestCase
{
    public function test_it_exports_metadata_refresh_plan_for_native_shadow_worker(): void
    {
        config([
            'cache.prefix' => 'nntmux-cache-',
            'database.redis.options.prefix' => 'nntmux_database_',
        ]);

        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('metadata-refresh', [
            'constants' => [
                'run_ircscraper' => 0,
                'sequential' => 0,
            ],
            'settings' => [
                'metadata_refresh' => 1,
                'metadata_refresh_limit' => 25,
                'metadata_refresh_sleep_ms' => 250,
                'metadata_refresh_timer' => 900,
            ],
            'counts' => [
                'now' => [
                    'other_hashed' => 150,
                ],
            ],
            'killswitch' => [
                'pp' => false,
                'coll' => false,
            ],
        ]);

        $export = (new NativeWorkerPlanExporter)->export($plan, 42);

        $this->assertSame(1, $export['version']);
        $this->assertSame('shadow', $export['mode']);
        $this->assertIsString($export['generated_at']);
        $this->assertSame([
            'name' => 'metadata-refresh',
            'description' => 'Refresh external release-name evidence and run strong fix-name passes',
            'enabled' => true,
            'disabled_reason' => null,
            'sleep' => 900,
        ], $export['job']);
        $this->assertSame([
            'name' => 'nntmux:distributed-worker:metadata-refresh',
            'redis_key' => 'nntmux_database_nntmux-cache-nntmux:distributed-worker:metadata-refresh',
            'seconds' => 42,
        ], $export['lock']);
        $this->assertSame([
            [
                'command' => 'predb:refresh-external-metadata',
                'arguments' => [
                    '--source' => ['all'],
                    '--limit' => 25,
                    '--sleep-ms' => 250,
                ],
            ],
            [
                'command' => 'releases:fix-names',
                'arguments' => [
                    'method' => '20',
                    '--update' => true,
                    '--category' => 'hashed',
                    '--set-status' => true,
                    '--limit' => 500,
                    '--show' => true,
                ],
            ],
            [
                'command' => 'releases:fix-names',
                'arguments' => [
                    'method' => '16',
                    '--update' => true,
                    '--category' => 'hashed',
                    '--set-status' => true,
                    '--limit' => 500,
                    '--show' => true,
                ],
            ],
        ], $export['commands']);

        $encoded = json_encode($export, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('DB_PASSWORD', $encoded);
        $this->assertStringNotContainsString('NNTP_PASSWORD', $encoded);
        $this->assertStringNotContainsString('settings', $encoded);
    }

    public function test_it_exports_every_catalog_lane_with_native_lock_metadata(): void
    {
        config([
            'cache.prefix' => 'nntmux-cache-',
            'database.redis.options.prefix' => 'nntmux_database_',
        ]);

        $catalog = new DistributedJobCatalog;
        $exporter = new NativeWorkerPlanExporter;

        foreach (array_keys($catalog->jobs()) as $job) {
            $plan = $catalog->resolve($job, $this->runVar());
            $export = $exporter->export($plan, 42);

            $this->assertSame($job, $export['job']['name'], $job);
            $this->assertSame('nntmux:distributed-worker:'.$job, $export['lock']['name'], $job);
            $this->assertSame('nntmux_database_nntmux-cache-nntmux:distributed-worker:'.$job, $export['lock']['redis_key'], $job);
            $this->assertSame(42, $export['lock']['seconds'], $job);
        }
    }

    public function test_fixture_run_var_exports_native_supported_removecrap_types(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('removecrap', $this->runVar());

        $this->assertTrue($plan['enabled']);
        $this->assertSame(
            ['gibberish', 'executable', 'hashed', 'short', 'installbin', 'passwordurl', 'nzb', 'scr', 'passworded', 'sample', 'size', 'codec', 'blfiles', 'blacklist', 'par2only'],
            array_map(
                static fn (array $command): string => (string) $command['arguments']['--type'],
                $plan['commands'],
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runVar(): array
    {
        return [
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
    }
}
