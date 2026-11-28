<?php

declare(strict_types=1);

namespace Tests\Unit\Orchestrator;

use App\Services\Orchestrator\PipelineSnapshotRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * capture()'s `sb` term stopped walking binaries -> collections -> groups for
 * every pending binary and now derives the same three numbers from the cheap
 * complement plus ordered probes. The orchestrator gates backfill admission on
 * those numbers, so this runs the new term beside the join form it replaced
 * over the same fixture rows.
 *
 * Both statements are translated to sqlite with the same substitutions, so a
 * divergence here is a divergence in the set algebra, not in the dialect.
 */
final class PipelineSnapshotSchedulableBinariesQueryTest extends TestCase
{
    /**
     * The `sb` derived table in production before the rewrite. Do not "fix"
     * it: it is the reference the new term is measured against.
     */
    private const string LEGACY_SQL = '(SELECT COUNT(*) AS schedulable_binaries_backlog,
                    COALESCE(SUM(b.currentparts), 0) AS schedulable_parts_backlog,
                    COALESCE(TIMESTAMPDIFF(SECOND, MIN(c.dateadded), NOW()), 0) AS oldest_binary_age
                FROM binaries b INNER JOIN collections c ON c.id = b.collections_id INNER JOIN usenet_groups g ON g.id = c.groups_id
                WHERE b.partcheck = 0 AND c.filecheck IN (0, 1, 2, 15, 16) AND (g.active = 1 OR g.backfill = 1)) AS sb';

    public function test_the_complement_form_matches_the_join_form_on_count_parts_and_oldest_age(): void
    {
        $this->seedFixture();

        $legacy = $this->sbRow(self::LEGACY_SQL);
        $current = $this->sbRow($this->currentSql());

        self::assertSame(
            $legacy['schedulable_binaries_backlog'],
            $current['schedulable_binaries_backlog'],
        );
        self::assertSame(
            $legacy['schedulable_parts_backlog'],
            $current['schedulable_parts_backlog'],
        );
        // Both terms read the wall clock, a second apart at worst.
        self::assertEqualsWithDelta(
            $legacy['oldest_binary_age'],
            $current['oldest_binary_age'],
            2.0,
        );
    }

    public function test_the_fixture_exercises_every_exclusion_the_complement_has_to_subtract(): void
    {
        $this->seedFixture();

        // Guards the comparison above: if the fixture only held schedulable
        // rows, the complement would be trivially right. binaries_backlog
        // counts every pending binary including the orphaned one, because it
        // never carried a group filter; schedulable excludes it.
        self::assertSame([
            'schedulable_binaries_backlog' => 4,
            'schedulable_parts_backlog' => 46,
            'binaries_backlog' => 8,
        ], array_intersect_key(
            $this->sbRow($this->currentSql()),
            array_flip(['schedulable_binaries_backlog', 'schedulable_parts_backlog', 'binaries_backlog']),
        ));
    }

    public function test_a_collection_whose_group_row_is_gone_is_not_counted_as_schedulable(): void
    {
        $this->seedFixture();

        // Without the orphan branch of the complement this reads 5 and 946:
        // the row matches neither the filecheck exclusion nor the
        // inactive-group exclusion, so it survives the subtraction while the
        // join form it replaced dropped it.
        $row = $this->sbRow($this->currentSql());

        self::assertSame(4, $row['schedulable_binaries_backlog']);
        self::assertSame(46, $row['schedulable_parts_backlog']);
    }

    public function test_binaries_backlog_is_the_same_count_the_separate_scalar_subquery_used_to_issue(): void
    {
        $this->seedFixture();

        self::assertSame(
            (int) DB::scalar('SELECT COUNT(*) FROM binaries WHERE partcheck = 0'),
            $this->sbRow($this->currentSql())['binaries_backlog'],
        );
    }

    public function test_oldest_binary_age_ignores_collections_whose_binaries_are_all_checked(): void
    {
        $this->seedFixture();

        // Collection 1 is the oldest schedulable collection in the fixture but
        // every binary on it is checked, so it must not set the age. Collection
        // 2 is two hours old and still pending, and does.
        self::assertEqualsWithDelta(7_200, $this->sbRow($this->currentSql())['oldest_binary_age'], 5.0);
    }

    private function currentSql(): string
    {
        return (string) (new ReflectionClass(PipelineSnapshotRepository::class))
            ->getConstant('SCHEDULABLE_BINARIES_SQL');
    }

    /** @return array<string, int> */
    private function sbRow(string $sb): array
    {
        $row = DB::selectOne('SELECT sb.* FROM '.$this->forSqlite($sb));
        self::assertNotNull($row);

        return array_map(static fn (mixed $value): int => (int) $value, (array) $row);
    }

    private function forSqlite(string $sql): string
    {
        return str_replace(
            [
                'STRAIGHT_JOIN ',
                'GREATEST(',
                'CAST(ab.pending AS SIGNED)',
                'CAST(xb.pending AS SIGNED)',
                'CAST(ab.parts AS SIGNED)',
                'CAST(xb.parts AS SIGNED)',
                'COALESCE(TIMESTAMPDIFF(SECOND, MIN(p.dateadded), NOW()), 0)',
                'COALESCE(TIMESTAMPDIFF(SECOND, MIN(c.dateadded), NOW()), 0)',
            ],
            [
                '',
                'MAX(',
                'ab.pending',
                'xb.pending',
                'ab.parts',
                'xb.parts',
                "COALESCE(strftime('%s', 'now') - strftime('%s', MIN(p.dateadded)), 0)",
                "COALESCE(strftime('%s', 'now') - strftime('%s', MIN(c.dateadded)), 0)",
            ],
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
        Schema::create('binaries', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('collections_id');
            $table->unsignedTinyInteger('partcheck');
            $table->unsignedInteger('currentparts');
        });

        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.active', 'active' => 1, 'backfill' => 0],
            ['id' => 2, 'name' => 'alt.backfill', 'active' => 0, 'backfill' => 1],
            ['id' => 3, 'name' => 'alt.dormant', 'active' => 0, 'backfill' => 0],
        ]);

        $old = now()->subHours(9)->format('Y-m-d H:i:s');
        $mid = now()->subHours(2)->format('Y-m-d H:i:s');
        $new = now()->subMinutes(10)->format('Y-m-d H:i:s');

        DB::table('collections')->insert([
            // schedulable, but nothing pending on it -- must not set the age
            ['id' => 1, 'groups_id' => 1, 'filecheck' => 0, 'dateadded' => $old],
            // schedulable and pending: this is the oldest pending collection
            ['id' => 2, 'groups_id' => 1, 'filecheck' => 2, 'dateadded' => $mid],
            // schedulable and pending on a backfill-only group
            ['id' => 3, 'groups_id' => 2, 'filecheck' => 16, 'dateadded' => $new],
            // excluded by filecheck
            ['id' => 4, 'groups_id' => 1, 'filecheck' => 3, 'dateadded' => $old],
            ['id' => 5, 'groups_id' => 1, 'filecheck' => 5, 'dateadded' => $old],
            // excluded by group: schedulable filecheck, dormant group
            ['id' => 6, 'groups_id' => 3, 'filecheck' => 0, 'dateadded' => $old],
            // a NULL dateadded must not blank out its (group, filecheck) probe
            ['id' => 7, 'groups_id' => 1, 'filecheck' => 2, 'dateadded' => null],
            // orphaned: there is no foreign key on collections.groups_id, and
            // group 99 does not exist. The join form excluded it, so the
            // complement has to exclude it too or it counts as schedulable.
            ['id' => 8, 'groups_id' => 99, 'filecheck' => 0, 'dateadded' => $mid],
        ]);

        DB::table('binaries')->insert([
            ['id' => 1, 'collections_id' => 1, 'partcheck' => 1, 'currentparts' => 100],
            ['id' => 2, 'collections_id' => 2, 'partcheck' => 0, 'currentparts' => 11],
            ['id' => 3, 'collections_id' => 2, 'partcheck' => 0, 'currentparts' => 12],
            ['id' => 4, 'collections_id' => 3, 'partcheck' => 0, 'currentparts' => 13],
            ['id' => 5, 'collections_id' => 4, 'partcheck' => 0, 'currentparts' => 500],
            ['id' => 6, 'collections_id' => 5, 'partcheck' => 0, 'currentparts' => 600],
            ['id' => 7, 'collections_id' => 6, 'partcheck' => 0, 'currentparts' => 700],
            ['id' => 8, 'collections_id' => 7, 'partcheck' => 0, 'currentparts' => 10],
            ['id' => 9, 'collections_id' => 8, 'partcheck' => 0, 'currentparts' => 900],
        ]);
    }
}
