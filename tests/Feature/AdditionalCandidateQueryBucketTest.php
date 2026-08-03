<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The regression that stalled additional postprocessing for ten weeks.
 *
 * PostProcessRunner::processAdditional() fans out one child per GUID bucket
 * returned by AdditionalCandidateQuery::bucketChars(). The projection is a
 * single hex character taken from `releases.leftguid` -- it is not a key. When
 * it was aliased `id` on an Eloquent builder over the Release model, Eloquent's
 * implicit primary-key cast applied: HasAttributes::getCasts() merges
 * [getKeyName() => getKeyType()] for incrementing models, so reading `$row->id`
 * returned (int) 'e' === 0 for every hex-letter bucket.
 *
 * The observable damage was silent. bucketChars() still returned the right
 * NUMBER of buckets, so the fan-out looked healthy in the logs ("6 job(s) to
 * do"), but all six children were dispatched onto bucket '0'. In production
 * that bucket held 1 of 3,700 candidates, so every cycle finished in ~2 seconds
 * having done nothing, and no release ever gained the inner-archive / mediainfo
 * evidence that releases:fix-names needs. Obfuscated postings therefore stayed
 * in Other -> Hashed permanently instead of being renamed into Movies.
 *
 * Digit buckets are the reason this hid so well: (int) '0' === 0 round-trips
 * cleanly, so any fixture built on numeric GUIDs passes against the broken
 * code. The assertions below deliberately use hex-letter buckets.
 */
final class AdditionalCandidateQueryBucketTest extends TestCase
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

        // bucketChars() resolves the size window through Settings; without the
        // table the query throws before it can be exercised.
        DB::statement('CREATE TABLE settings (
            name VARCHAR(255) PRIMARY KEY,
            value TEXT
        )');
        DB::table('settings')->insert([
            ['name' => 'minsizetopostprocess', 'value' => '1'],
            ['name' => 'maxsizetopostprocess', 'value' => '100'],
        ]);

        DB::statement('CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            disablepreview INT DEFAULT 0
        )');

        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            leftguid VARCHAR(1) DEFAULT \'\',
            passwordstatus INT DEFAULT -1,
            haspreview INT DEFAULT -1,
            nzbstatus INT DEFAULT 0,
            size BIGINT DEFAULT 0,
            categories_id INT DEFAULT 0,
            groups_id INT DEFAULT 0
        )');

        DB::table('categories')->insert(['id' => 20, 'disablepreview' => 0]);
    }

    /**
     * @param  non-empty-string  $leftguid
     */
    private function seedCandidate(int $id, string $leftguid): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'leftguid' => $leftguid,
            'passwordstatus' => -1,
            'haspreview' => -1,
            'nzbstatus' => 1,
            // Comfortably inside the default 1 MB .. 100 GB size window.
            'size' => 2 * 1073741824,
            'categories_id' => 20,
            'groups_id' => 1,
        ]);
    }

    public function test_bucket_chars_preserves_hex_letter_buckets(): void
    {
        $this->seedCandidate(1, 'e');
        $this->seedCandidate(2, 'a');
        $this->seedCandidate(3, 'f');

        $chars = AdditionalCandidateQuery::bucketChars();
        sort($chars);

        // Against the pre-fix code this was ['0', '0', '0'].
        $this->assertSame(['a', 'e', 'f'], $chars);
    }

    public function test_every_bucket_returned_actually_holds_a_candidate(): void
    {
        $this->seedCandidate(1, 'c');
        $this->seedCandidate(2, 'd');

        foreach (AdditionalCandidateQuery::bucketChars() as $char) {
            $this->assertGreaterThan(
                0,
                AdditionalCandidateQuery::baseBuilder('', $char)->count(),
                sprintf('bucket "%s" was advertised by the fan-out but holds no candidate', $char),
            );
        }
    }

    public function test_mixed_digit_and_letter_buckets_are_not_collapsed(): void
    {
        $this->seedCandidate(1, '0');
        $this->seedCandidate(2, 'b');

        $chars = AdditionalCandidateQuery::bucketChars();
        sort($chars);

        // The broken cast collapsed 'b' onto '0', hiding a whole bucket behind
        // a duplicate of one that legitimately existed.
        $this->assertSame(['0', 'b'], $chars);
        $this->assertCount(2, array_unique($chars));
    }
}
