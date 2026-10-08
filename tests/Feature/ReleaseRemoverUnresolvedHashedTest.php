<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRemoverService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class ReleaseRemoverUnresolvedHashedTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const int GB = 1073741824;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(40) NOT NULL,
            searchname VARCHAR(255) NOT NULL,
            categories_id INTEGER NOT NULL DEFAULT 10,
            nzbstatus INTEGER NOT NULL DEFAULT 1,
            passwordstatus INTEGER NOT NULL DEFAULT -1,
            size BIGINT NOT NULL DEFAULT 0,
            adddate DATETIME NULL
        )');
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value VARCHAR(255) NULL)');
        DB::table('settings')->insert([
            ['name' => 'minsizetopostprocess', 'value' => '1'],
            ['name' => 'maxsizetopostprocess', 'value' => '100'],
        ]);
    }

    public function test_processed_hashed_release_past_the_grace_period_is_deleted(): void
    {
        $this->release(1, hoursOld: 25, passwordstatus: 0);

        $this->assertTrue($this->service(['1'])->removeCrap(true, '4', 'hashed_unresolved'));
    }

    public function test_release_never_eligible_for_post_processing_is_deleted(): void
    {
        $this->release(1, hoursOld: 30, size: 500);
        $this->release(2, hoursOld: 30, size: 120 * self::GB);
        $this->release(3, hoursOld: 30, nzbstatus: 0);

        $this->assertTrue($this->service(['1', '2', '3'])->removeCrap(true, '4', 'hashed_unresolved'));
    }

    public function test_releases_that_can_still_be_resolved_are_kept(): void
    {
        // Processed, but still inside the grace period.
        $this->release(1, hoursOld: 23, passwordstatus: 0);
        // Old, but additional processing has not run on it yet.
        $this->release(2, hoursOld: 48);
        // Old and processed, but no longer hashed.
        $this->release(3, hoursOld: 48, passwordstatus: 0, category: Category::MOVIE_HD);

        $this->assertTrue($this->service([])->removeCrap(true, '4', 'hashed_unresolved'));
    }

    private function release(
        int $id,
        int $hoursOld,
        int $passwordstatus = -1,
        int $nzbstatus = 1,
        int $size = 4 * self::GB,
        int $category = Category::OTHER_HASHED,
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => 'guid-'.$id,
            'searchname' => 'e71d4da1e98b65f1a9c3',
            'categories_id' => $category,
            'nzbstatus' => $nzbstatus,
            'passwordstatus' => $passwordstatus,
            'size' => $size,
            'adddate' => now()->subHours($hoursOld)->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param  list<string>  $expectedIds
     */
    private function service(array $expectedIds): ReleaseRemoverService
    {
        $releaseManagement = Mockery::mock(ReleaseManagementService::class);
        if ($expectedIds === []) {
            $releaseManagement->shouldNotReceive('deleteSingleWithService');
        }
        foreach ($expectedIds as $id) {
            $releaseManagement->shouldReceive('deleteSingleWithService')
                ->once()
                ->with(['g' => 'guid-'.$id, 'i' => (int) $id, 'reason' => 'HashedUnresolved'], Mockery::any(), Mockery::any());
        }

        return new ReleaseRemoverService(
            releaseManagement: $releaseManagement,
            nzb: Mockery::mock(NzbService::class),
            nzbParser: Mockery::mock(NzbParserService::class),
            releaseImage: Mockery::mock(ReleaseImageService::class)
        );
    }
}
