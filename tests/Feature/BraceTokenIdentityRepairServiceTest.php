<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\ObfuscatedSubjectNormalizer;
use App\Services\Diagnostics\BraceTokenIdentityRepairService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class BraceTokenIdentityRepairServiceTest extends TestCase
{
    private const GROUP_ID = 6979;

    private const GROUP_NAME = 'alt.binaries.movies';

    private int $nextBinaryId = 1;

    private int $nextArticle = 1_000_000;

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

        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255)
        )');

        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            subject VARCHAR(255),
            fromname VARCHAR(255) DEFAULT \'\',
            date DATETIME NULL,
            xref TEXT DEFAULT \'\',
            groups_id INT,
            totalfiles INT DEFAULT 0,
            collectionhash VARCHAR(255) UNIQUE,
            collection_regexes_id INT DEFAULT 0,
            dateadded DATETIME NULL,
            filecheck INT DEFAULT 0,
            filesize INT DEFAULT 0,
            releases_id INT NULL,
            noise VARCHAR(32) DEFAULT \'\'
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
    }

    public function test_dry_run_reports_cohorts_without_touching_anything(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 512);
        $this->seedStrandedFile('{Soulm8te.2026.part02.rar} yEnc', 512);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, false);

        $this->assertFalse($summary['updated']);
        $this->assertSame(2, $summary['cohorts_found']);
        $this->assertSame(1024, $summary['collections_in_cohorts']);
        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['collections_removed']);

        // Nothing moved.
        $this->assertSame(1024, DB::table('collections')->count());
        $this->assertSame(1024, DB::table('binaries')->count());
    }

    public function test_update_collapses_each_file_onto_one_collection_and_one_binary(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 40);
        $this->seedStrandedFile('{Soulm8te.2026.part02.rar} yEnc', 12);
        $this->seedStrandedFile('{Soulm8te.2026.vol000+01.par2} yEnc', 5);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(3, $summary['cohorts_merged']);
        $this->assertSame(0, $summary['cohorts_skipped']);
        $this->assertSame(57 - 3, $summary['collections_removed']);
        $this->assertSame(57 - 3, $summary['binaries_removed']);
        $this->assertSame(57 - 3, $summary['parts_moved']);

        $this->assertSame(3, DB::table('collections')->count());
        $this->assertSame(3, DB::table('binaries')->count());
        // Every part is retained -- the merge rehomes them, never drops them.
        $this->assertSame(57, DB::table('parts')->count());

        foreach ([
            '{Soulm8te.2026.part01.rar} yEnc' => 40,
            '{Soulm8te.2026.part02.rar} yEnc' => 12,
            '{Soulm8te.2026.vol000+01.par2} yEnc' => 5,
        ] as $name => $parts) {
            $hash = sha1(ObfuscatedSubjectNormalizer::collectionKey($name, self::GROUP_ID));
            $collection = DB::table('collections')->where('collectionhash', $hash)->first();

            $this->assertNotNull($collection, $name);
            $this->assertSame($name, $collection->subject);
            $this->assertSame(1, (int) $collection->totalfiles);
            $this->assertSame(0, (int) $collection->filecheck);

            $binaries = DB::table('binaries')->where('collections_id', $collection->id)->get();
            $this->assertCount(1, $binaries, $name);
            $binary = $binaries->first();
            $this->assertSame(1, (int) $binary->filenumber);
            $this->assertSame($parts, (int) $binary->currentparts);
            $this->assertSame($parts, (int) $binary->totalparts);
            $this->assertSame(0, (int) $binary->partcheck);
            $this->assertSame(
                $parts,
                DB::table('parts')->where('binaries_id', $binary->id)->count(),
                $name
            );
            $this->assertSame(
                (int) DB::table('parts')->where('binaries_id', $binary->id)->sum('size'),
                (int) $binary->partsize
            );
        }
    }

    public function test_survivor_keeps_the_oldest_member_timestamps(): void
    {
        $this->seedStrandedFile('{Lioness.S03.vol007+08.par2} yEnc', 3, [
            'dates' => ['2026-08-02 05:00:00', '2026-08-02 03:30:04', '2026-08-02 07:15:00'],
            'dateadded' => ['2026-08-02 06:00:00', '2026-08-02 04:00:00', '2026-08-02 08:00:00'],
        ]);

        $this->service()->repair(self::GROUP_ID, 50, null, true);

        $collection = DB::table('collections')->first();
        $this->assertSame('2026-08-02 03:30:04', $collection->date);
        $this->assertSame('2026-08-02 04:00:00', $collection->dateadded);
    }

    public function test_a_second_update_is_idempotent(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 6);

        $first = $this->service()->repair(self::GROUP_ID, 50, null, true);
        $this->assertSame(1, $first['cohorts_merged']);

        $before = [
            'collections' => DB::table('collections')->get()->toArray(),
            'binaries' => DB::table('binaries')->get()->toArray(),
            'parts' => DB::table('parts')->orderBy('number')->get()->toArray(),
        ];

        $second = $this->service()->repair(self::GROUP_ID, 50, null, true);

        // A repaired survivor carries the de-tokenised subject, so it is no
        // longer a candidate at all -- the pass converges rather than churning.
        $this->assertSame(0, $second['cohorts_found']);
        $this->assertSame(0, $second['collections_removed']);
        $this->assertSame(0, $second['binaries_removed']);
        $this->assertSame(0, $second['parts_moved']);

        $this->assertEquals($before['collections'], DB::table('collections')->get()->toArray());
        $this->assertEquals($before['binaries'], DB::table('binaries')->get()->toArray());
        $this->assertEquals($before['parts'], DB::table('parts')->orderBy('number')->get()->toArray());
    }

    public function test_a_cohort_whose_members_claim_the_same_partnumber_is_skipped(): void
    {
        // Two collections both holding part 1 are not one file; merging them
        // would silently drop an article.
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 2, [
            'partnumbers' => [1, 1],
        ]);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(0, $summary['cohorts_merged']);
        $this->assertSame(1, $summary['cohorts_skipped']);
        $this->assertSame('duplicate_partnumber', $summary['skipped'][0]['reason']);
        $this->assertSame([1], $summary['skipped'][0]['values']);

        // Untouched.
        $this->assertSame(2, DB::table('collections')->count());
        $this->assertSame(2, DB::table('binaries')->count());
        $this->assertSame(2, DB::table('parts')->count());
    }

    public function test_it_adopts_a_collection_ingest_already_created_under_the_repaired_key(): void
    {
        $name = '{Soulm8te.2026.part01.rar} yEnc';
        // The stranded rows, plus a row the fixed ingest path minted later under
        // the correct key with a HIGHER id than any of them.
        $this->seedStrandedFile($name, 4);
        $ingestId = $this->seedCollection(
            $name,
            sha1(ObfuscatedSubjectNormalizer::collectionKey($name, self::GROUP_ID)),
            '2026-08-03 10:00:00',
            '2026-08-03 10:00:00',
        );
        $this->seedBinary($ingestId, $name, 1, 4, [99]);

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(1, $summary['cohorts_merged']);
        $this->assertSame(1, DB::table('collections')->count());
        // The pre-existing owner of the hash survives, not the lowest id.
        $this->assertSame($ingestId, (int) DB::table('collections')->value('id'));
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(5, DB::table('parts')->count());
        $this->assertSame(5, (int) DB::table('binaries')->value('currentparts'));
    }

    public function test_the_cohort_limit_bounds_cohorts_and_reports_the_truncation(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 3);
        $this->seedStrandedFile('{Soulm8te.2026.part02.rar} yEnc', 3);
        $this->seedStrandedFile('{Soulm8te.2026.part03.rar} yEnc', 3);

        $summary = $this->service()->repair(self::GROUP_ID, 2, null, true);

        $this->assertTrue($summary['cohort_limit_reached']);
        $this->assertSame(2, $summary['cohorts_found']);
        $this->assertSame(2, $summary['cohorts_merged']);
        // The two merged cohorts collapse to 1 collection each; the third is
        // left whole, not half-merged.
        $this->assertSame(2 + 3, DB::table('collections')->count());
        $this->assertSame(9, DB::table('parts')->count());
    }

    public function test_it_ignores_collections_that_are_not_brace_token(): void
    {
        $this->seedCollection(
            'Some.Normal.Release.2024 - [01/20] - "file.rar" yEnc',
            'plain-hash',
            '2026-08-02 05:00:00',
            '2026-08-02 05:00:00',
        );
        // Braced metadata that is not a random token must be left alone too.
        $this->seedCollection(
            '{Movie.Name.2024.rar} {Some.Group.Name} yEnc',
            'metadata-hash',
            '2026-08-02 05:00:00',
            '2026-08-02 05:00:00',
        );

        $summary = $this->service()->repair(self::GROUP_ID, 50, null, true);

        $this->assertSame(0, $summary['cohorts_found']);
        $this->assertSame(2, DB::table('collections')->count());
    }

    public function test_before_excludes_newer_collections(): void
    {
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 2, [
            'dateadded' => ['2026-08-01 00:00:00', '2026-08-03 00:00:00'],
        ]);

        $summary = $this->service()->repair(self::GROUP_ID, 50, '2026-08-02 00:00:00', false);

        $this->assertSame(1, $summary['cohorts_found']);
        $this->assertSame(1, $summary['collections_in_cohorts']);
    }

    public function test_it_refuses_to_apply_to_a_group_ingest_does_not_normalize(): void
    {
        config(['nntmux.obfuscated_brace_token_groups' => []]);
        $this->seedStrandedFile('{Soulm8te.2026.part01.rar} yEnc', 2);

        $summary = (new BraceTokenIdentityRepairService)->repair(self::GROUP_ID, 50, null, false);
        $this->assertFalse($summary['group_normalization_enabled']);
        $this->assertSame(1, $summary['cohorts_found']);

        $this->expectException(InvalidArgumentException::class);
        (new BraceTokenIdentityRepairService)->repair(self::GROUP_ID, 50, null, true);
    }

    public function test_unknown_group_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->repair('alt.binaries.nonexistent', 50, null, false);
    }

    public function test_limit_below_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->repair(self::GROUP_ID, 0, null, false);
    }

    private function service(): BraceTokenIdentityRepairService
    {
        return new BraceTokenIdentityRepairService(
            new ObfuscatedSubjectNormalizer([self::GROUP_NAME])
        );
    }

    /**
     * Reproduce the pre-fix shape: one collection per ARTICLE, each holding one
     * binary with one part, keyed on a subject that still carries its token.
     *
     * @param  array{dates?: list<string>, dateadded?: list<string>, partnumbers?: list<int>}  $overrides
     */
    private function seedStrandedFile(string $normalizedName, int $parts, array $overrides = []): void
    {
        for ($i = 0; $i < $parts; $i++) {
            $partNumber = $overrides['partnumbers'][$i] ?? ($i + 1);
            $token = substr(str_pad((string) ($i + 1), 12, 'aBcDeFgHiJkL'), 0, 12);
            $subject = str_replace('} yEnc', '} {'.$token.'} yEnc', $normalizedName);

            $collectionId = $this->seedCollection(
                $subject,
                sha1($subject.$parts),
                $overrides['dates'][$i] ?? '2026-08-02 05:00:00',
                $overrides['dateadded'][$i] ?? '2026-08-02 05:00:00',
            );

            $this->seedBinary($collectionId, $subject, $partNumber, $parts, [$partNumber]);
        }
    }

    private function seedCollection(string $subject, string $hash, string $date, string $dateadded): int
    {
        return (int) DB::table('collections')->insertGetId([
            'subject' => $subject,
            'fromname' => 'Ultraman <bowman@test.com>',
            'date' => $date,
            'groups_id' => self::GROUP_ID,
            'totalfiles' => 1,
            'collectionhash' => $hash,
            'dateadded' => $dateadded,
            'filecheck' => 0,
        ]);
    }

    /** @param  list<int>  $partNumbers */
    private function seedBinary(int $collectionId, string $name, int $fileNumber, int $totalParts, array $partNumbers): int
    {
        $binaryId = $this->nextBinaryId++;
        DB::table('binaries')->insert([
            'id' => $binaryId,
            'binaryhash' => md5($name.$collectionId),
            'name' => $name,
            'collections_id' => $collectionId,
            'filenumber' => $fileNumber,
            'totalparts' => $totalParts,
            'currentparts' => \count($partNumbers),
            'partcheck' => 0,
            'partsize' => 740162 * \count($partNumbers),
        ]);

        foreach ($partNumbers as $partNumber) {
            DB::table('parts')->insert([
                'binaries_id' => $binaryId,
                'messageid' => 'article'.$this->nextArticle.'@ngPost',
                'number' => $this->nextArticle++,
                'partnumber' => $partNumber,
                'size' => 740162,
            ]);
        }

        return $binaryId;
    }
}
