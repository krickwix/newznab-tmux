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

    public function test_without_the_age_backstop_a_release_still_queued_for_processing_is_immortal(): void
    {
        config(['nntmux.remove_unresolved_hashed_max_age_hours' => 0]);

        // passwordstatus = -1 with nzbstatus = 1 and a size inside the
        // post-processing bounds matches none of the removal arms, which are
        // all negative. 6,196 of the 11,799 cat-20 releases on live sit here.
        $this->release(1, hoursOld: 24 * 90);

        $this->assertTrue($this->service([])->removeCrap(true, '4', 'hashed_unresolved'));
    }

    public function test_the_age_backstop_drops_it_regardless_of_the_processing_arms(): void
    {
        config(['nntmux.remove_unresolved_hashed_max_age_hours' => 168]);

        $this->release(1, hoursOld: 24 * 90);

        $this->assertTrue($this->service(['1'])->removeCrap(true, '4', 'hashed_unresolved'));
    }

    public function test_the_age_backstop_leaves_younger_releases_to_the_processing_arms(): void
    {
        config(['nntmux.remove_unresolved_hashed_max_age_hours' => 168]);

        // Older than the 24 hour grace but younger than the backstop, and
        // still queued for processing, so neither rule reaches it.
        $this->release(1, hoursOld: 48);
        // Same age, but processing has finished on it, so the grace arm does.
        $this->release(2, hoursOld: 48, passwordstatus: 0);

        $this->assertTrue($this->service(['2'])->removeCrap(true, '4', 'hashed_unresolved'));
    }

    public function test_the_age_backstop_does_not_reach_outside_the_hashed_category(): void
    {
        config(['nntmux.remove_unresolved_hashed_max_age_hours' => 168]);

        $this->release(1, hoursOld: 24 * 90, category: Category::MOVIE_HD);

        $this->assertTrue($this->service([])->removeCrap(true, '4', 'hashed_unresolved'));
    }

    public function test_a_per_pass_limit_bounds_the_first_run_and_resumes_on_the_next(): void
    {
        config([
            'nntmux.remove_unresolved_hashed_max_age_hours' => 168,
            'nntmux.remove_unresolved_hashed_max_per_pass' => 2,
        ]);

        $this->release(1, hoursOld: 24 * 90);
        $this->release(2, hoursOld: 24 * 90);
        $this->release(3, hoursOld: 24 * 90);

        // Lowest ids first, so the order is stable and each pass continues
        // where the last stopped -- deleted rows do not come back.
        $this->assertTrue($this->service(['1', '2'], deleteRows: true)->removeCrap(true, '4', 'hashed_unresolved'));
        $this->assertSame([3], DB::table('releases')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        $this->assertTrue($this->service(['3'], deleteRows: true)->removeCrap(true, '4', 'hashed_unresolved'));
        $this->assertSame([], DB::table('releases')->pluck('id')->all());
    }

    public function test_without_a_limit_one_pass_takes_the_whole_backlog(): void
    {
        config([
            'nntmux.remove_unresolved_hashed_max_age_hours' => 168,
            'nntmux.remove_unresolved_hashed_max_per_pass' => 0,
        ]);

        $this->release(1, hoursOld: 24 * 90);
        $this->release(2, hoursOld: 24 * 90);
        $this->release(3, hoursOld: 24 * 90);

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
    private function service(array $expectedIds, bool $deleteRows = false): ReleaseRemoverService
    {
        $releaseManagement = Mockery::mock(ReleaseManagementService::class);
        if ($expectedIds === []) {
            $releaseManagement->shouldNotReceive('deleteSingleWithService');
        }
        foreach ($expectedIds as $id) {
            $expectation = $releaseManagement->shouldReceive('deleteSingleWithService')
                ->once()
                ->with(['g' => 'guid-'.$id, 'i' => (int) $id, 'reason' => 'HashedUnresolved'], Mockery::any(), Mockery::any());

            if ($deleteRows) {
                // Mirror the real service's effect on the table so a following
                // pass sees what is actually left.
                $expectation->andReturnUsing(static function (array $identifiers): bool {
                    DB::table('releases')->where('id', $identifiers['i'])->delete();

                    return true;
                });
            }
        }

        return new ReleaseRemoverService(
            releaseManagement: $releaseManagement,
            nzb: Mockery::mock(NzbService::class),
            nzbParser: Mockery::mock(NzbParserService::class),
            releaseImage: Mockery::mock(ReleaseImageService::class)
        );
    }
}
