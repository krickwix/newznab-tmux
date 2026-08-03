<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\ObfuscatedSubjectNormalizer;
use App\Services\BlacklistService;
use App\Services\CollectionsCleaningService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Brace-token postings must land one collection and one binary PER FILE.
 *
 * The regression this guards: HeaderParser strips the per-article token, and the
 * cleaned name that remains is too coarse -- CollectionsCleaningService strips
 * digit runs, so part01..partNN and every par2 volume of a posting clean to one
 * name. Keyed on that, all of them share a collection, and because these
 * subjects pin file_number to 1/1 against UNIQUE (collections_id, filenumber),
 * they then share a single binary and pile every part onto it. Measured against
 * the live cleaner, 98 real filenames collapsed onto 5 keys that way.
 */
final class BraceTokenCollectionKeyingTest extends TestCase
{
    private const GROUP = ['id' => 6979, 'name' => 'alt.binaries.movies'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.obfuscated_brace_token_groups' => [self::GROUP['name']],
            'nntmux.obfuscated_hash_set_groups' => [],
        ]);
        DB::purge();
        DB::reconnect();

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
            dateadded DATETIME NULL,
            noise VARCHAR(64) DEFAULT \'\'
        )');

        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            binaryhash BLOB,
            name VARCHAR(255),
            collections_id INT,
            totalparts INT,
            currentparts INT,
            filenumber INT,
            partsize INT,
            UNIQUE(collections_id, filenumber)
        )');

        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, number)
        )');

        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
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

        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INT DEFAULT 1,
            ordinal INT DEFAULT 0
        )');
    }

    public function test_header_parser_flags_brace_token_subjects(): void
    {
        $parsed = $this->parse([
            $this->rawHeader(1, '{Soulm8te.2026.part01.rar} {4e6V9vStTb1E} yEnc (7/512)'),
        ]);

        $this->assertCount(1, $parsed);
        $this->assertTrue($parsed[0]['collection_brace_token']);
        $this->assertSame(1, $parsed[0]['collection_file_number']);
        $this->assertSame(1, $parsed[0]['collection_total_files']);
        $this->assertSame('{Soulm8te.2026.part01.rar} yEnc', $parsed[0]['matches'][1]);
    }

    public function test_header_parser_does_not_flag_ordinary_subjects(): void
    {
        $parsed = $this->parse([
            $this->rawHeader(1, 'Some.Normal.Release.2024 - [01/20] - "file.rar" yEnc (3/40)'),
        ]);

        $this->assertCount(1, $parsed);
        $this->assertArrayNotHasKey('collection_brace_token', $parsed[0]);
    }

    public function test_distinct_files_of_one_posting_get_their_own_collection_and_binary(): void
    {
        // Every one of these cleans to the same name under the real cleaner.
        $headers = $this->parse([
            $this->rawHeader(11, '{Soulm8te.2026.part01.rar} {4e6V9vStTb1E} yEnc (1/512)'),
            $this->rawHeader(12, '{Soulm8te.2026.part01.rar} {6zFxF8To7GWe} yEnc (2/512)'),
            $this->rawHeader(13, '{Soulm8te.2026.part02.rar} {DIVfOxfTRBEY} yEnc (1/512)'),
            $this->rawHeader(14, '{Soulm8te.2026.part35.rar} {P39URH0AB8CS} yEnc (1/512)'),
            $this->rawHeader(15, '{Soulm8te.2026.vol000+01.par2} {stWsuZvUnzVX} yEnc (1/5)'),
        ]);

        $failed = $this->storage()->store($headers, self::GROUP, true);

        $this->assertSame([], $failed);

        // Four distinct real files -> four collections, four binaries.
        $this->assertSame(4, DB::table('collections')->count());
        $this->assertSame(4, DB::table('binaries')->count());
        // Five articles, all retained: the two part01 articles share one binary.
        $this->assertSame(5, DB::table('parts')->count());

        $part01 = DB::table('collections')
            ->where('collectionhash', sha1(ObfuscatedSubjectNormalizer::collectionKey(
                '{Soulm8te.2026.part01.rar} yEnc',
                self::GROUP['id'],
            )))
            ->first();
        $this->assertNotNull($part01);
        $this->assertSame(1, DB::table('binaries')->where('collections_id', $part01->id)->count());
        $this->assertSame(
            2,
            DB::table('parts')
                ->whereIn('binaries_id', DB::table('binaries')->where('collections_id', $part01->id)->pluck('id'))
                ->count()
        );

        // Each collection holds exactly one file, keyed 1 of 1.
        foreach (DB::table('binaries')->get() as $binary) {
            $this->assertSame(1, (int) $binary->filenumber);
        }
        foreach (DB::table('collections')->get() as $collection) {
            $this->assertSame(1, (int) $collection->totalfiles);
        }
    }

    public function test_every_article_of_one_file_shares_one_collection(): void
    {
        $headers = $this->parse([
            $this->rawHeader(21, '{Lioness.S03.vol007+08.par2} {0f3BTxxgGFHx} yEnc (1/37)'),
            $this->rawHeader(22, '{Lioness.S03.vol007+08.par2} {1Ld2qBo2wkrr} yEnc (2/37)'),
            $this->rawHeader(23, '{Lioness.S03.vol007+08.par2} {nEFv6Sr4xwPr} yEnc (3/37)'),
        ]);

        $this->assertSame([], $this->storage()->store($headers, self::GROUP, true));

        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(3, DB::table('parts')->count());
        $this->assertSame(3, (int) DB::table('binaries')->value('currentparts'));
        $this->assertSame(37, (int) DB::table('binaries')->value('totalparts'));
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<array<string, mixed>>
     */
    private function parse(array $raw): array
    {
        $parser = new HeaderParser(
            new class extends BlacklistService
            {
                public function isBlackListed(array $msg, string $groupName): bool
                {
                    return false;
                }
            },
            new ObfuscatedSubjectNormalizer([self::GROUP['name']]),
        );

        return $parser->parse($raw, self::GROUP['name'])['headers'];
    }

    private function storage(): HeaderStorageService
    {
        return new HeaderStorageService(
            // The real cleaner strips digit runs, which is exactly the hazard
            // under test; stub it to that behaviour deterministically so the
            // test does not depend on the DB regex table.
            new CollectionHandler(new class extends CollectionsCleaningService
            {
                public function collectionsCleaner(string $subject, string $groupName = ''): array
                {
                    return [
                        'id' => self::REGEX_GENERIC_MATCH,
                        'name' => preg_replace('/\d+/', '', $subject) ?? $subject,
                    ];
                }
            }),
            config: new BinariesConfig(partsChunkSize: 10, headerChunkSize: 10)
        );
    }

    /** @return array<string, mixed> */
    private function rawHeader(int $number, string $subject): array
    {
        return [
            'Number' => $number,
            'Subject' => $subject,
            'From' => 'Ultraman <bowman@test.com>',
            'Date' => 'Sun, 02 Aug 2026 18:23:58 +0000',
            'Message-ID' => '<article'.$number.'@ngPost>',
            'Bytes' => 740162,
            'Xref' => 'news.example '.self::GROUP['name'].':'.$number,
        ];
    }
}
