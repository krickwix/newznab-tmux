<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\CollectionFileNumberAllocator;
use App\Services\Binaries\CollectionFileNumberCollision;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\IngestCollectionKeying;
use App\Services\CollectionsCleaningService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The contention half of section 3 of
 * docs/design/2026-08-04-ingest-collection-keying.md, which the design calls
 * "the piece I would want reviewed hardest" and "not optional".
 *
 * `binaries` is UNIQUE (collections_id, filenumber) and there is no per-group
 * lock anywhere in the ingest path, so two lanes -- `binaries`,
 * `current-forward`, `backfill` all reach this code -- can allocate from the
 * same stale MAX(filenumber). The design's contract is: catch the collision,
 * re-read MAX, retry the batch, and on repeated failure fail the batch to part
 * repair rather than guessing.
 *
 * That contract is served by the chunk retry loop HeaderStorageService already
 * had: TransientHeaderStorageFailure recognises the collision, storeChunk()
 * rolls back and retries up to MAX_CHUNK_ATTEMPTS, and the final failure pushes
 * the chunk's article numbers into part repair. This file proves the path runs,
 * not that it exists.
 *
 * A real race cannot be staged from one process, so the collision is injected
 * at the point the production code would detect it.
 */
final class IngestCollectionKeyingContentionTest extends TestCase
{
    private const GROUP = ['id' => 4211, 'name' => 'alt.binaries.cinemageddon'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.obfuscated_brace_token_groups' => [],
            'nntmux.obfuscated_hash_set_groups' => [],
        ]);
        DB::purge();
        DB::reconnect();

        $this->createTables();
    }

    /**
     * One collision, then success: the batch survives, and the shape is the one
     * a clean run would have produced. Re-reading MAX after the rollback is what
     * makes the second attempt land on 1..N rather than N+1..2N.
     */
    public function test_a_single_collision_is_retried_and_the_batch_lands_intact(): void
    {
        $allocator = new FlakyFileNumberAllocator(1);

        $failed = $this->storage($allocator)->store($this->postingHeaders(), self::GROUP, true);

        self::assertSame(1, $allocator->collisionsRaised, 'the retry path must actually have been taken');
        self::assertSame([], $failed, 'a retried chunk must not reach part repair');

        self::assertSame(1, DB::table('collections')->count());
        self::assertSame(0, (int) DB::table('collections')->value('totalfiles'));
        self::assertSame(
            [1, 2, 3],
            array_map('intval', DB::table('binaries')->orderBy('filenumber')->pluck('filenumber')->all()),
            'the retry must re-read MAX, not continue from the rolled-back allocation',
        );
        self::assertSame(6, DB::table('parts')->count());
    }

    /**
     * Persistent contention fails the batch to part repair rather than guessing.
     * "The failure mode is a stall, not corruption": every article number comes
     * back for retry and nothing is left half-written.
     */
    public function test_persistent_contention_fails_the_batch_to_part_repair(): void
    {
        $allocator = new FlakyFileNumberAllocator(PHP_INT_MAX);

        $headers = $this->postingHeaders();
        $failed = $this->storage($allocator)->store($headers, self::GROUP, true);

        self::assertSame(
            array_map(static fn (array $header): int => (int) $header['Number'], $headers),
            $failed,
            'every article of the failed chunk must go to part repair',
        );

        self::assertGreaterThan(1, $allocator->collisionsRaised, 'the batch must be retried before it is given up on');

        // Rolled back, not half-written.
        self::assertSame(0, DB::table('binaries')->count());
        self::assertSame(0, DB::table('parts')->count());
        self::assertSame(0, DB::table('collections')->count());
    }

    /**
     * The escape hatch must not swallow a non-transient bug. A collision is
     * retried; anything else still propagates out of store().
     */
    public function test_a_non_transient_allocator_failure_is_not_retried(): void
    {
        $this->expectException(\LogicException::class);

        $this->storage(new ExplodingFileNumberAllocator)
            ->store($this->postingHeaders(), self::GROUP, true);
    }

    // ------------------------------------------------------------- fixtures

    private function storage(CollectionFileNumberAllocator $allocator): HeaderStorageService
    {
        return new HeaderStorageService(
            new CollectionHandler(new class extends CollectionsCleaningService
            {
                public function collectionsCleaner(string $subject, string $groupName = ''): array
                {
                    return [
                        'id' => self::REGEX_GENERIC_MATCH,
                        'name' => preg_replace('/\d+/', '', $subject) ?? $subject,
                    ];
                }
            }),
            config: new BinariesConfig(partsChunkSize: 50, headerChunkSize: 50),
            keying: new IngestCollectionKeying([self::GROUP['name']], false),
            fileNumbers: $allocator,
        );
    }

    /**
     * Three counter-less files of one posting, two articles each.
     *
     * @return list<array<string, mixed>>
     */
    private function postingHeaders(): array
    {
        $names = [
            '60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol001+01.PAR2)',
            '60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol009+06.PAR2)',
            '60s_Sleaze_-_Submission_(1969)_repost_(Submission.vol015+11.PAR2)',
        ];
        $totalParts = [2, 7, 11];

        $parsed = [];
        $article = 0;
        foreach ($names as $fileIndex => $name) {
            foreach ([1, 2] as $part) {
                $article++;
                $subject = \sprintf('%s yEnc (%d/%d)', $name, $part, $totalParts[$fileIndex]);
                preg_match('/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/', $subject, $matches);

                $parsed[] = [
                    'Number' => $article,
                    'Subject' => $subject,
                    'From' => 'Sleazer <sleaze@test.com>',
                    'Date' => 'Tue, 04 Aug 2026 09:11:02 +0000',
                    'Message-ID' => '<article'.$article.'@ngPost>',
                    'Bytes' => 640000,
                    'Xref' => 'news.example '.self::GROUP['name'].':'.$article,
                    'matches' => $matches,
                ];
            }
        }

        return $parsed;
    }

    private function createTables(): void
    {
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            dateadded DATETIME NULL,
            xref TEXT DEFAULT \'\',
            groups_id INT,
            totalfiles INT,
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

        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INT DEFAULT 1,
            ordinal INT DEFAULT 0
        )');
    }
}

/**
 * Raises the collision the production code would raise when another lane takes
 * an allocated ordinal, for the first N verification passes.
 */
final class FlakyFileNumberAllocator extends CollectionFileNumberAllocator
{
    public int $collisionsRaised = 0;

    public function __construct(private readonly int $collisionsToRaise) {}

    public function assertOrdinalsHeld(array $requests, array $binaryIds): void
    {
        if ($this->collisionsRaised < $this->collisionsToRaise) {
            $this->collisionsRaised++;

            throw CollectionFileNumberCollision::sharedBinary(1, 'aaa', 'bbb');
        }

        parent::assertOrdinalsHeld($requests, $binaryIds);
    }
}

/**
 * A genuine bug in the allocator, not contention. It must not be retried into
 * silence.
 */
final class ExplodingFileNumberAllocator extends CollectionFileNumberAllocator
{
    public function assertOrdinalsHeld(array $requests, array $binaryIds): void
    {
        throw new \LogicException('allocator is broken');
    }
}
