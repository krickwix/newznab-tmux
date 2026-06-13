<?php

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinaryHandler;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\HeaderStorageTransaction;
use App\Services\Binaries\PartHandler;
use App\Services\BlacklistService;
use App\Services\CollectionsCleaningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class BinariesStorageInternalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
    }

    public function test_header_parser_excludes_usenet_index_posts_and_returns_received_numbers(): void
    {
        $parser = new HeaderParser(new class extends BlacklistService
        {
            public function isBlackListed(array $msg, string $groupName): bool
            {
                return false;
            }
        });

        $result = $parser->parse([
            $this->rawHeader(101, 'Example.Release (1/2)'),
            $this->rawHeader(102, 'Usenet Index Post Example.Release (1/1)'),
            ['Subject' => 'Missing number (1/1)'],
        ], 'alt.test');

        $this->assertSame([101, 102], $result['received']);
        $this->assertCount(1, $result['headers']);
        $this->assertSame(1, $result['notYEnc']);
    }

    public function test_part_handler_ignored_duplicate_is_not_reported_failed(): void
    {
        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, number)
        )');

        $handler = new PartHandler(100);
        $this->assertTrue($handler->addPart(1, $this->parsedHeader(201, 1)));
        $this->assertTrue($handler->flush());
        $this->assertSame([201], $handler->getInsertedNumbers());
        $this->assertSame([], $handler->getFailedNumbers());

        $this->assertTrue($handler->addPart(1, $this->parsedHeader(201, 1)));
        $this->assertTrue($handler->flush());
        $this->assertSame([], $handler->getFailedNumbers());
        $this->assertSame(1, DB::table('parts')->count());
    }

    public function test_binary_handler_flushes_cached_article_aggregate_updates(): void
    {
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            filenumber INT,
            partsize INT,
            UNIQUE(binaryhash, collections_id)
        )');

        $handler = new BinaryHandler;
        $first = $this->parsedHeader(251, 1, 'Aggregate.Release', 100);
        $second = $this->parsedHeader(252, 2, 'Aggregate.Release', 50);

        $binaryId = $handler->getOrCreateBinary($first, 1, 1, 0);
        $this->assertNotNull($binaryId);
        $this->assertSame($binaryId, $handler->getOrCreateBinary($second, 1, 1, 0));
        $this->assertTrue($handler->flushUpdates());

        $binary = DB::table('binaries')->where('id', $binaryId)->first();
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(150, (int) $binary->partsize);
    }

    public function test_sqlite_rollback_cleanup_keeps_unrelated_parts_with_same_article_number(): void
    {
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, collectionhash VARCHAR(40), noise VARCHAR(64))');
        DB::statement('CREATE TABLE binaries (id INTEGER PRIMARY KEY, collections_id INT)');
        DB::statement('CREATE TABLE parts (binaries_id INT, number INT, messageid VARCHAR(255), UNIQUE(binaries_id, number))');

        DB::table('collections')->insert(['id' => 1, 'collectionhash' => 'keep', 'noise' => '']);
        DB::table('binaries')->insert(['id' => 1, 'collections_id' => 1]);
        DB::table('parts')->insert(['binaries_id' => 1, 'number' => 777, 'messageid' => '<keep@example>']);

        $collectionHandler = new CollectionHandler;
        $binaryHandler = new BinaryHandler;
        $partHandler = new PartHandler;
        $transaction = new HeaderStorageTransaction($collectionHandler, $binaryHandler, $partHandler);

        $transaction->begin();
        DB::table('collections')->insert(['id' => 2, 'collectionhash' => 'rollback', 'noise' => $transaction->getBatchNoise()]);
        DB::table('binaries')->insert(['id' => 2, 'collections_id' => 2]);
        DB::table('parts')->insert(['binaries_id' => 2, 'number' => 777, 'messageid' => '<rollback@example>']);
        $this->setPrivateProperty($collectionHandler, 'insertedCollectionIds', [2 => true]);
        $this->setPrivateProperty($binaryHandler, 'insertedBinaryIds', [2 => true]);
        $transaction->markError();
        $this->assertFalse($transaction->finish());

        $this->assertSame(1, DB::table('parts')->where('binaries_id', 1)->where('number', 777)->count());
        $this->assertSame(0, DB::table('parts')->where('binaries_id', 2)->count());
    }

    public function test_header_storage_commits_successful_chunks_and_reports_failed_chunk_numbers(): void
    {
        $this->createHeaderStorageTables('CHECK(size < 500)');

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 2, headerChunkSize: 2));
        $failed = $service->store([
            $this->parsedHeader(301, 1, 'Chunk.One', 100),
            $this->parsedHeader(302, 2, 'Chunk.One', 100),
            $this->parsedHeader(303, 1, 'Chunk.Two', 999),
            $this->parsedHeader(304, 2, 'Chunk.Two', 999),
        ], ['id' => 1, 'name' => 'alt.test'], true);

        sort($failed);

        $this->assertSame([303, 304], $failed);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame([301, 302], DB::table('parts')->orderBy('number')->pluck('number')->all());
    }

    public function test_part_handler_logs_partial_insert_failures_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);
        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT CHECK(size < 500),
            UNIQUE(binaries_id, number)
        )');

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Parts insert partially failed',
                Mockery::on(static fn (array $context): bool => $context['attempted'] === 2
                    && $context['inserted'] === 1
                    && $context['failed'] === 1
                    && $context['failed_numbers'] === [702])
            );

        $handler = new PartHandler(100);
        $this->assertTrue($handler->addPart(1, $this->parsedHeader(701, 1, 'Partial.Insert', 100)));
        $this->assertTrue($handler->addPart(1, $this->parsedHeader(702, 2, 'Partial.Insert', 999)));

        $this->assertFalse($handler->flush());
        $this->assertSame([702], $handler->getFailedNumbers());
    }

    public function test_collection_handler_logs_bulk_insert_failures_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            collectionhash VARCHAR(40) UNIQUE,
            xref TEXT DEFAULT \'\'
        )');

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Bulk collection insert failed',
                Mockery::on(static fn (array $context): bool => $context['driver'] === 'sqlite'
                    && $context['group_id'] === 1
                    && $context['pending'] === 1
                    && $context['exception'] !== ''
                    && str_contains($context['message'], 'collection_regexes_id'))
            );

        $handler = $this->deterministicCollectionHandler();
        $resolved = $handler->getOrCreateCollections(
            [$this->parsedHeader(801, 1, 'Collection.Log', 100)],
            1,
            'alt.test',
            [0 => 2],
            'batch-noise'
        );

        $this->assertSame([], $resolved);
    }

    public function test_binary_handler_logs_bulk_insert_failures_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            collections_id INT,
            UNIQUE(binaryhash, collections_id)
        )');

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Bulk binary insert failed',
                Mockery::on(static fn (array $context): bool => $context['driver'] === 'sqlite'
                    && $context['group_id'] === 1
                    && $context['pending'] === 1
                    && $context['exception'] !== ''
                    && str_contains($context['message'], 'currentparts'))
            );

        $handler = new BinaryHandler;
        $resolved = $handler->getOrCreateBinaries([
            0 => [
                'header' => $this->parsedHeader(901, 1, 'Binary.Log', 100),
                'collection_id' => 1,
                'file_number' => 1,
            ],
        ], 1);

        $this->assertSame([], $resolved);
    }

    public function test_header_storage_batch_reuses_collection_and_binary_for_parts(): void
    {
        $this->createHeaderStorageTables();

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $failed = $service->store([
            $this->parsedHeader(401, 1, 'Batch.Release', 150),
            $this->parsedHeader(402, 2, 'Batch.Release', 175),
        ], ['id' => 1, 'name' => 'alt.test'], true);

        $binary = DB::table('binaries')->first();

        $this->assertSame([], $failed);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(325, (int) $binary->partsize);
    }

    public function test_header_storage_batch_updates_binary_that_exists_before_chunk(): void
    {
        $this->createHeaderStorageTables();

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $this->assertSame([], $service->store([
            $this->parsedHeader(501, 1, 'Existing.Batch.Release', 100),
        ], ['id' => 1, 'name' => 'alt.test'], true));

        $this->assertSame([], $service->store([
            $this->parsedHeader(502, 2, 'Existing.Batch.Release', 150),
        ], ['id' => 1, 'name' => 'alt.test'], true));

        $binary = DB::table('binaries')->first();

        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(250, (int) $binary->partsize);
    }

    public function test_header_storage_cleans_each_distinct_subject_once_per_chunk(): void
    {
        $this->createHeaderStorageTables();

        $cleaner = new class extends CollectionsCleaningService
        {
            public int $calls = 0;

            public function __construct()
            {
                parent::__construct();
            }

            public function collectionsCleaner(string $subject, string $groupName = ''): array
            {
                $this->calls++;

                return ['id' => 0, 'name' => $subject];
            }
        };

        $service = new HeaderStorageService(
            new CollectionHandler($cleaner),
            config: new BinariesConfig(partsChunkSize: 10, headerChunkSize: 10)
        );

        $failed = $service->store([
            $this->parsedHeaderWithTotal(551, 1, 4, 'Cached.Subject', 100),
            $this->parsedHeaderWithTotal(552, 2, 4, 'Cached.Subject', 100),
            $this->parsedHeaderWithTotal(553, 3, 4, 'Cached.Subject', 100),
            $this->parsedHeaderWithTotal(554, 4, 4, 'Cached.Subject', 100),
            $this->parsedHeaderWithTotal(555, 1, 2, 'Other.Subject', 100),
            $this->parsedHeaderWithTotal(556, 2, 2, 'Other.Subject', 100),
        ], ['id' => 1, 'name' => 'alt.test'], true);

        $this->assertSame([], $failed);
        $this->assertSame(2, $cleaner->calls);
        $this->assertSame(2, DB::table('collections')->count());
        $this->assertSame(2, DB::table('binaries')->count());
        $this->assertSame(6, DB::table('parts')->count());
    }

    public function test_header_storage_does_not_merge_same_subject_across_different_collections(): void
    {
        $this->createHeaderStorageTables();

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $failed = $service->store([
            $this->parsedHeaderWithTotal(601, 1, 2, 'Same.Subject', 100),
            $this->parsedHeaderWithTotal(602, 1, 3, 'Same.Subject', 200),
        ], ['id' => 1, 'name' => 'alt.test'], true);

        $binaries = DB::table('binaries')->orderBy('partsize')->get();

        $this->assertSame([], $failed);
        $this->assertSame(2, DB::table('collections')->count());
        $this->assertSame(2, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame([100, 200], $binaries->pluck('partsize')->map(static fn ($value): int => (int) $value)->all());
        $this->assertSame([1, 1], $binaries->pluck('currentparts')->map(static fn ($value): int => (int) $value)->all());
    }

    private function rawHeader(int $number, string $subject): array
    {
        return [
            'Number' => $number,
            'Subject' => $subject,
            'From' => 'poster@example.com',
            'Date' => time(),
            'Bytes' => 100,
            'Message-ID' => '<msg'.$number.'@example.com>',
            'Xref' => 'news.example.com group:'.$number,
        ];
    }

    private function parsedHeader(int $number, int $partNumber, string $subjectBase = 'Example.Release', int $bytes = 100): array
    {
        return $this->parsedHeaderWithTotal($number, $partNumber, 2, $subjectBase, $bytes);
    }

    private function parsedHeaderWithTotal(int $number, int $partNumber, int $totalParts, string $subjectBase, int $bytes = 100): array
    {
        $header = $this->rawHeader($number, $subjectBase.' ('.$partNumber.'/'.$totalParts.')');
        $header['Bytes'] = $bytes;
        $header['matches'] = [
            0 => $header['Subject'],
            1 => $subjectBase,
            2 => $partNumber,
            3 => $totalParts,
        ];

        return $header;
    }

    private function createHeaderStorageTables(string $partSizeConstraint = ''): void
    {
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            xref TEXT DEFAULT \'\',
            groups_id INT,
            totalfiles INT,
            collectionhash VARCHAR(40) UNIQUE,
            collection_regexes_id INT,
            dateadded DATETIME NULL,
            noise VARCHAR(64) DEFAULT \'\'
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            filenumber INT,
            partsize INT,
            UNIQUE(binaryhash, collections_id)
        )');

        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT '.$partSizeConstraint.',
            UNIQUE(binaries_id, number)
        )');

        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INT DEFAULT 1,
            ordinal INT DEFAULT 0
        )');
    }

    private function deterministicCollectionHandler(): CollectionHandler
    {
        return new CollectionHandler(new class extends CollectionsCleaningService
        {
            public function __construct()
            {
                parent::__construct();
            }

            public function collectionsCleaner(string $subject, string $groupName = ''): array
            {
                return ['id' => 0, 'name' => $subject];
            }
        });
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
