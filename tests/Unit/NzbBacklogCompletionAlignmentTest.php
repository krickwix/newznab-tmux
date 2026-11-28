<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Settings;
use App\Services\Nzb\NzbBacklogCreationService;
use App\Services\Nzb\NzbService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * NZB creation rejected a release if any one of its binaries was short, while
 * release creation had already accepted that release under stage 4's rule,
 * which tolerates up to (100 - completionpercent)% short files per collection.
 * 4,543 releases older than 48 hours sit at nzbstatus = 0 with no path forward
 * because of that gap, pinning 66M parts rows.
 *
 * NNTMUX_NZB_ALIGN_RELEASE_COMPLETION closes it and defaults to the historical
 * behaviour. Both states are pinned here.
 */
final class NzbBacklogCompletionAlignmentTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::connection()->getPdo()->sqliteCreateFunction('GREATEST', max(...), -1);
        DB::connection()->getPdo()->sqliteCreateFunction('CEIL', ceil(...), 1);

        Schema::create('settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->string('value')->nullable();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('nzbstatus')->default(0);
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('releases_id')->nullable();
            $table->unsignedInteger('totalfiles')->default(0);
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collections_id');
            $table->unsignedTinyInteger('partcheck')->default(0);
            $table->unsignedInteger('totalparts')->default(0);
            $table->unsignedInteger('currentparts')->default(0);
        });

        DB::table('settings')->insert([['name' => 'completionpercent', 'value' => '94']]);
        // Settings are memoised statically for 30 s, so a seeded row is not
        // visible to settingValue() until the memo is dropped.
        Settings::forgetCachedSettings();

        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        Settings::forgetCachedSettings();

        parent::tearDown();
    }

    public function test_by_default_one_short_file_still_blocks_the_whole_release(): void
    {
        config(['nntmux.nzb_align_release_completion' => false]);

        // Release 1 has 47 of its 50 files complete, which is what stage 4
        // accepted when it created the release; the default gate rejects it
        // anyway. Release 4's single complete binary carries no part total,
        // which the default gate also rejects outright.
        self::assertSame([3], $this->counterCompleteIds());
    }

    public function test_the_flag_accepts_exactly_what_release_creation_accepted(): void
    {
        config(['nntmux.nzb_align_release_completion' => true]);

        // 1: 47 of 50 complete, and CEIL(50 * 94 / 100) = 47 -> accepted.
        // 2: 46 of 50 complete, one short of the threshold -> still rejected.
        // 3: nothing short at all -> accepted under either rule.
        // 4: complete binary with totalparts = 0 -> stage 4 counts it.
        self::assertSame([1, 3, 4], $this->counterCompleteIds());
    }

    public function test_a_release_whose_collection_holds_no_binaries_is_never_written(): void
    {
        foreach ([false, true] as $aligned) {
            config(['nntmux.nzb_align_release_completion' => $aligned]);

            // Release 5's collection exists but holds nothing, so there is no
            // NZB to write under either rule.
            self::assertNotContains(
                5,
                $this->counterCompleteIds(),
                'aligned='.var_export($aligned, true),
            );
        }
    }

    public function test_the_aligned_predicate_is_release_creations_own_predicate_verbatim(): void
    {
        $pinned = (string) (new ReflectionClass(NzbBacklogCreationService::class))
            ->getConstant('STAGE_FOUR_ACCEPTABLE_SQL');
        $releaseProcessing = file_get_contents(__DIR__.'/../../app/Services/ReleaseProcessingService.php');

        self::assertNotSame('', $pinned);
        self::assertIsString($releaseProcessing);
        // The alignment is only worth anything if the two predicates cannot
        // drift apart, so the gate carries stage 4's SQL character for
        // character rather than a restatement of it.
        self::assertStringContainsString(
            $pinned,
            $releaseProcessing,
            'The NZB gate no longer mirrors ReleaseProcessingService::stageFourAcceptable().'
        );
    }

    /** @return list<int> */
    private function counterCompleteIds(): array
    {
        $service = new NzbBacklogCreationService(Mockery::mock(NzbService::class));
        $completion = (new ReflectionMethod($service, 'requiredCompletionPercent'))->invoke($service);

        self::assertSame(94, $completion);

        /** @var list<int> $ids */
        $ids = (new ReflectionMethod($service, 'counterCompleteReleaseIds'))
            ->invoke($service, [1, 2, 3, 4, 5], $completion);
        sort($ids);

        return $ids;
    }

    private function seedFixture(): void
    {
        DB::table('releases')->insert([
            ['id' => 1, 'nzbstatus' => 0],
            ['id' => 2, 'nzbstatus' => 0],
            ['id' => 3, 'nzbstatus' => 0],
            ['id' => 4, 'nzbstatus' => 0],
            ['id' => 5, 'nzbstatus' => 0],
        ]);
        DB::table('collections')->insert([
            ['id' => 10, 'releases_id' => 1, 'totalfiles' => 50],
            ['id' => 20, 'releases_id' => 2, 'totalfiles' => 50],
            ['id' => 30, 'releases_id' => 3, 'totalfiles' => 3],
            ['id' => 40, 'releases_id' => 4, 'totalfiles' => 1],
            ['id' => 50, 'releases_id' => 5, 'totalfiles' => 2],
        ]);

        // totalfiles is a multiple of 50 on purpose. MariaDB evaluates
        // `totalfiles * 94 / 100` as decimal while sqlite does integer
        // division, so CEIL() of the two disagrees for most totals; 50 * 94 /
        // 100 is exactly 47 under both, which keeps this test about the
        // predicate rather than about the arithmetic.
        $binaries = [];
        $id = 1000;
        for ($i = 0; $i < 47; $i++) {
            $binaries[] = ['id' => $id++, 'collections_id' => 10, 'partcheck' => 1, 'totalparts' => 10, 'currentparts' => 10];
        }
        for ($i = 0; $i < 3; $i++) {
            $binaries[] = ['id' => $id++, 'collections_id' => 10, 'partcheck' => 0, 'totalparts' => 10, 'currentparts' => 3];
        }

        for ($i = 0; $i < 46; $i++) {
            $binaries[] = ['id' => $id++, 'collections_id' => 20, 'partcheck' => 1, 'totalparts' => 10, 'currentparts' => 10];
        }
        for ($i = 0; $i < 4; $i++) {
            $binaries[] = ['id' => $id++, 'collections_id' => 20, 'partcheck' => 0, 'totalparts' => 10, 'currentparts' => 3];
        }

        for ($i = 0; $i < 3; $i++) {
            $binaries[] = ['id' => $id++, 'collections_id' => 30, 'partcheck' => 1, 'totalparts' => 10, 'currentparts' => 10];
        }

        $binaries[] = ['id' => $id++, 'collections_id' => 40, 'partcheck' => 1, 'totalparts' => 0, 'currentparts' => 7];

        DB::table('binaries')->insert($binaries);
    }
}
