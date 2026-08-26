<?php

declare(strict_types=1);

namespace Tests\Unit\Binaries;

use App\Services\Binaries\CollectionFileNumberAllocator;
use App\Services\Binaries\CollectionFileNumberCollision;
use App\Services\Binaries\TransientHeaderStorageFailure;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Section 3 of docs/design/2026-08-04-ingest-collection-keying.md -- the dense
 * ordinal allocator, and the part the design says it would want reviewed
 * hardest.
 *
 * The property under test is the one stage 0 actually gates on. With
 * `settings.completion` NULL (-> 100 via requiredCompletionPercent()), the gate
 *
 *     COUNT(DISTINCT filenumber) >= GREATEST(1, CEIL(MAX(filenumber) * completion / 100))
 *
 * reduces to COUNT(DISTINCT filenumber) == MAX(filenumber). Dense means dense:
 * an ordinal left at 1 fails, and so does a sparse one.
 */
final class CollectionFileNumberAllocatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
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
    }

    private function allocator(): CollectionFileNumberAllocator
    {
        return new CollectionFileNumberAllocator;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: string}>  $rows  index => [collectionId, hash, sortSubject]
     * @return array<int, array{collection_id: int, hash: string, sort: string}>
     */
    private function requests(array $rows): array
    {
        $requests = [];
        foreach ($rows as $index => [$collectionId, $hash, $sort]) {
            $requests[$index] = [
                'collection_id' => $collectionId,
                'hash' => $hash,
                'sort' => $sort,
            ];
        }

        return $requests;
    }

    private function insertBinary(int $id, int $collectionId, string $hash, int $fileNumber): void
    {
        DB::table('binaries')->insert([
            'id' => $id,
            'binaryhash' => $hash,
            'name' => 'file-'.$fileNumber,
            'collections_id' => $collectionId,
            'totalparts' => 1,
            'currentparts' => 1,
            'filenumber' => $fileNumber,
            'partsize' => 1,
        ]);
    }

    /**
     * The whole point: an empty collection numbers 1..N, and N is dense.
     */
    public function test_a_fresh_collection_is_numbered_densely_from_one(): void
    {
        $allocated = $this->allocator()->allocate($this->requests([
            10 => [7, str_repeat('a', 32), 'Posting_(x.part03.rar) yEnc'],
            11 => [7, str_repeat('b', 32), 'Posting_(x.part01.rar) yEnc'],
            12 => [7, str_repeat('c', 32), 'Posting_(x.part02.rar) yEnc'],
        ]));

        // Subject sort, not arrival order: part01, part02, part03.
        self::assertSame([10 => 3, 11 => 1, 12 => 2], $allocated);

        $numbers = array_values($allocated);
        sort($numbers);
        self::assertSame(range(1, 3), $numbers, 'ordinals must be dense 1..N');
    }

    /**
     * Every article of one file shares a binary hash, so every article of one
     * file must share an ordinal -- otherwise one file would consume N slots and
     * the density gate could never be met.
     */
    public function test_every_article_of_one_file_gets_the_same_ordinal(): void
    {
        $hashA = str_repeat('a', 32);
        $hashB = str_repeat('b', 32);

        $allocated = $this->allocator()->allocate($this->requests([
            0 => [7, $hashA, 'Posting_(x.part01.rar) yEnc'],
            1 => [7, $hashA, 'Posting_(x.part01.rar) yEnc'],
            2 => [7, $hashA, 'Posting_(x.part01.rar) yEnc'],
            3 => [7, $hashB, 'Posting_(x.part02.rar) yEnc'],
        ]));

        self::assertSame([0 => 1, 1 => 1, 2 => 1, 3 => 2], $allocated);
    }

    /**
     * Design step 1: continue from MAX(filenumber), so a posting split across
     * ingest chunks stays dense across the seam.
     */
    public function test_allocation_continues_from_the_collections_high_water_mark(): void
    {
        $this->insertBinary(1, 7, str_repeat('a', 32), 1);
        $this->insertBinary(2, 7, str_repeat('b', 32), 2);

        $allocated = $this->allocator()->allocate($this->requests([
            0 => [7, str_repeat('c', 32), 'Posting_(x.part03.rar) yEnc'],
            1 => [7, str_repeat('d', 32), 'Posting_(x.part04.rar) yEnc'],
        ]));

        self::assertSame([0 => 3, 1 => 4], $allocated);
    }

    /**
     * Design step 3: "Files already present resolve by binary hash and keep the
     * ordinal they have." Re-numbering a committed row would move a filenumber
     * other rows may already be paired against.
     */
    public function test_a_file_already_present_keeps_its_ordinal(): void
    {
        $hashA = str_repeat('a', 32);
        $this->insertBinary(1, 7, $hashA, 1);
        $this->insertBinary(2, 7, str_repeat('b', 32), 2);

        $allocated = $this->allocator()->allocate($this->requests([
            0 => [7, $hashA, 'Posting_(x.part01.rar) yEnc'],
            1 => [7, str_repeat('c', 32), 'Posting_(x.part03.rar) yEnc'],
        ]));

        self::assertSame([0 => 1, 1 => 3], $allocated);
    }

    /**
     * A legacy row sitting at filenumber 0 keeps its 0 and consumes no slot.
     * Stage 0 joins on `binaries.filenumber > 0`, so the 0-row is invisible to
     * both COUNT and MAX and density is preserved.
     */
    public function test_a_legacy_zero_ordinal_is_kept_and_consumes_no_slot(): void
    {
        $hashA = str_repeat('a', 32);
        $this->insertBinary(1, 7, $hashA, 0);

        $allocated = $this->allocator()->allocate($this->requests([
            0 => [7, $hashA, 'Posting_(x.part01.rar) yEnc'],
            1 => [7, str_repeat('b', 32), 'Posting_(x.part02.rar) yEnc'],
            2 => [7, str_repeat('c', 32), 'Posting_(x.part03.rar) yEnc'],
        ]));

        self::assertSame([0 => 0, 1 => 1, 2 => 2], $allocated);
    }

    /**
     * Collections are numbered independently; one collection's high-water mark
     * must not leak into another's.
     */
    public function test_collections_are_numbered_independently(): void
    {
        $this->insertBinary(1, 7, str_repeat('a', 32), 9);

        $allocated = $this->allocator()->allocate($this->requests([
            0 => [7, str_repeat('b', 32), 'A yEnc'],
            1 => [8, str_repeat('c', 32), 'A yEnc'],
        ]));

        self::assertSame([0 => 10, 1 => 1], $allocated);
    }

    /**
     * Replaying the same chunk must produce the same numbering, whatever order
     * the headers arrive in. Without this a part-repair replay would renumber a
     * posting and strand the ordinals already written.
     */
    public function test_allocation_is_deterministic_under_reordering(): void
    {
        $rows = [
            [7, str_repeat('c', 32), 'Posting_(x.part03.rar) yEnc'],
            [7, str_repeat('a', 32), 'Posting_(x.part01.rar) yEnc'],
            [7, str_repeat('b', 32), 'Posting_(x.part02.rar) yEnc'],
        ];

        $forward = $this->allocator()->allocate($this->requests($rows));
        $reversed = $this->allocator()->allocate($this->requests(array_reverse($rows)));

        self::assertSame(
            [$rows[0][1] => $forward[0], $rows[1][1] => $forward[1], $rows[2][1] => $forward[2]],
            [$rows[0][1] => $reversed[2], $rows[1][1] => $reversed[1], $rows[2][1] => $reversed[0]],
        );
    }

    /**
     * The contention path, exercised rather than merely present.
     *
     * A concurrent lane taking an allocated ordinal does NOT raise on insert --
     * BinaryHandler's bulk statement carries
     * ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) -- it silently resolves to
     * the other writer's binary. So the check is that the binary we were handed
     * is the file we asked for.
     */
    public function test_a_stolen_ordinal_is_detected_after_resolution(): void
    {
        $ours = str_repeat('a', 32);
        $theirs = str_repeat('f', 32);

        // The row that actually landed at (collection 7, filenumber 1) belongs
        // to another writer's file.
        $this->insertBinary(1, 7, $theirs, 1);

        $requests = $this->requests([
            0 => [7, $ours, 'Posting_(x.part01.rar) yEnc'],
        ]);

        $this->expectException(CollectionFileNumberCollision::class);
        $this->allocator()->assertOrdinalsHeld($requests, [0 => 1]);
    }

    /**
     * The same collision seen from inside the batch, before any read: two
     * distinct files cannot share one binary.
     */
    public function test_two_files_resolving_to_one_binary_is_a_collision(): void
    {
        $requests = $this->requests([
            0 => [7, str_repeat('a', 32), 'Posting_(x.part01.rar) yEnc'],
            1 => [7, str_repeat('b', 32), 'Posting_(x.part02.rar) yEnc'],
        ]);

        $this->expectException(CollectionFileNumberCollision::class);
        $this->allocator()->assertOrdinalsHeld($requests, [0 => 41, 1 => 41]);
    }

    /**
     * The corner that decides how this allocator is keyed.
     *
     * BinaryHandler's in-batch article key is `collectionId . ':' . matches[1]`,
     * but its binary hash also folds in `From`. Two headers with the same
     * subject and a different poster in one chunk therefore collapse to ONE
     * binary there. If this allocator keyed on the hash, it would see two files,
     * hand out two ordinals, then read the single shared binary as a stolen
     * ordinal -- and because a replay derives exactly the same thing, those
     * articles would stall in part repair forever rather than transiently.
     *
     * So identity here is the subject, matching BinaryHandler, and this case is
     * one file with one ordinal.
     */
    public function test_one_subject_posted_twice_is_one_file_not_a_collision(): void
    {
        $subject = 'Posting_(x.part01.rar) yEnc';

        $allocated = $this->allocator()->allocate($this->requests([
            0 => [7, str_repeat('a', 32), $subject],
            1 => [7, str_repeat('b', 32), $subject],
            2 => [7, str_repeat('c', 32), 'Posting_(x.part02.rar) yEnc'],
        ]));

        self::assertSame([0 => 1, 1 => 1, 2 => 2], $allocated);

        // And the same pair resolving to one binary is not read as theft.
        $this->insertBinary(1, 7, str_repeat('a', 32), 1);
        $this->allocator()->assertOrdinalsHeld(
            $this->requests([
                0 => [7, str_repeat('a', 32), $subject],
                1 => [7, str_repeat('b', 32), $subject],
            ]),
            [0 => 1, 1 => 1],
        );

        self::assertTrue(true, 'one subject collapsing to one binary is BinaryHandler behaviour, not contention');
    }

    /**
     * The happy path must not raise, or every enabled batch would churn through
     * three attempts and land in part repair.
     */
    public function test_ordinals_we_hold_verify_clean(): void
    {
        $hashA = str_repeat('a', 32);
        $hashB = str_repeat('b', 32);
        $this->insertBinary(1, 7, $hashA, 1);
        $this->insertBinary(2, 7, $hashB, 2);

        $requests = $this->requests([
            0 => [7, $hashA, 'Posting_(x.part01.rar) yEnc'],
            1 => [7, $hashB, 'Posting_(x.part02.rar) yEnc'],
        ]);

        $this->allocator()->assertOrdinalsHeld($requests, [0 => 1, 1 => 2]);

        self::assertTrue(true, 'verification of ordinals we hold must not raise');
    }

    /**
     * A header with no binary at all is already handled by the caller as a
     * failed header; it is not a stolen ordinal and must not poison the chunk.
     */
    public function test_a_header_without_a_binary_is_not_a_collision(): void
    {
        $requests = $this->requests([
            0 => [7, str_repeat('a', 32), 'Posting_(x.part01.rar) yEnc'],
        ]);

        $this->allocator()->assertOrdinalsHeld($requests, []);

        self::assertTrue(true, 'a missing binary id is the caller\'s failure path, not a collision');
    }

    /**
     * The collision must land on the chunk retry path -- roll back, re-read
     * MAX(filenumber), and after MAX_CHUNK_ATTEMPTS send the articles to part
     * repair. If it were not recognised as transient it would escape store()
     * entirely and take the lane down.
     */
    public function test_a_collision_is_treated_as_transient_by_header_storage(): void
    {
        self::assertTrue(TransientHeaderStorageFailure::is(
            CollectionFileNumberCollision::sharedBinary(1, 'a', 'b')
        ));
    }
}
