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
            last_record INTEGER
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

    public function test_backfill_range_disables_group_when_provider_floor_was_scanned_without_lowering_cursor(): void
    {
        DB::table('settings')->insert(['name' => 'disablebackfillgroup', 'value' => '1']);

        $this->updateGroupRecords('backfill', [
            'id' => 1,
            'name' => 'a.b.multimedia.vintage-film',
            'first_record' => 3,
        ], [
            'firstArticleNumber' => 3,
            'firstArticleDate' => '2008-08-19 00:59:23',
        ], 2);

        $group = DB::table('usenet_groups')->where('id', 1)->first();

        $this->assertSame(3, (int) $group->first_record);
        $this->assertSame(0, (int) $group->backfill);
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
}
