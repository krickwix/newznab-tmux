<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Release;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Nzb\NzbBacklogCreationService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseProcessingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

final class NzbCreateBacklogCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'nntmux-nzb-backlog-test-');
        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('innerfileblacklist', ''),
            ('showpasswordedrelease', '0')");

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
            'nntmux.echocli' => false,
        ]);
        DB::disconnect();
        DB::purge();
        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }
        touch($this->databasePath);
        DB::reconnect();

        $this->createTables();
        $this->seedSettings();
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        DB::purge();

        if (isset($this->databasePath) && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_command_processes_only_matching_group_partition_with_payload_and_limit(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->seedRelease(2, groupId: 1, leftGuid: 'a');
        $this->seedRelease(3, groupId: 1, leftGuid: 'a');
        $this->seedRelease(4, groupId: 1, leftGuid: 'b');
        $this->seedRelease(5, groupId: 2, leftGuid: 'a');
        $this->seedRelease(6, groupId: 1, leftGuid: 'a', nzbStatus: NzbService::NZB_ADDED);
        $this->seedRelease(7, groupId: 1, leftGuid: 'a', withPayload: false);

        $writtenIds = [];
        $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;
            DB::table('releases')->where('id', $release->id)->update(['nzbstatus' => NzbService::NZB_ADDED]);

            return true;
        });

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'a',
            '--limit' => 2,
            '--sleep' => 0,
        ])
            ->expectsOutputToContain('selected=2 scanned=2 scan_exhausted=no')
            ->assertSuccessful();

        $this->assertSame([1, 2], $writtenIds);
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 3)->value('nzbstatus'));
    }

    public function test_command_loop_drains_until_no_nzbs_are_created(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->seedRelease(2, groupId: 1, leftGuid: 'a');
        $this->seedRelease(3, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;
            DB::table('releases')->where('id', $release->id)->update(['nzbstatus' => NzbService::NZB_ADDED]);

            return true;
        });

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'a',
            '--limit' => 2,
            '--loop' => true,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->assertSame([1, 2, 3], $writtenIds);
        $this->assertSame(0, DB::table('releases')->where('nzbstatus', NzbService::NZB_NONE)->count());
    }

    public function test_command_records_bounded_selector_and_nzb_item_telemetry(): void
    {
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->bindNzbWriter(static function (Release $release): bool {
            DB::table('releases')->where('id', $release->id)->update(['nzbstatus' => NzbService::NZB_ADDED]);

            return true;
        });

        $this->artisan('nntmux:nzb-create-backlog', ['--limit' => 1])->assertSuccessful();

        $snapshot = app(DistributedWorkerTelemetry::class)->snapshot(['nzb-backlog']);
        $items = $snapshot['workers']['nzb-backlog']['items']['nzb'];

        $this->assertSame(1, $items['scanned']);
        $this->assertSame(1, $items['selected']);
        $this->assertSame(1, $items['attempted']);
        $this->assertSame(1, $items['created']);
        $this->assertSame(0, $items['failed']);
        $this->assertSame(0, $items['marked_failed']);
        $this->assertSame(0, $items['scan_exhausted']);
        $this->assertGreaterThanOrEqual(0.0, $snapshot['nzb_selector_last_duration_seconds']);
    }

    public function test_command_marks_failed_releases_when_requested(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');

        $this->bindNzbWriter(static fn (): bool => false);

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'a',
            '--mark-failed' => true,
        ])->assertSuccessful();

        $this->assertSame(NzbService::NZB_FAILED, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_command_does_not_mark_failed_when_payload_disappears_before_failure_update(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');

        $this->bindNzbWriter(static function (): bool {
            DB::table('parts')->delete();

            return false;
        });

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'a',
            '--mark-failed' => true,
        ])->assertSuccessful();

        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_command_loop_continues_after_marking_failed_rows(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->seedRelease(2, groupId: 1, leftGuid: 'a');

        $this->bindNzbWriter(static fn (): bool => false);

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'a',
            '--limit' => 1,
            '--loop' => true,
            '--mark-failed' => true,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->assertSame(2, DB::table('releases')->where('nzbstatus', NzbService::NZB_FAILED)->count());
    }

    public function test_command_skips_incomplete_pending_releases_before_applying_limit(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a', currentParts: 0, totalParts: 1);
        $this->seedRelease(2, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;
            DB::table('releases')->where('id', $release->id)->update(['nzbstatus' => NzbService::NZB_ADDED]);

            return true;
        });

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'a',
            '--limit' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->assertSame([2], $writtenIds);
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_command_honors_completion_percent_when_selecting_nzb_backlog(): void
    {
        DB::table('settings')->where('name', 'completionpercent')->update(['value' => '94']);

        $this->seedRelease(1, groupId: 1, leftGuid: 'a', currentParts: 94, totalParts: 100);
        $this->seedRelease(2, groupId: 1, leftGuid: 'a', currentParts: 93, totalParts: 100);
        $this->seedRelease(3, groupId: 1, leftGuid: 'a', currentParts: 100, totalParts: 100);

        $writtenIds = [];
        $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;
            DB::table('releases')->where('id', $release->id)->update(['nzbstatus' => NzbService::NZB_ADDED]);

            return true;
        });

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'a',
            '--limit' => 10,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->assertSame([1, 3], $writtenIds);
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 2)->value('nzbstatus'));
    }

    public function test_command_selection_requires_parts_table_payload(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a', withParts: false);

        $this->bindNzbWriter(static function (): bool {
            self::fail('NZB writer should not be called without parts payload.');
        });

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'a',
            '--limit' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_command_skips_release_when_any_collection_binary_has_no_parts(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        DB::table('binaries')->insert([
            'id' => 101,
            'name' => 'Release 1 sibling yEnc',
            'collections_id' => 10,
            'currentparts' => 1,
            'totalparts' => 1,
        ]);
        $this->seedRelease(2, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;
            DB::table('releases')->where('id', $release->id)->update(['nzbstatus' => NzbService::NZB_ADDED]);

            return true;
        });

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'a',
            '--limit' => 1,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->assertSame([2], $writtenIds);
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_pending_id_scan_does_not_join_or_subquery_payload_tables(): void
    {
        /** @var NzbService&MockInterface $nzb */
        $nzb = Mockery::mock(NzbService::class);
        $service = new NzbBacklogCreationService($nzb);
        $method = new ReflectionMethod($service, 'pendingIdQuery');
        $method->setAccessible(true);

        $query = $method->invoke($service);
        $sql = strtolower($query->toSql());

        $this->assertStringContainsString('indexed by ix_releases_nzbstatus_id', $sql);
        $this->assertStringContainsString('where "nzbstatus" = ?', $sql);
        $this->assertStringNotContainsString('collections', $sql);
        $this->assertStringNotContainsString('binaries', $sql);
        $this->assertStringNotContainsString('parts', $sql);
        $this->assertStringNotContainsString('select count', $sql);
        $this->assertStringNotContainsString('exists (', $sql);
    }

    public function test_eligibility_query_is_bounded_to_one_explicit_id_chunk(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');

        /** @var NzbService&MockInterface $nzb */
        $nzb = Mockery::mock(NzbService::class);
        $service = new NzbBacklogCreationService($nzb);
        $method = new ReflectionMethod($service, 'eligibleReleasesByIds');
        $method->setAccessible(true);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $releases = $method->invoke($service, [1], 100);

        $this->assertCount(1, $releases);
        $this->assertInstanceOf(Release::class, $releases->first());
        $eligibilitySql = collect($queries)->first(
            static fn (string $sql): bool => str_contains($sql, 'from "releases"')
                && str_contains($sql, 'collections')
        );
        $this->assertIsString($eligibilitySql);
        $this->assertStringContainsString('"releases"."id" in (?)', $eligibilitySql);
    }

    public function test_sparse_eligibility_scan_uses_bounded_chunks_instead_of_one_query_per_id(): void
    {
        foreach (range(1, 100) as $id) {
            $this->seedRelease($id, groupId: 1, leftGuid: 'a', withPayload: false);
        }
        $this->seedRelease(101, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $nzb = $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;

            return true;
        });

        $eligibilityQueries = [];
        DB::listen(static function ($query) use (&$eligibilityQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "releases"') && str_contains($sql, 'collections')) {
                $eligibilityQueries[] = $sql;
            }
        });

        $service = new NzbBacklogCreationService($nzb);
        $result = $service->create(limit: 1, scanCap: 101);

        $this->assertSame([101], $writtenIds);
        $this->assertSame(101, $result['scanned']);
        $this->assertCount(2, $eligibilityQueries);
        $this->assertStringContainsString('"releases"."id" in (', $eligibilityQueries[0]);
        $this->assertStringContainsString('"releases"."id" in (?)', $eligibilityQueries[1]);
    }

    public function test_candidate_scan_cap_limits_the_number_of_pending_ids_considered(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->seedRelease(2, groupId: 1, leftGuid: 'a');
        $this->seedRelease(3, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $nzb = $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;

            return true;
        });

        $service = new NzbBacklogCreationService($nzb);
        $result = $service->create(limit: 3, scanCap: 2);

        $this->assertSame([1, 2], $writtenIds);
        $this->assertSame(2, $result['selected']);
        $this->assertSame(2, $result['attempted']);
        $this->assertSame(2, $result['scanned']);
        $this->assertTrue($result['scan_exhausted']);
        $this->assertIsFloat($result['selection_duration_seconds']);
        $this->assertGreaterThanOrEqual(0.0, $result['selection_duration_seconds']);
    }

    public function test_scan_is_not_exhausted_when_pending_rows_exactly_equal_cap(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->seedRelease(2, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $nzb = $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;

            return true;
        });

        $service = new NzbBacklogCreationService($nzb);
        $result = $service->create(limit: 3, scanCap: 2);

        $this->assertSame([1, 2], $writtenIds);
        $this->assertSame(2, $result['scanned']);
        $this->assertFalse($result['scan_exhausted']);
    }

    public function test_configured_scan_cap_reduces_default_window_and_reports_exhaustion(): void
    {
        config(['nntmux.distributed_nzb_scan_cap' => 2]);
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->seedRelease(2, groupId: 1, leftGuid: 'a');
        $this->seedRelease(3, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $nzb = $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;

            return true;
        });

        $service = new NzbBacklogCreationService($nzb);
        $result = $service->create(limit: 3);

        $this->assertSame([1, 2], $writtenIds);
        $this->assertSame(2, $result['scanned']);
        $this->assertTrue($result['scan_exhausted']);
    }

    public function test_default_scan_uses_full_configured_cap_to_reach_later_eligible_release(): void
    {
        config(['nntmux.distributed_nzb_scan_cap' => 5000]);
        foreach (range(1, 100) as $id) {
            $this->seedRelease($id, groupId: 1, leftGuid: 'a', withPayload: false);
        }
        $this->seedRelease(101, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $nzb = $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;

            return true;
        });

        $service = new NzbBacklogCreationService($nzb);
        $result = $service->create(limit: 1);

        $this->assertSame([101], $writtenIds);
        $this->assertSame(101, $result['scanned']);
        $this->assertFalse($result['scan_exhausted']);
    }

    public function test_ineligible_pending_id_does_not_starve_later_eligible_id_within_scan_cap(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a', currentParts: 0, totalParts: 1);
        $this->seedRelease(2, groupId: 1, leftGuid: 'a');
        $this->seedRelease(3, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $nzb = $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;

            return true;
        });

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $service = new NzbBacklogCreationService($nzb);
        $result = $service->create(limit: 1, scanCap: 2);

        $this->assertSame([2], $writtenIds);
        $this->assertSame(1, $result['selected']);
        $this->assertSame(2, $result['scanned']);
        $this->assertFalse($result['scan_exhausted']);

        $firstReleaseQuery = collect($queries)->first(
            static fn (string $sql): bool => str_contains($sql, 'from "releases"')
        );
        $this->assertIsString($firstReleaseQuery);
        $this->assertStringNotContainsString('collections', $firstReleaseQuery);
        $this->assertStringNotContainsString('binaries', $firstReleaseQuery);
        $this->assertStringNotContainsString('parts', $firstReleaseQuery);
    }

    public function test_candidate_count_is_exact_within_the_bounded_scan_window(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->seedRelease(2, groupId: 1, leftGuid: 'a', currentParts: 0, totalParts: 1);
        $this->seedRelease(3, groupId: 1, leftGuid: 'a');

        $writtenIds = [];
        $nzb = $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;

            return true;
        });

        $service = new NzbBacklogCreationService($nzb);
        $result = $service->create(
            limit: 1,
            order: 'desc',
            countCandidates: true,
            scanCap: 3
        );

        $this->assertSame([3], $writtenIds);
        $this->assertSame(2, $result['candidate_total']);
        $this->assertSame(1, $result['selected']);
        $this->assertSame(1, $result['attempted']);
    }

    public function test_selected_release_keeps_category_tree_eager_loaded(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');

        $nzb = $this->bindNzbWriter(static function (Release $release): bool {
            self::assertTrue($release->relationLoaded('category'));
            self::assertTrue($release->category->relationLoaded('parent'));

            return true;
        });

        $service = new NzbBacklogCreationService($nzb);
        $service->create(limit: 1, scanCap: 1);
    }

    public function test_group_partition_switches_to_the_partition_covering_index(): void
    {
        /** @var NzbService&MockInterface $nzb */
        $nzb = Mockery::mock(NzbService::class);
        $service = new NzbBacklogCreationService($nzb);
        $baseMethod = new ReflectionMethod($service, 'pendingIdQuery');
        $baseMethod->setAccessible(true);
        $groupMethod = new ReflectionMethod($service, 'applyGroupFilter');
        $groupMethod->setAccessible(true);

        $query = $baseMethod->invoke($service);
        $groupMethod->invoke($service, $query, [1]);

        $this->assertStringContainsString(
            'indexed by ix_releases_nzb_backlog_partition',
            $query->toSql()
        );
    }

    public function test_command_does_not_count_the_full_eligible_backlog_before_a_bounded_pass(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->bindNzbWriter(static function (Release $release): bool {
            DB::table('releases')->where('id', $release->id)->update(['nzbstatus' => NzbService::NZB_ADDED]);

            return true;
        });

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->artisan('nntmux:nzb-create-backlog', ['--limit' => 1])->assertSuccessful();

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'count(*) as "aggregate"')
        ));
    }

    public function test_command_rejects_invalid_leftguid_partition(): void
    {
        $this->bindNzbWriter(static function (): bool {
            self::fail('NZB writer should not be called for invalid partitions.');
        });

        $this->artisan('nntmux:nzb-create-backlog', [
            '--groups' => 'alt.binaries.boneless',
            '--leftguid' => 'z',
        ])->assertExitCode(1);
    }

    public function test_release_processing_service_uses_bounded_nzb_backlog_selection(): void
    {
        DB::table('settings')->where('name', 'maxnzbsprocessed')->update(['value' => '2']);
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        $this->seedRelease(2, groupId: 1, leftGuid: 'b');
        $this->seedRelease(3, groupId: 1, leftGuid: 'c');

        $writtenIds = [];
        $nzb = $this->bindNzbWriter(static function (Release $release) use (&$writtenIds): bool {
            $writtenIds[] = (int) $release->id;
            DB::table('releases')->where('id', $release->id)->update(['nzbstatus' => NzbService::NZB_ADDED]);

            return true;
        });

        $service = new ReleaseProcessingService(nzb: $nzb);
        $service->setEchoCLI(false);

        $this->assertSame(2, $service->createNZBs(1));
        $this->assertSame([1, 2], $writtenIds);
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 3)->value('nzbstatus'));
    }

    public function test_release_processing_service_can_skip_inline_nzb_backlog_work(): void
    {
        config(['nntmux.inline_nzb_creation' => false]);
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');

        $nzb = $this->bindNzbWriter(static function (): bool {
            self::fail('Inline NZB creation must stay off the release-formation lane.');
        });

        $service = new ReleaseProcessingService(nzb: $nzb);
        $service->setEchoCLI(false);

        $this->assertSame(0, $service->createNZBsIfEnabled(1));
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_main_release_creation_loop_honors_inline_nzb_disable_flag(): void
    {
        $method = new ReflectionMethod(ReleaseProcessingService::class, 'runReleaseCreationLoop');
        $sourceLines = file($method->getFileName());

        $this->assertIsArray($sourceLines);
        $source = implode('', array_slice(
            $sourceLines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertStringContainsString('$this->createNZBsIfEnabled(', $source);
        $this->assertStringNotContainsString('$this->createNZBs($normalizedGroupId)', $source);
    }

    public function test_stuck_collection_cleanup_preserves_payload_for_release_waiting_on_nzb(): void
    {
        $this->seedRelease(1, groupId: 1, leftGuid: 'a');
        DB::table('collections')->where('releases_id', 1)->update([
            'added' => now()->subHours(72)->format('Y-m-d H:i:s'),
        ]);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);

        $method = new ReflectionMethod($service, 'deleteStuckCollectionBatch');
        $method->setAccessible(true);
        $deleted = $method->invoke($service, 1, now());

        $this->assertSame(0, $deleted);
        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    /**
     * @param  callable(Release): bool  $callback
     */
    private function bindNzbWriter(callable $callback): NzbService
    {
        /** @var NzbService&MockInterface $nzb */
        $nzb = Mockery::mock(NzbService::class);
        // @phpstan-ignore-next-line Mockery fluent expectations expose zeroOrMoreTimes() dynamically.
        $nzb->shouldReceive('writeNzbForReleaseId')->zeroOrMoreTimes()->andReturnUsing($callback);
        $this->app->instance(NzbService::class, $nzb);

        return $nzb;
    }

    private function seedRelease(
        int $id,
        int $groupId,
        string $leftGuid,
        int $nzbStatus = NzbService::NZB_NONE,
        bool $withPayload = true,
        bool $withParts = true,
        int $currentParts = 1,
        int $totalParts = 1
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => 'Release '.$id,
            'searchname' => 'Release '.$id,
            'totalpart' => 1,
            'groups_id' => $groupId,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat($leftGuid, 32).str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'leftguid' => $leftGuid,
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 100,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => $nzbStatus,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        if (! $withPayload) {
            return;
        }

        DB::table('collections')->insert([
            'id' => $id * 10,
            'subject' => 'Release '.$id,
            'fromname' => 'poster@example.com',
            'date' => now()->format('Y-m-d H:i:s'),
            'dateadded' => now()->format('Y-m-d H:i:s'),
            'added' => now()->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:'.$id,
            'groups_id' => $groupId,
            'totalfiles' => 1,
            'filesize' => 100,
            'filecheck' => 1,
            'collectionhash' => 'collection-'.$id,
            'collection_regexes_id' => 0,
            'releases_id' => $id,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => $id * 100,
            'name' => 'Release '.$id.' yEnc',
            'collections_id' => $id * 10,
            'currentparts' => $currentParts,
            'totalparts' => $totalParts,
        ]);
        if (! $withParts) {
            return;
        }

        DB::table('parts')->insert([
            'binaries_id' => $id * 100,
            'number' => $id,
            'messageid' => '<'.$id.'@example.com>',
            'partnumber' => 1,
            'size' => 100,
        ]);
    }

    private function seedSettings(): void
    {
        foreach ([
            'maxnzbsprocessed' => '1000',
            'delaytime' => '2',
            'crossposttime' => '2',
            'completionpercent' => '0',
            'collection_timeout' => '48',
            'maxsizetoformrelease' => '0',
            'minsizetoformrelease' => '0',
            'minfilestoformrelease' => '0',
            'releaseretentiondays' => '0',
            'deletepasswordedrelease' => '0',
            'miscotherretentionhours' => '0',
            'mischashedretentionhours' => '0',
            'partretentionhours' => '24',
            'last_run_time' => '',
        ] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
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

    private function createTables(): void
    {
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE categories (id INTEGER PRIMARY KEY, title VARCHAR(255), parent_categories_id INTEGER NULL)');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            searchname VARCHAR(255),
            totalpart INTEGER,
            groups_id INTEGER,
            adddate DATETIME NULL,
            guid VARCHAR(64),
            leftguid VARCHAR(1),
            postdate DATETIME NULL,
            fromname VARCHAR(255),
            size INTEGER,
            passwordstatus INTEGER,
            haspreview INTEGER,
            categories_id INTEGER,
            nfostatus INTEGER,
            nzbstatus INTEGER,
            isrenamed INTEGER,
            iscategorized INTEGER,
            predb_id INTEGER,
            source VARCHAR(255) NULL
        )');
        DB::statement('CREATE INDEX ix_releases_nzb_backlog_partition ON releases (nzbstatus, groups_id, leftguid, id)');
        DB::statement('CREATE INDEX ix_releases_nzbstatus_id ON releases (nzbstatus, id)');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            dateadded DATETIME NULL,
            added DATETIME NULL,
            xref TEXT,
            groups_id INTEGER,
            totalfiles INTEGER,
            filesize INTEGER,
            filecheck INTEGER,
            collectionhash VARCHAR(255),
            collection_regexes_id INTEGER,
            releases_id INTEGER NULL,
            noise VARCHAR(64)
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            collections_id INTEGER,
            currentparts INTEGER,
            totalparts INTEGER
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INTEGER,
            number INTEGER,
            messageid VARCHAR(255),
            partnumber INTEGER,
            size INTEGER
        )');

        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.boneless'],
            ['id' => 2, 'name' => 'a.b.boneless'],
        ]);
        DB::table('categories')->insert(['id' => 1, 'title' => 'Misc', 'parent_categories_id' => null]);
    }
}
