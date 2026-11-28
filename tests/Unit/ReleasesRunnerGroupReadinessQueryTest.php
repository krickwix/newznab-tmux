<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Runners\ReleasesRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The group-readiness query was rewritten from a GROUP BY aggregate over every
 * collection of every active group into per-group EXISTS probes. The rewrite is
 * only worth anything if it is exactly equivalent, so this pins the old
 * statement here and runs both against the same fixture rows.
 *
 * Both statements are dialect-translated identically for sqlite (only the
 * MariaDB interval literal differs), so any divergence is a divergence in the
 * predicates, not in the dialect.
 */
final class ReleasesRunnerGroupReadinessQueryTest extends TestCase
{
    /**
     * The statement in production before this rewrite. Do not "fix" it: it is
     * the reference the new statement is measured against.
     */
    private const string LEGACY_SQL = 'SELECT g.id, g.name,
                MAX(CASE
                    WHEN c.filecheck IN (1, 2, 3, 15, 16) THEN 1
                    WHEN c.filecheck = 0 AND c.dateadded >= (NOW() - INTERVAL 15 MINUTE) THEN 1
                    ELSE 0
                END) AS actionable,
                MAX(CASE WHEN c.filecheck = 3 THEN 1 ELSE 0 END) AS ready
            FROM usenet_groups g
            INNER JOIN collections c ON c.groups_id = g.id
            WHERE (g.active = 1 OR g.backfill = 1)
            GROUP BY g.id, g.name
            ORDER BY g.id';

    public function test_the_exists_rewrite_returns_the_same_rows_in_the_same_order_as_the_group_by_form(): void
    {
        $this->seedFixture();

        self::assertSame(
            $this->rowsFor(self::LEGACY_SQL),
            $this->rowsFor(ReleasesRunner::GROUP_READINESS_SQL),
        );
    }

    public function test_the_fixture_actually_exercises_every_readiness_outcome(): void
    {
        $this->seedFixture();

        // Guards the test above: an all-zero or empty result would make the
        // equivalence assertion vacuous.
        self::assertSame([
            ['id' => 1, 'name' => 'alt.ready', 'actionable' => 1, 'ready' => 1],
            ['id' => 2, 'name' => 'alt.preparing', 'actionable' => 1, 'ready' => 0],
            ['id' => 3, 'name' => 'alt.fresh-unprocessed', 'actionable' => 1, 'ready' => 0],
            ['id' => 4, 'name' => 'alt.stale-unprocessed', 'actionable' => 0, 'ready' => 0],
            ['id' => 5, 'name' => 'alt.terminal-only', 'actionable' => 0, 'ready' => 0],
            ['id' => 7, 'name' => 'alt.backfill-only', 'actionable' => 1, 'ready' => 0],
        ], $this->rowsFor(ReleasesRunner::GROUP_READINESS_SQL));
    }

    public function test_groups_without_collections_and_disabled_groups_are_both_excluded(): void
    {
        $this->seedFixture();

        $ids = array_column($this->rowsFor(ReleasesRunner::GROUP_READINESS_SQL), 'id');

        // 6 is active with zero collections (the dropped INNER JOIN), 8 is
        // disabled but still holds collections (the active/backfill filter).
        self::assertNotContains(6, $ids);
        self::assertNotContains(8, $ids);
    }

    /** @return list<array{id: int, name: string, actionable: int, ready: int}> */
    private function rowsFor(string $sql): array
    {
        $rows = DB::select($this->forSqlite($sql));

        return array_map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'actionable' => (int) $row->actionable,
            'ready' => (int) $row->ready,
        ], $rows);
    }

    private function forSqlite(string $sql): string
    {
        return str_replace(
            '(NOW() - INTERVAL 15 MINUTE)',
            "datetime('now', '-15 minutes')",
            $sql,
        );
    }

    private function seedFixture(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedTinyInteger('active');
            $table->unsignedTinyInteger('backfill');
        });
        Schema::create('collections', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('groups_id');
            $table->unsignedTinyInteger('filecheck');
            $table->dateTime('dateadded')->nullable();
        });

        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.ready', 'active' => 1, 'backfill' => 0],
            ['id' => 2, 'name' => 'alt.preparing', 'active' => 1, 'backfill' => 0],
            ['id' => 3, 'name' => 'alt.fresh-unprocessed', 'active' => 1, 'backfill' => 0],
            ['id' => 4, 'name' => 'alt.stale-unprocessed', 'active' => 1, 'backfill' => 0],
            ['id' => 5, 'name' => 'alt.terminal-only', 'active' => 1, 'backfill' => 0],
            ['id' => 6, 'name' => 'alt.no-collections', 'active' => 1, 'backfill' => 0],
            ['id' => 7, 'name' => 'alt.backfill-only', 'active' => 0, 'backfill' => 1],
            ['id' => 8, 'name' => 'alt.disabled', 'active' => 0, 'backfill' => 0],
        ]);

        $fresh = now()->subMinutes(2)->format('Y-m-d H:i:s');
        $stale = now()->subHours(6)->format('Y-m-d H:i:s');

        DB::table('collections')->insert([
            // ready: filecheck 3 implies actionable too
            ['id' => 1, 'groups_id' => 1, 'filecheck' => 3, 'dateadded' => $stale],
            ['id' => 2, 'groups_id' => 1, 'filecheck' => 5, 'dateadded' => $stale],
            // preparing: mid-stage filechecks are actionable, never ready
            ['id' => 3, 'groups_id' => 2, 'filecheck' => 1, 'dateadded' => $stale],
            ['id' => 4, 'groups_id' => 2, 'filecheck' => 16, 'dateadded' => $stale],
            // filecheck 0 inside the 15 minute window is actionable
            ['id' => 5, 'groups_id' => 3, 'filecheck' => 0, 'dateadded' => $fresh],
            ['id' => 6, 'groups_id' => 3, 'filecheck' => 0, 'dateadded' => $stale],
            // filecheck 0 outside the window is not
            ['id' => 7, 'groups_id' => 4, 'filecheck' => 0, 'dateadded' => $stale],
            // a group that only holds terminal collections stays listed, idle
            ['id' => 8, 'groups_id' => 5, 'filecheck' => 5, 'dateadded' => $fresh],
            // a NULL dateadded must not satisfy the window
            ['id' => 9, 'groups_id' => 5, 'filecheck' => 0, 'dateadded' => null],
            // backfill-only groups are in scope
            ['id' => 10, 'groups_id' => 7, 'filecheck' => 2, 'dateadded' => $stale],
            // disabled group collections are out of scope
            ['id' => 11, 'groups_id' => 8, 'filecheck' => 3, 'dateadded' => $fresh],
        ]);
    }
}
