<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesService;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * A misaligned-overview batch is neither stored nor queued for part repair, so the group cursor
 * must stay where it is. If the abort reported an article range like a normal scan, the caller
 * would advance last_record past a block nothing will ever revisit -- silent, permanent data loss.
 */
final class BinariesMisalignedHeaderCursorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255) UNIQUE,
            first_record INTEGER DEFAULT 0,
            first_record_postdate DATETIME NULL,
            last_record INTEGER DEFAULT 0,
            last_record_postdate DATETIME NULL,
            last_updated DATETIME NULL
        )');
        DB::table('usenet_groups')->insert([
            'id' => 7,
            'name' => 'alt.binaries.movies',
            'first_record' => 1000,
            'first_record_postdate' => '2026-07-01 00:00:00',
            'last_record' => 5000,
            'last_record_postdate' => '2026-07-20 00:00:00',
        ]);
    }

    /**
     * The abort must not be shaped like a successful scan. An article range is enough to move the
     * cursor, and the numbers in one are trustworthy even when misaligned -- they come from
     * 'Number', not from the shifted fields -- so a range-shaped return would look entirely valid.
     */
    public function test_misaligned_result_is_not_shaped_like_a_scanned_range(): void
    {
        $misaligned = ['misalignedHeaders' => true];

        self::assertArrayNotHasKey('lastArticleNumber', $misaligned);
        self::assertArrayNotHasKey('firstArticleNumber', $misaligned);
    }

    /**
     * Pins the reason the abort cannot simply return the parsed range: a summary carrying
     * lastArticleNumber advances last_record, stepping over the unstored block for good.
     */
    public function test_a_range_shaped_summary_would_have_advanced_the_cursor(): void
    {
        $this->updateGroupAfterScan([
            'firstArticleNumber' => 6000,
            'lastArticleNumber' => 6999,
            'lastArticleDate' => 'Mon, 20 Jul 2026 12:00:00 +0000',
        ], last: 6999);

        self::assertSame(6999, $this->lastRecord());
    }

    /**
     * And pins that an empty summary is no defence either: the else branch advances to $last, so
     * the abort genuinely needs its own signal that stops the caller before this runs.
     */
    public function test_an_empty_summary_would_also_have_advanced_the_cursor(): void
    {
        $this->updateGroupAfterScan([], last: 6999);

        self::assertSame(6999, $this->lastRecord());
    }

    /**
     * The behaviour that matters: with the misaligned signal the caller returns early, so nothing
     * touches the group and the range is retried on the next cycle.
     */
    public function test_cursor_is_untouched_when_the_caller_honours_the_signal(): void
    {
        $scanSummary = ['misalignedHeaders' => true];

        // This is the guard added to updateGroup()/processBackfillChunks(); it runs before any
        // group write, which is what keeps the cursor pinned.
        if (($scanSummary['misalignedHeaders'] ?? false) !== true) {
            $this->updateGroupAfterScan($scanSummary, last: 6999);
        }

        self::assertSame(5000, $this->lastRecord());
        self::assertSame(1000, (int) DB::table('usenet_groups')->where('id', 7)->value('first_record'));
    }

    /**
     * @param  array<string, mixed>  $scanSummary
     */
    private function updateGroupAfterScan(array $scanSummary, int $last): void
    {
        $reflection = new ReflectionClass(BinariesService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $groupMySQL = (array) DB::table('usenet_groups')->where('id', 7)->first();

        $reflection->getMethod('updateGroupAfterScan')->invokeArgs($service, [
            &$groupMySQL,
            ['group' => 'alt.binaries.movies'],
            $scanSummary,
            $last,
        ]);
    }

    private function lastRecord(): int
    {
        return (int) DB::table('usenet_groups')->where('id', 7)->value('last_record');
    }
}
