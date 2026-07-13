<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\GetArticleRange;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class GetArticleRangeBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            first_record INTEGER,
            first_record_postdate DATETIME NULL,
            last_record INTEGER,
            last_record_postdate DATETIME NULL,
            last_updated DATETIME NULL,
            active INTEGER,
            backfill INTEGER
        )');
        DB::statement('CREATE TABLE short_groups (
            name VARCHAR(255),
            first_record INTEGER,
            last_record INTEGER,
            updated DATETIME NULL
        )');

        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'a.b.multimedia.vintage-film',
            'first_record' => 3,
            'first_record_postdate' => '2025-09-20 16:56:29',
            'last_record' => 39602,
            'last_record_postdate' => '2026-02-12 23:32:04',
            'last_updated' => '2026-06-12 16:06:41',
            'active' => 1,
            'backfill' => 1,
        ]);
        DB::table('short_groups')->insert([
            'name' => 'a.b.multimedia.vintage-film',
            'first_record' => 2,
            'last_record' => 39602,
        ]);
    }

    public function test_backfill_range_disables_group_after_cursor_reaches_provider_first_article_when_configured(): void
    {
        DB::table('settings')->insert(['name' => 'disablebackfillgroup', 'value' => '1']);

        $this->updateGroupRecords('backfill', [
            'id' => 1,
            'name' => 'a.b.multimedia.vintage-film',
            'first_record' => 3,
        ], [
            'firstArticleNumber' => 2,
            'firstArticleDate' => '2008-08-19 00:59:23',
        ], 2);

        $group = DB::table('usenet_groups')->where('id', 1)->first();

        $this->assertSame(2, (int) $group->first_record);
        $this->assertSame(0, (int) $group->backfill);
    }

    public function test_backfill_range_keeps_group_enabled_when_disable_setting_is_off(): void
    {
        DB::table('settings')->insert(['name' => 'disablebackfillgroup', 'value' => '0']);

        $this->updateGroupRecords('backfill', [
            'id' => 1,
            'name' => 'a.b.multimedia.vintage-film',
            'first_record' => 3,
        ], [
            'firstArticleNumber' => 2,
            'firstArticleDate' => '2008-08-19 00:59:23',
        ], 2);

        $this->assertSame(1, (int) DB::table('usenet_groups')->where('id', 1)->value('backfill'));
    }

    public function test_backfill_range_keeps_group_enabled_when_provider_floor_prefix_does_not_lower_cursor(): void
    {
        DB::table('settings')->insert(['name' => 'disablebackfillgroup', 'value' => '1']);
        DB::table('usenet_groups')->where('id', 1)->update(['first_record' => 7349326361]);

        $this->updateGroupRecords('backfill', [
            'id' => 1,
            'name' => 'a.b.multimedia.vintage-film',
            'first_record' => 7349326361,
        ], [
            'firstArticleNumber' => 7349326361,
            'firstArticleDate' => '2008-08-19 00:59:23',
        ], 2);

        $group = DB::table('usenet_groups')->where('id', 1)->first();

        $this->assertSame(7349326361, (int) $group->first_record);
        $this->assertSame(1, (int) $group->backfill);
    }

    public function test_backfill_range_advances_without_fabricating_a_date_when_every_header_date_is_invalid(): void
    {
        $this->updateGroupRecords('backfill', [
            'id' => 1,
            'name' => 'a.b.multimedia.vintage-film',
            'first_record' => 3,
        ], [
            'firstArticleNumber' => 2,
        ], 2);

        $group = DB::table('usenet_groups')->where('id', 1)->first();

        $this->assertSame(2, (int) $group->first_record);
        $this->assertSame('2025-09-20 16:56:29', $group->first_record_postdate);
    }

    public function test_binary_range_advances_without_fabricating_a_date_when_every_header_date_is_invalid(): void
    {
        $this->updateGroupRecords('binaries', [
            'id' => 1,
            'last_record' => 39602,
        ], [
            'lastArticleNumber' => 40000,
        ], 39603);

        $group = DB::table('usenet_groups')->where('id', 1)->first();

        $this->assertSame(40000, (int) $group->last_record);
        $this->assertSame('2026-02-12 23:32:04', $group->last_record_postdate);
    }

    public function test_requested_range_is_clamped_to_the_selected_provider_summary(): void
    {
        $this->assertSame(
            [96727, 100000],
            $this->clampRange(90000, 100000, ['first' => 96727, 'last' => 56925589])
        );
        $this->assertSame(
            [56925000, 56925589],
            $this->clampRange(56925000, 57000000, ['first' => 96727, 'last' => 56925589])
        );
    }

    public function test_wholly_unavailable_requested_range_is_rejected(): void
    {
        $this->assertNull(
            $this->clampRange(85300, 95299, ['first' => 96727, 'last' => 56925589])
        );
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $return
     */
    private function updateGroupRecords(string $mode, array $group, array $return, int $rangeFirstArticle): void
    {
        $method = new ReflectionMethod(GetArticleRange::class, 'updateGroupRecords');
        $method->setAccessible(true);
        $method->invoke(new GetArticleRange, $mode, $group, $return, $rangeFirstArticle);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{int, int}|null
     */
    private function clampRange(int $first, int $last, array $summary): ?array
    {
        $method = new ReflectionMethod(GetArticleRange::class, 'clampToSelectedProviderRange');
        $method->setAccessible(true);

        /** @var array{int, int}|null $result */
        $result = $method->invoke(new GetArticleRange, $first, $last, $summary);

        return $result;
    }
}
