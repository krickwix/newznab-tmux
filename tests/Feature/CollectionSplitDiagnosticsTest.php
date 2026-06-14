<?php

namespace Tests\Feature;

use App\Services\Diagnostics\CollectionSplitDiagnosticsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CollectionSplitDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            active INT DEFAULT 1,
            backfill INT DEFAULT 1
        )');

        DB::statement('CREATE TABLE settings (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            value TEXT NULL
        )');

        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255),
            date DATETIME NULL,
            xref TEXT DEFAULT \'\',
            groups_id INT,
            totalfiles INT,
            collectionhash VARCHAR(40) UNIQUE,
            collection_regexes_id INT,
            releases_id INT NULL,
            filecheck INT DEFAULT 0,
            dateadded DATETIME NULL,
            noise VARCHAR(64) DEFAULT \'\'
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            filenumber INT
        )');
    }

    public function test_it_reports_one_binary_multifile_split_cohorts_for_a_group(): void
    {
        $this->seedSplitCollections();

        $summary = (new CollectionSplitDiagnosticsService)->summarizeGroup('alt.binaries.blu-ray', 5);

        $this->assertSame(['id' => 5, 'name' => 'alt.binaries.blu-ray'], $summary['group']);
        $this->assertSame(7, $summary['totals']['collections']);
        $this->assertSame(4, $summary['totals']['one_binary_multifile']);
        $this->assertSame([
            ['collection_regexes_id' => 88, 'one_binary_multifile' => 3],
            ['collection_regexes_id' => -10, 'one_binary_multifile' => 1],
        ], $summary['regexes']);

        $this->assertSame('batch-a', $summary['cohorts'][0]['noise']);
        $this->assertSame(3, $summary['cohorts'][0]['totalfiles']);
        $this->assertSame(3, $summary['cohorts'][0]['collections']);
        $this->assertSame(3, $summary['cohorts'][0]['hashes']);
        $this->assertSame(3, $summary['cohorts'][0]['posters']);
        $this->assertSame(2, $summary['cohorts'][0]['distinct_filenumbers']);
        $this->assertSame('1,2', $summary['cohorts'][0]['filenumber_span']);
        $this->assertSame(0, $summary['cohorts'][0]['complete_binaries']);
        $this->assertSame(3, $summary['cohorts'][0]['incomplete_binaries']);
        $this->assertSame('incomplete_part_fragments', $summary['cohorts'][0]['classification']);
        $this->assertSame('-10,88', $summary['cohorts'][0]['regex_ids']);
    }

    public function test_artisan_command_emits_json_diagnostics(): void
    {
        $this->seedSplitCollections();

        $exitCode = Artisan::call('nntmux:collection-split-diagnostics', [
            'group' => 'alt.binaries.blu-ray',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"name": "alt.binaries.blu-ray"', $output);
        $this->assertStringContainsString('"one_binary_multifile"', $output);
        $this->assertStringContainsString('"noise": "batch-a"', $output);
    }

    public function test_artisan_command_can_emit_cohorts_only_json_diagnostics(): void
    {
        $this->seedSplitCollections();

        $exitCode = Artisan::call('nntmux:collection-split-diagnostics', [
            'group' => 'alt.binaries.blu-ray',
            '--cohorts-only' => true,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame([], $output['totals']);
        $this->assertSame([], $output['regexes']);
        $this->assertSame('batch-a', $output['cohorts'][0]['noise']);
        $this->assertSame('-10,88', $output['cohorts'][0]['regex_ids']);
    }

    private function seedSplitCollections(): void
    {
        DB::table('usenet_groups')->insert(['id' => 5, 'name' => 'alt.binaries.blu-ray']);

        $this->insertCollection(101, 5, '[1/3] - "aaa.bin" yEnc', 'poster-a', 3, 88, 'batch-a', 1, 80);
        $this->insertCollection(102, 5, '[2/3] - "bbb.bin" yEnc', 'poster-b', 3, -10, 'batch-a', 2, 82);
        $this->insertCollection(103, 5, '[2/3] - "ccc.bin" yEnc', 'poster-c', 3, 88, 'batch-a', 2, 82);
        $this->insertCollection(104, 5, '[1/2] - "other.bin" yEnc', 'poster-d', 2, 88, 'batch-b', 1, 20);
        $this->insertCollection(105, 6, '[1/3] - "foreign.bin" yEnc', 'poster-e', 3, 88, 'batch-a', 1, 10);
        $this->insertCompleteCollection(107, 5, 3, 88, 'batch-a');
        $this->insertCompleteCollection(108, 5, 3, 88, 'batch-a');

        DB::table('binaries')->insert([
            ['id' => 1001, 'name' => 'complete-one', 'collections_id' => 106, 'totalparts' => 2, 'currentparts' => 2, 'filenumber' => 1],
            ['id' => 1002, 'name' => 'complete-two', 'collections_id' => 106, 'totalparts' => 2, 'currentparts' => 2, 'filenumber' => 2],
        ]);
        DB::table('collections')->insert([
            'id' => 106,
            'subject' => 'complete',
            'fromname' => 'poster-z',
            'date' => '2026-06-14 06:00:00',
            'xref' => 'alt.binaries.blu-ray:1006',
            'groups_id' => 5,
            'totalfiles' => 2,
            'collectionhash' => 'hash-106',
            'collection_regexes_id' => 88,
            'dateadded' => '2026-06-14 06:00:00',
            'noise' => 'batch-complete',
        ]);
    }

    private function insertCompleteCollection(
        int $id,
        int $groupId,
        int $totalFiles,
        int $regexId,
        string $noise
    ): void {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => 'complete '.$id,
            'fromname' => 'poster-complete',
            'date' => '2026-06-14 05:55:00',
            'xref' => 'alt.binaries.blu-ray:'.$id,
            'groups_id' => $groupId,
            'totalfiles' => $totalFiles,
            'collectionhash' => 'hash-'.$id,
            'collection_regexes_id' => $regexId,
            'dateadded' => '2026-06-14 06:00:00',
            'noise' => $noise,
        ]);

        DB::table('binaries')->insert([
            [
                'id' => 2000 + ($id * 10),
                'name' => 'complete-one-'.$id,
                'collections_id' => $id,
                'totalparts' => 2,
                'currentparts' => 2,
                'filenumber' => 1,
            ],
            [
                'id' => 2001 + ($id * 10),
                'name' => 'complete-two-'.$id,
                'collections_id' => $id,
                'totalparts' => 2,
                'currentparts' => 2,
                'filenumber' => 2,
            ],
        ]);
    }

    private function insertCollection(
        int $id,
        int $groupId,
        string $subject,
        string $poster,
        int $totalFiles,
        int $regexId,
        string $noise,
        int $fileNumber,
        int $totalParts
    ): void {
        DB::table('collections')->insert([
            'id' => $id,
            'subject' => $subject,
            'fromname' => $poster,
            'date' => '2026-06-14 05:50:00',
            'xref' => 'alt.binaries.blu-ray:'.$id,
            'groups_id' => $groupId,
            'totalfiles' => $totalFiles,
            'collectionhash' => 'hash-'.$id,
            'collection_regexes_id' => $regexId,
            'dateadded' => '2026-06-14 06:00:00',
            'noise' => $noise,
        ]);

        DB::table('binaries')->insert([
            'id' => 1000 + $id,
            'name' => $subject,
            'collections_id' => $id,
            'totalparts' => $totalParts,
            'currentparts' => 1,
            'filenumber' => $fileNumber,
        ]);
    }
}
