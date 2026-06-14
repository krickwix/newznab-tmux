<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class ReleaseProcessingCrossPostCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        $this->createTables();
        $this->seedSettings();
    }

    public function test_cross_post_cleanup_deletes_every_release_in_duplicate_cluster(): void
    {
        DB::table('releases')->insert([
            $this->release(1, 'Duplicate.Release', 'same-poster@example.com', 'guid-1'),
            $this->release(2, 'Duplicate.Release', 'same-poster@example.com', 'guid-2'),
            $this->release(3, 'Duplicate.Release', 'same-poster@example.com', 'guid-3'),
            $this->release(4, 'Unique.Release', 'same-poster@example.com', 'guid-4'),
        ]);

        $releaseManagement = new RecordingReleaseManagementService;
        $service = new ReleaseProcessingService(
            nzb: Mockery::mock(NzbService::class),
            releaseManagement: $releaseManagement,
            releaseImage: Mockery::mock(ReleaseImageService::class),
        );
        $service->setEchoCLI(false);

        $service->deleteReleases();

        $this->assertSame([1, 2, 3], $releaseManagement->deletedIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function release(int $id, string $name, string $fromName, string $guid): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'fromname' => $fromName,
            'guid' => $guid,
            'adddate' => now()->subMinutes(10)->format('Y-m-d H:i:s'),
            'categories_id' => 1,
            'completion' => 0,
            'passwordstatus' => 0,
            'size' => 1000,
        ];
    }

    private function seedSettings(): void
    {
        foreach ([
            'maxnzbsprocessed' => '1000',
            'delaytime' => '12',
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
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            fromname VARCHAR(255),
            guid VARCHAR(64),
            adddate DATETIME,
            categories_id INTEGER,
            completion INTEGER,
            passwordstatus INTEGER,
            size INTEGER
        )');
        DB::statement('CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            status INTEGER DEFAULT 1,
            minsizetoformrelease INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE genres (
            id INTEGER PRIMARY KEY,
            disabled INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE musicinfo (
            id INTEGER PRIMARY KEY,
            genre_id INTEGER
        )');
    }
}

final class RecordingReleaseManagementService extends ReleaseManagementService
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
