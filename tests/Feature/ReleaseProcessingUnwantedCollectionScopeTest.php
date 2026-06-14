<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class ReleaseProcessingUnwantedCollectionScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction(
            'REGEXP',
            static function (?string $first, ?string $second): int {
                foreach ([[$first, $second], [$second, $first]] as [$pattern, $subject]) {
                    if ($pattern === null || $subject === null || $pattern === '') {
                        continue;
                    }

                    foreach ([$pattern, stripslashes($pattern)] as $candidate) {
                        set_error_handler(static fn (): true => true);
                        $matched = @preg_match('~'.$candidate.'~i', $subject);
                        restore_error_handler();

                        if ($matched === 1) {
                            return 1;
                        }
                    }
                }

                return 0;
            },
            2
        );

        $this->createTables();
        $this->seedSettings();
        $this->seedGroups();
    }

    public function test_group_scoped_unwanted_collection_cleanup_does_not_delete_other_groups(): void
    {
        $this->seedCollection(101, 1, 'Target.Par2.Only', 'Target.Par2.Only.par2', 500, 3);
        $this->seedCollection(102, 1, 'Target.Too.Small', 'Target.Too.Small.mkv', 50, 3);
        $this->seedCollection(103, 1, 'Target.Too.Large', 'Target.Too.Large.mkv', 2000, 3);
        $this->seedCollection(104, 1, 'Target.Too.Few.Files', 'Target.Too.Few.Files.mkv', 500, 1);

        $this->seedCollection(201, 2, 'Other.Par2.Only', 'Other.Par2.Only.par2', 500, 3);
        $this->seedCollection(202, 2, 'Other.Too.Small', 'Other.Too.Small.mkv', 50, 3);
        $this->seedCollection(203, 2, 'Other.Too.Large', 'Other.Too.Large.mkv', 2000, 3);
        $this->seedCollection(204, 2, 'Other.Too.Few.Files', 'Other.Too.Few.Files.mkv', 500, 1);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);

        $service->deleteUnwantedCollections(1);

        $this->assertSame(0, DB::table('collections')->where('groups_id', 1)->count());
        $this->assertSame(4, DB::table('collections')->where('groups_id', 2)->count());
        $this->assertSame(4, DB::table('binaries')->whereIn('collections_id', [201, 202, 203, 204])->count());
    }

    public function test_explicit_group_zero_disables_site_minimum_collection_cleanup(): void
    {
        DB::table('usenet_groups')->insert([
            'id' => 3,
            'name' => 'alt.explicit-zero',
            'active' => 1,
            'minsizetoformrelease' => 0,
            'minfilestoformrelease' => 0,
        ]);

        $this->seedCollection(301, 3, 'Explicit.Zero.Too.Small', 'Explicit.Zero.Too.Small.mkv', 50, 3);
        $this->seedCollection(302, 3, 'Explicit.Zero.One.File', 'Explicit.Zero.One.File.iso', 500, 1);
        $this->seedCollection(303, 3, 'Explicit.Zero.Par2.Only', 'Explicit.Zero.Par2.Only.par2', 500, 3);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);

        $service->deleteUnwantedCollections(3);

        $this->assertSame([301, 302], DB::table('collections')->where('groups_id', 3)->orderBy('id')->pluck('id')->all());
        $this->assertFalse(DB::table('binaries')->where('collections_id', 303)->exists());
        $this->assertFalse(DB::table('parts')->where('binaries_id', 3030)->exists());
    }

    public function test_explicit_group_zero_disables_site_minimum_release_cleanup(): void
    {
        DB::table('usenet_groups')->insert([
            [
                'id' => 3,
                'name' => 'alt.explicit-zero',
                'active' => 1,
                'minsizetoformrelease' => 0,
                'minfilestoformrelease' => 0,
            ],
            [
                'id' => 4,
                'name' => 'alt.global-fallback',
                'active' => 1,
                'minsizetoformrelease' => null,
                'minfilestoformrelease' => null,
            ],
        ]);
        DB::table('releases')->insert([
            $this->release(401, 3, 'explicit-too-small', 50, 3),
            $this->release(402, 3, 'explicit-one-file', 500, 1),
            $this->release(403, 4, 'global-one-file', 500, 1),
            $this->release(404, 4, 'global-too-small', 50, 3),
        ]);

        $releaseManagement = new RecordingReleaseManagementServiceForUnwantedCollections;
        /** @var NzbService $nzb */
        $nzb = Mockery::mock(NzbService::class);
        /** @var ReleaseImageService $releaseImage */
        $releaseImage = Mockery::mock(ReleaseImageService::class);
        $service = new ReleaseProcessingService(
            nzb: $nzb,
            releaseManagement: $releaseManagement,
            releaseImage: $releaseImage,
        );
        $service->setEchoCLI(false);

        $service->deletedReleasesByGroup();

        $this->assertEqualsCanonicalizing([403, 404], $releaseManagement->deletedIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function release(int $id, int $groupId, string $guid, int $size, int $totalParts): array
    {
        return [
            'id' => $id,
            'guid' => $guid,
            'groups_id' => $groupId,
            'size' => $size,
            'totalpart' => $totalParts,
        ];
    }

    private function seedCollection(
        int $id,
        int $groupId,
        string $subject,
        string $binaryName,
        int $fileSize,
        int $totalFiles
    ): void {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => $subject,
            'groups_id' => $groupId,
            'totalfiles' => $totalFiles,
            'filesize' => $fileSize,
            'filecheck' => CollectionFileCheckStatus::Sized->value,
        ]);

        DB::table('binaries')->insert([
            'id' => $id * 10,
            'name' => $binaryName,
            'collections_id' => $id,
        ]);

        DB::table('parts')->insert([
            'id' => $id * 100,
            'binaries_id' => $id * 10,
        ]);
    }

    private function seedSettings(): void
    {
        foreach ([
            'maxnzbsprocessed' => '1000',
            'delaytime' => '12',
            'crossposttime' => '2',
            'completionpercent' => '95',
            'collection_timeout' => '48',
            'maxsizetoformrelease' => '1000',
            'minsizetoformrelease' => '100',
            'minfilestoformrelease' => '2',
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

    private function seedGroups(): void
    {
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.target', 'active' => 1, 'minsizetoformrelease' => null, 'minfilestoformrelease' => null],
            ['id' => 2, 'name' => 'alt.other', 'active' => 1, 'minsizetoformrelease' => null, 'minfilestoformrelease' => null],
        ]);
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            active INTEGER,
            minsizetoformrelease VARCHAR(255) NULL,
            minfilestoformrelease VARCHAR(255) NULL
        )');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            groups_id INTEGER,
            totalfiles INTEGER,
            filesize INTEGER,
            filecheck INTEGER
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            collections_id INTEGER
        )');
        DB::statement('CREATE TABLE parts (
            id INTEGER PRIMARY KEY,
            binaries_id INTEGER
        )');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64),
            groups_id INTEGER,
            size INTEGER,
            totalpart INTEGER
        )');
    }
}

final class RecordingReleaseManagementServiceForUnwantedCollections extends ReleaseManagementService
{
    /**
     * @var list<int>
     */
    public array $deletedIds = [];

    public function deleteSingle(array $identifiers, NzbService $nzb, ReleaseImageService $releaseImage): void
    {
        $this->deletedIds[] = (int) $identifiers['i'];
    }
}
