<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BodyPreambleFragmentRequeueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255)
        )');

        DB::statement('CREATE TABLE settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255),
            value TEXT NULL
        )');
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ]);
        Settings::forgetCachedSettings();

        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            xref TEXT DEFAULT \'\',
            groups_id INT,
            totalfiles INT,
            collection_regexes_id INT,
            filecheck INT DEFAULT 0,
            dateadded DATETIME NULL
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            collections_id INT,
            totalparts INT,
            currentparts INT,
            filenumber INT
        )');

        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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

        DB::statement('CREATE TABLE parts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            binaries_id INT,
            number INT
        )');
    }

    public function test_dry_run_reports_legacy_fragments_without_inserting_missed_parts(): void
    {
        $this->seedFragments();

        $exitCode = Artisan::call('nntmux:requeue-body-preamble-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88', '-10'],
            '--limit' => 10,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertFalse($output['updated']);
        $this->assertSame(2, $output['candidates']);
        $this->assertSame(0, $output['inserted']);
        $this->assertSame(1, $output['skipped_existing']);
        $this->assertSame(1, DB::table('missed_parts')->count());
        $this->assertSame([
            'collection_id_min' => 1,
            'collection_id_max' => 6,
            'next_after_collection_id' => 6,
        ], $output['batch']);
        $this->assertSame([7304209420, 7304209421], $output['candidate_numberids']);
        $this->assertSame([], $output['inserted_numberids']);
        $this->assertSame([7304209421], $output['skipped_existing_numberids']);
        $this->assertSame([7304209420, 7304209421], array_column($output['sample'], 'article'));
    }

    public function test_update_inserts_missing_part_rows_for_matching_legacy_fragments(): void
    {
        $this->seedFragments();

        $exitCode = Artisan::call('nntmux:requeue-body-preamble-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88', '-10'],
            '--limit' => 10,
            '--before' => '2026-06-14 12:30:00',
            '--update' => true,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($output['updated']);
        $this->assertSame(2, $output['candidates']);
        $this->assertSame(1, $output['inserted']);
        $this->assertSame(1, $output['skipped_existing']);
        $this->assertSame(
            [7304209420, 7304209421],
            DB::table('missed_parts')->orderBy('numberid')->pluck('numberid')->all()
        );
        $this->assertSame(
            0,
            (int) DB::table('missed_parts')->where(['groups_id' => 5, 'numberid' => 7304209420])->value('attempts')
        );
        $provenance = DB::table('missed_parts')->orderBy('numberid')->get([
            'numberid',
            'attempts',
            'recovery_kind',
            'recovery_source_collection_id',
            'recovery_source_binary_id',
        ])->map(static fn (object $row): array => (array) $row)->all();
        $this->assertSame([
            [
                'numberid' => 7304209420,
                'attempts' => 0,
                'recovery_kind' => 'body_preamble',
                'recovery_source_collection_id' => 1,
                'recovery_source_binary_id' => 101,
            ],
            [
                'numberid' => 7304209421,
                'attempts' => 1,
                'recovery_kind' => 'body_preamble',
                'recovery_source_collection_id' => 2,
                'recovery_source_binary_id' => 102,
            ],
        ], $provenance);
    }

    public function test_json_output_includes_full_trace_metadata_for_batch_followup(): void
    {
        $this->seedFragments();

        $exitCode = Artisan::call('nntmux:requeue-body-preamble-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88', '-10'],
            '--limit' => 10,
            '--before' => '2026-06-14 12:30:00',
            '--update' => true,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertArrayHasKey('batch', $output);
        $this->assertArrayHasKey('candidate_numberids', $output);
        $this->assertArrayHasKey('inserted_numberids', $output);
        $this->assertArrayHasKey('skipped_existing_numberids', $output);
        $this->assertSame([
            'collection_id_min' => 1,
            'collection_id_max' => 6,
            'next_after_collection_id' => 6,
        ], $output['batch']);
        $this->assertSame([7304209420, 7304209421], $output['candidate_numberids']);
        $this->assertSame([7304209420], $output['inserted_numberids']);
        $this->assertSame([7304209421], $output['skipped_existing_numberids']);
    }

    public function test_prune_deletes_only_source_fragments_proven_in_a_normalized_collection(): void
    {
        $this->seedFragments();
        DB::table('collections')->where('id', 1)->update([
            'subject' => '[PRiVATE] \\opaque\\::payload::/opaque/ [newzNZB] [2/41] - yEnc',
        ]);
        DB::table('parts')->insert(['binaries_id' => 101, 'number' => 7304209420]);
        DB::table('collections')->insert([
            'id' => 7,
            'subject' => '"Recovered.Movie.part01.rar"',
            'xref' => 'alt.binaries.blu-ray:7304209420',
            'groups_id' => 5,
            'totalfiles' => 10,
            'collection_regexes_id' => 88,
            'filecheck' => 0,
            'dateadded' => '2026-06-15 00:00:00',
        ]);
        DB::table('binaries')->insert([
            'id' => 70,
            'collections_id' => 7,
            'totalparts' => 64,
            'currentparts' => 1,
            'filenumber' => 1,
        ]);
        DB::table('parts')->insert(['binaries_id' => 70, 'number' => 7304209420]);
        $this->insertCollection(8, 88, 'alt.binaries.blu-ray:7304209420', 1, 64);
        DB::table('collections')->where('id', 8)->update(['subject' => '"Already.Normalized.part02.rar"']);

        $arguments = [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88', '-10'],
            '--limit' => 10,
            '--before' => '2026-06-14 12:30:00',
            '--json' => true,
        ];
        self::assertSame(0, Artisan::call('nntmux:prune-recovered-body-fragments', $arguments));
        $dryRun = json_decode(Artisan::output(), true);
        self::assertSame(1, $dryRun['recovered']);
        self::assertSame(0, $dryRun['deleted']);
        self::assertTrue(DB::table('collections')->where('id', 1)->exists());

        self::assertSame(1, Artisan::call('nntmux:prune-recovered-body-fragments', [
            ...$arguments,
            '--update' => true,
            '--manifest-hash' => str_repeat('0', 64),
        ]));
        self::assertTrue(DB::table('collections')->where('id', 1)->exists());

        self::assertSame(0, Artisan::call('nntmux:prune-recovered-body-fragments', [
            ...$arguments,
            '--update' => true,
            '--manifest-hash' => $dryRun['manifest_hash'],
        ]));
        $updated = json_decode(Artisan::output(), true);
        self::assertSame(1, $updated['deleted']);
        self::assertFalse(DB::table('collections')->where('id', 1)->exists());
        self::assertTrue(DB::table('collections')->where('id', 7)->exists());
        self::assertTrue(DB::table('collections')->where('id', 8)->exists());
    }

    public function test_prune_rejects_partial_multipart_proof_and_future_cutoffs(): void
    {
        $this->seedFragments();
        DB::table('collections')->where('id', 1)->update([
            'subject' => '[PRiVATE] \\opaque\\::payload::/opaque/ [newzNZB] [2/41] - yEnc',
        ]);
        DB::table('parts')->insert([
            ['binaries_id' => 101, 'number' => 7304209420],
            ['binaries_id' => 101, 'number' => 7304209426],
        ]);
        DB::table('binaries')->where('id', 101)->update(['currentparts' => 2]);
        DB::table('collections')->insert([
            'id' => 7,
            'subject' => '"Only.One.Part.Recovered.rar"',
            'xref' => 'alt.binaries.blu-ray:7304209420',
            'groups_id' => 5,
            'totalfiles' => 10,
            'collection_regexes_id' => 88,
            'filecheck' => 0,
            'dateadded' => '2026-06-15 00:00:00',
        ]);
        DB::table('binaries')->insert(['id' => 70, 'collections_id' => 7, 'totalparts' => 64, 'currentparts' => 1, 'filenumber' => 1]);
        DB::table('parts')->insert(['binaries_id' => 70, 'number' => 7304209420]);
        DB::table('collections')->insert([
            'id' => 8,
            'subject' => '"Other.Collection.With.Second.Part.rar"',
            'xref' => 'alt.binaries.blu-ray:7304209426',
            'groups_id' => 5,
            'totalfiles' => 10,
            'collection_regexes_id' => 88,
            'filecheck' => 0,
            'dateadded' => '2026-06-15 00:00:00',
        ]);
        DB::table('binaries')->insert(['id' => 80, 'collections_id' => 8, 'totalparts' => 64, 'currentparts' => 1, 'filenumber' => 1]);
        DB::table('parts')->insert(['binaries_id' => 80, 'number' => 7304209426]);

        self::assertSame(0, Artisan::call('nntmux:prune-recovered-body-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88'],
            '--limit' => 10,
            '--before' => '2026-06-14 12:30:00',
            '--json' => true,
        ]));
        self::assertSame(0, json_decode(Artisan::output(), true)['recovered']);

        self::assertSame(1, Artisan::call('nntmux:prune-recovered-body-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88'],
            '--before' => now()->addDay()->toDateTimeString(),
        ]));
        self::assertTrue(DB::table('collections')->where('id', 1)->exists());
    }

    public function test_recovery_cycle_prunes_proven_sources_then_replenishes_a_bounded_queue(): void
    {
        $this->seedFragments();
        DB::table('settings')->insert([
            ['name' => 'orchestrator_profile', 'value' => 'fail_safe'],
            ['name' => 'orchestrator_recovery_ok', 'value' => '1'],
            ['name' => 'orchestrator_lease_until', 'value' => (string) (time() + 600)],
        ]);
        Settings::forgetCachedSettings();
        DB::table('collections')->where('id', 1)->update([
            'subject' => '[PRiVATE] \\opaque\\::payload::/opaque/ [newzNZB] [2/41] - yEnc',
        ]);
        DB::table('parts')->insert(['binaries_id' => 101, 'number' => 7304209420]);
        DB::table('collections')->insert([
            'id' => 7,
            'subject' => '"Recovered.Movie.part01.rar"',
            'xref' => 'alt.binaries.blu-ray:7304209420',
            'groups_id' => 5,
            'totalfiles' => 10,
            'collection_regexes_id' => 88,
            'filecheck' => 0,
            'dateadded' => '2026-06-15 00:00:00',
        ]);
        DB::table('binaries')->insert(['id' => 70, 'collections_id' => 7, 'totalparts' => 64, 'currentparts' => 1, 'filenumber' => 1]);
        DB::table('parts')->insert(['binaries_id' => 70, 'number' => 7304209420]);

        self::assertSame(0, Artisan::call('nntmux:body-preamble-recovery-cycle', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88', '-10'],
            '--limit' => 10,
            '--cutoff-hours' => 2,
            '--json' => true,
        ]));
        $output = json_decode(Artisan::output(), true);

        self::assertSame(1, $output['prune']['deleted']);
        self::assertFalse(DB::table('collections')->where('id', 1)->exists());
        self::assertSame(0, $output['requeue']['inserted']);
        self::assertSame(1, $output['requeue']['skipped_existing']);
    }

    public function test_recovery_cycle_skips_all_mutation_under_fail_safe(): void
    {
        $this->seedFragments();
        DB::table('settings')->insert([
            ['name' => 'orchestrator_profile', 'value' => 'fail_safe'],
            ['name' => 'orchestrator_lease_until', 'value' => (string) (time() + 600)],
        ]);
        Settings::forgetCachedSettings();

        self::assertSame(0, Artisan::call('nntmux:body-preamble-recovery-cycle', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88', '-10'],
            '--json' => true,
        ]));
        $output = json_decode(Artisan::output(), true);

        self::assertTrue($output['skipped']);
        self::assertSame('orchestrator_profile_unsafe', $output['reason']);
        self::assertSame(1, DB::table('missed_parts')->count());
        self::assertSame(6, DB::table('collections')->count());
    }

    public function test_update_requires_regex_selector(): void
    {
        $this->seedFragments();

        $exitCode = Artisan::call('nntmux:requeue-body-preamble-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--limit' => 10,
            '--update' => true,
            '--before' => '2026-06-14 12:30:00',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('requires at least one --regex selector', Artisan::output());
    }

    public function test_update_requires_before_cutoff(): void
    {
        $this->seedFragments();

        $exitCode = Artisan::call('nntmux:requeue-body-preamble-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88'],
            '--limit' => 10,
            '--update' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('requires --before', Artisan::output());
    }

    public function test_invalid_regex_selector_returns_failure(): void
    {
        $this->seedFragments();

        $exitCode = Artisan::call('nntmux:requeue-body-preamble-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['oops'],
            '--limit' => 10,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid --regex value', Artisan::output());
    }

    public function test_after_collection_id_skips_earlier_candidates(): void
    {
        $this->seedFragments();

        $exitCode = Artisan::call('nntmux:requeue-body-preamble-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88', '-10'],
            '--limit' => 10,
            '--after-collection-id' => 1,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $output['candidates']);
        $this->assertSame([
            'collection_id_min' => 2,
            'collection_id_max' => 6,
            'next_after_collection_id' => 6,
        ], $output['batch']);
        $this->assertSame([7304209421], $output['candidate_numberids']);
        $this->assertSame(2, $output['sample'][0]['collection_id']);
        $this->assertSame(7304209421, $output['sample'][0]['article']);
    }

    public function test_empty_result_reports_stable_trace_metadata(): void
    {
        $this->seedFragments();

        $exitCode = Artisan::call('nntmux:requeue-body-preamble-fragments', [
            'group' => 'alt.binaries.blu-ray',
            '--regex' => ['88', '-10'],
            '--limit' => 10,
            '--after-collection-id' => 99,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $output['candidates']);
        $this->assertSame([
            'collection_id_min' => null,
            'collection_id_max' => null,
            'next_after_collection_id' => 99,
        ], $output['batch']);
        $this->assertSame([], $output['candidate_numberids']);
        $this->assertSame([], $output['inserted_numberids']);
        $this->assertSame([], $output['skipped_existing_numberids']);
    }

    public function test_unknown_group_returns_failure(): void
    {
        $exitCode = Artisan::call('nntmux:requeue-body-preamble-fragments', [
            'group' => 'missing.group',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown Usenet group', Artisan::output());
    }

    private function seedFragments(): void
    {
        DB::table('usenet_groups')->insert(['id' => 5, 'name' => 'alt.binaries.blu-ray']);

        $this->insertCollection(1, 88, 'alt.binaries.blu-ray:7304209420', 1, 64);
        $this->insertCollection(2, -10, 'alt.binaries.blu-ray:7304209421', 1, 59);
        $this->insertCollection(3, 88, 'alt.binaries.blu-ray:7304209422', 4, 59);
        $this->insertCollection(4, 88, 'alt.binaries.blu-ray:7304209423', 1, 5);
        $this->insertCollection(5, 77, 'alt.binaries.blu-ray:7304209424', 1, 60);
        $this->insertCollection(6, 88, 'alt.binaries.other:7304209425', 1, 60);

        DB::table('missed_parts')->insert([
            'numberid' => 7304209421,
            'groups_id' => 5,
            'attempts' => 1,
        ]);
    }

    private function insertCollection(
        int $id,
        int $regexId,
        string $xref,
        int $currentParts,
        int $totalParts
    ): void {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => '[PRiVATE]-[newzNZB] [2/41] - "opaque-'.$id.'" yEnc',
            'xref' => $xref,
            'groups_id' => 5,
            'totalfiles' => 41,
            'collection_regexes_id' => $regexId,
            'filecheck' => 0,
            'dateadded' => '2026-02-03 01:39:00',
        ]);

        DB::table('binaries')->insert([
            'id' => 100 + $id,
            'collections_id' => $id,
            'totalparts' => $totalParts,
            'currentparts' => $currentParts,
            'filenumber' => 2,
        ]);
    }
}
