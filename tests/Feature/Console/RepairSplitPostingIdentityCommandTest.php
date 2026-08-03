<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The command wiring only. The merge itself is proven in
 * SplitPostingIdentityRepairServiceTest and its survival downstream in
 * SplitPostingRepairSurvivesReleaseGatesTest.
 *
 * This file exists because the service can be entirely correct and still be
 * unreachable: bootstrap/app.php lists only two commands explicitly, so
 * nntmux:repair-split-posting-identity depends on autodiscovery from
 * app/Console/Commands, and `artisan list` cannot be used to check that in the
 * gate container (it needs a real database). An Artisan::call() against the
 * sqlite fixture is the only check that actually resolves the signature.
 */
final class RepairSplitPostingIdentityCommandTest extends TestCase
{
    private const GROUP_ID = 5079;

    private const GROUP_NAME = 'alt.binaries.cinemageddon';

    private const POSTER = 'Helljahve <helljahve@example.com>';

    private const STEM = 'The Paper Boy (1994) Dvdrip by Helljahve.avi';

    private int $nextBinaryId = 1;

    private int $nextArticle = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            minfilestoformrelease INT NULL
        )');
        // Resolving the console kernel boots ProcessingServiceProvider, which
        // reads settings; without the table every Artisan::call() dies there.
        DB::statement('CREATE TABLE settings (id INTEGER PRIMARY KEY, name VARCHAR(255), value TEXT NULL)');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255) DEFAULT \'\',
            date DATETIME NULL,
            groups_id INT,
            totalfiles INT DEFAULT 0,
            collectionhash VARCHAR(255) UNIQUE,
            dateadded DATETIME NULL,
            filecheck INT DEFAULT 0
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(1000),
            collections_id INT,
            filenumber INT DEFAULT 0 CHECK (filenumber >= 0),
            totalparts INT DEFAULT 0,
            currentparts INT DEFAULT 0,
            partcheck INT DEFAULT 0,
            partsize INT DEFAULT 0,
            UNIQUE(collections_id, filenumber)
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            messageid VARCHAR(255),
            number INT,
            partnumber INT,
            size INT,
            PRIMARY KEY (binaries_id, number)
        )');

        DB::table('usenet_groups')->insert([
            'id' => self::GROUP_ID,
            'name' => self::GROUP_NAME,
            'minfilestoformrelease' => null,
        ]);
        DB::table('settings')->insert(['id' => 1, 'name' => 'minfilestoformrelease', 'value' => '2']);

        $this->seedSplitPosting();
    }

    public function test_the_command_is_registered_and_dry_runs_by_default(): void
    {
        $exitCode = Artisan::call('nntmux:repair-split-posting-identity', [
            'group' => self::GROUP_NAME,
            '--json' => true,
        ]);
        $summary = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertFalse($summary['updated']);
        // The floor the refusal is measured against comes from the live
        // settings, not the command default.
        $this->assertSame(2, $summary['min_files']);
        $this->assertSame(2, $summary['cohorts_found']);
        $this->assertSame(4, $summary['collections_in_cohorts']);
        $this->assertSame(0, $summary['collections_removed']);
        // Reported even when zero, so its absence in a production run means the
        // key was dropped rather than the damage being absent.
        $this->assertSame(0, $summary['binaries_with_duplicate_partnumbers']);
        $this->assertSame(4, (int) DB::table('collections')->count());
    }

    public function test_update_merges_and_the_human_summary_reports_it(): void
    {
        $exitCode = Artisan::call('nntmux:repair-split-posting-identity', [
            'group' => (string) self::GROUP_ID,
            '--update' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Repaired '.self::GROUP_NAME, $output);
        $this->assertStringContainsString('cohorts merged', $output);
        // The refusal is surfaced to the operator, not buried in --json.
        $this->assertStringContainsString('below_min_files', $output);

        // The 3 fragments of the posting collapse to 1; the lone sidecar is
        // refused and left where it is.
        $this->assertSame(2, (int) DB::table('collections')->count());
        $this->assertSame(5, (int) DB::table('binaries')->count());
        $this->assertSame(11, (int) DB::table('parts')->count());
    }

    public function test_a_named_posting_is_the_only_one_touched(): void
    {
        $exitCode = Artisan::call('nntmux:repair-split-posting-identity', [
            'group' => self::GROUP_NAME,
            '--posting' => self::STEM,
            '--json' => true,
        ]);
        $summary = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(self::STEM, $summary['cohorts'][0]['posting']);
    }

    public function test_an_unknown_group_fails_without_a_stack_trace(): void
    {
        $exitCode = Artisan::call('nntmux:repair-split-posting-identity', [
            'group' => 'alt.binaries.nonexistent',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown Usenet group', Artisan::output());
    }

    public function test_a_bad_limit_fails_before_anything_is_scanned(): void
    {
        $exitCode = Artisan::call('nntmux:repair-split-posting-identity', [
            'group' => self::GROUP_NAME,
            '--limit' => '0',
            '--update' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--limit must be at least 1', Artisan::output());
        $this->assertSame(4, (int) DB::table('collections')->count());
    }

    /**
     * One posting over 3 collections keyed by 3 different part counts, plus a
     * single-file sidecar that must be refused.
     */
    private function seedSplitPosting(): void
    {
        $big = $this->seedCollection('big', 242, '2026-07-31 04:00:00');
        $this->seedBinary($big, self::STEM.'.001', 242, 2, 38);
        $this->seedBinary($big, self::STEM.'.002', 242, 2, 172);

        $vol1 = $this->seedCollection('vol000', 63, '2026-07-31 04:10:00');
        $this->seedBinary($vol1, self::STEM.'.vol000+045.par2', 63, 2);
        $vol2 = $this->seedCollection('vol045', 124, '2026-07-31 04:15:00');
        $this->seedBinary($vol2, self::STEM.'.vol045+090.par2', 124, 2);

        $jpg = $this->seedCollection('jpg', 3, '2026-07-31 04:25:00');
        $this->seedBinary($jpg, self::STEM.'.jpg', 3, 3);
    }

    private function seedCollection(string $hash, int $totalFiles, string $dateadded): int
    {
        $description = 'The Paper Boy (1994) Dvdrip by Helljahve';

        return (int) DB::table('collections')->insertGetId([
            // The production quote layout: the FIRST quoted run is ' yEnc '.
            'subject' => $description.' " yEnc "'.$description.'.avi" yEnc',
            'fromname' => self::POSTER,
            'date' => $dateadded,
            'groups_id' => self::GROUP_ID,
            'totalfiles' => $totalFiles,
            'collectionhash' => 'seed-'.$hash,
            'dateadded' => $dateadded,
            'filecheck' => 0,
        ]);
    }

    private function seedBinary(
        int $collectionId,
        string $file,
        int $declaredParts,
        int $parts,
        ?int $fileNumber = null
    ): int {
        $binaryId = $this->nextBinaryId++;

        DB::table('binaries')->insert([
            'id' => $binaryId,
            'binaryhash' => md5($file.$collectionId),
            'name' => 'The Paper Boy (1994) Dvdrip by Helljahve " yEnc "'.$file.'" yEnc',
            'collections_id' => $collectionId,
            'filenumber' => $fileNumber ?? 1,
            'totalparts' => $declaredParts,
            'currentparts' => $parts,
            'partcheck' => 0,
            'partsize' => 740162 * $parts,
        ]);

        $rows = [];
        for ($i = 1; $i <= $parts; $i++) {
            $rows[] = [
                'binaries_id' => $binaryId,
                'messageid' => 'part'.$this->nextArticle.'@news',
                'number' => $this->nextArticle++,
                'partnumber' => $i,
                'size' => 740162,
            ];
        }
        DB::table('parts')->insert($rows);

        return $binaryId;
    }
}
