<?php

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinaryHandler;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\HeaderStorageTransaction;
use App\Services\Binaries\PartHandler;
use App\Services\Binaries\TransientHeaderStorageFailure;
use App\Services\BlacklistService;
use App\Services\CollectionsCleaningService;
use Illuminate\Database\QueryException;
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

    public function test_part_handler_deduplicates_pending_parts_before_flush(): void
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
        $this->assertTrue($handler->addPart(1, $this->parsedHeader(211, 1)));
        $this->assertTrue($handler->addPart(1, $this->parsedHeader(211, 1)));

        $queries = $this->captureQueries(fn (): bool => $handler->flush());

        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame([211], $handler->getInsertedNumbers());
        $this->assertSame([], $handler->getFailedNumbers());
        $this->assertSame(1, $this->countValueTuplesForTable($queries, 'parts'));
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
            UNIQUE(collections_id, filenumber)
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

    public function test_binary_handler_prelocks_resolved_ids_in_primary_key_order_before_parts(): void
    {
        $handler = new BinaryHandler;

        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('select')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'FORCE INDEX (PRIMARY)')
                    && str_contains($sql, 'ORDER BY id FOR UPDATE')),
                [2, 9],
            )
            ->andReturn([(object) ['id' => 2], (object) ['id' => 9]]);

        $handler->prelockForPartWrites([9, 2, 9]);

        $this->addToAssertionCount(1);
    }

    public function test_binary_handler_prelocks_existing_ids_before_missing_binary_inserts(): void
    {
        $source = (string) file_get_contents(app_path('Services/Binaries/BinaryHandler.php'));
        $method = strpos($source, 'private function bulkInsertAndResolve');
        $prelock = strpos($source, '$this->prelockForPartWrites(array_values($idsByKey));', $method);
        $insert = strpos($source, '$this->bulkInsertBinariesMysql($insertRows);', $method);

        self::assertNotFalse($method);
        self::assertNotFalse($prelock);
        self::assertNotFalse($insert);
        self::assertLessThan($insert, $prelock);
    }

    public function test_binary_handler_sorts_missing_inserts_by_stable_unique_key(): void
    {
        $handler = new BinaryHandler;
        $method = new \ReflectionMethod($handler, 'sortBinaryInsertRows');
        $rows = [
            ['collections_id' => 2, 'filenumber' => 1, 'hash' => 'bbb'],
            ['collections_id' => 1, 'filenumber' => 2, 'hash' => 'ccc'],
            ['collections_id' => 1, 'filenumber' => 2, 'hash' => 'aaa'],
        ];

        $method->invokeArgs($handler, [&$rows]);

        self::assertSame(['aaa', 'ccc', 'bbb'], array_column($rows, 'hash'));
    }

    public function test_header_storage_locks_and_updates_binaries_before_any_part_write(): void
    {
        $source = (string) file_get_contents(app_path('Services/Binaries/HeaderStorageService.php'));
        $method = strpos($source, 'private function processHeaderChunk');
        $resolve = strpos($source, '$this->binaryHandler->getOrCreateBinaries', $method);
        $aggregate = strpos($source, '$this->binaryHandler->flushUpdates', $resolve);
        $part = strpos($source, '$this->partHandler->addPart', $resolve);

        self::assertNotFalse($method);
        self::assertNotFalse($resolve);
        self::assertNotFalse($aggregate);
        self::assertNotFalse($part);
        self::assertLessThan($aggregate, $resolve);
        self::assertLessThan($part, $aggregate);
    }

    public function test_binary_part_existence_check_uses_current_locking_read(): void
    {
        $handler = new BinaryHandler;
        $method = new \ReflectionMethod($handler, 'existingPartKeysForResolvedRows');

        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('select')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'FROM parts')
                    && str_contains($sql, 'FOR UPDATE')),
                [7, 100],
            )
            ->andReturn([(object) ['binaries_id' => 7, 'number' => 100]]);

        $existing = $method->invoke($handler, [
            'article' => [
                'hash' => 'abc',
                'collections_id' => 1,
                'filenumber' => 1,
                'number' => 100,
            ],
        ], ['abc:hash:1' => 7]);

        self::assertSame(['7:100' => true], $existing);
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

    public function test_transient_header_storage_failure_classifies_database_lock_errors(): void
    {
        foreach ([
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
            'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction',
            "SQLSTATE[HY000]: General error: 123 Got error 123 when reading table './nntmux/collections'",
            'SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table \'collections\'; try restarting transaction',
            'WSREP detected deadlock/conflict and aborted the transaction. Try restarting the transaction',
            'The process has been chosen as the deadlock victim',
        ] as $message) {
            $this->assertTrue(TransientHeaderStorageFailure::is($this->transientQueryException($message)), $message);
        }

        $this->assertFalse(TransientHeaderStorageFailure::is(new QueryException(
            'sqlite',
            'INSERT INTO collections',
            [],
            new \RuntimeException('SQLSTATE[42S02]: Base table or view not found: missing table')
        )));
    }

    public function test_binary_statement_retry_does_not_retry_inside_transaction(): void
    {
        $handler = new BinaryHandler;
        $method = new \ReflectionMethod($handler, 'runBinaryWriteWithRetry');
        $calls = 0;

        DB::beginTransaction();

        try {
            $method->invoke($handler, function () use (&$calls): void {
                $calls++;

                throw $this->transientQueryException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            });

            $this->fail('Transient failures inside a transaction must bubble to the chunk retry wrapper.');
        } catch (QueryException) {
            $this->assertSame(1, $calls);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_header_storage_retries_entire_chunk_after_transient_transaction_failure(): void
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

                if ($this->calls === 1) {
                    throw new QueryException(
                        'mariadb',
                        'INSERT INTO collections',
                        [],
                        new \RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')
                    );
                }

                return ['id' => 0, 'name' => $subject];
            }
        };

        $service = new HeaderStorageService(
            new CollectionHandler($cleaner),
            config: new BinariesConfig(partsChunkSize: 10, headerChunkSize: 10)
        );

        $failed = $service->store([
            $this->parsedHeader(751, 1, 'Transient.Chunk.Retry', 100),
            $this->parsedHeader(752, 2, 'Transient.Chunk.Retry', 150),
        ], ['id' => 1, 'name' => 'alt.test'], true);

        $binary = DB::table('binaries')->first();

        $this->assertSame([], $failed);
        $this->assertSame(2, $cleaner->calls);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(250, (int) $binary->partsize);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_header_storage_exhausts_transient_chunk_retries_without_partial_rows(): void
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

                throw new QueryException(
                    'mariadb',
                    'INSERT INTO collections',
                    [],
                    new \RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')
                );
            }
        };

        $service = new HeaderStorageService(
            new CollectionHandler($cleaner),
            config: new BinariesConfig(partsChunkSize: 10, headerChunkSize: 10)
        );

        $failed = $service->store([
            $this->parsedHeader(761, 1, 'Transient.Chunk.Exhausted', 100),
            $this->parsedHeader(762, 2, 'Transient.Chunk.Exhausted', 150),
        ], ['id' => 1, 'name' => 'alt.test'], true);

        sort($failed);

        $this->assertSame([761, 762], $failed);
        $this->assertSame(3, $cleaner->calls);
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::transactionLevel());
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

    public function test_collection_bulk_insert_retries_transient_mariadb_record_changed_errors(): void
    {
        $handler = $this->deterministicCollectionHandler();
        $method = new \ReflectionMethod($handler, 'bulkInsertCollectionsMysql');

        DB::shouldReceive('statement')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->andThrow(new QueryException(
                'mariadb',
                'INSERT INTO collections',
                [],
                new \RuntimeException('SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table \'collections\'; try restarting transaction')
            ));
        DB::shouldReceive('statement')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->andReturn(true);

        $method->invoke($handler, [
            'retry-collection' => [
                'subject' => 'Retry Collection',
                'fromname' => 'poster@example.com',
                'unixtime' => time(),
                'xref' => 'alt.test:123',
                'groups_id' => 1,
                'totalfiles' => 2,
                'collectionhash' => sha1('retry-collection'),
                'collection_regexes_id' => 0,
                'noise' => 'batch-noise',
                'xref_append' => '',
            ],
        ], []);

        $this->addToAssertionCount(1);
    }

    public function test_collection_xref_updates_prelock_global_primary_key_order(): void
    {
        $handler = $this->deterministicCollectionHandler();
        $method = new \ReflectionMethod($handler, 'bulkInsertCollectionsMysql');
        $ids = new \ReflectionProperty($handler, 'existingIdsByHash');
        $ids->setValue($handler, ['aaa' => 1, 'bbb' => 2]);

        DB::shouldReceive('select')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'FORCE INDEX (PRIMARY)')
                    && str_contains($sql, 'SELECT id, xref')
                    && str_contains($sql, 'ORDER BY id FOR UPDATE')),
                [1, 2],
            )
            ->andReturn([(object) ['id' => 1, 'xref' => ''], (object) ['id' => 2, 'xref' => '']]);

        DB::shouldReceive('statement')
            ->once()
            ->with(Mockery::on(static fn (string $sql): bool => str_contains($sql, 'u.id = c.id')), [1, 'alt.binaries.a:1', 2, 'alt.binaries.b:2', "\n"])
            ->andReturn(true);

        $method->invoke($handler, [], ['aaa' => true, 'bbb' => true], [
            'second' => ['collectionhash' => 'bbb', 'xref_append' => 'alt.binaries.b:2'],
            'first' => ['collectionhash' => 'aaa', 'xref_append' => 'alt.binaries.a:1'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_collection_xref_update_revalidates_missing_group_after_lock(): void
    {
        $handler = $this->deterministicCollectionHandler();
        $method = new \ReflectionMethod($handler, 'bulkInsertCollectionsMysql');
        $ids = new \ReflectionProperty($handler, 'existingIdsByHash');
        $ids->setValue($handler, ['aaa' => 1]);

        DB::shouldReceive('select')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'FORCE INDEX (PRIMARY)')
                    && str_contains($sql, 'ORDER BY id FOR UPDATE')),
                [1],
            )
            ->andReturn([(object) ['id' => 1, 'xref' => 'alt.binaries.foo:100']]);

        DB::shouldReceive('statement')->never();

        $method->invoke($handler, [], ['aaa' => true], [
            'first' => ['collectionhash' => 'aaa', 'xref_append' => 'alt.binaries.foo:900'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_collection_xref_update_keeps_only_groups_still_missing_after_lock(): void
    {
        $handler = $this->deterministicCollectionHandler();
        $method = new \ReflectionMethod($handler, 'bulkInsertCollectionsMysql');
        $ids = new \ReflectionProperty($handler, 'existingIdsByHash');
        $ids->setValue($handler, ['aaa' => 1]);

        DB::shouldReceive('select')
            ->once()
            ->with(Mockery::type('string'), [1])
            ->andReturn([(object) ['id' => 1, 'xref' => 'alt.binaries.foo:100']]);

        DB::shouldReceive('statement')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'u.id = c.id')),
                [1, 'alt.binaries.bar:200', "\n"],
            )
            ->andReturn(true);

        $method->invoke($handler, [], ['aaa' => true], [
            'first' => [
                'collectionhash' => 'aaa',
                'xref_append' => 'alt.binaries.foo:900 alt.binaries.bar:200',
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_existing_collections_take_shared_parent_locks_without_xref_updates(): void
    {
        $handler = $this->deterministicCollectionHandler();
        $method = new \ReflectionMethod($handler, 'bulkInsertCollectionsMysql');
        $ids = new \ReflectionProperty($handler, 'existingIdsByHash');
        $ids->setValue($handler, ['aaa' => 1]);

        DB::shouldReceive('select')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, 'LOCK IN SHARE MODE')
                    && str_contains($sql, 'ORDER BY id')),
                [1],
            )
            ->andReturn([(object) ['id' => 1, 'xref' => 'alt.binaries.foo:100']]);
        DB::shouldReceive('statement')->never();

        $method->invoke($handler, [], ['aaa' => true], [
            'first' => ['collectionhash' => 'aaa', 'xref_append' => ''],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_binary_handler_logs_bulk_insert_failures_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            collections_id INT,
            filenumber INT,
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

    public function test_binary_bulk_insert_retries_transient_mariadb_record_changed_errors(): void
    {
        $handler = new BinaryHandler;
        $method = new \ReflectionMethod($handler, 'bulkInsertBinariesMysql');

        DB::shouldReceive('statement')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->andThrow(new QueryException(
                'mariadb',
                'INSERT INTO binaries',
                [],
                new \RuntimeException('SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table \'binaries\'; try restarting transaction')
            ));
        DB::shouldReceive('statement')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->andReturn(true);

        $method->invoke($handler, [[
            'hash' => md5('retry-binary'),
            'name' => 'Retry.Binary',
            'collections_id' => 1,
            'totalparts' => 2,
            'filenumber' => 1,
            'partsize' => 100,
        ]]);

        $this->addToAssertionCount(1);
    }

    public function test_binary_bulk_insert_initializes_aggregates_at_zero(): void
    {
        $handler = new BinaryHandler;
        $method = new \ReflectionMethod($handler, 'bulkInsertBinariesMysql');

        DB::shouldReceive('statement')
            ->once()
            ->with(
                Mockery::on(static fn (string $sql): bool => str_contains($sql, '(UNHEX(?), ?, ?, ?, 0, ?, 0)')),
                Mockery::type('array'),
            )
            ->andReturn(true);

        $method->invoke($handler, [[
            'hash' => md5('zero-binary'),
            'name' => 'Zero.Binary',
            'collections_id' => 1,
            'totalparts' => 2,
            'filenumber' => 1,
            'partsize' => 100,
        ]]);

        $this->addToAssertionCount(1);
    }

    public function test_binary_aggregate_update_retries_transient_mariadb_record_changed_errors(): void
    {
        $handler = new BinaryHandler;
        $method = new \ReflectionMethod($handler, 'flushUpdatesMysql');

        DB::shouldReceive('statement')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->andThrow(new QueryException(
                'mariadb',
                'UPDATE binaries',
                [],
                new \RuntimeException('SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table \'binaries\'; try restarting transaction')
            ));
        DB::shouldReceive('statement')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->andReturn(true);

        $this->assertTrue($method->invoke($handler, [[
            'id' => 1,
            'partsize' => 100,
            'currentparts' => 1,
        ]], 1000));
    }

    public function test_binary_aggregate_update_bubbles_transient_failures_inside_transaction(): void
    {
        $handler = new BinaryHandler;
        $this->setPrivateProperty($handler, 'binariesUpdate', [
            1 => ['Size' => 100, 'Parts' => 1],
        ]);

        $connection = Mockery::mock();
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);

        DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('statement')
            ->once()
            ->with(Mockery::type('string'), Mockery::type('array'))
            ->andThrow($this->transientQueryException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));

        $this->expectException(QueryException::class);

        $handler->flushUpdates();
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

    public function test_header_storage_does_not_increment_binary_counts_for_duplicate_article_replay(): void
    {
        $this->createHeaderStorageTables();

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $header = $this->parsedHeader(525, 1, 'Duplicate.Article.Replay', 125);

        $this->assertSame([], $service->store([$header], ['id' => 1, 'name' => 'alt.test'], true));
        $this->assertSame([], $service->store([$header], ['id' => 1, 'name' => 'alt.test'], true));

        $binary = DB::table('binaries')->first();

        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame(1, (int) $binary->currentparts);
        $this->assertSame(125, (int) $binary->partsize);
    }

    public function test_header_storage_does_not_increment_binary_counts_for_duplicate_multipart_replay(): void
    {
        $this->createHeaderStorageTables();

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $headers = [
            $this->parsedHeader(526, 1, 'Duplicate.Multipart.Replay', 125),
            $this->parsedHeader(527, 2, 'Duplicate.Multipart.Replay', 175),
        ];

        $this->assertSame([], $service->store($headers, ['id' => 1, 'name' => 'alt.test'], true));
        $this->assertSame([], $service->store($headers, ['id' => 1, 'name' => 'alt.test'], true));

        $binary = DB::table('binaries')->first();

        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(300, (int) $binary->partsize);
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

    public function test_header_storage_falls_back_to_subject_counts_when_collection_metadata_is_partial(): void
    {
        $this->createHeaderStorageTables();

        $onlyFileNumber = $this->parsedHeaderWithTotal(701, 2, 3, 'Partial.Metadata.File', 100);
        $onlyFileNumber['collection_file_number'] = 99;

        $onlyTotalFiles = $this->parsedHeaderWithTotal(702, 2, 3, 'Partial.Metadata.Total', 150);
        $onlyTotalFiles['collection_total_files'] = 99;

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $failed = $service->store([$onlyFileNumber, $onlyTotalFiles], ['id' => 1, 'name' => 'alt.test'], true);

        $collections = DB::table('collections')->orderBy('subject')->get();
        $binaries = DB::table('binaries')->orderBy('name')->get();

        $this->assertSame([], $failed);
        $this->assertSame([3, 3], $collections->pluck('totalfiles')->map(static fn ($value): int => (int) $value)->all());
        $this->assertSame([2, 2], $binaries->pluck('filenumber')->map(static fn ($value): int => (int) $value)->all());
    }

    public function test_header_storage_prefers_bracketed_file_counts_over_year_ranges(): void
    {
        $this->createHeaderStorageTables();

        $header = $this->parsedHeaderWithTotal(
            703,
            148,
            148,
            'Joni Mitchell Archives Vol. 4 Asylum Years 1976-1980 2024 - [159/194]  - "Joni Mitchell Archives Vol. 4 Asylum Years 1976-1980 2024.part158.rar" yEnc',
            100
        );

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $failed = $service->store([$header], ['id' => 1, 'name' => 'alt.test'], true);

        $this->assertSame([], $failed);
        $this->assertSame(194, (int) DB::table('collections')->value('totalfiles'));
        $this->assertSame(159, (int) DB::table('binaries')->value('filenumber'));
        $this->assertSame(148, (int) DB::table('binaries')->value('totalparts'));
    }

    public function test_header_storage_prefers_file_count_marker_over_disc_count_marker(): void
    {
        $this->createHeaderStorageTables();

        $header = $this->parsedHeaderWithTotal(
            704,
            782,
            782,
            'AsianDVDClub.org - Rideback (2009) AVC 1080p BD50 Disk 2 of 2 - File 36 of 59: "adc-rbb.r33" yEnc',
            100
        );

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $failed = $service->store([$header], ['id' => 1, 'name' => 'alt.test'], true);

        $this->assertSame([], $failed);
        $this->assertSame(59, (int) DB::table('collections')->value('totalfiles'));
        $this->assertSame(36, (int) DB::table('binaries')->value('filenumber'));
        $this->assertSame(782, (int) DB::table('binaries')->value('totalparts'));
    }

    public function test_header_storage_accepts_legacy_underscore_of_file_counts(): void
    {
        $this->createHeaderStorageTables();

        $header = $this->parsedHeaderWithTotal(
            705,
            23,
            23,
            'Legacy underscore count [008_of_031] - "legacy.part007.rar" yEnc',
            100
        );

        $service = new HeaderStorageService($this->deterministicCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $failed = $service->store([$header], ['id' => 1, 'name' => 'alt.test'], true);

        $this->assertSame([], $failed);
        $this->assertSame(31, (int) DB::table('collections')->value('totalfiles'));
        $this->assertSame(8, (int) DB::table('binaries')->value('filenumber'));
        $this->assertSame(23, (int) DB::table('binaries')->value('totalparts'));
    }

    public function test_header_storage_resolves_duplicate_filenumber_with_different_binary_hash(): void
    {
        $this->createHeaderStorageTables();

        $service = new HeaderStorageService($this->singleCollectionHandler(), config: new BinariesConfig(partsChunkSize: 10));
        $first = $this->parsedHeaderWithTotal(1001, 1, 2, 'Same.File.Name.One', 100);
        $first['collection_file_number'] = 1;
        $first['collection_total_files'] = 1;
        $second = $this->parsedHeaderWithTotal(1002, 2, 2, 'Same.File.Name.Two', 150);
        $second['collection_file_number'] = 1;
        $second['collection_total_files'] = 1;

        $failed = $service->store([$first, $second], ['id' => 1, 'name' => 'alt.test'], true);

        $binary = DB::table('binaries')->first();

        $this->assertSame([], $failed);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
        $this->assertSame(2, (int) $binary->currentparts);
        $this->assertSame(250, (int) $binary->partsize);
    }

    public function test_collection_handler_skips_insert_when_prefetch_resolves_existing_hash(): void
    {
        $this->createHeaderStorageTables();

        $collectionHash = sha1('Existing.Collection2');
        DB::table('collections')->insert([
            'id' => 10,
            'subject' => 'Existing.Collection',
            'fromname' => 'poster@example.com',
            'date' => now(),
            'xref' => 'group:1200',
            'groups_id' => 1,
            'totalfiles' => 2,
            'collectionhash' => $collectionHash,
            'collection_regexes_id' => 0,
            'dateadded' => now(),
            'noise' => '',
        ]);

        $handler = $this->deterministicCollectionHandler();
        $queries = $this->captureQueries(fn (): array => $handler->getOrCreateCollections(
            [$this->parsedHeader(1201, 1, 'Existing.Collection', 100)],
            1,
            'alt.test',
            [0 => 2],
            'batch-noise'
        ));

        $this->assertSame(1, DB::table('collections')->count());
        $this->assertFalse($this->hasInsertIntoTable($queries, 'collections'));
    }

    public function test_binary_handler_skips_insert_when_file_key_already_resolves_existing_binary(): void
    {
        $this->createHeaderStorageTables();
        DB::table('binaries')->insert([
            'id' => 20,
            'binaryhash' => 'different-hash',
            'name' => 'Existing Binary',
            'collections_id' => 1,
            'totalparts' => 2,
            'currentparts' => 1,
            'filenumber' => 1,
            'partsize' => 100,
        ]);

        $handler = new BinaryHandler;
        $queries = $this->captureQueries(fn (): array => $handler->getOrCreateBinaries([
            0 => [
                'header' => $this->parsedHeader(1301, 1, 'Existing.Binary.Different.Hash', 100),
                'collection_id' => 1,
                'file_number' => 1,
            ],
        ], 1));

        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertFalse($this->hasInsertIntoTable($queries, 'binaries'));
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
            UNIQUE(collections_id, filenumber)
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

    private function singleCollectionHandler(): CollectionHandler
    {
        return new CollectionHandler(new class extends CollectionsCleaningService
        {
            public function __construct()
            {
                parent::__construct();
            }

            public function collectionsCleaner(string $subject, string $groupName = ''): array
            {
                return ['id' => 0, 'name' => 'single-cleaned-collection'];
            }
        });
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }

    private function transientQueryException(string $message): QueryException
    {
        return new QueryException(
            'mariadb',
            'INSERT INTO collections',
            [],
            new \RuntimeException($message)
        );
    }

    /**
     * @return list<string>
     */
    private function captureQueries(\Closure $callable): array
    {
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $callable();

        return $queries;
    }

    /**
     * @param  list<string>  $queries
     */
    private function hasInsertIntoTable(array $queries, string $table): bool
    {
        foreach ($queries as $sql) {
            if (preg_match('/\binsert(?:\s+or\s+ignore)?\s+into\s+["`]?'.preg_quote($table, '/').'["`]?\b/i', $sql)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $queries
     */
    private function countValueTuplesForTable(array $queries, string $table): int
    {
        $count = 0;
        foreach ($queries as $sql) {
            if (! preg_match('/\binsert(?:\s+or\s+ignore)?\s+into\s+["`]?'.preg_quote($table, '/').'["`]?\b/i', $sql)) {
                continue;
            }

            $count += substr_count($sql, '(?,?,?,?,?)');
        }

        return $count;
    }
}
