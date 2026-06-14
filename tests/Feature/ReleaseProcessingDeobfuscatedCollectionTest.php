<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Services\ReleaseProcessingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReleaseProcessingDeobfuscatedCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction(
            'GREATEST',
            static fn (int|float $left, int|float $right): int|float => max($left, $right),
            2
        );

        $this->createTables();
        $this->seedSettings();
    }

    public function test_totalfile_zero_deobfuscated_collection_promotes_from_dense_observed_file_numbers(): void
    {
        $this->seedCollection(1, 'dense-obfuscated', 0);

        for ($fileNumber = 1; $fileNumber <= 95; $fileNumber++) {
            $this->seedBinary(1000 + $fileNumber, 1, $fileNumber, currentParts: 1, totalParts: 1);
        }
        $this->seedBinary(1200, 1, 100, currentParts: 0, totalParts: 1);

        $this->seedCollection(2, 'sparse-obfuscated', 0);
        $this->seedBinary(2001, 2, 70, currentParts: 1, totalParts: 1);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $dense = DB::table('collections')->where('id', 1)->first();
        $sparse = DB::table('collections')->where('id', 2)->first();

        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $dense->filecheck);
        $this->assertSame(96, (int) $dense->totalfiles);
        $this->assertSame(CollectionFileCheckStatus::Default->value, (int) $sparse->filecheck);
        $this->assertSame(70, (int) $sparse->totalfiles);
    }

    public function test_totalfile_zero_sparse_collection_records_observed_total_without_promotion(): void
    {
        $this->seedCollection(7, 'legacy-sparse-obfuscated', 0);
        $this->seedBinary(7001, 7, 70, currentParts: 1, totalParts: 1);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $collection = DB::table('collections')->where('id', 7)->first();

        $this->assertSame(CollectionFileCheckStatus::Default->value, (int) $collection->filecheck);
        $this->assertSame(70, (int) $collection->totalfiles);
    }

    public function test_totalfile_zero_repair_does_not_promote_duplicate_file_numbers(): void
    {
        $this->seedCollection(8, 'duplicate-file-number-obfuscated', 0);
        for ($index = 0; $index < 67; $index++) {
            $this->seedBinary(8000 + $index, 8, 70, currentParts: 1, totalParts: 1);
        }

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $collection = DB::table('collections')->where('id', 8)->first();

        $this->assertSame(CollectionFileCheckStatus::Default->value, (int) $collection->filecheck);
        $this->assertSame(70, (int) $collection->totalfiles);
    }

    public function test_binary_part_completion_honors_configured_completion_percent(): void
    {
        $this->seedCollection(3, 'near-complete-parts', 2);
        $this->seedBinary(3001, 3, 1, currentParts: 95, totalParts: 100);
        $this->seedBinary(3002, 3, 2, currentParts: 100, totalParts: 100);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $collection = DB::table('collections')->where('id', 3)->first();

        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $collection->filecheck);
        $this->assertSame(2, (int) $collection->totalfiles);
    }

    public function test_delayed_collection_is_not_forced_complete_when_any_binary_is_incomplete(): void
    {
        DB::table('settings')->where('name', 'completionpercent')->update(['value' => '100']);

        $this->seedCollection(4, 'delayed-incomplete-parts', 2, now()->subHours(13)->format('Y-m-d H:i:s'));
        $this->seedBinary(4001, 4, 1, currentParts: 100, totalParts: 100);
        $this->seedBinary(4002, 4, 2, currentParts: 94, totalParts: 100);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $collection = DB::table('collections')->where('id', 4)->first();

        $this->assertSame(CollectionFileCheckStatus::CompleteCollection->value, (int) $collection->filecheck);
        $this->assertSame(2, (int) $collection->totalfiles);
    }

    public function test_totalfiles_collection_uses_completion_threshold_before_promotion(): void
    {
        DB::table('settings')->where('name', 'completionpercent')->update(['value' => '75']);

        $this->seedCollection(5, 'three-of-four-files', 4);
        $this->seedBinary(5001, 5, 1, currentParts: 1, totalParts: 1);
        $this->seedBinary(5002, 5, 2, currentParts: 1, totalParts: 1);
        $this->seedBinary(5003, 5, 3, currentParts: 1, totalParts: 1);

        $this->seedCollection(6, 'two-of-four-files', 4);
        $this->seedBinary(6001, 6, 1, currentParts: 1, totalParts: 1);
        $this->seedBinary(6002, 6, 2, currentParts: 1, totalParts: 1);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $completeEnough = DB::table('collections')->where('id', 5)->first();
        $tooSparse = DB::table('collections')->where('id', 6)->first();

        $this->assertSame(CollectionFileCheckStatus::CompleteParts->value, (int) $completeEnough->filecheck);
        $this->assertSame(3, (int) $completeEnough->totalfiles);
        $this->assertSame(CollectionFileCheckStatus::Default->value, (int) $tooSparse->filecheck);
        $this->assertSame(4, (int) $tooSparse->totalfiles);
    }

    public function test_totalfiles_collection_promotion_processes_sparse_windows_past_batch_size(): void
    {
        DB::table('settings')->where('name', 'completionpercent')->update(['value' => '75']);

        for ($collectionId = 1000; $collectionId < 1605; $collectionId++) {
            if ($collectionId === 1250) {
                $this->seedCollection($collectionId, 'sparse-middle', 4);
                $this->seedBinary($collectionId * 10, $collectionId, 1, currentParts: 1, totalParts: 1);
                $this->seedBinary($collectionId * 10 + 1, $collectionId, 2, currentParts: 1, totalParts: 1);

                continue;
            }

            $this->seedCollection($collectionId, 'complete-window-'.$collectionId, 4);
            $this->seedBinary($collectionId * 10, $collectionId, 1, currentParts: 1, totalParts: 1);
            $this->seedBinary($collectionId * 10 + 1, $collectionId, 2, currentParts: 1, totalParts: 1);
            $this->seedBinary($collectionId * 10 + 2, $collectionId, 3, currentParts: 1, totalParts: 1);
        }

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $remainingDefault = DB::table('collections')
            ->where('filecheck', CollectionFileCheckStatus::Default->value)
            ->pluck('id')
            ->all();

        $this->assertSame([1250], $remainingDefault);
        $this->assertSame(
            604,
            DB::table('collections')
                ->where('filecheck', CollectionFileCheckStatus::CompleteParts->value)
                ->count()
        );
    }

    public function test_totalfile_zero_dense_collection_promotion_processes_past_batch_size(): void
    {
        for ($collectionId = 2000; $collectionId < 2505; $collectionId++) {
            $this->seedCollection($collectionId, 'dense-zero-'.$collectionId, 0);
            $this->seedBinary($collectionId * 10, $collectionId, 1, currentParts: 1, totalParts: 1);
        }

        $this->seedCollection(2600, 'sparse-zero-boundary', 0);
        $this->seedBinary(26000, 2600, 10, currentParts: 1, totalParts: 1);

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $this->assertSame(
            505,
            DB::table('collections')
                ->whereBetween('id', [2000, 2504])
                ->where('filecheck', CollectionFileCheckStatus::CompleteParts->value)
                ->count()
        );
        $this->assertSame(
            CollectionFileCheckStatus::Default->value,
            (int) DB::table('collections')->where('id', 2600)->value('filecheck')
        );
    }

    public function test_later_filecheck_stages_process_candidates_past_batch_size(): void
    {
        DB::table('settings')->where('name', 'completionpercent')->update(['value' => '95']);

        for ($collectionId = 3000; $collectionId < 3505; $collectionId++) {
            $this->seedCollection(
                $collectionId,
                'temp-complete-'.$collectionId,
                1,
                filecheck: CollectionFileCheckStatus::TempComplete->value
            );
            $this->seedBinary($collectionId * 10, $collectionId, 1, currentParts: 95, totalParts: 100);
        }

        for ($collectionId = 4000; $collectionId < 4505; $collectionId++) {
            $this->seedCollection(
                $collectionId,
                'delayed-stage6-'.$collectionId,
                2,
                now()->subHours(13)->format('Y-m-d H:i:s'),
                10
            );
            $this->seedBinary($collectionId * 10, $collectionId, 1, currentParts: 100, totalParts: 100);
            $this->seedBinary($collectionId * 10 + 1, $collectionId, 2, currentParts: 100, totalParts: 100);
        }

        $service = new ReleaseProcessingService;
        $service->setEchoCLI(false);
        $service->processIncompleteCollections(null);

        $this->assertSame(
            505,
            DB::table('binaries')
                ->whereBetween('collections_id', [3000, 3504])
                ->where('partcheck', 1)
                ->count()
        );
        $this->assertSame(
            1010,
            DB::table('collections')
                ->whereBetween('id', [3000, 4504])
                ->where('filecheck', CollectionFileCheckStatus::CompleteParts->value)
                ->count()
        );
    }

    private function seedCollection(
        int $id,
        string $subject,
        int $totalFiles,
        ?string $dateAdded = null,
        int $filecheck = CollectionFileCheckStatus::Default->value
    ): void {
        $dateAdded ??= now()->format('Y-m-d H:i:s');

        DB::table('collections')->insert([
            'id' => $id,
            'subject' => $subject,
            'fromname' => 'poster@example.com',
            'date' => now()->format('Y-m-d H:i:s'),
            'dateadded' => $dateAdded,
            'added' => $dateAdded,
            'xref' => 'alt.binaries.blu-ray:'.$id,
            'groups_id' => 5,
            'totalfiles' => $totalFiles,
            'filesize' => 0,
            'filecheck' => $filecheck,
            'collectionhash' => 'collection-'.$id,
            'collection_regexes_id' => 0,
            'releases_id' => null,
            'noise' => '',
        ]);
    }

    private function seedBinary(
        int $id,
        int $collectionId,
        int $fileNumber,
        int $currentParts,
        int $totalParts
    ): void {
        DB::table('binaries')->insert([
            'id' => $id,
            'name' => sprintf('file.%03d.rar', $fileNumber),
            'collections_id' => $collectionId,
            'currentparts' => $currentParts,
            'totalparts' => $totalParts,
            'partcheck' => 0,
            'filenumber' => $fileNumber,
            'partsize' => 100,
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
            'maxsizetoformrelease' => '0',
            'minsizetoformrelease' => '0',
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

    private function createTables(): void
    {
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
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
            totalparts INTEGER,
            partcheck INTEGER DEFAULT 0,
            filenumber INTEGER,
            partsize INTEGER
        )');
    }
}
