<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Metrics\DistributedWorkerTelemetry;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseRemoverService;
use App\Services\Releases\ReleaseManagementService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * The removecrap worker deleted ~6.7 releases a minute and published no
 * counter for any of it, so a type suddenly eating a thousand releases and a
 * type that had silently stopped matching looked identical from outside.
 */
final class ReleaseRemoverTelemetryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const int GB = 1073741824;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.distributed_lock_store' => 'array',
        ]);
        Cache::store('array')->flush();
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

    public function test_deletions_are_counted_under_their_type_and_in_the_release_total(): void
    {
        $this->release(1, hoursOld: 30, passwordstatus: 0);
        $this->release(2, hoursOld: 40, passwordstatus: 0);

        $this->assertTrue($this->service(['1', '2'])->removeCrap(true, '4', 'hashed_unresolved'));

        $items = $this->items();
        self::assertSame(2, $items['hashed_unresolved']['deleted']);
        self::assertSame(2, $items['release']['deleted']);
    }

    public function test_a_dry_run_counts_nothing(): void
    {
        $this->release(1, hoursOld: 30, passwordstatus: 0);

        $remover = new ReleaseRemoverService(
            releaseManagement: Mockery::mock(ReleaseManagementService::class)
                ->shouldNotReceive('deleteSingleWithService')
                ->getMock(),
            nzb: Mockery::mock(NzbService::class),
            nzbParser: Mockery::mock(NzbParserService::class),
            releaseImage: Mockery::mock(ReleaseImageService::class),
        );

        self::assertTrue($remover->removeCrap(false, '4', 'hashed_unresolved'));

        // Counting a preview as a deletion would make --delete and its
        // absence indistinguishable in the metric.
        $items = $this->items();
        self::assertSame(0, $items['hashed_unresolved']['deleted']);
        self::assertSame(0, $items['release']['deleted']);
    }

    public function test_a_type_that_matches_nothing_leaves_its_counter_alone(): void
    {
        // Inside the grace period, so nothing is eligible.
        $this->release(1, hoursOld: 2, passwordstatus: 0);

        self::assertTrue($this->service([])->removeCrap(true, '4', 'hashed_unresolved'));

        self::assertSame(0, $this->items()['hashed_unresolved']['deleted']);
    }

    public function test_the_exporter_vocabulary_covers_every_removal_type_the_service_can_run(): void
    {
        $declared = DistributedWorkerTelemetry::ITEMS_BY_WORKER['removecrap'] ?? [];
        $handlers = array_keys($this->handlerTypes());

        // A type missing here would have its deletions silently dropped by
        // recordItem()'s own vocabulary check.
        self::assertSame([], array_diff($handlers, $declared));
        self::assertContains('release', $declared);
    }

    /** @return array<string, array<string, int>> */
    private function items(): array
    {
        return (new DistributedWorkerTelemetry)->snapshot(['removecrap'])['workers']['removecrap']['items'];
    }

    /** @return array<string, mixed> */
    private function handlerTypes(): array
    {
        $property = new \ReflectionProperty(ReleaseRemoverService::class, 'removalHandlers');

        return (array) $property->getValue(new ReleaseRemoverService(
            releaseManagement: Mockery::mock(ReleaseManagementService::class),
            nzb: Mockery::mock(NzbService::class),
            nzbParser: Mockery::mock(NzbParserService::class),
            releaseImage: Mockery::mock(ReleaseImageService::class),
        ));
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

    /** @param  list<string>  $expectedIds */
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
            releaseImage: Mockery::mock(ReleaseImageService::class),
        );
    }
}
