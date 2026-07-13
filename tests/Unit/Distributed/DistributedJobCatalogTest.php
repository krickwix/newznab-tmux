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

    public function test_backfill_is_disabled_when_no_group_level_safe_backfill_work_exists(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('backfill', $this->runVar(
            [
                'backfill' => 4,
                'backfill_days' => 1,
            ],
            [
                'backfill_groups_days' => 0,
            ],
        ));

        $this->assertFalse($plan['enabled']);
        $this->assertSame('no backfill groups to process', $plan['disabled_reason']);
        $this->assertSame([], $plan['commands']);
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

        foreach (['binaries', 'backfill', 'releases', 'fixnames', 'hashed-fixnames', 'post-additional'] as $job) {
            $this->assertFalse($catalog->resolve($job, $runVar)['enabled'], $job);
        }

        $this->assertTrue($catalog->resolve('per-group', $runVar)['enabled']);
    }

    public function test_nzb_backlog_lane_stays_bounded_and_independent_of_sequential_mode(): void
    {
        config([
            'nntmux.distributed_nzb_limit' => 1,
            'nntmux.distributed_nzb_sleep' => 60,
        ]);

        $plan = (new DistributedJobCatalog)->resolve(
            'nzb-backlog',
            $this->runVar(constants: ['sequential' => 2]),
        );

        $this->assertTrue($plan['enabled']);
        $this->assertSame(60, $plan['sleep']);
        $this->assertSame('nntmux:nzb-create-backlog', $plan['commands'][0]['command']);
        $this->assertSame([
            '--limit' => 1,
            '--order' => 'desc',
        ], $plan['commands'][0]['arguments']);
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

    public function test_hashed_fixnames_runs_full_history_hashed_passes_when_hashed_count_exceeds_threshold(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('hashed-fixnames', $this->runVar(
            ['fix_names' => 1],
            ['other_hashed' => 101],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame(
            ['4', '6', '21', '18', '10', '14', '16', '20', '12', '8'],
            $this->argumentValues($plan, 'method'),
        );

        foreach ($plan['commands'] as $command) {
            $this->assertSame('releases:fix-names', $command['command']);
            $this->assertSame('hashed', $command['arguments']['--category']);
            $this->assertTrue($command['arguments']['--update']);
            $this->assertTrue($command['arguments']['--set-status']);
        }
    }

    public function test_hashed_fixnames_does_not_run_when_hashed_count_is_not_greater_than_threshold(): void
    {
        $catalog = new DistributedJobCatalog;

        foreach ([0, 100] as $count) {
            $plan = $catalog->resolve('hashed-fixnames', $this->runVar(
                ['fix_names' => 1],
                ['other_hashed' => $count],
            ));

            $this->assertFalse($plan['enabled'], 'count='.$count);
            $this->assertSame([], $plan['commands'], 'count='.$count);
        }
    }

    public function test_metadata_refresh_runs_external_source_refresh_before_strong_fixname_passes(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('metadata-refresh', $this->runVar(
            [
                'metadata_refresh' => 1,
                'metadata_refresh_limit' => 25,
                'metadata_refresh_sleep_ms' => 250,
                'metadata_refresh_timer' => 900,
            ],
            ['other_hashed' => 150],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame(900, $plan['sleep']);
        $this->assertSame('predb:refresh-external-metadata', $plan['commands'][0]['command']);
        $this->assertSame(25, $plan['commands'][0]['arguments']['--limit']);
        $this->assertSame(250, $plan['commands'][0]['arguments']['--sleep-ms']);
        $this->assertSame(['all'], $plan['commands'][0]['arguments']['--source']);
        $this->assertSame(['20', '16'], array_map(
            static fn (array $command): string => (string) $command['arguments']['method'],
            array_slice($plan['commands'], 1, 2),
        ));
        $this->assertSame('hashed', $plan['commands'][1]['arguments']['--category']);
        $this->assertSame('hashed', $plan['commands'][2]['arguments']['--category']);
    }

    public function test_post_additional_runs_external_metadata_refresh_after_postprocess_when_hashed_backlog_exists(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('post-additional', $this->runVar(
            [
                'post' => 3,
                'metadata_refresh' => 1,
                'metadata_refresh_postprocess' => 1,
                'metadata_refresh_postprocess_limit' => 7,
                'metadata_refresh_sleep_ms' => 125,
            ],
            [
                'work' => 5,
                'processnfo' => 4,
                'other_hashed' => 150,
            ],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame([
            'multiprocessing:postprocess',
            'multiprocessing:postprocess',
            'predb:refresh-external-metadata',
            'releases:fix-names',
            'releases:fix-names',
        ], array_column($plan['commands'], 'command'));
        $this->assertSame('add', $plan['commands'][0]['arguments']['type']);
        $this->assertSame('nfo', $plan['commands'][1]['arguments']['type']);
        $this->assertSame(['all'], $plan['commands'][2]['arguments']['--source']);
        $this->assertSame(7, $plan['commands'][2]['arguments']['--limit']);
        $this->assertSame(125, $plan['commands'][2]['arguments']['--sleep-ms']);
        $this->assertSame(['20', '16'], array_map(
            static fn (array $command): string => (string) $command['arguments']['method'],
            array_slice($plan['commands'], 3, 2),
        ));
    }

    public function test_post_additional_skips_external_metadata_refresh_when_postprocess_refresh_is_disabled(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('post-additional', $this->runVar(
            [
                'post' => 3,
                'metadata_refresh_postprocess' => 0,
            ],
            [
                'work' => 5,
                'processnfo' => 4,
                'other_hashed' => 150,
            ],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame([
            'multiprocessing:postprocess',
            'multiprocessing:postprocess',
        ], array_column($plan['commands'], 'command'));
    }

    public function test_post_additional_skips_external_metadata_refresh_when_hashed_backlog_is_below_threshold(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('post-additional', $this->runVar(
            [
                'post' => 3,
                'metadata_refresh_postprocess' => 1,
            ],
            [
                'work' => 5,
                'processnfo' => 4,
                'other_hashed' => 100,
            ],
        ));

        $this->assertTrue($plan['enabled']);
        $this->assertSame([
            'multiprocessing:postprocess',
            'multiprocessing:postprocess',
        ], array_column($plan['commands'], 'command'));
    }

    public function test_metadata_refresh_is_disabled_when_not_enabled(): void
    {
        $catalog = new DistributedJobCatalog;
        $plan = $catalog->resolve('metadata-refresh', $this->runVar(
            ['metadata_refresh' => 0],
            ['other_hashed' => 150],
        ));

        $this->assertFalse($plan['enabled']);
        $this->assertSame([], $plan['commands']);
        $this->assertSame('disabled in settings', $plan['disabled_reason']);
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

    public function test_orchestrator_active_profile_overrides_worker_timers_and_nzb_batch(): void
    {
        config([
            'nntmux.distributed_nzb_limit' => 20,
            'nntmux.distributed_nzb_sleep' => 55,
        ]);
        $settings = [
            'binaries_run' => 1,
            'releases_run' => 1,
            'orchestrator_mode' => 'active',
            'orchestrator_lease_until' => time() + 300,
            'orchestrator_bins_timer' => 40,
            'orchestrator_rel_timer' => 30,
            'orchestrator_nzb_timer' => 90,
            'orchestrator_nzb_limit' => 10,
        ];
        $catalog = new DistributedJobCatalog;

        self::assertSame(40, $catalog->resolve('binaries', $this->runVar($settings))['sleep']);
        self::assertSame(30, $catalog->resolve('releases', $this->runVar($settings))['sleep']);
        $nzb = $catalog->resolve('nzb-backlog', $this->runVar($settings));
        self::assertSame(90, $nzb['sleep']);
        self::assertSame(10, $nzb['commands'][0]['arguments']['--limit']);
    }

    public function test_managed_backfill_requires_fresh_active_unpaused_permit(): void
    {
        $catalog = new DistributedJobCatalog;
        $base = [
            'backfill' => 4,
            'orchestrator_mode' => 'active',
            'orchestrator_lease_until' => time() + 300,
            'orchestrator_back_timer' => 20,
            'orchestrator_bf_paused' => 0,
        ];

        $denied = $catalog->resolve('backfill', $this->runVar($base, ['backfill_groups_days' => 1]));
        self::assertFalse($denied['enabled']);
        self::assertStringContainsString('permit', (string) $denied['disabled_reason']);
        self::assertSame(20, $denied['sleep']);

        $allowed = $catalog->resolve('backfill', $this->runVar(
            $base + ['orchestrator_bf_permit' => 9],
            ['backfill_groups_days' => 1],
        ));
        self::assertTrue($allowed['enabled']);
        self::assertSame(20, $allowed['sleep']);
    }

    public function test_unmanaged_backfill_preserves_its_static_sleep(): void
    {
        $plan = (new DistributedJobCatalog)->resolve('backfill', $this->runVar([
            'backfill' => 4,
            'back_timer' => 600,
        ], ['backfill_groups_days' => 1]));

        self::assertTrue($plan['enabled']);
        self::assertSame(600, $plan['sleep']);
    }

    public function test_stale_managed_profile_fails_closed(): void
    {
        config([
            'nntmux.distributed_nzb_limit' => 20,
            'nntmux.distributed_nzb_sleep' => 55,
        ]);
        $settings = [
            'binaries_run' => 1,
            'releases_run' => 1,
            'backfill' => 4,
            'orchestrator_mode' => 'active',
            'orchestrator_lease_until' => time() - 1,
            'orchestrator_bf_paused' => 0,
            'orchestrator_bf_permit' => 1,
        ];
        $catalog = new DistributedJobCatalog;

        self::assertSame(300, $catalog->resolve('binaries', $this->runVar($settings))['sleep']);
        self::assertSame(180, $catalog->resolve('releases', $this->runVar($settings))['sleep']);
        self::assertSame(180, $catalog->resolve('nzb-backlog', $this->runVar($settings))['sleep']);
        $backfill = $catalog->resolve('backfill', $this->runVar($settings, ['backfill_groups_days' => 1]));
        self::assertFalse($backfill['enabled']);
        self::assertSame(60, $backfill['sleep']);
    }

    public function test_shadow_mode_preserves_static_timers_but_denies_backfill(): void
    {
        config([
            'nntmux.distributed_nzb_limit' => 20,
            'nntmux.distributed_nzb_sleep' => 55,
        ]);
        $settings = [
            'binaries_run' => 1,
            'releases_run' => 1,
            'backfill' => 4,
            'bins_timer' => 5,
            'rel_timer' => 120,
            'orchestrator_mode' => 'shadow',
            'orchestrator_lease_until' => 0,
            'orchestrator_bf_paused' => 1,
        ];
        $catalog = new DistributedJobCatalog;

        self::assertSame(5, $catalog->resolve('binaries', $this->runVar($settings))['sleep']);
        self::assertSame(120, $catalog->resolve('releases', $this->runVar($settings))['sleep']);
        self::assertSame(55, $catalog->resolve('nzb-backlog', $this->runVar($settings))['sleep']);
        self::assertFalse($catalog->resolve('backfill', $this->runVar($settings, ['backfill_groups_days' => 1]))['enabled']);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $constants
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
                    'other_hashed' => 0,
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
