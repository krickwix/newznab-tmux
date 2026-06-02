<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\Distributed\DistributedJobCatalog;
use Tests\TestCase;

class DistributedJobCatalogTest extends TestCase
{
    public function test_it_builds_core_job_commands_from_tmux_settings(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar([
            'binaries_run' => 1,
            'backfill' => 1,
            'releases_run' => 1,
        ]);

        $this->assertSame('multiprocessing:safe', $catalog->resolve('binaries', $runVar)['commands'][0]['command']);
        $this->assertSame(['type' => 'binaries'], $catalog->resolve('binaries', $runVar)['commands'][0]['arguments']);

        $this->assertSame('multiprocessing:safe', $catalog->resolve('backfill', $runVar)['commands'][0]['command']);
        $this->assertSame(['type' => 'backfill'], $catalog->resolve('backfill', $runVar)['commands'][0]['arguments']);

        $this->assertSame('multiprocessing:releases', $catalog->resolve('releases', $runVar)['commands'][0]['command']);
    }

    public function test_it_filters_disabled_core_jobs(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar([
            'binaries_run' => 0,
            'backfill' => 0,
            'releases_run' => 0,
        ]);

        foreach (['binaries', 'backfill', 'releases'] as $job) {
            $plan = $catalog->resolve($job, $runVar);

            $this->assertFalse($plan['enabled'], $job);
            $this->assertSame([], $plan['commands'], $job);
        }
    }

    public function test_it_matches_tmux_sequential_mode_one(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar(
            [
                'binaries_run' => 1,
                'backfill' => 4,
                'releases_run' => 1,
                'post' => 3,
            ],
            [
                'work' => 1,
                'processnfo' => 1,
            ],
            ['sequential' => 1],
        );

        $this->assertFalse($catalog->resolve('binaries', $runVar)['enabled']);
        $this->assertFalse($catalog->resolve('backfill', $runVar)['enabled']);
        $this->assertFalse($catalog->resolve('per-group', $runVar)['enabled']);
        $this->assertTrue($catalog->resolve('releases', $runVar)['enabled']);
        $this->assertTrue($catalog->resolve('post-additional', $runVar)['enabled']);
    }

    public function test_it_matches_tmux_sequential_mode_two(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar(
            [
                'binaries_run' => 1,
                'backfill' => 4,
                'releases_run' => 1,
                'post' => 3,
            ],
            [
                'work' => 1,
                'processnfo' => 1,
            ],
            ['sequential' => 2],
        );

        foreach (['binaries', 'backfill', 'releases', 'fixnames', 'post-additional'] as $job) {
            $this->assertFalse($catalog->resolve($job, $runVar)['enabled'], $job);
        }

        $this->assertTrue($catalog->resolve('per-group', $runVar)['enabled']);
    }

    public function test_it_filters_postprocess_jobs_by_enabled_flags_and_work_counts(): void
    {
        $catalog = new DistributedJobCatalog;
        $runVar = $this->runVar(
            [
                'post' => 3,
                'post_non' => 1,
                'post_amazon' => 1,
                'processtvrage' => 1,
                'processanime' => 1,
                'processmovies' => 1,
            ],
            [
                'work' => 5,
                'processnfo' => 4,
                'processtv' => 3,
                'processanime' => 2,
                'processmovies' => 1,
                'processmusic' => 1,
            ],
        );

        $this->assertSame(['add', 'nfo'], $this->argumentValues($catalog->resolve('post-additional', $runVar), 'type'));
        $this->assertSame(['tv', 'ani'], $this->argumentValues($catalog->resolve('post-tv', $runVar), 'type'));
        $this->assertSame(['mov'], $this->argumentValues($catalog->resolve('post-movies', $runVar), 'type'));
        $this->assertSame(['ama'], $this->argumentValues($catalog->resolve('post-amazon', $runVar), 'type'));
    }

    public function test_fixnames_includes_full_history_nfo_and_file_passes(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('fixnames', $this->runVar(
            ['fix_names' => 1],
            ['processrenames' => 1],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame(
            ['3', '4', '5', '6', '6', '21', '21', '8', '7', '9', '11', '13', '15', '17', '19'],
            $this->argumentValues($plan, 'method'),
        );
        $this->assertSame('movies', $plan['commands'][4]['arguments']['--category']);
        $this->assertSame(500, $plan['commands'][4]['arguments']['--limit']);
        $this->assertSame('other', $plan['commands'][5]['arguments']['--category']);
        $this->assertSame(500, $plan['commands'][5]['arguments']['--limit']);
        $this->assertSame('movies', $plan['commands'][6]['arguments']['--category']);
        $this->assertSame(500, $plan['commands'][6]['arguments']['--limit']);
        $this->assertSame('other', $plan['commands'][7]['arguments']['--category']);
        $this->assertSame(50, $plan['commands'][7]['arguments']['--limit']);
    }

    public function test_it_uses_sleep_metadata_without_shell_sleep_commands(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('releases', $this->runVar([
            'releases_run' => 1,
            'rel_timer' => 123,
        ]));

        $this->assertSame(123, $plan['sleep']);

        foreach ($plan['commands'] as $command) {
            $this->assertStringNotContainsString('sleep', $command['command']);
            $this->assertStringNotContainsString('tmux', $command['command']);
            $this->assertStringNotContainsString('tee', $command['command']);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @return array<string, mixed>
     */
    private function runVar(array $settings = [], array $counts = [], array $constants = []): array
    {
        return [
            'constants' => array_merge([
                'run_ircscraper' => 0,
                'sequential' => 0,
            ], $constants),
            'settings' => array_merge([
                'bins_timer' => 60,
                'back_timer' => 600,
                'rel_timer' => 60,
                'fix_timer' => 300,
                'crap_timer' => 300,
                'post_timer' => 300,
                'post_timer_non' => 300,
                'post_timer_amazon' => 300,
            ], $settings),
            'counts' => [
                'now' => array_merge([
                    'collections_table' => 0,
                    'processrenames' => 0,
                    'work' => 0,
                    'processnfo' => 0,
                    'processtv' => 0,
                    'processanime' => 0,
                    'processmovies' => 0,
                    'processmusic' => 0,
                    'processbooks' => 0,
                    'processconsole' => 0,
                    'processgames' => 0,
                ], $counts),
            ],
            'killswitch' => [
                'pp' => false,
                'coll' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function argumentValues(array $plan, string $key): array
    {
        return array_map(
            static fn (array $command): string => (string) $command['arguments'][$key],
            $plan['commands'],
        );
    }
}
