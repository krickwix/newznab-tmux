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
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.distributed_lock_store' => 'array',
        ]);
        DB::purge('sqlite');
        Cache::store('array')->flush();
        DB::connection()->getPdo()->sqliteCreateFunction('GREATEST', max(...), -1);

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
        DB::listen(static function ($query): void {
            if (str_starts_with($query->sql, 'select "id" from "collections"')) {
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

    private function cooperativeService(int $batchSize = 500): ReleaseProcessingService
    {
        $reflection = new ReflectionClass(ReleaseProcessingService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('cooperativeSlice')->setValue($service, true);
        $reflection->getProperty('workBatchSize')->setValue($service, $batchSize);
        $reflection->getProperty('settings')->setValue(
            $service,
            new ProcessReleasesSettings(completion: 100),
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
