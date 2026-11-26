<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\IngestCollectionKeying;
use App\Services\CollectionsCleaningService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * binaries.currentparts / partsize are set after each chunk commits, from the
 * parts rows that exist, instead of being incremented inside the chunk
 * transaction from a pre-insert existence check. These pin what the counters
 * must still mean.
 */
final class BinaryPartCountAfterCommitTest extends TestCase
{
    private const GROUP = ['id' => 5, 'name' => 'alt.binaries.test'];

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

        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY, subject VARCHAR(255), fromname VARCHAR(255), date DATETIME NULL,
            dateadded DATETIME NULL, added DATETIME NULL, xref TEXT DEFAULT \'\', groups_id INT, totalfiles INT,
            filesize INTEGER DEFAULT 0, filecheck INTEGER DEFAULT 0, collectionhash VARCHAR(40) UNIQUE,
            collection_regexes_id INT, releases_id INTEGER NULL, noise VARCHAR(64) DEFAULT \'\'
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY, binaryhash BLOB, name VARCHAR(255), collections_id INT, totalparts INT,
            currentparts INT, partcheck INTEGER DEFAULT 0, filenumber INT, partsize INT,
            UNIQUE(collections_id, filenumber)
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INT, number INT, messageid VARCHAR(255), partnumber INT, size INT,
            UNIQUE(binaries_id, number)
        )');
        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY, numberid INT, groups_id INT, attempts INT DEFAULT 0,
            recovery_kind VARCHAR(32) NULL, recovery_source_collection_id INT NULL,
            recovery_source_binary_id INT NULL, claim_token VARCHAR(64) NULL, claim_owner VARCHAR(128) NULL,
            claim_expires_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE(numberid, groups_id)
        )');
    }

    public function test_counts_match_the_parts_rows_across_chunk_boundaries(): void
    {
        self::assertSame([], $this->storage(headerChunkSize: 3)->store($this->headers(1, 10), self::GROUP, true));

        $binary = DB::table('binaries')->first();
        self::assertSame(1, DB::table('binaries')->count());
        self::assertSame(10, (int) $binary->currentparts);
        self::assertSame(10 * 700, (int) $binary->partsize);
    }

    public function test_storing_the_same_articles_again_does_not_overcount(): void
    {
        // The overlap two lanes can produce (part repair re-fetching what a
        // range worker already stored). An increment from a pre-insert
        // existence read counts such a part twice once chunks run unserialised.
        $storage = $this->storage();
        $storage->store($this->headers(1, 6), self::GROUP, true);
        $storage->store($this->headers(4, 8), self::GROUP, true);

        $binary = DB::table('binaries')->first();
        self::assertSame(8, DB::table('parts')->count());
        self::assertSame(8, (int) $binary->currentparts);
        self::assertSame(8 * 700, (int) $binary->partsize);
    }

    public function test_a_missed_recount_is_repaired_by_the_next_chunk_on_that_binary(): void
    {
        $storage = $this->storage();
        $storage->store($this->headers(1, 5), self::GROUP, true);

        // As if the process died between the chunk commit and its recount.
        DB::table('binaries')->update(['currentparts' => 0, 'partsize' => 0]);

        $storage->store($this->headers(6, 6), self::GROUP, true);

        $binary = DB::table('binaries')->first();
        self::assertSame(6, (int) $binary->currentparts);
        self::assertSame(6 * 700, (int) $binary->partsize);
    }

    public function test_a_recount_never_lowers_an_existing_counter(): void
    {
        $storage = $this->storage();
        $storage->store($this->headers(1, 2), self::GROUP, true);
        DB::table('binaries')->update(['currentparts' => 40, 'partsize' => 99_999]);

        $storage->store($this->headers(3, 3), self::GROUP, true);

        $binary = DB::table('binaries')->first();
        self::assertSame(40, (int) $binary->currentparts);
        self::assertSame(99_999, (int) $binary->partsize);
    }

    private function storage(int $headerChunkSize = 50): HeaderStorageService
    {
        return new HeaderStorageService(
            new CollectionHandler(new class extends CollectionsCleaningService
            {
                public function collectionsCleaner(string $subject, string $groupName = ''): array
                {
                    return ['id' => self::REGEX_GENERIC_MATCH, 'name' => $subject];
                }
            }),
            config: new BinariesConfig(partsChunkSize: 50, headerChunkSize: $headerChunkSize),
            keying: new IngestCollectionKeying([], false),
        );
    }

    /** @return list<array<string, mixed>> Articles 100+$first..100+$last, parts $first..$last of 40 */
    private function headers(int $first, int $last): array
    {
        $headers = [];
        for ($part = $first; $part <= $last; $part++) {
            $subject = \sprintf('Some.Release [1/1] - "some.release.rar" yEnc (%d/40)', $part);
            preg_match('/^\s*(.+?)\s+\((\d+)\/(\d+)\)/', $subject, $matches);
            $headers[] = [
                'Number' => 100 + $part,
                'Subject' => $subject,
                'From' => 'poster <poster@example.com>',
                'Date' => 'Thu, 26 Nov 2026 08:00:00 +0000',
                'Message-ID' => '<p'.$part.'@ngPost>',
                'Bytes' => 700,
                'Xref' => 'news.example '.self::GROUP['name'].':'.(100 + $part),
                'matches' => $matches,
            ];
        }

        return $headers;
    }
}
