<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Models\Release;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ReleaseInsertSearchSyncTest extends TestCase
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

        $this->createReleasesTable();
    }

    public function test_sync_search_false_inserts_without_search_side_effect(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $releaseId = Release::insertRelease($this->releaseParameters('no-sync-guid'), false);

        self::assertSame(1, $releaseId);
        self::assertSame('No Sync Release', DB::table('releases')->where('id', $releaseId)->value('name'));
    }

    public function test_sync_search_false_and_forced_rollback_leave_no_release_or_search_call(): void
    {
        Search::shouldReceive('updateRelease')->never();

        try {
            DB::transaction(function (): never {
                Release::insertRelease($this->releaseParameters('rollback-guid'), false);

                self::assertSame(1, DB::table('releases')->count());

                throw new RuntimeException('Force release transaction rollback.');
            });
        } catch (RuntimeException $error) {
            self::assertSame('Force release transaction rollback.', $error->getMessage());
        }

        self::assertSame(0, DB::table('releases')->count());
    }

    public function test_default_sync_search_updates_once_after_successful_insert(): void
    {
        Search::shouldReceive('updateRelease')->once()->with(1);

        $releaseId = Release::insertRelease($this->releaseParameters('default-sync-guid'));

        self::assertSame(1, $releaseId);
        self::assertTrue(DB::table('releases')->where('id', $releaseId)->exists());
    }

    /** @return array<string, int|string> */
    private function releaseParameters(string $guid): array
    {
        return [
            'name' => 'No Sync Release',
            'searchname' => 'No Sync Release',
            'totalpart' => 3,
            'groups_id' => 1,
            'guid' => $guid,
            'postdate' => '2026-07-18 09:00:00',
            'fromname' => 'poster@example.test',
            'size' => 123456,
            'categories_id' => 5040,
            'nzbstatus' => 0,
            'isrenamed' => 0,
            'predb_id' => 0,
            'source' => 'test',
        ];
    }

    private function createReleasesTable(): void
    {
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            searchname TEXT NOT NULL,
            totalpart INTEGER NOT NULL,
            groups_id INTEGER NOT NULL,
            adddate TEXT NOT NULL,
            guid TEXT NOT NULL,
            leftguid TEXT NOT NULL,
            postdate TEXT NOT NULL,
            fromname TEXT NOT NULL,
            size INTEGER NOT NULL,
            passwordstatus INTEGER NOT NULL,
            haspreview INTEGER NOT NULL,
            categories_id INTEGER NOT NULL,
            nfostatus INTEGER NOT NULL,
            nzbstatus INTEGER NOT NULL,
            isrenamed INTEGER NOT NULL,
            iscategorized INTEGER NOT NULL,
            predb_id INTEGER NOT NULL,
            source TEXT NULL
        )');
    }
}
