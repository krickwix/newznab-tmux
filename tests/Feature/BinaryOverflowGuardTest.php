<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinaryHandler;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\IngestCollectionKeying;
use App\Services\CollectionsCleaningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A binary that already holds far more parts than it declares is a repost
 * flood folded into one row (binaryhash = md5(name + poster + group)). It can
 * never become a release, and every chunk that touched it X-locked it for the
 * whole chunk transaction. Its further headers are skipped: no part rows, no
 * aggregate update, and not reported as failed (so not sent to part repair).
 */
final class BinaryOverflowGuardTest extends TestCase
{
    private const GROUP = ['id' => 77, 'name' => 'alt.binaries.boneless'];

    private const POSTER = 'anon <anon@anon.local>';

    private const SUBJECT = 'pSEwzCD9OE - [01/20] - "pSEwzCD9OE.part004.rar" yEnc';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.obfuscated_brace_token_groups' => [],
            'nntmux.obfuscated_hash_set_groups' => [],
            'nntmux.ingest_collection_keying_groups' => [],
            'nntmux.ingest_collection_keying_legacy_adoption' => false,
        ]);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction(
            'GREATEST',
            static fn (int|float $left, int|float $right): int|float => max($left, $right),
            2
        );

        $this->createTables();
    }

    public function test_overflow_needs_a_declared_count_the_ratio_and_the_floor(): void
    {
        self::assertTrue(BinaryHandler::isOverflowed(64_235, 250));
        self::assertTrue(BinaryHandler::isOverflowed(1_000, 250));
        self::assertFalse(BinaryHandler::isOverflowed(999, 100), 'below the absolute floor');
        self::assertFalse(BinaryHandler::isOverflowed(3_999, 1_000), 'below 4x the declared count');
        self::assertTrue(BinaryHandler::isOverflowed(4_000, 1_000));
        self::assertFalse(BinaryHandler::isOverflowed(50_000, 0), 'no declared count, nothing to compare');
    }

    public function test_headers_for_an_overflowed_binary_are_skipped_and_never_counted(): void
    {
        DB::table('binaries')->insert([
            ['id' => 10, 'binaryhash' => BinaryHandler::binaryHash('Flood.File', 'poster@example.com', 1),
                'name' => 'Flood.File', 'collections_id' => 1, 'totalparts' => 250, 'currentparts' => 64_235, 'filenumber' => 1, 'partsize' => 1],
            ['id' => 20, 'binaryhash' => BinaryHandler::binaryHash('Normal.File', 'poster@example.com', 1),
                'name' => 'Normal.File', 'collections_id' => 2, 'totalparts' => 250, 'currentparts' => 10, 'filenumber' => 1, 'partsize' => 1],
        ]);

        $records = [
            0 => $this->record(1, 1, 'Flood.File', 11, 70_001),
            1 => $this->record(2, 1, 'Normal.File', 11, 70_002),
            2 => $this->record(1, 1, 'Flood.File', 12, 70_003),
        ];

        $handler = new BinaryHandler;
        $resolved = $handler->getOrCreateBinaries($records, 1);

        self::assertSame([1 => 20], $resolved);
        self::assertSame([0 => true, 2 => true], $handler->getOverflowSkippedIndexes());
        self::assertSame([10], $handler->getOverflowedBinaryIds());

        self::assertTrue($handler->flushUpdates());
        self::assertSame(64_235, (int) DB::table('binaries')->where('id', 10)->value('currentparts'));
        self::assertSame(11, (int) DB::table('binaries')->where('id', 20)->value('currentparts'));

        // State is per call: a clean chunk reports nothing skipped.
        $handler->reset();
        $handler->getOrCreateBinaries([0 => $this->record(2, 1, 'Normal.File', 12, 70_004)], 1);
        self::assertSame([], $handler->getOverflowSkippedIndexes());
        self::assertSame([], $handler->getOverflowedBinaryIds());
    }

    public function test_a_file_number_match_on_an_overflowed_row_is_not_skipped(): void
    {
        // Same (collection, filenumber), different identity: that is a shared
        // ordinal for the collision check to see, not a repost to drop.
        DB::table('binaries')->insert([
            'id' => 30, 'binaryhash' => 'someone-else', 'name' => 'Other.File',
            'collections_id' => 3, 'totalparts' => 250, 'currentparts' => 64_235, 'filenumber' => 1, 'partsize' => 1,
        ]);

        $handler = new BinaryHandler;
        $resolved = $handler->getOrCreateBinaries([0 => $this->record(3, 1, 'Flood.File', 1, 70_010)], 1);

        self::assertSame([0 => 30], $resolved);
        self::assertSame([], $handler->getOverflowSkippedIndexes());
    }

    public function test_storage_skips_the_flood_without_failing_or_queueing_part_repair(): void
    {
        $storage = $this->storage();

        self::assertSame([], $storage->store($this->headers(1, 2), self::GROUP, true));
        self::assertSame(2, DB::table('parts')->count());
        self::assertSame(0, $storage->overflowSkippedHeaders());

        // The flood: the row now holds far more than its 250 declared parts.
        $binaryId = (int) DB::table('binaries')->value('id');
        DB::table('binaries')->where('id', $binaryId)->update(['currentparts' => 64_235]);

        $log = Log::spy();
        $failed = $storage->store($this->headers(3, 5), self::GROUP, true);

        self::assertSame([], $failed, 'skipped headers must not go to part repair');
        self::assertSame(3, $storage->overflowSkippedHeaders());
        self::assertSame(2, DB::table('parts')->count(), 'no part rows for the flood');
        self::assertSame(64_235, (int) DB::table('binaries')->where('id', $binaryId)->value('currentparts'));
        $log->shouldHaveReceived('info', ['ingest skipped headers for overflowed binaries', [
            'group' => self::GROUP['name'],
            'headers' => 3,
            'binaries' => [$binaryId],
        ]]);
    }

    private function storage(): HeaderStorageService
    {
        return new HeaderStorageService(
            new CollectionHandler(new class extends CollectionsCleaningService
            {
                public function collectionsCleaner(string $subject, string $groupName = ''): array
                {
                    return ['id' => self::REGEX_GENERIC_MATCH, 'name' => $subject];
                }
            }),
            config: new BinariesConfig(partsChunkSize: 50, headerChunkSize: 50),
            keying: new IngestCollectionKeying([], false),
        );
    }

    /** @return list<array<string, mixed>> Articles $first..$last, parts $first..$last of 250 */
    private function headers(int $first, int $last): array
    {
        $headers = [];
        for ($part = $first; $part <= $last; $part++) {
            $subject = \sprintf('%s (%d/250)', self::SUBJECT, $part);
            preg_match('/^\s*(.+?)\s+\((\d+)\/(\d+)\)/', $subject, $matches);
            $headers[] = [
                'Number' => 1_000 + $part,
                'Subject' => $subject,
                'From' => self::POSTER,
                'Date' => 'Thu, 26 Nov 2026 08:00:00 +0000',
                'Message-ID' => '<flood'.$part.'@ngPost>',
                'Bytes' => 716_800,
                'Xref' => 'news.example '.self::GROUP['name'].':'.(1_000 + $part),
                'matches' => $matches,
            ];
        }

        return $headers;
    }

    /** @return array{header: array<string, mixed>, collection_id: int, file_number: int} */
    private function record(int $collectionId, int $fileNumber, string $subject, int $part, int $number): array
    {
        return [
            'header' => [
                'Number' => $number,
                'Subject' => $subject.' ('.$part.'/250)',
                'From' => 'poster@example.com',
                'Bytes' => 100,
                'matches' => [0 => $subject.' ('.$part.'/250)', 1 => $subject, 2 => $part, 3 => 250],
            ],
            'collection_id' => $collectionId,
            'file_number' => $fileNumber,
        ];
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255) UNIQUE)');
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            dateadded DATETIME NULL,
            added DATETIME NULL,
            xref TEXT DEFAULT \'\',
            groups_id INT,
            totalfiles INT,
            filesize INTEGER DEFAULT 0,
            filecheck INTEGER DEFAULT 0,
            collectionhash VARCHAR(40) UNIQUE,
            collection_regexes_id INT,
            releases_id INTEGER NULL,
            noise VARCHAR(64) DEFAULT \'\'
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            partcheck INTEGER DEFAULT 0,
            filenumber INT,
            partsize INT,
            UNIQUE(collections_id, filenumber)
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, number)
        )');
        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
            numberid INT,
            groups_id INT,
            attempts INT DEFAULT 0,
            recovery_kind VARCHAR(32) NULL,
            recovery_source_collection_id INT NULL,
            recovery_source_binary_id INT NULL,
            claim_token VARCHAR(64) NULL,
            claim_owner VARCHAR(128) NULL,
            claim_expires_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(numberid, groups_id)
        )');
    }
}
