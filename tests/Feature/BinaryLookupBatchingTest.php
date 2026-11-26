<?php

namespace Tests\Feature;

use App\Services\Binaries\BinaryHandler;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BinaryHandler's pre-insert binary lookups (by hash and by file number) must
 * cost a bounded number of round trips per header chunk, not one per
 * collection, without changing which binary a header resolves to.
 */
class BinaryLookupBatchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            filenumber INT,
            partsize INT,
            UNIQUE(collections_id, filenumber)
        )');
        DB::statement('CREATE TABLE parts (binaries_id INT, number INT, size INT DEFAULT 0, UNIQUE(binaries_id, number))');
    }

    public function test_lookup_round_trips_do_not_scale_with_collections_in_the_chunk(): void
    {
        $small = $this->lookupCounts($this->records(5));
        DB::table('binaries')->delete();
        $large = $this->lookupCounts($this->records(40, 1000));

        // hash prefetch + file-number prefetch + post-insert file-number fallback
        $this->assertSame(['hash' => 1, 'file' => 2], $small);
        $this->assertSame($small, $large);
    }

    public function test_lookups_are_chunked_at_the_statement_value_cap(): void
    {
        $records = $this->records(501);

        $counts = $this->lookupCounts($records, $resolved);

        $this->assertSame(['hash' => 2, 'file' => 4], $counts);
        $this->assertCount(501, $resolved);
        $this->assertCount(501, array_unique($resolved));
        $this->assertSame(501, DB::table('binaries')->count());
        foreach ($records as $index => $record) {
            $this->assertSame(
                $resolved[$index],
                (int) DB::table('binaries')->where('collections_id', $record['collection_id'])->value('id')
            );
        }
    }

    public function test_duplicate_headers_of_one_file_resolve_to_one_binary(): void
    {
        $records = [
            0 => $this->record(1, 1, 'Dup.File', 1, 11),
            1 => $this->record(1, 1, 'Dup.File', 2, 12),
            2 => $this->record(2, 1, 'Other.File', 1, 21),
            3 => $this->record(1, 1, 'Dup.File', 1, 11),
        ];

        $handler = new BinaryHandler;
        $resolved = $handler->getOrCreateBinaries($records, 1);

        $this->assertSame(2, DB::table('binaries')->count());
        $this->assertSame($resolved[0], $resolved[1]);
        $this->assertSame($resolved[0], $resolved[3]);
        $this->assertNotSame($resolved[0], $resolved[2]);

        // The count is the parts rows that exist: article 11 appears twice in
        // the chunk but is one row.
        DB::table('parts')->insert([
            ['binaries_id' => $resolved[0], 'number' => 11],
            ['binaries_id' => $resolved[0], 'number' => 12],
            ['binaries_id' => $resolved[2], 'number' => 21],
        ]);
        $this->assertTrue($handler->syncPartCounts());
        $this->assertSame(2, (int) DB::table('binaries')->where('id', $resolved[0])->value('currentparts'));
        $this->assertSame(1, (int) DB::table('binaries')->where('id', $resolved[2])->value('currentparts'));
    }

    public function test_existing_and_new_binaries_across_collections_resolve_correctly(): void
    {
        DB::table('binaries')->insert([
            ['id' => 70, 'binaryhash' => BinaryHandler::binaryHash('Old.By.Hash', 'poster@example.com', 1),
                'name' => 'Old.By.Hash', 'collections_id' => 3, 'totalparts' => 2, 'currentparts' => 1, 'filenumber' => 1, 'partsize' => 100],
            ['id' => 80, 'binaryhash' => 'legacy-hash', 'name' => 'Old.By.File',
                'collections_id' => 4, 'totalparts' => 2, 'currentparts' => 1, 'filenumber' => 2, 'partsize' => 100],
        ]);

        $records = [
            0 => $this->record(3, 1, 'Old.By.Hash', 2, 31),
            1 => $this->record(3, 2, 'New.In.Three', 1, 32),
            2 => $this->record(4, 2, 'Old.By.File', 2, 41),
            3 => $this->record(5, 1, 'New.In.Five', 1, 51),
        ];

        $handler = new BinaryHandler;
        $resolved = $handler->getOrCreateBinaries($records, 1);

        $this->assertSame(70, $resolved[0]);
        $this->assertSame(80, $resolved[2]);
        $this->assertSame(4, DB::table('binaries')->count());
        $this->assertSame(
            [$resolved[1], $resolved[3]],
            array_map(static fn ($id): int => (int) $id, DB::table('binaries')->whereNotIn('id', [70, 80])->orderBy('id')->pluck('id')->all())
        );

        $inserted = $handler->getInsertedIds();
        sort($inserted);
        $this->assertSame([$resolved[1], $resolved[3]], $inserted);
    }

    public function test_row_inserted_by_another_worker_after_the_prefetch_is_still_resolved(): void
    {
        $records = [
            0 => $this->record(7, 1, 'Raced.File', 1, 71),
            1 => $this->record(8, 1, 'Calm.File', 1, 81),
        ];

        // Another worker commits the same (collection, filenumber) under a
        // different hash after both prefetches ran and before the insert, so
        // only the post-insert fallback can find it.
        $injected = false;
        DB::listen(static function ($event) use (&$injected): void {
            if ($injected || ! str_contains($event->sql, 'filenumber IN')) {
                return;
            }

            $injected = true;
            DB::table('binaries')->insert([
                'id' => 500, 'binaryhash' => 'other-worker', 'name' => 'Raced.File',
                'collections_id' => 7, 'totalparts' => 2, 'currentparts' => 1, 'filenumber' => 1, 'partsize' => 100,
            ]);
        });

        $handler = new BinaryHandler;
        $resolved = $handler->getOrCreateBinaries($records, 1);

        $this->assertTrue($injected);
        $this->assertSame(500, $resolved[0]);
        $this->assertGreaterThan(0, $resolved[1]);
        $this->assertNotSame(500, $resolved[1]);
        $this->assertSame(2, DB::table('binaries')->count());
    }

    /**
     * @param  array<int, array{header: array<string, mixed>, collection_id: int, file_number: int}>  $records
     * @param  array<int, int>|null  $resolved
     * @return array{hash: int, file: int}
     */
    private function lookupCounts(array $records, ?array &$resolved = null): array
    {
        $counts = ['hash' => 0, 'file' => 0];
        DB::listen(static function ($event) use (&$counts): void {
            if (! str_starts_with($event->sql, 'SELECT') || ! str_contains($event->sql, 'FROM binaries')) {
                return;
            }
            if (str_contains($event->sql, 'binaryhash IN')) {
                $counts['hash']++;
            } elseif (str_contains($event->sql, 'filenumber IN')) {
                $counts['file']++;
            }
        });

        $resolved = (new BinaryHandler)->getOrCreateBinaries($records, 1);
        $this->assertCount(\count($records), $resolved);

        return $counts;
    }

    /** @return array<int, array{header: array<string, mixed>, collection_id: int, file_number: int}> */
    private function records(int $collections, int $firstCollectionId = 1): array
    {
        $records = [];
        for ($i = 0; $i < $collections; $i++) {
            $collectionId = $firstCollectionId + $i;
            $records[$i] = $this->record($collectionId, 1, 'File.'.$collectionId, 1, 10_000 + $collectionId);
        }

        return $records;
    }

    /** @return array{header: array<string, mixed>, collection_id: int, file_number: int} */
    private function record(int $collectionId, int $fileNumber, string $subject, int $part, int $number): array
    {
        return [
            'header' => [
                'Number' => $number,
                'Subject' => $subject.' ('.$part.'/2)',
                'From' => 'poster@example.com',
                'Bytes' => 100,
                'matches' => [0 => $subject.' ('.$part.'/2)', 1 => $subject, 2 => $part, 3 => 2],
            ],
            'collection_id' => $collectionId,
            'file_number' => $fileNumber,
        ];
    }
}
