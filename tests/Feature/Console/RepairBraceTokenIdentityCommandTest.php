<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Binaries\ObfuscatedSubjectNormalizer;
use App\Services\Diagnostics\BraceTokenIdentityRepairService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RepairBraceTokenIdentityCommandTest extends TestCase
{
    private const GROUP_ID = 6979;

    private const GROUP_NAME = 'alt.binaries.movies';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.obfuscated_brace_token_groups' => [self::GROUP_NAME],
        ]);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
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
            filenumber INT DEFAULT 0,
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

        DB::table('usenet_groups')->insert(['id' => self::GROUP_ID, 'name' => self::GROUP_NAME]);
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 3);
    }

    public function test_the_command_is_registered_and_dry_runs_by_default(): void
    {
        $exitCode = Artisan::call('nntmux:repair-brace-token-identity', [
            'group' => self::GROUP_NAME,
            '--json' => true,
        ]);
        $summary = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertFalse($summary['updated']);
        $this->assertTrue($summary['group_normalization_enabled']);
        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(3, $summary['collections_in_cohorts']);
        $this->assertSame(0, $summary['collections_removed']);
        $this->assertSame(3, DB::table('collections')->count());
    }

    public function test_update_merges_and_the_human_summary_reports_it(): void
    {
        $exitCode = Artisan::call('nntmux:repair-brace-token-identity', [
            'group' => (string) self::GROUP_ID,
            '--update' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Repaired '.self::GROUP_NAME, $output);
        $this->assertStringContainsString('cohorts merged', $output);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(3, DB::table('parts')->count());
    }

    public function test_an_unknown_group_fails_without_a_stack_trace(): void
    {
        $exitCode = Artisan::call('nntmux:repair-brace-token-identity', [
            'group' => 'alt.binaries.nonexistent',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown Usenet group', Artisan::output());
    }

    public function test_update_is_refused_when_the_group_is_not_normalized_at_ingest(): void
    {
        config(['nntmux.obfuscated_brace_token_groups' => []]);
        $this->app->forgetInstance(BraceTokenIdentityRepairService::class);

        $exitCode = Artisan::call('nntmux:repair-brace-token-identity', [
            'group' => self::GROUP_NAME,
            '--update' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('normalization is not enabled', Artisan::output());
        $this->assertSame(3, DB::table('collections')->count());
    }

    private function seedStrandedFile(string $normalizedName, int $parts): void
    {
        for ($i = 1; $i <= $parts; $i++) {
            $token = substr(str_pad((string) $i, 12, 'aBcDeFgHiJkL'), 0, 12);
            $subject = str_replace('} yEnc', '} {'.$token.'} yEnc', $normalizedName);

            $collectionId = (int) DB::table('collections')->insertGetId([
                'subject' => $subject,
                'date' => '2026-08-02 05:00:00',
                'groups_id' => self::GROUP_ID,
                'totalfiles' => 1,
                'collectionhash' => sha1($subject),
                'dateadded' => '2026-08-02 05:00:00',
                'filecheck' => 0,
            ]);

            DB::table('binaries')->insert([
                'id' => $i,
                'binaryhash' => md5($subject),
                'name' => $subject,
                'collections_id' => $collectionId,
                'filenumber' => $i,
                'totalparts' => $parts,
                'currentparts' => 1,
                'partsize' => 740162,
            ]);

            DB::table('parts')->insert([
                'binaries_id' => $i,
                'messageid' => 'article'.$i.'@ngPost',
                'number' => 1_000_000 + $i,
                'partnumber' => $i,
                'size' => 740162,
            ]);
        }

        // Sanity: the seed really is the pre-fix shape the repair targets.
        $this->assertNotNull(
            (new ObfuscatedSubjectNormalizer([self::GROUP_NAME]))->normalize(
                (string) DB::table('collections')->value('subject')
            )
        );
    }
}
