<?php

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Release;
use App\Services\CollectionCleanupService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\ReleaseDuplicateFinder;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

class CbpCleanupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));
        DB::connection()->getPdo()->sqliteCreateFunction(
            'REGEXP',
            static function (?string $subject, ?string $pattern): int {
                if ($subject === null || $pattern === null || $pattern === '') {
                    return 0;
                }
                set_error_handler(static fn (): true => true);
                $ok = @preg_match($pattern, $subject);
                restore_error_handler();

                return $ok ? 1 : 0;
            },
            2
        );

        $this->createTables();
        $this->seedSettings();
    }

    public function test_retention_cleanup_deletes_parts_binaries_and_collections_without_fk_cascades(): void
    {
        DB::table('collections')->insert([
            'id' => 100,
            'subject' => 'Retention.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:123',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'retention-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 1000,
            'name' => 'Retention.Release.par2',
            'collections_id' => 100,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 1000,
            'number' => 1,
            'messageid' => '<retention-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_retention_cleanup_preserves_payload_for_release_waiting_on_nzb(): void
    {
        DB::table('releases')->insert([
            'id' => 30,
            'name' => 'Pending.Nzb.Release',
            'searchname' => 'Pending.Nzb.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'guid' => str_repeat('a', 36),
            'leftguid' => 'a',
            'postdate' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);
        DB::table('collections')->insert([
            'id' => 101,
            'subject' => 'Pending.Nzb.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(10)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:101',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'pending-nzb-retention',
            'collection_regexes_id' => 0,
            'releases_id' => 30,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 1010,
            'name' => 'Pending.Nzb.Release.par2',
            'collections_id' => 101,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 1010,
            'number' => 1,
            'messageid' => '<pending-nzb-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);

        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 30)->value('nzbstatus'));
    }

    public function test_group_scoped_cleanup_deletes_only_requested_group_orphans(): void
    {
        DB::table('collections')->insert([
            [
                'id' => 110,
                'subject' => 'Scoped.Group.One.Orphan',
                'fromname' => 'poster@example.com',
                'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'xref' => 'alt.test:110',
                'groups_id' => 1,
                'totalfiles' => 1,
                'filesize' => 0,
                'filecheck' => CollectionFileCheckStatus::Default->value,
                'collectionhash' => 'scoped-orphan-group-1',
                'collection_regexes_id' => 0,
                'releases_id' => null,
                'noise' => '',
            ],
            [
                'id' => 120,
                'subject' => 'Scoped.Group.Two.Orphan',
                'fromname' => 'poster@example.com',
                'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
                'xref' => 'alt.other:120',
                'groups_id' => 2,
                'totalfiles' => 1,
                'filesize' => 0,
                'filecheck' => CollectionFileCheckStatus::Default->value,
                'collectionhash' => 'scoped-orphan-group-2',
                'collection_regexes_id' => 0,
                'releases_id' => null,
                'noise' => '',
            ],
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false, 1);

        $this->assertFalse(DB::table('collections')->where('id', 110)->exists());
        $this->assertTrue(DB::table('collections')->where('id', 120)->exists());
    }

    public function test_release_cleanup_resolves_group_name_scope(): void
    {
        $cleanup = new RecordingScopedCollectionCleanupService;

        $this->makeReleaseProcessingService($cleanup)->deleteCollections('alt.test');

        $this->assertSame(1, $cleanup->calls);
        $this->assertFalse($cleanup->lastEchoCli);
        $this->assertSame(1, $cleanup->lastGroupId);
    }

    public function test_group_scoped_cleanup_deletes_only_requested_group_retention_rows(): void
    {
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.other']);
        DB::table('collections')->insert([
            [
                'id' => 130,
                'subject' => 'Scoped.Group.One.Retention',
                'fromname' => 'poster@example.com',
                'date' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'dateadded' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'added' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'xref' => 'alt.test:130',
                'groups_id' => 1,
                'totalfiles' => 1,
                'filesize' => 500,
                'filecheck' => CollectionFileCheckStatus::Sized->value,
                'collectionhash' => 'scoped-retention-group-1',
                'collection_regexes_id' => 0,
                'releases_id' => null,
                'noise' => '',
            ],
            [
                'id' => 140,
                'subject' => 'Scoped.Group.Two.Retention',
                'fromname' => 'poster@example.com',
                'date' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'dateadded' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'added' => now()->subHours(10)->format('Y-m-d H:i:s'),
                'xref' => 'alt.other:140',
                'groups_id' => 2,
                'totalfiles' => 1,
                'filesize' => 500,
                'filecheck' => CollectionFileCheckStatus::Sized->value,
                'collectionhash' => 'scoped-retention-group-2',
                'collection_regexes_id' => 0,
                'releases_id' => null,
                'noise' => '',
            ],
        ]);
        DB::table('binaries')->insert([
            ['id' => 1300, 'name' => 'Scoped.Group.One.Retention.par2', 'collections_id' => 130, 'totalparts' => 1],
            ['id' => 1400, 'name' => 'Scoped.Group.Two.Retention.par2', 'collections_id' => 140, 'totalparts' => 1],
        ]);
        DB::table('parts')->insert([
            ['binaries_id' => 1300, 'number' => 1, 'messageid' => '<retention-scope-1@example.com>', 'partnumber' => 1, 'size' => 10],
            ['binaries_id' => 1400, 'number' => 1, 'messageid' => '<retention-scope-2@example.com>', 'partnumber' => 1, 'size' => 10],
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false, 1);

        $this->assertFalse(DB::table('collections')->where('id', 130)->exists());
        $this->assertFalse(DB::table('binaries')->where('id', 1300)->exists());
        $this->assertFalse(DB::table('parts')->where('binaries_id', 1300)->exists());
        $this->assertTrue(DB::table('collections')->where('id', 140)->exists());
        $this->assertTrue(DB::table('binaries')->where('id', 1400)->exists());
        $this->assertTrue(DB::table('parts')->where('binaries_id', 1400)->exists());
    }

    public function test_group_scoped_cleanup_deletes_only_requested_group_missed_nzb_rows(): void
    {
        DB::table('usenet_groups')->insert(['id' => 2, 'name' => 'alt.other']);
        DB::table('releases')->insert([
            [
                'id' => 40,
                'name' => 'Scoped.Group.One.Nzb.Done',
                'searchname' => 'Scoped.Group.One.Nzb.Done',
                'totalpart' => 1,
                'groups_id' => 1,
                'adddate' => now()->format('Y-m-d H:i:s'),
                'guid' => str_repeat('d', 36),
                'leftguid' => 'd',
                'postdate' => now()->format('Y-m-d H:i:s'),
                'fromname' => 'poster@example.com',
                'size' => 500,
                'passwordstatus' => 0,
                'haspreview' => -1,
                'categories_id' => 1,
                'nfostatus' => -1,
                'nzbstatus' => 1,
                'isrenamed' => 1,
                'iscategorized' => 1,
                'predb_id' => 0,
                'source' => null,
            ],
            [
                'id' => 41,
                'name' => 'Scoped.Group.Two.Nzb.Done',
                'searchname' => 'Scoped.Group.Two.Nzb.Done',
                'totalpart' => 1,
                'groups_id' => 2,
                'adddate' => now()->format('Y-m-d H:i:s'),
                'guid' => str_repeat('e', 36),
                'leftguid' => 'e',
                'postdate' => now()->format('Y-m-d H:i:s'),
                'fromname' => 'poster@example.com',
                'size' => 500,
                'passwordstatus' => 0,
                'haspreview' => -1,
                'categories_id' => 1,
                'nfostatus' => -1,
                'nzbstatus' => 1,
                'isrenamed' => 1,
                'iscategorized' => 1,
                'predb_id' => 0,
                'source' => null,
            ],
        ]);
        DB::table('collections')->insert([
            [
                'id' => 150,
                'subject' => 'Scoped.Group.One.Nzb.Done',
                'fromname' => 'poster@example.com',
                'date' => now()->format('Y-m-d H:i:s'),
                'dateadded' => now()->format('Y-m-d H:i:s'),
                'added' => now()->format('Y-m-d H:i:s'),
                'xref' => 'alt.test:150',
                'groups_id' => 1,
                'totalfiles' => 1,
                'filesize' => 500,
                'filecheck' => CollectionFileCheckStatus::Inserted->value,
                'collectionhash' => 'scoped-missed-nzb-group-1',
                'collection_regexes_id' => 0,
                'releases_id' => 40,
                'noise' => '',
            ],
            [
                'id' => 160,
                'subject' => 'Scoped.Group.Two.Nzb.Done',
                'fromname' => 'poster@example.com',
                'date' => now()->format('Y-m-d H:i:s'),
                'dateadded' => now()->format('Y-m-d H:i:s'),
                'added' => now()->format('Y-m-d H:i:s'),
                'xref' => 'alt.other:160',
                'groups_id' => 2,
                'totalfiles' => 1,
                'filesize' => 500,
                'filecheck' => CollectionFileCheckStatus::Inserted->value,
                'collectionhash' => 'scoped-missed-nzb-group-2',
                'collection_regexes_id' => 0,
                'releases_id' => 41,
                'noise' => '',
            ],
        ]);

        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false, 1);

        $this->assertFalse(DB::table('collections')->where('id', 150)->exists());
        $this->assertTrue(DB::table('collections')->where('id', 160)->exists());
    }

    public function test_nzb_creation_cleans_up_collection_binary_and_parts_explicitly(): void
    {
        DB::table('releases')->insert([
            'id' => 1,
            'name' => 'Nzb.Release',
            'searchname' => 'Nzb.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('a', 36),
            'leftguid' => 'a',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        DB::table('collections')->insert([
            'id' => 200,
            'subject' => 'Nzb.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:200',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Inserted->value,
            'collectionhash' => 'nzb-hash',
            'collection_regexes_id' => 0,
            'releases_id' => 1,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 2000,
            'name' => 'Nzb.Release yEnc',
            'collections_id' => 200,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 2000,
            'number' => 1,
            'messageid' => '<nzb-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $written = app(NzbService::class)->writeNzbForReleaseId($release);

        $this->assertTrue($written);
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(1, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_nzb_creation_uses_collection_group_when_xref_is_empty(): void
    {
        $guid = str_repeat('f', 36);
        DB::table('releases')->insert([
            'id' => 20,
            'name' => 'Empty.Xref.Release',
            'searchname' => 'Empty.Xref.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => $guid,
            'leftguid' => 'f',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);
        DB::table('collections')->insert([
            'id' => 201,
            'subject' => 'Empty.Xref.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => '',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Inserted->value,
            'collectionhash' => 'empty-xref-hash',
            'collection_regexes_id' => 0,
            'releases_id' => 20,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 2010,
            'name' => 'Empty.Xref.Release yEnc',
            'collections_id' => 201,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 2010,
            'number' => 1,
            'messageid' => '<empty-xref-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        $release = Release::query()->findOrFail(20);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $written = app(NzbService::class)->writeNzbForReleaseId($release);

        $this->assertTrue($written);
        $nzbPath = app(NzbService::class)->nzbPath($guid);
        $this->assertIsString($nzbPath);
        $this->assertStringContainsString('<group>alt.test</group>', (string) gzdecode((string) file_get_contents($nzbPath)));
        $this->assertSame(1, (int) DB::table('releases')->where('id', 20)->value('nzbstatus'));
    }

    public function test_release_creation_links_collection_group_when_xref_is_empty(): void
    {
        DB::table('collections')->insert([
            'id' => 202,
            'subject' => 'Source.Group.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => '',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'source-group-link-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);

        $service = new ReleaseCreationService(
            app(ReleaseCleaningService::class),
            app(CollectionCleanupService::class),
            app(ReleaseDuplicateFinder::class)
        );
        $result = $service->createReleases(null, 10, false);

        $releaseId = (int) DB::table('releases')->where('searchname', 'Source.Group.Release')->value('id');
        $this->assertSame(['added' => 1, 'dupes' => 0], $result);
        $this->assertGreaterThan(0, $releaseId);
        $this->assertTrue(DB::table('releases_groups')->where([
            'releases_id' => $releaseId,
            'groups_id' => 1,
        ])->exists());
    }

    public function test_release_creation_skips_unknown_xref_group_without_zero_group_link(): void
    {
        DB::table('collections')->insert([
            'id' => 203,
            'subject' => 'Unknown.Xref.Group.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'a.b.newgroup:203',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 500,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'unknown-xref-group-link-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);

        $service = new ReleaseCreationService(
            app(ReleaseCleaningService::class),
            app(CollectionCleanupService::class),
            app(ReleaseDuplicateFinder::class)
        );
        $result = $service->createReleases(null, 10, false);

        $releaseId = (int) DB::table('releases')->where('searchname', 'Unknown.Xref.Group.Release')->value('id');
        $this->assertSame(['added' => 1, 'dupes' => 0], $result);
        $this->assertGreaterThan(0, $releaseId);
        $this->assertFalse(DB::table('usenet_groups')->where('name', 'alt.binaries.newgroup')->exists());
        $this->assertSame(0, DB::table('releases_groups')->where('groups_id', 0)->count());
        $this->assertTrue(DB::table('releases_groups')->where([
            'releases_id' => $releaseId,
            'groups_id' => 1,
        ])->exists());
    }

    public function test_duplicate_release_path_cleans_up_collection_binary_and_parts(): void
    {
        DB::table('releases')->insert([
            'id' => 2,
            'name' => 'Duplicate.Release',
            'searchname' => 'Duplicate.Release',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('b', 36),
            'leftguid' => 'b',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster@example.com',
            'size' => 1000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        DB::table('collections')->insert([
            'id' => 300,
            'subject' => 'Duplicate.Release',
            'fromname' => 'poster@example.com',
            'date' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'dateadded' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'added' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'xref' => 'alt.test:300',
            'groups_id' => 1,
            'totalfiles' => 1,
            'filesize' => 1000,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
            'collectionhash' => 'duplicate-hash',
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
        DB::table('binaries')->insert([
            'id' => 3000,
            'name' => 'Duplicate.Release yEnc',
            'collections_id' => 300,
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => 3000,
            'number' => 1,
            'messageid' => '<duplicate-1@example.com>',
            'partnumber' => 1,
            'size' => 10,
        ]);

        $service = new ReleaseCreationService(
            app(ReleaseCleaningService::class),
            app(CollectionCleanupService::class),
            app(ReleaseDuplicateFinder::class)
        );
        $result = $service->createReleases(null, 10, false);

        $this->assertSame(['added' => 0, 'dupes' => 1], $result);
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('collections')->count());
    }

    public function test_release_duplicate_finder_matches_searchname_within_size_band(): void
    {
        DB::table('releases')->insert([
            'id' => 20,
            'name' => 'raw-obfuscated-a',
            'searchname' => 'Unified.Scene.S01E01.1080p',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('c', 36),
            'leftguid' => 'c',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'poster-a@example.com',
            'size' => 1_000_000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'raw-obfuscated-b',
            'Unified.Scene.S01E01.1080p',
            0,
            1_020_000
        );

        $this->assertNotNull($dup);
        $this->assertSame('searchname_match', $reason);
    }

    public function test_release_duplicate_finder_matches_predb_id_when_searchname_differs(): void
    {
        DB::table('releases')->insert([
            'id' => 21,
            'name' => 'old',
            'searchname' => 'Old Style Name',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('d', 36),
            'leftguid' => 'd',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'p@example.com',
            'size' => 2_000_000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 9001,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate(
            'new',
            'New Style Name',
            9001,
            2_050_000
        );

        $this->assertNotNull($dup);
        $this->assertSame('predb_id_match', $reason);
    }

    public function test_release_duplicate_finder_does_not_match_outside_size_tolerance(): void
    {
        config(['nntmux.release_dedupe_size_tolerance' => 0.05]);

        DB::table('releases')->insert([
            'id' => 22,
            'name' => 'x',
            'searchname' => 'Same.Search',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('e', 36),
            'leftguid' => 'e',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'p@example.com',
            'size' => 1_000_000,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup] = $finder->findDuplicate('x', 'Same.Search', 0, 1_200_000);

        $this->assertNull($dup);
    }

    public function test_release_duplicate_finder_falls_back_to_name_when_searchname_empty(): void
    {
        DB::table('releases')->insert([
            'id' => 23,
            'name' => 'fallback.unique',
            'searchname' => '',
            'totalpart' => 1,
            'groups_id' => 1,
            'adddate' => now()->format('Y-m-d H:i:s'),
            'guid' => str_repeat('f', 36),
            'leftguid' => 'f',
            'postdate' => now()->format('Y-m-d H:i:s'),
            'fromname' => 'p@example.com',
            'size' => 500,
            'passwordstatus' => 0,
            'haspreview' => -1,
            'categories_id' => 1,
            'nfostatus' => -1,
            'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 1,
            'iscategorized' => 1,
            'predb_id' => 0,
            'source' => null,
        ]);

        $finder = app(ReleaseDuplicateFinder::class);
        [$dup, $reason] = $finder->findDuplicate('fallback.unique', '', 0, 500);

        $this->assertNotNull($dup);
        $this->assertSame('name_match_fallback', $reason);
    }

    private function seedSettings(): void
    {
        $settings = [
            'partretentionhours' => '1',
            'nzbsplitlevel' => '1',
            'check_passworded_rars' => '0',
            'categorizeforeign' => '1',
            'catwebdl' => '1',
        ];

        foreach ($settings as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value]);
        }
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            description VARCHAR(255) NULL,
            backfill_target INTEGER DEFAULT 1,
            first_record INTEGER DEFAULT 0,
            last_record INTEGER DEFAULT 0,
            active INTEGER DEFAULT 0,
            backfill INTEGER DEFAULT 0,
            minsizetoformrelease VARCHAR(255) NULL,
            minfilestoformrelease VARCHAR(255) NULL
        )');
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
            totalparts INTEGER
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INTEGER,
            number INTEGER,
            messageid VARCHAR(255),
            partnumber INTEGER,
            size INTEGER
        )');
        DB::statement('CREATE TABLE release_naming_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INTEGER DEFAULT 1,
            ordinal INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE release_regexes (
            releases_id INTEGER,
            collection_regex_id INTEGER,
            naming_regex_id INTEGER,
            PRIMARY KEY (releases_id, collection_regex_id, naming_regex_id)
        )');
        DB::statement('CREATE TABLE releases_groups (
            releases_id INTEGER,
            groups_id INTEGER,
            PRIMARY KEY (releases_id, groups_id)
        )');
        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INTEGER DEFAULT 1,
            ordinal INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE predb (
            id INTEGER PRIMARY KEY,
            title VARCHAR(255),
            filename VARCHAR(255)
        )');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        DB::table('categories')->insert(['id' => 1, 'title' => 'Misc', 'parent_categories_id' => null]);
    }

    private function makeReleaseProcessingService(CollectionCleanupService $cleanup): ReleaseProcessingService
    {
        $reflection = new ReflectionClass(ReleaseProcessingService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $echoCli = $reflection->getProperty('echoCLI');
        $echoCli->setValue($service, false);

        $collectionCleanupService = $reflection->getProperty('collectionCleanupService');
        $collectionCleanupService->setValue($service, $cleanup);

        return $service;
    }
}

final class RecordingScopedCollectionCleanupService extends CollectionCleanupService
{
    public int $calls = 0;

    public ?bool $lastEchoCli = null;

    public ?int $lastGroupId = null;

    public function deleteFinishedAndOrphans(bool $echoCLI, ?int $groupId = null): int
    {
        $this->calls++;
        $this->lastEchoCli = $echoCLI;
        $this->lastGroupId = $groupId;

        return 0;
    }
}
