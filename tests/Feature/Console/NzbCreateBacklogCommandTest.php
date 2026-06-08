<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseProcessingService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class NzbCreateBacklogCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        $this->createTables();
        $this->seedSettings();
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
        ])->assertSuccessful();

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

    /**
     * @param  callable(Release): bool  $callback
     */
    private function bindNzbWriter(callable $callback): NzbService
    {
        $nzb = Mockery::mock(NzbService::class);
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
