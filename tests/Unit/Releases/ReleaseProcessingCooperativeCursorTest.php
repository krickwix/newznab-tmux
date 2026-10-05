<?php

declare(strict_types=1);

namespace Tests\Unit\Releases;

use App\Enums\CollectionFileCheckStatus;
use App\Enums\FileCompletionStatus;
use App\Services\ReleaseProcessingService;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class ReleaseProcessingCooperativeCursorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $dsn = (string) env('NNTMUX_TEST_MARIADB_DSN', '');
        if ($dsn !== '') {
            // Optional engine switch, e.g. mysql://root:t@127.0.0.1:33061/t
            $parts = parse_url($dsn);
            config([
                'database.default' => 'mariadb_t',
                'database.connections.mariadb_t' => [
                    'driver' => 'mariadb',
                    'host' => $parts['host'] ?? '127.0.0.1',
                    'port' => $parts['port'] ?? 3306,
                    'database' => ltrim($parts['path'] ?? '/t', '/'),
                    'username' => $parts['user'] ?? 'root',
                    'password' => $parts['pass'] ?? '',
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => false,
                ],
            ]);
            DB::purge('mariadb_t');
            Schema::dropIfExists('binaries');
            Schema::dropIfExists('collections');
        } else {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
            ]);
            DB::purge('sqlite');
            DB::connection()->getPdo()->sqliteCreateFunction('GREATEST', max(...), -1);
            DB::connection()->getPdo()->sqliteCreateFunction('CEIL', ceil(...), 1);
        }
        config(['nntmux.distributed_lock_store' => 'array']);
        Cache::store('array')->flush();

        Schema::create('collections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('groups_id');
            $table->unsignedTinyInteger('filecheck');
            $table->unsignedInteger('totalfiles');
            $table->dateTime('dateadded');
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('collections_id');
            $table->unsignedInteger('filenumber');
            $table->unsignedInteger('totalparts');
            $table->unsignedInteger('currentparts');
            $table->unsignedTinyInteger('partcheck');
        });
    }

    public function test_incomplete_five_hundred_row_prefix_cannot_starve_a_later_complete_collection(): void
    {
        config(['nntmux.release_stage_scan_window' => 500]);
        $collections = [];
        $binaries = [];
        for ($id = 1; $id <= 501; $id++) {
            $collections[] = [
                'id' => $id,
                'groups_id' => 1,
                'filecheck' => CollectionFileCheckStatus::CompleteCollection->value,
                'totalfiles' => 1,
                'dateadded' => now()->subDay(),
            ];
            $binaries[] = [
                'id' => $id,
                'collections_id' => $id,
                'filenumber' => 1,
                'totalparts' => 1,
                'currentparts' => $id === 501 ? 1 : 0,
                'partcheck' => FileCompletionStatus::Incomplete->value,
            ];
        }
        foreach (array_chunk($collections, 250) as $chunk) {
            DB::table('collections')->insert($chunk);
        }
        foreach (array_chunk($binaries, 250) as $chunk) {
            DB::table('binaries')->insert($chunk);
        }

        $service = $this->cooperativeService();
        $this->runStagesTwoToFive($service);
        self::assertSame(
            CollectionFileCheckStatus::CompleteCollection->value,
            (int) DB::table('collections')->where('id', 501)->value('filecheck'),
        );

        $this->runStagesTwoToFive($service);
        self::assertSame(
            CollectionFileCheckStatus::CompleteParts->value,
            (int) DB::table('collections')->where('id', 501)->value('filecheck'),
        );
    }

    public function test_stage_six_filters_eligibility_before_limiting_the_page(): void
    {
        $collections = [];
        $binaries = [];
        for ($id = 1; $id <= 501; $id++) {
            $collections[] = [
                'id' => $id,
                'groups_id' => 1,
                'filecheck' => 10,
                'totalfiles' => 1,
                'dateadded' => now()->subDay(),
            ];
            $binaries[] = [
                'id' => $id,
                'collections_id' => $id,
                'filenumber' => 1,
                'totalparts' => 1,
                'currentparts' => $id === 501 ? 1 : 0,
                'partcheck' => FileCompletionStatus::Incomplete->value,
            ];
        }
        foreach (array_chunk($collections, 250) as $chunk) {
            DB::table('collections')->insert($chunk);
        }
        foreach (array_chunk($binaries, 250) as $chunk) {
            DB::table('binaries')->insert($chunk);
        }

        $service = $this->cooperativeService();
        $method = new ReflectionMethod($service, 'runCollectionFileCheckStage6');
        $method->invoke($service, ' AND c.groups_id = 1 ');

        self::assertSame(
            CollectionFileCheckStatus::CompleteParts->value,
            (int) DB::table('collections')->where('id', 501)->value('filecheck'),
        );
    }

    public function test_stage_two_update_does_not_clobber_a_concurrently_moved_row(): void
    {
        $this->insertCollection(1, 1, CollectionFileCheckStatus::CompleteCollection->value);
        $this->insertCollection(2, 1, CollectionFileCheckStatus::CompleteCollection->value);
        DB::table('binaries')->insert([
            $this->binary(1, 1, 1, 1, FileCompletionStatus::Complete->value),
            $this->binary(2, 2, 1, 1, FileCompletionStatus::Complete->value),
        ]);
        DB::listen(static function ($query): void {
            if (str_starts_with(str_replace('`', '"', $query->sql), 'select "id" from "collections"')) {
                DB::table('collections')->where('id', 2)->update(['filecheck' => CollectionFileCheckStatus::Sized->value]);
            }
        });

        $service = $this->cooperativeService();
        (new ReflectionMethod($service, 'updateCollectionsFilecheckInChunks'))->invoke(
            $service,
            1,
            CollectionFileCheckStatus::CompleteCollection->value,
            CollectionFileCheckStatus::TempComplete->value,
        );

        self::assertSame(CollectionFileCheckStatus::TempComplete->value, (int) DB::table('collections')->where('id', 1)->value('filecheck'));
        self::assertSame(CollectionFileCheckStatus::Sized->value, (int) DB::table('collections')->where('id', 2)->value('filecheck'));
    }

    public function test_stage_three_marks_every_binary_of_a_large_collection_in_one_slice(): void
    {
        $this->insertCollection(1, 1, CollectionFileCheckStatus::TempComplete->value, 450);
        $binaries = [];
        for ($i = 1; $i <= 450; $i++) {
            $binaries[] = $this->binary($i, 1, 1, 1);
        }
        foreach (array_chunk($binaries, 200) as $chunk) {
            DB::table('binaries')->insert($chunk);
        }

        $service = $this->cooperativeService(200);
        $this->runStage($service, 'runCollectionFileCheckStage3', 1);

        self::assertSame(0, DB::table('binaries')->where('partcheck', FileCompletionStatus::Incomplete->value)->count());
    }

    public function test_stage_one_cursor_advances_by_scanned_rows_so_a_later_qualifier_is_promoted(): void
    {
        config(['nntmux.release_stage_scan_window' => 2]);
        for ($id = 1; $id <= 5; $id++) {
            $this->insertCollection($id, 1, CollectionFileCheckStatus::Default->value, 1);
        }
        DB::table('binaries')->insert($this->binary(5, 5, 1, 1));
        $service = $this->cooperativeService();
        $cache = Cache::store('array');

        $this->runStage($service, 'runCollectionFileCheckStage1', 1);
        self::assertSame(2, $cache->get('nntmux:release-pump:stage1:1'));
        $this->runStage($service, 'runCollectionFileCheckStage1', 1);
        self::assertSame(4, $cache->get('nntmux:release-pump:stage1:1'));
        self::assertSame(0, (int) DB::table('collections')->where('id', 5)->value('filecheck'));

        $this->runStage($service, 'runCollectionFileCheckStage1', 1);
        self::assertSame(0, $cache->get('nntmux:release-pump:stage1:1'));
        self::assertSame(CollectionFileCheckStatus::CompleteCollection->value, (int) DB::table('collections')->where('id', 5)->value('filecheck'));
    }

    public function test_stage_zero_and_six_cursors_are_independent_per_group(): void
    {
        config(['nntmux.release_stage_scan_window' => 2]);
        foreach ([[1, 1], [2, 1], [3, 1], [4, 2]] as [$id, $group]) {
            $this->insertCollection($id, $group, CollectionFileCheckStatus::Default->value, 0);
        }
        $service = $this->cooperativeService();
        $cache = Cache::store('array');

        $this->runStage($service, 'runCollectionFileCheckStage0', 1);
        self::assertSame(2, $cache->get('nntmux:release-pump:stage0:1'));
        self::assertNull($cache->get('nntmux:release-pump:stage0:2'));
        $this->runStage($service, 'runCollectionFileCheckStage0', 2);
        self::assertSame(0, $cache->get('nntmux:release-pump:stage0:2'));
        self::assertSame(2, $cache->get('nntmux:release-pump:stage0:1'));

        $this->insertCollection(10, 1, 10, 1);
        $this->runStage($service, 'runCollectionFileCheckStage6', ' AND c.groups_id = 1 ');
        self::assertSame(2, $cache->get('nntmux:release-pump:stage6:1'));
        self::assertNull($cache->get('nntmux:release-pump:stage6:2'));
    }

    public function test_non_cooperative_mode_ignores_the_scan_cursors(): void
    {
        config(['nntmux.release_stage_scan_window' => 1]);
        foreach ([1, 2, 3] as $id) {
            $this->insertCollection($id, 1, CollectionFileCheckStatus::Default->value, 1);
            DB::table('binaries')->insert($this->binary($id, $id, 1, 1));
        }
        $service = $this->cooperativeService();
        (new ReflectionClass($service))->getProperty('cooperativeSlice')->setValue($service, false);

        $this->runStage($service, 'runCollectionFileCheckStage1', 1);

        self::assertSame(3, DB::table('collections')->where('filecheck', CollectionFileCheckStatus::CompleteCollection->value)->count());
        self::assertNull(Cache::store('array')->get('nntmux:release-pump:stage1:1'));
    }

    public function test_stage_zero_scan_window_wraps_and_promotes_a_row_after_a_non_qualifying_prefix(): void
    {
        config(['nntmux.release_stage_scan_window' => 2]);
        foreach ([1, 2, 3] as $id) {
            $this->insertCollection($id, 1, CollectionFileCheckStatus::Default->value, 0);
        }
        DB::table('binaries')->insert([...$this->binary(3, 3, 1, 1), 'filenumber' => 1]);
        $service = $this->cooperativeService();

        $this->runStage($service, 'runCollectionFileCheckStage0', 1);
        self::assertSame(0, (int) DB::table('collections')->where('id', 3)->value('filecheck'));
        self::assertSame(2, Cache::store('array')->get('nntmux:release-pump:stage0:1'));

        $this->runStage($service, 'runCollectionFileCheckStage0', 1);
        self::assertSame(CollectionFileCheckStatus::CompleteCollection->value, (int) DB::table('collections')->where('id', 3)->value('filecheck'));
        self::assertSame(0, Cache::store('array')->get('nntmux:release-pump:stage0:1'));
    }

    public function test_stage_six_age_gated_row_is_caught_after_the_cursor_wraps(): void
    {
        config(['nntmux.release_stage_scan_window' => 1]);
        $this->insertCollection(1, 1, 10, 1);
        DB::table('collections')->where('id', 1)->update(['dateadded' => now()]);
        $this->insertCollection(2, 1, 10, 1);
        DB::table('binaries')->insert([$this->binary(1, 1, 1, 1), $this->binary(2, 2, 0, 1)]);
        $service = $this->cooperativeService(500, 100, 12);

        $this->runStage($service, 'runCollectionFileCheckStage6', ' AND c.groups_id = 1 ');
        self::assertSame(10, (int) DB::table('collections')->where('id', 1)->value('filecheck'));
        $this->runStage($service, 'runCollectionFileCheckStage6', ' AND c.groups_id = 1 ');
        $this->runStage($service, 'runCollectionFileCheckStage6', ' AND c.groups_id = 1 ');
        self::assertSame(0, Cache::store('array')->get('nntmux:release-pump:stage6:1'));

        DB::table('collections')->where('id', 1)->update(['dateadded' => now()->subDay()]);
        $this->runStage($service, 'runCollectionFileCheckStage6', ' AND c.groups_id = 1 ');
        self::assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) DB::table('collections')->where('id', 1)->value('filecheck'));
    }

    public function test_stage_two_zero_part_move_is_page_scoped_and_prefiltered(): void
    {
        config(['nntmux.release_stage_scan_window' => 2]);
        foreach ([1, 2, 3, 4] as $id) {
            $this->insertCollection($id, 1, CollectionFileCheckStatus::CompleteCollection->value, 1);
        }
        $zero = fn (int $id, int $collection, int $partcheck): array => [...$this->binary($id, $collection, $partcheck === FileCompletionStatus::Complete->value ? 1 : 0, 1, $partcheck), 'filenumber' => 0];
        DB::table('binaries')->insert([
            $zero(1, 1, FileCompletionStatus::Complete->value),
            $zero(2, 2, FileCompletionStatus::Incomplete->value),
            $zero(3, 3, FileCompletionStatus::Complete->value),
        ]);
        $service = $this->cooperativeService();

        $this->runStage($service, 'runCollectionFileCheckStage2', 1);
        $filechecks = DB::table('collections')->orderBy('id')->pluck('filecheck', 'id')->map(fn ($v): int => (int) $v)->all();
        self::assertSame(CollectionFileCheckStatus::ZeroPart->value, $filechecks[1]);
        self::assertSame(1, $filechecks[2], 'unacceptable zero-part row is not moved');
        self::assertSame(1, $filechecks[3], 'row outside the page is untouched');

        $this->runStage($service, 'runCollectionFileCheckStage2', 1);
        self::assertSame(CollectionFileCheckStatus::ZeroPart->value, (int) DB::table('collections')->where('id', 3)->value('filecheck'));
    }

    public function test_cursor_is_not_stored_when_the_stage_work_fails(): void
    {
        config(['nntmux.release_stage_scan_window' => 1]);
        $this->insertCollection(1, 1, CollectionFileCheckStatus::Default->value, 0);
        DB::table('binaries')->insert($this->binary(1, 1, 1, 1));
        DB::listen(static function ($query): void {
            if (str_starts_with(str_replace('`', '"', $query->sql), 'update "collections"')) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $this->runStage($this->cooperativeService(), 'runCollectionFileCheckStage0', 1);
            self::fail('expected failure');
        } catch (\Throwable) {
        }

        self::assertNull(Cache::store('array')->get('nntmux:release-pump:stage0:1'));
    }

    /** @return array<string, int> */
    private function binary(int $id, int $collectionId, int $current, int $total, int $partcheck = 0): array
    {
        return [
            'id' => $id,
            'collections_id' => $collectionId,
            'filenumber' => $id,
            'totalparts' => $total,
            'currentparts' => $current,
            'partcheck' => $partcheck,
        ];
    }

    private function runStage(ReleaseProcessingService $service, string $method, mixed ...$args): void
    {
        (new ReflectionMethod($service, $method))->invoke($service, ...$args);
    }

    public function test_stage_two_prefilter_leaves_incomplete_collections_at_one_with_no_updates(): void
    {
        for ($id = 1; $id <= 3; $id++) {
            $this->insertCollection($id, 1, CollectionFileCheckStatus::CompleteCollection->value, 2);
            DB::table('binaries')->insert($this->binary($id, $id, 0, 1));
        }
        $updates = 0;
        DB::listen(static function ($query) use (&$updates): void {
            if (str_starts_with(strtolower($query->sql), 'update')) {
                $updates++;
            }
        });

        $this->runStage($this->cooperativeService(), 'runCollectionFileCheckStage2', 1);

        self::assertSame(0, $updates);
        self::assertSame(3, DB::table('collections')->where('filecheck', CollectionFileCheckStatus::CompleteCollection->value)->count());
    }

    public function test_stage_two_mirrors_stage_four_at_a_partial_completion_threshold(): void
    {
        // completion=75, totalfiles=4: stage 4 needs CEIL(3) = 3 complete
        // binaries, and stage 3 completes a binary at CEIL(100 * 75 / 100) = 75.
        // Figures divide exactly because SQLite integer division would
        // truncate where MariaDB does not.
        $this->insertCollection(1, 1, CollectionFileCheckStatus::CompleteCollection->value, 4);
        $this->insertCollection(2, 1, CollectionFileCheckStatus::CompleteCollection->value, 4);
        DB::table('binaries')->insert([
            $this->binary(1, 1, 100, 100, FileCompletionStatus::Complete->value),
            $this->binary(2, 1, 75, 100),
            $this->binary(3, 1, 80, 100),
            $this->binary(4, 2, 100, 100, FileCompletionStatus::Complete->value),
            $this->binary(5, 2, 75, 100),
            $this->binary(6, 2, 74, 100),
        ]);
        $service = $this->cooperativeService(500, 75);

        foreach (['runCollectionFileCheckStage2', 'runCollectionFileCheckStage3', 'runCollectionFileCheckStage4'] as $stage) {
            $this->runStage($service, $stage, 1);
        }

        self::assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) DB::table('collections')->where('id', 1)->value('filecheck'));
        self::assertSame(CollectionFileCheckStatus::CompleteCollection->value, (int) DB::table('collections')->where('id', 2)->value('filecheck'));
    }

    public function test_stage_two_cursor_advances_by_scanned_rows_and_wraps_per_group(): void
    {
        config(['nntmux.release_stage_scan_window' => 2]);
        foreach ([1 => 1, 2 => 1, 3 => 1, 4 => 2] as $id => $group) {
            $this->insertCollection($id, $group, CollectionFileCheckStatus::CompleteCollection->value, 1);
        }
        DB::table('binaries')->insert($this->binary(3, 3, 1, 1, FileCompletionStatus::Complete->value));
        $service = $this->cooperativeService();
        $cache = Cache::store('array');

        $this->runStage($service, 'runCollectionFileCheckStage2', 1);
        self::assertSame(2, $cache->get('nntmux:release-pump:stage2:1'));
        self::assertSame(1, (int) DB::table('collections')->where('id', 3)->value('filecheck'));

        $this->runStage($service, 'runCollectionFileCheckStage2', 1);
        self::assertSame(0, $cache->get('nntmux:release-pump:stage2:1'));
        self::assertSame(CollectionFileCheckStatus::TempComplete->value, (int) DB::table('collections')->where('id', 3)->value('filecheck'));
        self::assertNull($cache->get('nntmux:release-pump:stage2:2'));
    }

    private function insertCollection(int $id, int $groupId, int $filecheck, int $totalfiles = 1): void
    {
        DB::table('collections')->insert([
            'id' => $id,
            'groups_id' => $groupId,
            'filecheck' => $filecheck,
            'totalfiles' => $totalfiles,
            'dateadded' => now()->subDay(),
        ]);
    }

    private function cooperativeService(int $batchSize = 500, int $completion = 100, int $delayHours = 2): ReleaseProcessingService
    {
        $reflection = new ReflectionClass(ReleaseProcessingService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('cooperativeSlice')->setValue($service, true);
        $reflection->getProperty('workBatchSize')->setValue($service, $batchSize);
        $reflection->getProperty('settings')->setValue(
            $service,
            new ProcessReleasesSettings(completion: $completion, collectionDelayTime: $delayHours),
        );

        return $service;
    }

    private function runStagesTwoToFive(ReleaseProcessingService $service): void
    {
        foreach (['runCollectionFileCheckStage2', 'runCollectionFileCheckStage3', 'runCollectionFileCheckStage4', 'runCollectionFileCheckStage5'] as $method) {
            $reflection = new ReflectionMethod($service, $method);
            $reflection->invoke($service, 1);
        }
    }
}
