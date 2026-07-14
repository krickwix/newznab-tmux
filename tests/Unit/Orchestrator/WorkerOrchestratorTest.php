<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Orchestrator\ControlDecision;
use App\Services\Orchestrator\ControlProfile;
use App\Services\Orchestrator\ControlState;
use App\Services\Orchestrator\PipelineSnapshot;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\WorkerControlPolicy;
use App\Services\Orchestrator\WorkerControlStateStore;
use App\Services\Orchestrator\WorkerOrchestrator;
use App\Services\Orchestrator\WorkerProfileApplier;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class WorkerOrchestratorTest extends TestCase
{
    private string $databasePath;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-worker-orchestrator-test.sqlite';
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
            ('innerfileblacklist', '')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
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

    public function test_context_progress_is_attributed_only_to_an_inactive_backfill_group(): void
    {
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            Mockery::mock(WorkerControlStateStore::class),
            Mockery::mock(WorkerProfileApplier::class),
        );
        $method = new ReflectionMethod($orchestrator, 'hasBackfillOnlyContextProgress');
        $observation = [
            'backfill_group_active' => 0,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ];
        $progress = [
            'group_active' => 0,
            'partial_collections' => 3,
            'complete_binaries' => 24,
        ];

        self::assertTrue($method->invoke($orchestrator, $observation, $progress, true));
        self::assertFalse($method->invoke($orchestrator, $observation, $progress, false));
        self::assertFalse($method->invoke($orchestrator, [...$observation, 'backfill_group_active' => 1], $progress, true));
        self::assertFalse($method->invoke($orchestrator, $observation, [...$progress, 'group_active' => 1], true));
        self::assertFalse($method->invoke($orchestrator, $observation, [
            ...$progress,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], true));
        self::assertFalse($method->invoke($orchestrator, [], $progress, true));
    }

    public function test_raw_context_accepts_binary_growth_inside_an_existing_collection(): void
    {
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            Mockery::mock(WorkerControlStateStore::class),
            Mockery::mock(WorkerProfileApplier::class),
        );
        $method = new ReflectionMethod($orchestrator, 'hasRawBackfillOnlyContextProgress');
        $observation = [
            'backfill_group_active' => 0,
            'raw_collections' => 10,
            'raw_binaries' => 20,
        ];
        $outcome = [
            'group_active' => 0,
            'raw_collections' => 11,
            'raw_binaries' => 21,
        ];
        $quality = ['productive' => 0, 'hold' => false, 'failure' => null];
        $invoke = static function (
            ?array $baseline = null,
            ?array $current = null,
            bool $claimed = true,
            bool $completed = true,
            int $delta = 10_000,
            int $quantity = 10_000,
            int $releases = 0,
            int $nzbs = 0,
            ?array $cohortQuality = null,
        ) use ($method, $orchestrator, $observation, $outcome, $quality): bool {
            return $method->invoke(
                $orchestrator,
                $baseline ?? $observation,
                $current ?? $outcome,
                $claimed,
                $completed,
                $delta,
                $quantity,
                $releases,
                $nzbs,
                $cohortQuality ?? $quality,
            );
        };

        self::assertTrue($invoke());
        self::assertFalse($invoke(current: [...$outcome, 'group_active' => 1]));
        self::assertFalse($invoke(baseline: [...$observation, 'backfill_group_active' => 1]));
        self::assertTrue($invoke(current: [...$outcome, 'raw_collections' => 10]));
        self::assertFalse($invoke(current: [...$outcome, 'raw_collections' => 9]));
        self::assertTrue($invoke(current: [...$outcome, 'raw_binaries' => 20]));
        self::assertFalse($invoke(current: [...$outcome, 'raw_collections' => 10, 'raw_binaries' => 20]));
        self::assertFalse($invoke(baseline: array_diff_key($observation, ['raw_collections' => true])));
        self::assertFalse($invoke(baseline: array_diff_key($observation, ['raw_binaries' => true])));
        self::assertFalse($invoke(current: array_diff_key($outcome, ['raw_collections' => true])));
        self::assertFalse($invoke(current: array_diff_key($outcome, ['raw_binaries' => true])));
        self::assertFalse($invoke(claimed: false));
        self::assertFalse($invoke(completed: false));
        self::assertFalse($invoke(delta: 9_999));
        self::assertFalse($invoke(quantity: 20_000, delta: 20_000));
        self::assertFalse($invoke(releases: 1));
        self::assertFalse($invoke(nzbs: 1));
        self::assertFalse($invoke(cohortQuality: ['productive' => 1, 'hold' => false, 'failure' => null]));
        self::assertFalse($invoke(cohortQuality: ['productive' => 0, 'hold' => false, 'failure' => 'wrong']));
    }

    public function test_incomplete_release_lineage_requires_exact_completed_input(): void
    {
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            Mockery::mock(WorkerControlStateStore::class),
            Mockery::mock(WorkerProfileApplier::class),
        );
        $method = new ReflectionMethod($orchestrator, 'shouldRememberIncompleteReleaseCohort');
        $observation = ['backfill_quantity' => 10_000];

        self::assertTrue($method->invoke($orchestrator, $observation, true, true, 10_000, 1, 0));
        self::assertFalse($method->invoke($orchestrator, $observation, true, true, 9_999, 1, 0));
        self::assertFalse($method->invoke($orchestrator, $observation, false, true, 10_000, 1, 0));
        self::assertFalse($method->invoke($orchestrator, $observation, true, true, 10_000, 0, 0));
        self::assertFalse($method->invoke($orchestrator, $observation, true, true, 10_000, 1, 1));
        self::assertFalse($method->invoke($orchestrator, ['backfill_quantity' => 0], true, true, 0, 1, 0));
    }

    #[DataProvider('cohortCategoryQualityCases')]
    public function test_exact_cohort_category_quality_allows_only_fully_categorized_movie_or_tv_nzbs(
        array $counts,
        int $now,
        int $productive,
        bool $hold,
        ?string $failure,
    ): void {
        config()->set('nntmux.orchestrator.backfill_incomplete_release_grace_seconds', 600);
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            Mockery::mock(WorkerControlStateStore::class),
            Mockery::mock(WorkerProfileApplier::class),
        );
        $method = new ReflectionMethod($orchestrator, 'classifyCohortNzbQuality');

        self::assertSame([
            'productive' => $productive,
            'hold' => $hold,
            'failure' => $failure,
        ], $method->invoke($orchestrator, $counts, ['completed_observed_at' => 1_000], $now));
    }

    /** @return array<string, array{array{target: int, non_target: int, uncategorized: int}, int, int, bool, string|null}> */
    public static function cohortCategoryQualityCases(): array
    {
        return [
            'carried wrong Console root overrides current target yield' => [
                ['target' => 3, 'non_target' => 1, 'uncategorized' => 0],
                1_001,
                0,
                false,
                'backfill_permit_wrong_category',
            ],
            'all movie and TV roots are productive' => [
                ['target' => 3, 'non_target' => 0, 'uncategorized' => 0],
                1_001,
                3,
                false,
                null,
            ],
            'Other root remains held during grace' => [
                ['target' => 1, 'non_target' => 0, 'uncategorized' => 1],
                1_599,
                0,
                true,
                null,
            ],
            'Other root locks when unresolved after grace' => [
                ['target' => 1, 'non_target' => 0, 'uncategorized' => 1],
                1_600,
                0,
                false,
                'backfill_permit_uncategorized_after_grace',
            ],
        ];
    }

    public function test_mixed_cohort_is_productive_when_target_share_meets_the_configured_floor(): void
    {
        config([
            'nntmux.orchestrator.backfill_min_target_byte_share' => 0.9,
            'nntmux.orchestrator.backfill_max_non_target_releases' => 1,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => 536_870_912,
            'nntmux.orchestrator.backfill_incomplete_release_grace_seconds' => 600,
        ]);
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            Mockery::mock(WorkerControlStateStore::class),
            Mockery::mock(WorkerProfileApplier::class),
        );
        $method = new ReflectionMethod($orchestrator, 'classifyCohortNzbQuality');

        self::assertSame([
            'productive' => 1,
            'hold' => false,
            'failure' => null,
        ], $method->invoke(
            $orchestrator,
            ['target' => 1, 'non_target' => 1, 'uncategorized' => 0],
            ['completed_observed_at' => 1_000],
            1_001,
            ['target' => 3_867_698_952, 'non_target' => 324_000_760, 'uncategorized' => 0],
        ));
    }

    #[DataProvider('mixedCohortByteQualityCases')]
    public function test_mixed_cohort_byte_quality_enforces_share_count_size_and_uncategorized_guards(
        array $counts,
        array $bytes,
        float $minimumShare,
        int $maximumReleases,
        int $maximumBytes,
        int $now,
        array $expected,
    ): void {
        config([
            'nntmux.orchestrator.backfill_min_target_byte_share' => $minimumShare,
            'nntmux.orchestrator.backfill_max_non_target_releases' => $maximumReleases,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => $maximumBytes,
            'nntmux.orchestrator.backfill_incomplete_release_grace_seconds' => 600,
        ]);
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            Mockery::mock(WorkerControlStateStore::class),
            Mockery::mock(WorkerProfileApplier::class),
        );
        $method = new ReflectionMethod($orchestrator, 'classifyCohortNzbQuality');

        self::assertSame($expected, $method->invoke(
            $orchestrator,
            $counts,
            ['completed_observed_at' => 1_000],
            $now,
            $bytes,
        ));
    }

    /** @return array<string, array{array<string, int>, array<string, int>, float, int, int, int, array<string, int|bool|string|null>}> */
    public static function mixedCohortByteQualityCases(): array
    {
        $productive = ['productive' => 1, 'hold' => false, 'failure' => null];
        $wrong = ['productive' => 0, 'hold' => false, 'failure' => 'backfill_permit_wrong_category'];

        return [
            'byte share equality passes' => [
                ['target' => 1, 'non_target' => 1, 'uncategorized' => 0],
                ['target' => 900, 'non_target' => 100, 'uncategorized' => 0],
                0.9, 1, 100, 1_001, $productive,
            ],
            'byte share below floor locks' => [
                ['target' => 1, 'non_target' => 1, 'uncategorized' => 0],
                ['target' => 899, 'non_target' => 101, 'uncategorized' => 0],
                0.9, 1, 512, 1_001, $wrong,
            ],
            'release count above cap locks' => [
                ['target' => 1, 'non_target' => 2, 'uncategorized' => 0],
                ['target' => 9_800, 'non_target' => 200, 'uncategorized' => 0],
                0.9, 1, 512, 1_001, $wrong,
            ],
            'non target bytes above cap lock' => [
                ['target' => 1, 'non_target' => 1, 'uncategorized' => 0],
                ['target' => 9_500, 'non_target' => 500, 'uncategorized' => 0],
                0.9, 1, 499, 1_001, $wrong,
            ],
            'atomic bucket mismatch with uncategorized bytes locks' => [
                ['target' => 1, 'non_target' => 1, 'uncategorized' => 0],
                ['target' => 900, 'non_target' => 0, 'uncategorized' => 100],
                0.9, 1, 512, 1_001, $wrong,
            ],
            'positive non target count with zero non target bytes locks' => [
                ['target' => 1, 'non_target' => 1, 'uncategorized' => 0],
                ['target' => 900, 'non_target' => 0, 'uncategorized' => 0],
                0.9, 1, 512, 1_001, $wrong,
            ],
            'wrong only locks even at zero floor' => [
                ['target' => 0, 'non_target' => 1, 'uncategorized' => 0],
                ['target' => 0, 'non_target' => 100, 'uncategorized' => 0],
                0.0, 10, 1_000, 1_001, $wrong,
            ],
            'uncategorized output holds before grace before evaluating mixed share' => [
                ['target' => 1, 'non_target' => 2, 'uncategorized' => 1],
                ['target' => 100, 'non_target' => 900, 'uncategorized' => 100],
                0.9, 1, 512, 1_599,
                ['productive' => 0, 'hold' => true, 'failure' => null],
            ],
            'uncategorized output locks with its own reason after grace' => [
                ['target' => 1, 'non_target' => 2, 'uncategorized' => 1],
                ['target' => 100, 'non_target' => 900, 'uncategorized' => 100],
                0.9, 1, 512, 1_600,
                ['productive' => 0, 'hold' => false, 'failure' => 'backfill_permit_uncategorized_after_grace'],
            ],
        ];
    }

    public function test_wrong_root_completed_nzb_immediately_quality_locks_source_and_prevents_regrant(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);

        $store = new WorkerControlStateStore;
        $store->storeState(new ControlState(profile: ControlProfile::Fill));
        $store->markBackfillContextRepeat('alt.console', time() - 30);
        $baseline = new PipelineSnapshot(1, 2, 3, 0, 0, backfillGroup: 'alt.console', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 60, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ], quantity: 10_000);

        $current = new PipelineSnapshot(
            1,
            2,
            3,
            0,
            0,
            lowPressure: true,
            eligibleBackfillSupply: true,
            eligibleNzbs: 1,
            backfillGroup: 'alt.console',
            backfillCursor: 10_000,
            backfillRemainingArticles: 20_000,
            backfillSafeQuantity: 10_000,
        );
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->times(3)->andReturn($current, $current, $current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->twice()->with('alt.console')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 1,
            'release_high_watermark' => 101,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->twice()->andReturn([
            'target' => 0,
            'non_target' => 1,
            'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->twice()->andReturn(1);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('qualityLockBackfillTarget')->once()->with(
            'alt.console',
            'backfill_permit_wrong_category',
        );
        $applier->shouldReceive('apply')->twice()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => ! $decision->backfillPermitted),
            Mockery::type('int'),
            false,
            'alt.console',
            false,
        )->andReturn(8, 9);
        $orchestrator = new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier);

        $shadow = $orchestrator->runOnce(true);

        self::assertContains('backfill_permit_wrong_category', $shadow['reasons']);
        self::assertNotNull($store->permitObservation());
        self::assertSame([], $store->backfillYieldHistory());
        self::assertSame([], $store->loadState()->ineffectiveBackfillPermitsByTarget);
        self::assertSame('alt.console', $store->backfillContextRepeat(time())['group'] ?? null);

        $locked = $orchestrator->runOnce(false);
        $stillLocked = $orchestrator->runOnce(false);

        self::assertContains('backfill_permit_wrong_category', $locked['reasons']);
        self::assertContains('backfill_target_locked', $locked['reasons']);
        self::assertFalse($locked['permit_granted']);
        self::assertContains('backfill_target_locked', $stillLocked['reasons']);
        self::assertFalse($stillLocked['permit_granted']);
        self::assertSame(WorkerControlPolicy::INEFFECTIVE_BACKFILL_LIMIT, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.console']);
        self::assertSame(0.0, $store->backfillYieldHistory()['alt.console']['ewma_nzbs_per_10k']);
        self::assertNull($store->backfillContextRepeat(time()));
    }

    public function test_zero_output_context_retry_grace_is_bounded_to_five_minutes_minimum(): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_BACKFILL_ZERO_OUTPUT_GRACE_SECONDS';
        $previous = getenv($key);
        putenv($key.'=299');
        $_ENV[$key] = '299';
        $_SERVER[$key] = '299';

        try {
            $configuration = require base_path('config/nntmux.php');

            self::assertSame(300, $configuration['orchestrator']['backfill_zero_output_grace_seconds']);
        } finally {
            if ($previous === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$previous);
                $_ENV[$key] = $previous;
                $_SERVER[$key] = $previous;
            }
        }
    }

    public function test_incomplete_release_context_grace_is_bounded_to_ten_minutes_minimum(): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_BACKFILL_INCOMPLETE_RELEASE_GRACE_SECONDS';
        $previous = getenv($key);
        putenv($key.'=599');
        $_ENV[$key] = '599';
        $_SERVER[$key] = '599';

        try {
            $configuration = require base_path('config/nntmux.php');

            self::assertSame(600, $configuration['orchestrator']['backfill_incomplete_release_grace_seconds']);
        } finally {
            if ($previous === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$previous);
                $_ENV[$key] = $previous;
                $_SERVER[$key] = $previous;
            }
        }
    }

    public function test_productive_settlement_grace_is_bounded_between_thirty_seconds_and_five_minutes(): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_BACKFILL_PRODUCTIVE_SETTLEMENT_GRACE_SECONDS';
        $previous = getenv($key);

        try {
            putenv($key.'=29');
            $_ENV[$key] = '29';
            $_SERVER[$key] = '29';
            $configuration = require base_path('config/nntmux.php');
            self::assertSame(30, $configuration['orchestrator']['backfill_productive_settlement_grace_seconds']);

            putenv($key.'=301');
            $_ENV[$key] = '301';
            $_SERVER[$key] = '301';
            $configuration = require base_path('config/nntmux.php');
            self::assertSame(300, $configuration['orchestrator']['backfill_productive_settlement_grace_seconds']);
        } finally {
            if ($previous === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$previous);
                $_ENV[$key] = $previous;
                $_SERVER[$key] = $previous;
            }
        }
    }

    public function test_mixed_cohort_target_byte_share_is_bounded_between_zero_and_one(): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_BACKFILL_MIN_TARGET_BYTE_SHARE';
        $previous = getenv($key);

        try {
            foreach (['-1.0' => 0.0, '2.0' => 1.0] as $configured => $expected) {
                putenv($key.'='.$configured);
                $_ENV[$key] = $configured;
                $_SERVER[$key] = $configured;

                $configuration = require base_path('config/nntmux.php');

                self::assertSame($expected, $configuration['orchestrator']['backfill_min_target_byte_share']);
            }
        } finally {
            if ($previous === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$previous);
                $_ENV[$key] = $previous;
                $_SERVER[$key] = $previous;
            }
        }
    }

    public function test_mixed_cohort_quality_limits_default_to_strict_and_clamp_invalid_caps(): void
    {
        $keys = [
            'NNTMUX_ORCHESTRATOR_BACKFILL_MIN_TARGET_BYTE_SHARE',
            'NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_RELEASES',
            'NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_BYTES',
        ];
        $previous = array_combine($keys, array_map(getenv(...), $keys));

        try {
            foreach ($keys as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
            $strict = require base_path('config/nntmux.php');
            self::assertSame(1.0, $strict['orchestrator']['backfill_min_target_byte_share']);
            self::assertSame(0, $strict['orchestrator']['backfill_max_non_target_releases']);
            self::assertSame(0, $strict['orchestrator']['backfill_max_non_target_bytes']);

            putenv('NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_RELEASES=99');
            putenv('NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_BYTES=-1');
            $_ENV['NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_RELEASES'] = '99';
            $_ENV['NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_BYTES'] = '-1';
            $_SERVER['NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_RELEASES'] = '99';
            $_SERVER['NNTMUX_ORCHESTRATOR_BACKFILL_MAX_NON_TARGET_BYTES'] = '-1';
            $clamped = require base_path('config/nntmux.php');
            self::assertSame(10, $clamped['orchestrator']['backfill_max_non_target_releases']);
            self::assertSame(0, $clamped['orchestrator']['backfill_max_non_target_bytes']);
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    putenv($key.'='.$value);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    public function test_tv_date_range_identity_groups_are_empty_by_default_and_normalized_when_configured(): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_BACKFILL_TV_DATE_RANGE_GROUPS';
        $previous = getenv($key);

        try {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            $strict = require base_path('config/nntmux.php');
            self::assertSame([], $strict['orchestrator']['backfill_tv_date_range_groups']);

            $configured = ' alt.binaries.tvseries,alt.binaries.tvseries, alt.binaries.other ';
            putenv($key.'='.$configured);
            $_ENV[$key] = $configured;
            $_SERVER[$key] = $configured;
            $scoped = require base_path('config/nntmux.php');
            self::assertSame(
                ['alt.binaries.tvseries', 'alt.binaries.other'],
                $scoped['orchestrator']['backfill_tv_date_range_groups'],
            );
        } finally {
            if ($previous === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$previous);
                $_ENV[$key] = $previous;
                $_SERVER[$key] = $previous;
            }
        }
    }

    public function test_zero_output_context_retry_requires_exact_completed_input_and_unambiguous_safety(): void
    {
        config([
            'nntmux.orchestrator.backfill_zero_output_grace_seconds' => 300,
            'nntmux.orchestrator.backfill_incomplete_release_grace_seconds' => 600,
        ]);
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            Mockery::mock(WorkerControlStateStore::class),
            Mockery::mock(WorkerProfileApplier::class),
        );
        $method = new ReflectionMethod($orchestrator, 'zeroOutputContextReady');
        $observation = [
            'completed_observed_at' => 700,
            'backfill_quantity' => 10_000,
            'ready_collections' => 0,
            'release_total' => 4_210,
            'release_high_watermark' => 560_366,
        ];
        $outcome = [
            'ready_collections' => 0,
            'releases' => 4_210,
            'release_high_watermark' => 560_366,
        ];
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            0,
            0,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 10_000,
            backfillSafeQuantity: 50_000,
        );

        self::assertFalse($method->invoke($orchestrator, $observation, $outcome, $snapshot, true, true, 10_000, 0, 0, 999));
        self::assertTrue($method->invoke($orchestrator, $observation, $outcome, $snapshot, true, true, 10_000, 0, 0, 1_000));
        self::assertTrue($method->invoke($orchestrator, $observation, [
            ...$outcome,
            'releases' => 4_211,
            'release_high_watermark' => 560_367,
        ], $snapshot, true, true, 10_000, 0, 0, 1_000), 'an unrelated delayed release must not serialize the next exact cohort');
        foreach ([
            'provider unavailable' => new PipelineSnapshot(1, 2, 3, 0, 0, providerAvailable: false),
            'cursor unavailable' => new PipelineSnapshot(1, 2, 3, 0, 0, cursorAvailable: false),
            'current groups unavailable' => new PipelineSnapshot(1, 2, 3, 0, 0, currentGroupsAvailable: false),
            'no eligible backfill supply' => new PipelineSnapshot(1, 2, 3, 0, 0, eligibleBackfillSupply: false),
            'no safe quantity' => new PipelineSnapshot(1, 2, 3, 0, 0, backfillSafeQuantity: 0),
        ] as $label => $nextSupplyBlockedSnapshot) {
            self::assertTrue(
                $method->invoke(
                    $orchestrator,
                    $observation,
                    $outcome,
                    $nextSupplyBlockedSnapshot,
                    true,
                    true,
                    10_000,
                    0,
                    0,
                    1_000,
                ),
                $label,
            );
        }
        self::assertFalse($method->invoke($orchestrator, $observation, $outcome, $snapshot, true, true, 10_000, 1, 0, 1_299));
        self::assertTrue($method->invoke($orchestrator, $observation, $outcome, $snapshot, true, true, 10_000, 1, 0, 1_300));

        foreach ([
            'unclaimed' => [$observation, $outcome, $snapshot, false, true, 10_000, 0, 0],
            'incomplete' => [$observation, $outcome, $snapshot, true, false, 10_000, 0, 0],
            'partial cursor' => [$observation, $outcome, $snapshot, true, true, 9_999, 0, 0],
            'oversized cursor' => [$observation, $outcome, $snapshot, true, true, 10_001, 0, 0],
            'cohort release' => [$observation, $outcome, $snapshot, true, true, 10_000, 1, 0],
            'cohort nzb' => [$observation, $outcome, $snapshot, true, true, 10_000, 1, 1],
            'ready collection' => [$observation, [...$outcome, 'ready_collections' => 1], $snapshot, true, true, 10_000, 0, 0],
            'eligible nzb' => [$observation, $outcome, new PipelineSnapshot(1, 2, 3, 0, 0, eligibleBackfillSupply: true, eligibleNzbs: 1, backfillGroup: 'alt.test', backfillSafeQuantity: 50_000), true, true, 10_000, 0, 0],
            'database busy' => [$observation, $outcome, new PipelineSnapshot(1, 2, 3, 0, 0, databaseCurrentWaits: 1, eligibleBackfillSupply: true, backfillGroup: 'alt.test', backfillSafeQuantity: 50_000), true, true, 10_000, 0, 0],
            'high pressure' => [$observation, $outcome, new PipelineSnapshot(1, 2, 3, 0, 0, highPressure: true, eligibleBackfillSupply: true, backfillGroup: 'alt.test', backfillSafeQuantity: 50_000), true, true, 10_000, 0, 0],
            'invalid telemetry' => [$observation, $outcome, new PipelineSnapshot(1, 2, 3, 0, 0, telemetryFresh: false), true, true, 10_000, 0, 0],
            'hard safety failure' => [$observation, $outcome, new PipelineSnapshot(1, 2, 3, 0, 0, storageSafe: false), true, true, 10_000, 0, 0],
        ] as $label => $arguments) {
            self::assertFalse($method->invoke($orchestrator, ...[...$arguments, 1_000]), $label);
        }
    }

    public function test_zero_output_finalization_records_one_strike_and_defers_next_supply_until_the_next_cycle(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.backfill_zero_output_grace_seconds' => 300,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);

        $store = new WorkerControlStateStore;
        $store->storeState(new ControlState(profile: ControlProfile::Fill));
        $store->markBackfillContextRepeat('alt.exhausted', time() - 30);
        $baseline = new PipelineSnapshot(1, 2, 3, 0, 0, backfillGroup: 'alt.exhausted', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 600, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ], quantity: 10_000);
        $store->observePermitCompletion(7, time() - 301);

        $eligibleReplacement = new PipelineSnapshot(
            1,
            2,
            3,
            0,
            0,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.next',
            backfillCursor: 30_000,
            backfillRemainingArticles: 30_000,
            backfillSafeQuantity: 10_000,
        );
        $completedOutcome = [
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ];
        $nextOutcome = [
            'cursor' => 30_000,
            'cursor_postdate' => '2026-01-03 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ];
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->twice()->andReturn($eligibleReplacement, $eligibleReplacement);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.exhausted')->andReturn($completedOutcome);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->twice()->andReturn([
            'target' => 0,
            'non_target' => 0,
            'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(0);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.next')->andReturn($nextOutcome);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => $decision->backfillPermitted),
            Mockery::type('int'),
            false,
            'alt.next',
            false,
        )->andReturn(8);
        $applier->shouldReceive('apply')->once()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => $decision->backfillPermitted),
            Mockery::type('int'),
            true,
            'alt.next',
            false,
            10_000,
        )->andReturn(9);
        $orchestrator = new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier);

        $finalized = $orchestrator->runOnce(false);

        self::assertContains('backfill_delayed_attribution_queued', $finalized['reasons']);
        self::assertFalse($finalized['permit_granted'], 'a finalized observation must not grant replacement supply in the same cycle');
        self::assertNull($store->permitObservation());
        self::assertSame(0, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.exhausted'] ?? 0);
        self::assertSame(['alt.exhausted'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertNull($store->backfillContextRepeat(time()));

        $selected = $orchestrator->runOnce(false);

        self::assertTrue($selected['permit_granted']);
        self::assertSame('alt.next', $selected['backfill_target']['group']);
        $nextObservation = $store->permitObservation();
        self::assertNotNull($nextObservation);
        self::assertSame(9, $nextObservation['generation']);
    }

    public function test_completed_terminal_fringe_queues_delayed_attribution_without_an_early_strike(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.backfill_zero_output_grace_seconds' => 300,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);

        $store = new WorkerControlStateStore;
        $baseline = new PipelineSnapshot(
            1,
            2,
            3,
            0,
            0,
            backfillGroup: 'alt.test',
            backfillCursor: 16_389,
            backfillRemainingArticles: 16_387,
        );
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 600, outcome: [
            'cursor' => 16_389,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 4_210,
            'release_high_watermark' => 560_366,
        ], quantity: 10_000);
        $store->observePermitCompletion(7, time() - 301);

        $current = new PipelineSnapshot(
            1,
            2,
            3,
            0,
            0,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 10_002,
            backfillSafeQuantity: 50_000,
        );
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_002,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 4_211,
            'release_high_watermark' => 560_367,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 0,
            'non_target' => 0,
            'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertArrayHasKey('database_contention', $result);
        self::assertTrue($result['database_contention']['admission_safe']);
        self::assertContains('backfill_delayed_attribution_queued', $result['reasons']);
        self::assertNull($store->permitObservation());
        self::assertSame(0, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.test'] ?? 0);
        self::assertSame([], $store->backfillYieldHistory());
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertFalse($result['permit_granted']);
        self::assertSame(10, $result['worker_controls']['backfill_sleep_seconds']);
        self::assertContains('adaptive_backfill_attribution', $result['reasons']);
    }

    public function test_mature_delayed_attribution_settles_productive_yield_exactly_once(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 7_200,
            'nntmux.orchestrator.backfill_min_target_byte_share' => 0.9,
            'nntmux.orchestrator.backfill_max_non_target_releases' => 1,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => 536_870_912,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        $store->storeState(new ControlState(
            profile: ControlProfile::Balanced,
            ineffectiveBackfillPermitsByTarget: ['alt.test' => 2, 'alt.other' => 1],
        ));
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'backfill_expected_cursor_delta' => 6_387,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 6_387, time() - 7_201));

        $snapshot = new PipelineSnapshot(1, 2, 3, 0, 0);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->twice()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryQualityForCohort')->once()->with(
            'alt.test',
            100,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn([
            'counts' => ['target' => 3, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 3_867_698_952, 'non_target' => 324_000_760, 'uncategorized' => 0],
        ]);
        $snapshots->shouldReceive('backfillPendingCollectionsForCohort')->once()->with(
            'alt.test',
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->twice()->andReturn(8, 9);
        $orchestrator = new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier);

        $settled = $orchestrator->runOnce(false);
        $again = $orchestrator->runOnce(false);

        self::assertContains('backfill_delayed_attribution_settled', $settled['reasons']);
        self::assertSame('productive', $settled['delayed_attribution']['settled_result']);
        self::assertFalse($settled['permit_granted']);
        self::assertNotContains('backfill_delayed_attribution_settled', $again['reasons']);
        self::assertSame(1, $store->backfillYieldHistory()['alt.test']['attempts'] ?? null);
        self::assertSame(4.697041, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k'] ?? null);
        self::assertSame(['alt.other' => 1], $store->loadState()->ineffectiveBackfillPermitsByTarget);
        self::assertSame([], $store->pendingBackfillDelayedAttributionGroups());
    }

    public function test_mature_delayed_attribution_locks_when_atomic_byte_share_is_below_floor(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 7_200,
            'nntmux.orchestrator.backfill_min_target_byte_share' => 0.9,
            'nntmux.orchestrator.backfill_max_non_target_releases' => 1,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => 512,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'backfill_expected_cursor_delta' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time() - 7_201));

        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn(new PipelineSnapshot(1, 2, 3, 0, 0));
        $snapshots->shouldReceive('backfillCreatedNzbCategoryQualityForCohort')->once()->with(
            'alt.test',
            100,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn([
            'counts' => ['target' => 1, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 899, 'non_target' => 101, 'uncategorized' => 0],
        ]);
        $snapshots->shouldReceive('backfillPendingCollectionsForCohort')->once()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('qualityLockBackfillTarget')->once()->with(
            'alt.test',
            'backfill_permit_wrong_category',
        );
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
        ))->runOnce(false);

        self::assertContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame('backfill_permit_wrong_category', $result['delayed_attribution']['settled_result']);
        self::assertSame(0.0, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k']);
        self::assertSame([], $store->pendingBackfillDelayedAttributionGroups());
    }

    public function test_immature_productive_attribution_settles_when_the_cohort_is_fully_drained(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_incomplete_release_grace_seconds' => 600,
            'nntmux.orchestrator.backfill_productive_settlement_grace_seconds' => 45,
            'nntmux.orchestrator.backfill_min_target_byte_share' => 0.9,
            'nntmux.orchestrator.backfill_max_non_target_releases' => 1,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => 536_870_912,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        $store->storeState(new ControlState(
            profile: ControlProfile::Balanced,
            ineffectiveBackfillPermitsByTarget: ['alt.test' => 2, 'alt.other' => 1],
        ));
        $now = time();
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-04 03:04:05',
        ], ['cursor_postdate' => '2026-01-03 03:04:05'], 10_000, $now - 201));
        $store->markBackfillContextRepeat('alt.test', $now - 102, 7);
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 8,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-03 03:04:05',
        ], ['cursor_postdate' => '2026-01-02 03:04:05'], 10_000, $now - 101, contextContinuation: true));
        $store->markBackfillContextRepeat('alt.test', $now - 47, 8);
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 9,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, $now - 46, contextContinuation: true));
        self::assertSame($now - 46, $store->observeBackfillProductiveDrain(7, 2, 3, $now - 46));

        $snapshot = new PipelineSnapshot(1, 2, 3, 0, 0, lowPressure: true);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryQualityForCohort')->once()->with(
            'alt.test',
            100,
            '2026-01-04 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn([
            'counts' => ['target' => 2, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 3_867_698_952, 'non_target' => 324_000_760, 'uncategorized' => 0],
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->with(
            'alt.test',
            100,
            '2026-01-04 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn(3);
        $snapshots->shouldReceive('backfillPendingCollectionsForCohort')->once()->with(
            'alt.test',
            '2026-01-04 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $settled = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
        ))->runOnce(false);

        self::assertContains('backfill_delayed_attribution_settled', $settled['reasons']);
        self::assertNotContains('backfill_delayed_attribution_early_quality_lock', $settled['reasons']);
        self::assertSame('productive', $settled['delayed_attribution']['settled_result']);
        self::assertSame(1, $store->backfillYieldHistory()['alt.test']['attempts'] ?? null);
        self::assertSame(0.666667, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k'] ?? null);
        self::assertSame(['alt.other' => 1], $store->loadState()->ineffectiveBackfillPermitsByTarget);
        self::assertSame([], $store->pendingBackfillDelayedAttributionGroups());
        foreach ([7, 8, 9] as $settledGeneration) {
            self::assertFalse($store->queueBackfillDelayedAttribution([
                'generation' => $settledGeneration,
                'backfill_group' => 'alt.test',
                'backfill_quantity' => 10_000,
                'release_high_watermark' => 100,
                'backfill_cursor_postdate' => '2026-01-02 03:04:05',
            ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, $now));
        }
        self::assertFalse($settled['permit_granted']);
    }

    public function test_immature_productive_attribution_waits_while_collections_remain(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time() - 601));
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn(new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
        ));
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 1, 'non_target' => 0, 'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(1);
        $snapshots->shouldReceive('backfillPendingCollectionsForCohort')->once()->andReturn(1);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
        ))->runOnce(false);

        self::assertNotContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame([], $store->backfillYieldHistory());
    }

    public function test_stable_mixed_release_waiting_for_its_nzb_uses_full_release_byte_quality(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_productive_settlement_grace_seconds' => 45,
            'nntmux.orchestrator.backfill_min_target_byte_share' => 0.9,
            'nntmux.orchestrator.backfill_max_non_target_releases' => 1,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => 536_870_912,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        $now = time();
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, $now - 600));
        self::assertSame($now - 46, $store->observeBackfillProductiveDrain(7, 2, 4, $now - 46));
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn(new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
        ));
        $snapshots->shouldReceive('backfillCreatedNzbCategoryQualityForCohort')->once()->andReturn([
            'counts' => ['target' => 2, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 3_867_698_952, 'non_target' => 324_000_760, 'uncategorized' => 0],
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(4);
        $snapshots->shouldReceive('backfillCreatedReleaseCategoryQualityForCohort')->once()->andReturn([
            'counts' => ['target' => 3, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 4_500_000_000, 'non_target' => 324_000_760, 'uncategorized' => 0],
        ]);
        $snapshots->shouldReceive('backfillPendingCollectionsForCohort')->once()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
        ))->runOnce(false);

        self::assertContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame('productive', $result['delayed_attribution']['settled_result']);
        self::assertSame(2.0, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k']);
        self::assertSame([], $store->pendingBackfillDelayedAttributionGroups());
    }

    public function test_stable_drain_clears_when_full_release_bytes_exceed_the_mixed_cohort_cap(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_productive_settlement_grace_seconds' => 45,
            'nntmux.orchestrator.backfill_min_target_byte_share' => 0.9,
            'nntmux.orchestrator.backfill_max_non_target_releases' => 1,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => 536_870_912,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        $now = time();
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, $now - 600));
        self::assertSame($now - 46, $store->observeBackfillProductiveDrain(7, 2, 4, $now - 46));

        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn(new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
        ));
        $snapshots->shouldReceive('backfillCreatedNzbCategoryQualityForCohort')->once()->andReturn([
            'counts' => ['target' => 2, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 3_867_698_952, 'non_target' => 324_000_760, 'uncategorized' => 0],
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(4);
        $snapshots->shouldReceive('backfillCreatedReleaseCategoryQualityForCohort')->once()->andReturn([
            'counts' => ['target' => 3, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 4_500_000_000, 'non_target' => 600_000_000, 'uncategorized' => 0],
        ]);
        $snapshots->shouldNotReceive('backfillPendingCollectionsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldNotReceive('qualityLockBackfillTarget');
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
        ))->runOnce(false);

        self::assertNotContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame([], $store->backfillYieldHistory());
        self::assertSame($now, $store->observeBackfillProductiveDrain(7, 2, 4, $now));
    }

    public function test_release_waiting_for_its_nzb_blocks_settlement_when_its_category_is_non_target(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_productive_settlement_grace_seconds' => 45,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        $now = time();
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, $now - 600));
        self::assertSame($now - 46, $store->observeBackfillProductiveDrain(7, 3, 4, $now - 46));
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn(new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
        ));
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 3, 'non_target' => 0, 'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(4);
        $snapshots->shouldReceive('backfillCreatedReleaseCategoryCountsForCohort')->once()->andReturn([
            'target' => 3, 'non_target' => 1, 'uncategorized' => 0,
        ]);
        $snapshots->shouldNotReceive('backfillPendingCollectionsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
        ))->runOnce(false);

        self::assertNotContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame([], $store->backfillYieldHistory());
    }

    public function test_immature_productive_attribution_waits_until_every_release_has_a_target_identity(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time() - 601));
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn(new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
        ));
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 1, 'non_target' => 0, 'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(2);
        $snapshots->shouldReceive('backfillCreatedReleaseCategoryCountsForCohort')->once()->andReturn([
            'target' => 1, 'non_target' => 0, 'uncategorized' => 1,
        ]);
        $snapshots->shouldNotReceive('backfillPendingCollectionsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
        ))->runOnce(false);

        self::assertNotContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame([], $store->backfillYieldHistory());
    }

    public function test_immature_productive_attribution_waits_for_the_productive_settlement_grace(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_productive_settlement_grace_seconds' => 45,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time() - 44));
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn(new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
        ));
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 1, 'non_target' => 0, 'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(1);
        $snapshots->shouldReceive('backfillPendingCollectionsForCohort')->once()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
        ))->runOnce(false);

        self::assertNotContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame([], $store->backfillYieldHistory());
    }

    public function test_productive_settlement_grace_starts_at_the_first_clean_drain_and_restarts_for_late_output(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_productive_settlement_grace_seconds' => 30,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time() - 600));
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->twice()->andReturn(new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
        ));
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->twice()->andReturn(
            ['target' => 1, 'non_target' => 0, 'uncategorized' => 0],
            ['target' => 2, 'non_target' => 0, 'uncategorized' => 0],
        );
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->twice()->andReturn(1, 2);
        $snapshots->shouldReceive('backfillPendingCollectionsForCohort')->twice()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->twice()->andReturn(8);
        $orchestrator = new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier);

        $firstCleanSample = $orchestrator->runOnce(false);
        $lateOutputSample = $orchestrator->runOnce(false);

        self::assertNotContains('backfill_delayed_attribution_settled', $firstCleanSample['reasons']);
        self::assertNotContains('backfill_delayed_attribution_settled', $lateOutputSample['reasons']);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame([], $store->backfillYieldHistory());
    }

    public function test_immature_productive_attribution_waits_at_neutral_pressure(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time() - 601));
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn(new PipelineSnapshot(1, 2, 3, 0, 0));
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 1, 'non_target' => 0, 'uncategorized' => 0,
        ]);
        $snapshots->shouldNotReceive('backfillCreatedReleasesForCohort');
        $snapshots->shouldNotReceive('backfillPendingCollectionsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
        ))->runOnce(false);

        self::assertNotContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame([], $store->backfillYieldHistory());
    }

    public function test_immature_delayed_attribution_quality_failure_locks_and_settles_on_the_next_cycle(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_min_target_byte_share' => 0.9,
            'nntmux.orchestrator.backfill_max_non_target_releases' => 1,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => 512,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time()));

        $snapshot = new PipelineSnapshot(1, 2, 3, 0, 0);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->twice()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryQualityForCohort')->once()->with(
            'alt.test',
            100,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn([
            'counts' => ['target' => 1, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 899, 'non_target' => 101, 'uncategorized' => 0],
        ]);
        $snapshots->shouldNotReceive('backfillPendingCollectionsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('qualityLockBackfillTarget')->once()->with(
            'alt.test',
            'backfill_permit_wrong_category',
        );
        $applier->shouldReceive('apply')->twice()->andReturn(8, 9);
        $orchestrator = new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier);

        $locked = $orchestrator->runOnce(false, grantPermit: true);
        $again = $orchestrator->runOnce(false);

        self::assertContains('backfill_delayed_attribution_settled', $locked['reasons']);
        self::assertContains('backfill_delayed_attribution_early_quality_lock', $locked['reasons']);
        self::assertSame('backfill_permit_wrong_category', $locked['delayed_attribution']['settled_result']);
        self::assertNotContains('backfill_delayed_attribution_settled', $again['reasons']);
        self::assertSame(1, $store->backfillYieldHistory()['alt.test']['attempts'] ?? null);
        self::assertSame(0.0, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k'] ?? null);
        self::assertSame([], $store->pendingBackfillDelayedAttributionGroups());
        self::assertFalse($locked['permit_granted']);
    }

    public function test_immature_uncategorized_payload_waits_for_completion_grace(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_incomplete_release_grace_seconds' => 600,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time()));

        $snapshot = new PipelineSnapshot(1, 2, 3, 0, 0);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 0, 'non_target' => 0, 'uncategorized' => 1,
        ]);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldNotReceive('qualityLockBackfillTarget');
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertNotContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
    }

    public function test_immature_uncategorized_payload_locks_after_completion_grace(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_incomplete_release_grace_seconds' => 600,
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time() - 601));

        $snapshot = new PipelineSnapshot(1, 2, 3, 0, 0);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 0, 'non_target' => 0, 'uncategorized' => 1,
        ]);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('qualityLockBackfillTarget')->once()->with(
            'alt.test',
            'backfill_permit_uncategorized_after_grace',
        );
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_delayed_attribution_early_quality_lock', $result['reasons']);
        self::assertSame('backfill_permit_uncategorized_after_grace', $result['delayed_attribution']['settled_result']);
        self::assertSame([], $store->pendingBackfillDelayedAttributionGroups());
    }

    public function test_delayed_attribution_audit_does_not_clobber_a_permit_without_an_observation(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
        ]);
        Cache::store('array')->flush();
        DB::table('settings')->updateOrInsert(
            ['name' => 'orchestrator_bf_permit'],
            ['value' => '17'],
        );
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.old',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, time() - 601));

        $snapshot = new PipelineSnapshot(1, 2, 3, 0, 0);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldNotReceive('backfillCreatedNzbCategoryCountsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldNotReceive('qualityLockBackfillTarget');
        $applier->shouldReceive('apply')->once()->andReturn(18);

        (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertSame(['alt.old'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame('17', DB::table('settings')->where('name', 'orchestrator_bf_permit')->value('value'));
    }

    public function test_fresh_continuation_refreshes_uncategorized_quality_grace(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'nntmux.orchestrator.backfill_incomplete_release_grace_seconds' => 600,
        ]);
        Cache::store('array')->flush();
        $now = time();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-03 03:04:05',
        ], ['cursor_postdate' => '2026-01-02 03:04:05'], 10_000, $now - 700));
        $store->markBackfillContextRepeat('alt.test', $now - 1, 7);
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 8,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 101,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, $now, contextContinuation: true));

        $snapshot = new PipelineSnapshot(1, 2, 3, 0, 0);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 0, 'non_target' => 0, 'uncategorized' => 1,
        ]);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldNotReceive('qualityLockBackfillTarget');
        $applier->shouldReceive('apply')->once()->andReturn(9);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertNotContains('backfill_delayed_attribution_settled', $result['reasons']);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
    }

    public function test_mature_three_range_chain_settles_combined_window_once_and_tombstones_every_generation(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 60,
        ]);
        Cache::store('array')->flush();
        $now = time();
        $store = new WorkerControlStateStore;
        $store->storeState(new ControlState(
            profile: ControlProfile::Balanced,
            ineffectiveBackfillPermitsByTarget: ['alt.test' => 1, 'alt.other' => 1],
        ));
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-03 03:04:05',
        ], ['cursor_postdate' => '2026-01-02 03:04:05'], 10_000, $now - 200));
        $store->markBackfillContextRepeat('alt.test', $now - 122, 7);
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 8,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 101,
            'backfill_cursor_postdate' => '2026-01-02 03:04:05',
        ], ['cursor_postdate' => '2026-01-01 03:04:05'], 10_000, $now - 121, contextContinuation: true));
        $store->markBackfillContextRepeat('alt.test', $now - 62, 8);
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 9,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 102,
            'backfill_cursor_postdate' => '2026-01-01 03:04:05',
        ], ['cursor_postdate' => '2025-12-31 03:04:05'], 10_000, $now - 61, contextContinuation: true));

        $snapshot = new PipelineSnapshot(1, 2, 3, 0, 0);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->twice()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->with(
            'alt.test',
            100,
            '2026-01-03 03:04:05',
            '2025-12-31 03:04:05',
        )->andReturn(['target' => 4, 'non_target' => 0, 'uncategorized' => 0]);
        $snapshots->shouldReceive('backfillPendingCollectionsForCohort')->once()->with(
            'alt.test',
            '2026-01-03 03:04:05',
            '2025-12-31 03:04:05',
        )->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->twice()->andReturn(9, 10);
        $orchestrator = new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier);

        $settled = $orchestrator->runOnce(false);
        $again = $orchestrator->runOnce(false);

        self::assertContains('backfill_delayed_attribution_settled', $settled['reasons']);
        self::assertNotContains('backfill_delayed_attribution_settled', $again['reasons']);
        self::assertSame(1, $store->backfillYieldHistory()['alt.test']['attempts'] ?? null);
        self::assertSame(1.333333, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k'] ?? null);
        self::assertSame(['alt.other' => 1], $store->loadState()->ineffectiveBackfillPermitsByTarget);
        self::assertSame([], $store->pendingBackfillDelayedAttributionGroups());
        foreach ([8, 9] as $settledGeneration) {
            self::assertFalse($store->queueBackfillDelayedAttribution([
                'generation' => $settledGeneration,
                'backfill_group' => 'alt.test',
                'backfill_quantity' => 10_000,
                'release_high_watermark' => 100 + $settledGeneration - 7,
                'backfill_cursor_postdate' => $settledGeneration === 8
                    ? '2026-01-02 03:04:05'
                    : '2026-01-01 03:04:05',
            ], ['cursor_postdate' => $settledGeneration === 8
                ? '2026-01-01 03:04:05'
                : '2025-12-31 03:04:05'], 10_000, $now));
        }
        self::assertFalse($settled['permit_granted']);
    }

    public function test_completed_inactive_backfill_permit_attributes_collection_context_progress(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.backfill_zero_output_grace_seconds' => 300,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);

        $store = new WorkerControlStateStore;
        $store->storeState(new ControlState(
            profile: ControlProfile::Balanced,
            ineffectiveBackfillPermitsByTarget: ['alt.test' => 2, 'alt.other' => 1],
        ));
        $store->markBackfillContextRepeat('alt.test', time() - 30);
        $baseline = new PipelineSnapshot(1, 2, 3, 0, 0, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 600, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], quantity: 10_000);
        $store->observePermitCompletion(7, time() - 301);

        $current = new PipelineSnapshot(
            1,
            2,
            3,
            0,
            0,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 10_000,
            backfillSafeQuantity: 50_000,
        );
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->twice()->andReturn($current, $current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->twice()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'partial_collections' => 3,
            'complete_binaries' => 24,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->twice()->andReturn([
            'target' => 0,
            'non_target' => 0,
            'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->twice()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $orchestrator = new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier);
        $shadow = $orchestrator->runOnce(true);

        self::assertNotContains('backfill_permit_context_progress', $shadow['reasons']);
        self::assertNotNull($store->permitObservation());
        self::assertSame([], $store->backfillYieldHistory());
        self::assertSame(['alt.test' => 2, 'alt.other' => 1], $store->loadState()->ineffectiveBackfillPermitsByTarget);
        self::assertSame('alt.test', $store->backfillContextRepeat(time())['group'] ?? null);

        $result = $orchestrator->runOnce(false);

        self::assertContains('backfill_delayed_attribution_queued', $result['reasons']);
        self::assertNotContains('backfill_permit_effective', $result['reasons']);
        self::assertNotContains('backfill_permit_ineffective', $result['reasons']);
        self::assertSame(['alt.test' => 1, 'alt.other' => 1], $store->loadState()->ineffectiveBackfillPermitsByTarget);
        self::assertSame([], $store->backfillYieldHistory());
        self::assertNull($store->permitObservation());
        self::assertSame('alt.test', $store->backfillContextRepeat(time())['group'] ?? null);
        self::assertFalse($result['permit_granted']);
    }

    public function test_adjacent_raw_only_repeat_rearms_the_bounded_delayed_chain(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.backfill_zero_output_grace_seconds' => 300,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);

        $store = new WorkerControlStateStore;
        $store->storeState(new ControlState(
            profile: ControlProfile::Balanced,
            ineffectiveBackfillPermitsByTarget: ['alt.test' => 2],
        ));
        $baseline = new PipelineSnapshot(1, 2, 3, 0, 0, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, 7, time() - 600, [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 10,
            'raw_binaries' => 20,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], 10_000);
        $store->observePermitCompletion(7, time() - 301);
        $snapshot = new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 10_000,
            backfillSafeQuantity: 10_000,
        );
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->times(4)->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->times(4)->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 11,
            'raw_binaries' => 21,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], [
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 11,
            'raw_binaries' => 21,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], [
            'cursor' => 10_000,
            'cursor_postdate' => '2025-12-31 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 12,
            'raw_binaries' => 22,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], [
            'cursor' => 10_000,
            'cursor_postdate' => '2025-12-31 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 12,
            'raw_binaries' => 22,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->times(4)->andReturn([
            'target' => 0, 'non_target' => 0, 'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->times(4)->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->times(4);
        $applier->shouldReceive('apply')->times(4)->andReturn(8, 9, 10, 11);
        $orchestrator = new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier);

        $first = $orchestrator->runOnce(false);

        self::assertContains('backfill_delayed_attribution_queued', $first['reasons']);
        self::assertContains('backfill_permit_context_progress', $first['reasons']);
        self::assertSame(1, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.test'] ?? 0);
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame('alt.test', $store->backfillContextRepeat(time())['group'] ?? null);

        $store->beginPermitObservation($baseline, 7, time() - 600, [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 10,
            'raw_binaries' => 20,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], 10_000);
        $store->observePermitCompletion(7, time() - 301);

        $rootReplay = $orchestrator->runOnce(false);

        self::assertContains('backfill_delayed_attribution_queued', $rootReplay['reasons']);
        self::assertNotContains('backfill_permit_context_progress', $rootReplay['reasons']);
        self::assertSame(1, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.test'] ?? 0);
        self::assertSame('alt.test', $store->backfillContextRepeat(time())['group'] ?? null);
        self::assertNull($store->permitObservation());

        DB::table('settings')->where('name', 'orchestrator_bf_claimed')->update(['value' => '8']);
        DB::table('settings')->where('name', 'orchestrator_bf_completed')->update(['value' => '8']);
        $store->beginPermitObservation($baseline, 8, time() - 600, [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 11,
            'raw_binaries' => 21,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], 10_000);
        $store->observePermitCompletion(8, time() - 301);

        $result = $orchestrator->runOnce(false);

        self::assertContains('backfill_delayed_attribution_queued', $result['reasons']);
        self::assertContains('backfill_permit_context_progress', $result['reasons']);
        self::assertSame(0, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.test'] ?? 0);
        self::assertSame('alt.test', $store->backfillContextRepeat(time())['group'] ?? null);
        self::assertNull($store->permitObservation());
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertFalse($result['permit_granted']);

        $store->beginPermitObservation($baseline, 8, time() - 600, [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 11,
            'raw_binaries' => 21,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], 10_000);
        $store->observePermitCompletion(8, time() - 301);

        $continuationReplay = $orchestrator->runOnce(false);

        self::assertContains('backfill_delayed_attribution_queued', $continuationReplay['reasons']);
        self::assertNotContains('backfill_permit_context_progress', $continuationReplay['reasons']);
        self::assertSame(0, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.test'] ?? 0);
        self::assertSame(8, $store->backfillContextRepeat(time())['generation'] ?? null);
        self::assertNull($store->permitObservation());
        self::assertFalse($continuationReplay['permit_granted']);
    }

    #[DataProvider('rootAttributionReplayOutputProvider')]
    public function test_root_attribution_replay_defers_new_output_without_double_scoring(
        array $categories,
        ?string $qualityFailure,
    ): void {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);

        $now = time();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-03 03:04:05',
        ], ['cursor_postdate' => '2026-01-02 03:04:05'], 10_000, $now));
        $store->markBackfillContextRepeat('alt.test', $now, 7);
        $baseline = new PipelineSnapshot(1, 2, 3, 0, 0, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, 7, $now - 600, [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-03 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 10,
            'raw_binaries' => 20,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], 10_000);
        $store->observePermitCompletion(7, $now - 301);
        $snapshot = new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 10_000,
            backfillSafeQuantity: 10_000,
        );
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 1,
            'release_high_watermark' => 101,
            'group_active' => 0,
            'raw_collections' => 11,
            'raw_binaries' => 21,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(1);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn($categories);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        if ($qualityFailure === null) {
            $applier->shouldReceive('revokePermit')->once();
        } else {
            $applier->shouldReceive('qualityLockBackfillTarget')->once()->with('alt.test', $qualityFailure);
        }
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_delayed_attribution_queued', $result['reasons']);
        self::assertSame([], $store->backfillYieldHistory());
        self::assertSame(['alt.test'], $store->pendingBackfillDelayedAttributionGroups());
        self::assertSame('alt.test', $store->backfillContextRepeat(time())['group'] ?? null);
        self::assertNull($store->permitObservation());
        self::assertFalse($result['permit_granted']);
    }

    public static function rootAttributionReplayOutputProvider(): array
    {
        return [
            'target output' => [['target' => 1, 'non_target' => 0, 'uncategorized' => 0], null],
            'wrong category output' => [['target' => 0, 'non_target' => 1, 'uncategorized' => 0], 'backfill_permit_wrong_category'],
        ];
    }

    #[DataProvider('productiveContinuationContextProvider')]
    public function test_productive_context_continuation_only_rearms_the_bounded_delayed_chain_with_proven_context(
        bool $hasContextProgress,
    ): void {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '8'],
            ['name' => 'orchestrator_bf_completed', 'value' => '8'],
        ]);

        $now = time();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-03 03:04:05',
        ], ['cursor_postdate' => '2026-01-02 03:04:05'], 10_000, $now));
        $store->markBackfillContextRepeat('alt.test', $now, 7);
        $baseline = new PipelineSnapshot(1, 2, 3, 0, 0, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, 8, $now - 600, [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 10,
            'raw_binaries' => 20,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], 10_000);
        $store->observePermitCompletion(8, $now - 301);
        $snapshot = new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 10_000,
            backfillSafeQuantity: 10_000,
        );
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 1,
            'release_high_watermark' => 101,
            'group_active' => 0,
            'raw_collections' => 11,
            'raw_binaries' => 21,
            'partial_collections' => $hasContextProgress ? 3 : 2,
            'complete_binaries' => $hasContextProgress ? 21 : 20,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(1);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 1, 'non_target' => 0, 'uncategorized' => 0,
        ]);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(9);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_delayed_attribution_queued', $result['reasons']);
        self::assertSame($hasContextProgress, in_array('backfill_permit_effective', $result['reasons'], true));
        self::assertSame($hasContextProgress ? 8 : null, $store->backfillContextRepeat(time())['generation'] ?? null);
        self::assertSame($hasContextProgress, $store->backfillDelayedAttributionCanContinue('alt.test', time()));
        self::assertSame(20_000, $store->matureBackfillDelayedAttribution(time() + 9_001)['cursor_delta'] ?? null);
        self::assertSame([], $store->backfillYieldHistory());
        self::assertNull($store->permitObservation());
        self::assertFalse($result['permit_granted']);
    }

    public static function productiveContinuationContextProvider(): array
    {
        return [
            'target output plus context progress' => [true],
            'target output without context progress' => [false],
        ];
    }

    public function test_context_continuation_with_wrong_category_merges_before_quality_lock_without_early_yield(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_delayed_attribution_seconds' => 9_000,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '8'],
            ['name' => 'orchestrator_bf_completed', 'value' => '8'],
        ]);

        $now = time();
        $store = new WorkerControlStateStore;
        self::assertTrue($store->queueBackfillDelayedAttribution([
            'generation' => 7,
            'backfill_group' => 'alt.test',
            'backfill_quantity' => 10_000,
            'release_high_watermark' => 100,
            'backfill_cursor_postdate' => '2026-01-03 03:04:05',
        ], ['cursor_postdate' => '2026-01-02 03:04:05'], 10_000, $now));
        $store->markBackfillContextRepeat('alt.test', $now, 7);
        $baseline = new PipelineSnapshot(1, 2, 3, 0, 0, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, 8, $now - 600, [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
            'group_active' => 0,
            'raw_collections' => 10,
            'raw_binaries' => 20,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ], 10_000);
        $store->observePermitCompletion(8, $now - 301);
        $snapshot = new PipelineSnapshot(
            1, 2, 3, 0, 0,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 10_000,
            backfillSafeQuantity: 10_000,
        );
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 1,
            'release_high_watermark' => 101,
            'group_active' => 0,
            'raw_collections' => 11,
            'raw_binaries' => 21,
            'partial_collections' => 2,
            'complete_binaries' => 20,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(1);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 0, 'non_target' => 1, 'uncategorized' => 0,
        ]);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('qualityLockBackfillTarget')->once()->with(
            'alt.test',
            'backfill_permit_wrong_category',
        );
        $applier->shouldReceive('apply')->once()->andReturn(9);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_wrong_category', $result['reasons']);
        self::assertContains('backfill_delayed_attribution_queued', $result['reasons']);
        self::assertSame([], $store->backfillYieldHistory());
        self::assertNull($store->permitObservation());
        self::assertNull($store->backfillContextRepeat(time()));
        self::assertSame(20_000, $store->matureBackfillDelayedAttribution(time() + 9_001)['cursor_delta'] ?? null);
        self::assertFalse($result['permit_granted']);
    }

    public function test_configuration_clamps_the_observation_window_to_five_minutes(): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_PERMIT_OBSERVATION_SECONDS';
        $previous = getenv($key);
        putenv($key.'=299');
        $_ENV[$key] = '299';
        $_SERVER[$key] = '299';

        try {
            $configuration = require base_path('config/nntmux.php');

            self::assertSame(300, $configuration['orchestrator']['permit_observation_seconds']);
        } finally {
            if ($previous === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$previous);
                $_ENV[$key] = $previous;
                $_SERVER[$key] = $previous;
            }
        }
    }

    #[DataProvider('cohortPostdateToleranceProvider')]
    public function test_configuration_bounds_the_cohort_postdate_tolerance(string $input, int $expected): void
    {
        $key = 'NNTMUX_ORCHESTRATOR_BACKFILL_COHORT_POSTDATE_TOLERANCE_SECONDS';
        $previous = getenv($key);
        putenv($key.'='.$input);
        $_ENV[$key] = $input;
        $_SERVER[$key] = $input;

        try {
            $configuration = require base_path('config/nntmux.php');

            self::assertSame($expected, $configuration['orchestrator']['backfill_cohort_postdate_tolerance_seconds']);
        } finally {
            if ($previous === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$previous);
                $_ENV[$key] = $previous;
                $_SERVER[$key] = $previous;
            }
        }
    }

    /** @return array<string, array{string, int}> */
    public static function cohortPostdateToleranceProvider(): array
    {
        return [
            'negative becomes exact' => ['-1', 0],
            'one hour remains bounded' => ['3600', 3600],
            'more than one day is capped' => ['86401', 86400],
        ];
    }

    public function test_active_no_backfill_mode_applies_profile_without_issuing_a_permit(): void
    {
        config(['nntmux.orchestrator.auto_backfill' => false]);
        $snapshot = new PipelineSnapshot(1, 2, 3, 4, 5, lowPressure: true, eligibleBackfillSupply: true, backfillGroup: 'alt.test', backfillCursor: 123);
        $state = new ControlState(profile: ControlProfile::Balanced);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturnNull();
        $store->shouldReceive('loadState')->once()->andReturn($state);
        $store->shouldReceive('storeState')->once()->with(Mockery::type(ControlState::class));
        $store->shouldReceive('storeSnapshot')->once()->with($snapshot);
        $store->shouldReceive('storeDecision')->once()->with(Mockery::on(
            static fn (array $result): bool => $result['permit_granted'] === false,
        ));
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->with(null)->andReturn($snapshot);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => $decision->backfillPermitted),
            Mockery::type('int'),
            false,
            'alt.test',
            false,
        )->andReturn(1);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertTrue($result['applied']);
        self::assertFalse($result['permit_granted']);
    }

    public function test_fill_atomically_downshifts_a_positive_ewma_target_with_an_ineffective_strike(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.backfill_max_quantity' => 200_000,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '0']);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.multipart',
            backfillCursor: 1_000_000,
            backfillYieldNzbsPer10k: 1.848574,
            backfillYieldAttempts: 22,
            backfillLastCursorDelta: 80_000,
            backfillLastEffectiveAt: time() - 600,
            backfillHistoryRecent: true,
            backfillTargetIneffectivePermits: 1,
            backfillRemainingArticles: 1_000_000,
            backfillSafeQuantity: 80_000,
        );
        $state = new ControlState(
            profile: ControlProfile::Fill,
            ineffectiveBackfillPermitsByTarget: ['alt.multipart' => 1],
        );
        $outcome = [
            'cursor' => 1_000_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ];
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturnNull();
        $store->shouldReceive('loadState')->once()->andReturn($state);
        $store->shouldReceive('beginPermitObservation')->once()->with(
            $snapshot,
            42,
            Mockery::type('int'),
            $outcome,
            10_000,
        );
        $store->shouldReceive('storeState')->once()->with(Mockery::type(ControlState::class));
        $store->shouldReceive('storeSnapshot')->once()->with($snapshot);
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->with(null)->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.multipart')->andReturn($outcome);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => $decision->backfillPermitted),
            Mockery::type('int'),
            true,
            'alt.multipart',
            false,
            10_000,
        )->andReturn(42);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertTrue($result['permit_granted']);
        self::assertSame(10_000, $result['backfill_target']['quantity']);
    }

    public function test_cooldown_due_zero_yield_target_issues_only_one_probe_sized_retry(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.backfill_max_quantity' => 200_000,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '0']);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            lowPressure: true,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.cooldown-due',
            backfillCursor: 1_000_000,
            backfillYieldNzbsPer10k: 0.0,
            backfillYieldAttempts: 2,
            backfillLastCursorDelta: 10_000,
            backfillLastEffectiveAt: 0,
            backfillHistoryRecent: true,
            backfillTargetIneffectivePermits: 2,
            backfillTargetLockRetryDue: true,
            backfillRemainingArticles: 1_000_000,
            backfillSafeQuantity: 40_000,
        );
        $state = new ControlState(
            profile: ControlProfile::Fill,
            ineffectiveBackfillPermitsByTarget: ['alt.cooldown-due' => 2],
        );
        $outcome = [
            'cursor' => 1_000_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ];
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturnNull();
        $store->shouldReceive('loadState')->once()->andReturn($state);
        $store->shouldReceive('beginPermitObservation')->once()->with(
            $snapshot,
            42,
            Mockery::type('int'),
            $outcome,
            10_000,
        );
        $store->shouldReceive('markBackfillTargetAttempted')->once()->with(
            'alt.cooldown-due',
            Mockery::type('int'),
        );
        $store->shouldReceive('storeState')->once()->with(Mockery::type(ControlState::class));
        $store->shouldReceive('storeSnapshot')->once()->with($snapshot);
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->with(null)->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.cooldown-due')->andReturn($outcome);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => $decision->backfillPermitted),
            Mockery::type('int'),
            true,
            'alt.cooldown-due',
            false,
            10_000,
        )->andReturn(42);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertTrue($result['permit_granted']);
        self::assertContains('backfill_target_lock_retry_due', $result['reasons']);
        self::assertSame(10_000, $result['backfill_target']['quantity']);
    }

    public function test_redis_failure_before_lock_acquisition_persists_fail_closed_state(): void
    {
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andThrow(new RuntimeException('redis unavailable'));
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('failClosed')->once();
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            $store,
            $applier,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('redis unavailable');

        $orchestrator->runOnce(false);
    }

    public function test_an_expired_unconsumed_permit_is_revoked_without_consuming_a_strike(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '7']);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.current',
            backfillCursor: 100,
        );
        $state = new ControlState(profile: ControlProfile::Balanced);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturn([
            'generation' => 7,
            'issued_at' => time() - 1201,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 100,
        ]);
        $store->shouldReceive('clearPermitObservation')->once();
        $store->shouldReceive('loadState')->once()->andReturn($state);
        $store->shouldReceive('storeState')->once()->with(Mockery::on(
            static fn (ControlState $next): bool => $next->consecutiveIneffectiveBackfillPermits === 0
                && ! isset($next->ineffectiveBackfillPermitsByTarget['alt.test'])
                && ! isset($next->ineffectiveBackfillPermitsByTarget['alt.current']),
        ));
        $store->shouldReceive('storeSnapshot')->once();
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 100,
            'ready_collections' => 0,
            'releases' => 0,
        ]);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_unclaimed', $result['reasons']);
        self::assertNotContains('backfill_permit_ineffective', $result['reasons']);
        self::assertFalse($result['permit_granted']);
    }

    public function test_an_observation_stays_open_past_fifteen_minutes(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
        ]);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            eligibleBackfillSupply: true,
            backfillGroup: 'alt.test',
            backfillCursor: 90,
        );
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturn([
            'generation' => 7,
            'issued_at' => time() - 901,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 100,
        ]);
        $store->shouldReceive('loadState')->once()->andReturn(new ControlState(profile: ControlProfile::Balanced));
        $store->shouldReceive('storeState')->once();
        $store->shouldReceive('storeSnapshot')->once();
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldNotReceive('backfillOutcomeForGroup');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldNotReceive('revokePermit');
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertNotContains('backfill_permit_ineffective', $result['reasons']);
        self::assertFalse($result['permit_granted']);
    }

    public function test_a_revoked_permit_is_not_misattributed_as_a_worker_claim(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '0'],
        ]);
        $snapshot = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.test', backfillCursor: 90);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturn([
            'generation' => 7,
            'issued_at' => time() - 1201,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 100,
        ]);
        $store->shouldReceive('clearPermitObservation')->once();
        $store->shouldReceive('loadState')->once()->andReturn(new ControlState(profile: ControlProfile::Balanced));
        $store->shouldReceive('storeState')->once()->with(Mockery::on(
            static fn (ControlState $next): bool => $next->consecutiveIneffectiveBackfillPermits === 0,
        ));
        $store->shouldReceive('storeSnapshot')->once();
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->andReturn([
            'cursor' => 90,
            'ready_collections' => 1,
            'releases' => 1,
        ]);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_unclaimed', $result['reasons']);
        self::assertNotContains('backfill_permit_ineffective', $result['reasons']);
    }

    public function test_carried_target_yield_does_not_hide_a_new_incomplete_release_frontier(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'nntmux.orchestrator.backfill_min_target_byte_share' => 0.9,
            'nntmux.orchestrator.backfill_max_non_target_releases' => 1,
            'nntmux.orchestrator.backfill_max_non_target_bytes' => 536_870_912,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        $store->rememberIncompleteReleaseCohort([
            'backfill_group' => 'alt.test',
            'release_high_watermark' => 90,
            'backfill_cursor_postdate' => '2026-01-03 03:04:05',
        ], 100, '2026-01-02 03:04:05', time());
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 1201, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.test', backfillCursor: 10_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 1,
            'releases' => 1,
            'release_high_watermark' => 103,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryQualityForCohort')->once()->with(
            'alt.test',
            100,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn([
            'counts' => ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
            'bytes' => ['target' => 0, 'non_target' => 0, 'uncategorized' => 0],
        ]);
        $snapshots->shouldReceive('backfillCompletedNzbCategoryQualityForReleaseCohort')->once()->with(
            'alt.test',
            90,
            100,
            '2026-01-03 03:04:05',
            '2026-01-02 03:04:05',
        )->andReturn([
            'counts' => ['target' => 3, 'non_target' => 1, 'uncategorized' => 0],
            'bytes' => ['target' => 3_867_698_952, 'non_target' => 324_000_760, 'uncategorized' => 0],
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->with(
            'alt.test',
            100,
            '2026-01-02 03:04:05',
            '2026-01-01 03:04:05',
        )->andReturn(3);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldNotReceive('qualityLockBackfillTarget');
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_effective', $result['reasons']);
        self::assertSame(3.0, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k']);
        self::assertSame(1, $store->backfillYieldHistory()['alt.test']['attempts']);

        $store->beginPermitObservation($baseline, generation: 8, now: time(), outcome: [
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 1,
            'release_high_watermark' => 103,
        ]);
        self::assertSame([
            'id_low_exclusive' => 100,
            'id_high_inclusive' => 103,
            'cursor_start_postdate' => '2026-01-02 03:04:05',
            'cursor_end_postdate' => '2026-01-01 03:04:05',
        ], $store->permitObservation()['prior_release_cohort'] ?? null);
    }

    #[DataProvider('actionableFrontierCases')]
    public function test_a_claimed_permit_closes_only_after_exact_cohort_nzb_frontier_drains(
        int $eligibleNzbs,
        bool $closes,
    ): void {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        if ($closes) {
            $store->markBackfillContextRepeat('alt.test', time() - 30);
        }
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 61, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            eligibleBackfillSupply: true,
            eligibleNzbs: $eligibleNzbs,
            backfillGroup: 'alt.next',
            backfillCursor: 30_000,
        );
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'partial_collections' => 1,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 2,
            'non_target' => 0,
            'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(2);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->times($closes ? 1 : 0);
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        $closes
            ? self::assertContains('backfill_permit_effective', $result['reasons'])
            : self::assertNotContains('backfill_permit_effective', $result['reasons']);
        self::assertFalse($result['permit_granted']);
        self::assertSame($closes, $store->permitObservation() === null);
        self::assertSame($closes ? 2.0 : null, $store->backfillYieldHistory()['alt.test']['ewma_nzbs_per_10k'] ?? null);
        self::assertNull($store->backfillContextRepeat(time()));
    }

    /** @return array<string, array{int, bool}> */
    public static function actionableFrontierCases(): array
    {
        return [
            'drained' => [0, true],
            'still actionable' => [2, false],
        ];
    }

    public function test_aggregate_group_progress_does_not_close_a_current_observation_without_exact_nzbs(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 61, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.next', backfillCursor: 30_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 1,
            'releases' => 1,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 0,
            'non_target' => 0,
            'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldNotReceive('revokePermit');
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertNotContains('backfill_permit_effective', $result['reasons']);
        self::assertNotNull($store->permitObservation());
        self::assertSame([], $store->backfillYieldHistory());
    }

    public function test_timeout_uses_exact_cohort_nzbs_instead_of_aggregate_group_progress(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 1201, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.next', backfillCursor: 30_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 1,
            'releases' => 1,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 0,
            'non_target' => 0,
            'uncategorized' => 0,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);
        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('snapshot')->once()->with(['backfill'])->andReturn([
            'available' => false,
            'workers' => [],
        ]);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier, $telemetry))->runOnce(false);

        self::assertNotContains('backfill_permit_ineffective', $result['reasons']);
        self::assertNotNull($store->permitObservation());
        self::assertSame([], $store->backfillYieldHistory());
    }

    #[DataProvider('expiredFinishedWorkerCases')]
    public function test_an_expired_claimed_permit_only_closes_after_finished_worker_quality_is_settled(
        int $uncategorizedNzbs,
        bool $closes,
    ): void {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
        ]);
        $issuedAt = time() - 1201;
        $store = new WorkerControlStateStore;
        $store->storeState(new ControlState(
            profile: ControlProfile::Fill,
            consecutiveIneffectiveBackfillPermits: 1,
            ineffectiveBackfillPermitsByTarget: ['alt.test' => 1],
        ));
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: $issuedAt, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.next', backfillCursor: 30_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $snapshots->shouldReceive('backfillCreatedNzbCategoryCountsForCohort')->once()->andReturn([
            'target' => 0,
            'non_target' => 0,
            'uncategorized' => $uncategorizedNzbs,
        ]);
        $snapshots->shouldReceive('backfillCreatedReleasesForCohort')->once()->andReturn(0);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);
        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $finishedTelemetry = [
            'available' => true,
            'workers' => ['backfill' => [
                'last_started_timestamp_seconds' => $issuedAt + 10,
                'last_completed_timestamp_seconds' => $issuedAt + 20,
                'last_success_timestamp_seconds' => $issuedAt - 10,
                'in_progress' => false,
            ]],
        ];
        $closes
            ? $telemetry->shouldReceive('snapshot')->once()->with(['backfill'])->andReturn($finishedTelemetry)
            : $telemetry->shouldNotReceive('snapshot');

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier, $telemetry))->runOnce(false);

        $closes
            ? self::assertContains('backfill_permit_abandoned_after_worker_exit', $result['reasons'])
            : self::assertNotContains('backfill_permit_abandoned_after_worker_exit', $result['reasons']);
        self::assertNotContains('backfill_permit_ineffective', $result['reasons']);
        self::assertNotContains('backfill_permit_no_input', $result['reasons']);
        $closes
            ? self::assertNull($store->permitObservation())
            : self::assertNotNull($store->permitObservation());
        self::assertSame([], $store->backfillYieldHistory());
        self::assertSame(1, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.test'] ?? null);
        self::assertFalse($result['permit_granted']);
    }

    /** @return array<string, array{int, bool}> */
    public static function expiredFinishedWorkerCases(): array
    {
        return [
            'settled zero output' => [0, true],
            'uncategorized quality hold' => [1, false],
        ];
    }

    public function test_explicit_grant_never_replaces_an_existing_expired_observation_in_the_same_cycle(): void
    {
        config([
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
        ]);
        Cache::store('array')->flush();
        $store = new WorkerControlStateStore;
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 1201, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($baseline);
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldNotReceive('apply');
        $applier->shouldNotReceive('revokePermit');
        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldNotReceive('snapshot');

        $result = (new WorkerOrchestrator(
            $snapshots,
            new WorkerControlPolicy,
            $store,
            $applier,
            $telemetry,
        ))->runOnce(false, true);

        self::assertSame('backfill_permit_observation_in_progress', $result['reason']);
        self::assertNotNull($store->permitObservation());
    }

    #[DataProvider('finishedBackfillRunEvidenceCases')]
    public function test_finished_backfill_run_evidence_fails_closed_for_ambiguous_telemetry(
        array $telemetrySnapshot,
        bool $expected,
    ): void {
        $telemetry = Mockery::mock(DistributedWorkerTelemetry::class);
        $telemetry->shouldReceive('snapshot')->once()->with(['backfill'])->andReturn($telemetrySnapshot);
        $orchestrator = new WorkerOrchestrator(
            Mockery::mock(PipelineSnapshotRepository::class),
            new WorkerControlPolicy,
            Mockery::mock(WorkerControlStateStore::class),
            Mockery::mock(WorkerProfileApplier::class),
            $telemetry,
        );
        $method = new ReflectionMethod($orchestrator, 'backfillRunFinishedAfterPermitIssue');

        self::assertSame($expected, $method->invoke($orchestrator, ['issued_at' => 1_000]));
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function finishedBackfillRunEvidenceCases(): array
    {
        $worker = static fn (
            float $started,
            float $completed,
            bool $inProgress = false,
            float $lastSuccess = 0,
        ): array => [
            'last_started_timestamp_seconds' => $started,
            'last_completed_timestamp_seconds' => $completed,
            'last_success_timestamp_seconds' => $lastSuccess,
            'in_progress' => $inProgress,
        ];

        return [
            'telemetry unavailable' => [['available' => false, 'workers' => []], false],
            'worker missing' => [['available' => true, 'workers' => []], false],
            'worker still running' => [['available' => true, 'workers' => ['backfill' => $worker(1_010, 0, true)]], false],
            'run started before permit' => [['available' => true, 'workers' => ['backfill' => $worker(999, 1_020)]], false],
            'completion predates start' => [['available' => true, 'workers' => ['backfill' => $worker(1_020, 1_019)]], false],
            'successful run awaiting acknowledgement' => [['available' => true, 'workers' => ['backfill' => $worker(1_010, 1_020, false, 1_020)]], false],
            'finished matching run' => [['available' => true, 'workers' => ['backfill' => $worker(1_010, 1_020)]], true],
        ];
    }

    public function test_a_completed_no_input_permit_closes_early_without_consuming_a_strike(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
            ['name' => 'orchestrator_bf_completed', 'value' => '7'],
        ]);
        $store = new WorkerControlStateStore;
        $store->markBackfillContextRepeat('alt.test', time() - 30);
        $baseline = new PipelineSnapshot(1, 2, 3, 4, 5, backfillGroup: 'alt.test', backfillCursor: 20_000);
        $store->beginPermitObservation($baseline, generation: 7, now: time() - 61, outcome: [
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
            'release_high_watermark' => 100,
        ]);
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.next', backfillCursor: 30_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 20_000,
            'cursor_postdate' => '2026-01-02 03:04:05',
            'ready_collections' => 0,
            'releases' => 0,
        ]);
        $snapshots->shouldNotReceive('backfillCreatedNzbCategoryCountsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_no_input', $result['reasons']);
        self::assertNull($store->permitObservation());
        self::assertSame(1, $store->backfillYieldHistory()['alt.test']['attempts']);
        self::assertSame(0, $store->loadState()->ineffectiveBackfillPermitsByTarget['alt.test'] ?? 0);
        self::assertFalse($result['permit_granted']);
        self::assertSame('alt.test', $store->backfillContextRepeat(time())['group'] ?? null);
    }

    public function test_a_claimed_legacy_observation_closes_without_recording_yield(): void
    {
        config([
            'nntmux.orchestrator.auto_backfill' => false,
            'nntmux.orchestrator.permit_observation_seconds' => 1200,
            'nntmux.orchestrator.state_store' => 'array',
            'nntmux.orchestrator.lock_store' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        Cache::store('array')->flush();
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert([
            ['name' => 'orchestrator_bf_permit', 'value' => '0'],
            ['name' => 'orchestrator_bf_claimed', 'value' => '7'],
        ]);
        Cache::store('array')->forever('nntmux:orchestrator:permit-observation', [
            'generation' => 7,
            'issued_at' => time() - 1201,
            'parts' => 1,
            'binaries' => 2,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 20_000,
        ]);
        $store = new WorkerControlStateStore;
        $current = new PipelineSnapshot(1, 2, 3, 4, 5, eligibleBackfillSupply: true, backfillGroup: 'alt.next', backfillCursor: 30_000);
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($current);
        $snapshots->shouldReceive('backfillOutcomeForGroup')->once()->with('alt.test')->andReturn([
            'cursor' => 10_000,
            'cursor_postdate' => '2026-01-01 03:04:05',
            'ready_collections' => 1,
            'releases' => 1,
            'release_high_watermark' => 101,
        ]);
        $snapshots->shouldNotReceive('backfillCreatedNzbCategoryCountsForCohort');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('revokePermit')->once();
        $applier->shouldReceive('apply')->once()->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains('backfill_permit_effective', $result['reasons']);
        self::assertNull($store->permitObservation());
        self::assertSame([], $store->backfillYieldHistory());
    }

    #[DataProvider('softSupplyClaimGraceCases')]
    public function test_a_soft_supply_gate_preserves_an_unclaimed_permit_during_claim_grace(
        bool $providerAvailable,
        bool $eligibleBackfillSupply,
        string $expectedReason,
    ): void {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.permit_claim_grace_seconds' => 120,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '7']);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            providerAvailable: $providerAvailable,
            cursorAvailable: $providerAvailable,
            eligibleBackfillSupply: $eligibleBackfillSupply,
            backfillGroup: $providerAvailable ? 'alt.test' : '',
            backfillCursor: $providerAvailable ? 100 : 0,
            backfillSafeQuantity: $providerAvailable ? 10_000 : 0,
            backfillPermitHandoffSafe: true,
        );
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturn([
            'generation' => 7,
            'issued_at' => time() - 60,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 100,
        ]);
        $store->shouldReceive('loadState')->once()->andReturn(new ControlState(profile: ControlProfile::Fill));
        $store->shouldReceive('storeState')->once();
        $store->shouldReceive('storeSnapshot')->once();
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldNotReceive('backfillOutcomeForGroup');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->with(
            Mockery::on(static fn (ControlDecision $decision): bool => ! $decision->backfillPermitted),
            Mockery::type('int'),
            false,
            $providerAvailable ? 'alt.test' : '',
            true,
        )->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertContains($expectedReason, $result['reasons']);
        self::assertContains('backfill_permit_claim_grace', $result['reasons']);
        self::assertFalse($result['permit_granted']);
    }

    /** @return array<string, array{bool, bool, string}> */
    public static function softSupplyClaimGraceCases(): array
    {
        return [
            'no eligible supply' => [true, false, 'backfill_no_eligible_supply'],
            'provider unavailable after issue' => [false, false, 'backfill_provider_unavailable'],
        ];
    }

    #[DataProvider('claimGraceRevocationCases')]
    public function test_claim_grace_never_overrides_hard_safety_or_the_exact_expiry(
        int $ageSeconds,
        bool $databaseMemorySafe,
        bool $providerAvailable = true,
        bool $cursorAvailable = true,
        bool $currentGroupsAvailable = true,
        int $backfillSafeQuantity = 10_000,
        bool $highPressure = false,
        int $databaseCurrentWaits = 0,
        bool $backfillPermitHandoffSafe = false,
    ): void {
        config([
            'nntmux.orchestrator.auto_backfill' => true,
            'nntmux.orchestrator.permit_claim_grace_seconds' => 120,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value');
        });
        DB::table('settings')->insert(['name' => 'orchestrator_bf_permit', 'value' => '7']);
        $snapshot = new PipelineSnapshot(
            1,
            2,
            3,
            4,
            5,
            databaseMemorySafe: $databaseMemorySafe,
            highPressure: $highPressure,
            providerAvailable: $providerAvailable,
            cursorAvailable: $cursorAvailable,
            currentGroupsAvailable: $currentGroupsAvailable,
            eligibleBackfillSupply: false,
            databaseCurrentWaits: $databaseCurrentWaits,
            backfillGroup: 'alt.test',
            backfillCursor: 100,
            backfillSafeQuantity: $backfillSafeQuantity,
            backfillPermitHandoffSafe: $backfillPermitHandoffSafe,
        );
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once();
        $store = Mockery::mock(WorkerControlStateStore::class)->shouldIgnoreMissing();
        $store->shouldReceive('leaderLock')->once()->andReturn($lock);
        $store->shouldReceive('previousSnapshot')->once()->andReturnNull();
        $store->shouldReceive('permitObservation')->once()->andReturn([
            'generation' => 7,
            'issued_at' => time() - $ageSeconds,
            'ready_collections' => 0,
            'release_total' => 0,
            'backfill_group' => 'alt.test',
            'backfill_cursor' => 100,
        ]);
        $store->shouldReceive('loadState')->once()->andReturn(new ControlState(profile: ControlProfile::Fill));
        $store->shouldReceive('storeState')->once();
        $store->shouldReceive('storeSnapshot')->once();
        $store->shouldReceive('storeDecision')->once();
        $snapshots = Mockery::mock(PipelineSnapshotRepository::class);
        $snapshots->shouldReceive('capture')->once()->andReturn($snapshot);
        $snapshots->shouldNotReceive('backfillOutcomeForGroup');
        $applier = Mockery::mock(WorkerProfileApplier::class);
        $applier->shouldReceive('apply')->once()->with(
            Mockery::type(ControlDecision::class),
            Mockery::type('int'),
            false,
            'alt.test',
            false,
        )->andReturn(8);

        $result = (new WorkerOrchestrator($snapshots, new WorkerControlPolicy, $store, $applier))->runOnce(false);

        self::assertNotContains('backfill_permit_claim_grace', $result['reasons']);
    }

    /** @return array<string, array{int, bool, bool?, bool?, bool?, int?, bool?, int?, bool?}> */
    public static function claimGraceRevocationCases(): array
    {
        return [
            'hard database safety failure during grace' => [60, false],
            'soft denial at exact grace expiry' => [120, true],
            'an exhausted selected cursor is not a soft supply denial' => [60, true, true, false],
            'provider unavailable cannot mask missing current groups' => [60, true, false, true, false],
            'provider unavailable cannot mask an unsafe pinned target' => [60, true, false, false, true, 0],
            'a safe selected target cannot substitute for the pinned target' => [60, true, true, true, true, 10_000],
            'insufficient selected target capacity is not a soft supply denial' => [60, true, true, true, true, 9_999],
            'provider unavailable cannot mask high pressure' => [60, true, false, true, true, 10_000, true],
            'provider unavailable cannot mask current database waits' => [60, true, false, true, true, 10_000, false, 1],
        ];
    }
}
