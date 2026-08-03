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
 * What ingest currently does with a brace-token posting -- INCLUDING the part
 * that is still wrong.
 *
 * These are characterisation tests, not a specification. HeaderParser strips the
 * per-article token, which is correct and fixes the one-collection-per-article
 * stall. But the cleaned name that then keys the collection is too coarse:
 * CollectionsCleaningService strips digit runs, so part01..partNN and every par2
 * volume of one posting clean to the same name. Two consequences, both asserted
 * below so they cannot change unnoticed:
 *
 *  1. distinct real files share a collection AND -- because these subjects pin
 *     file_number to 1/1 against UNIQUE (collections_id, filenumber) -- a single
 *     binary, so parts of several files pile onto a binary carrying just one of
 *     their names;
 *  2. the resulting collection holds one binary, which the release pipeline
 *     deletes (stage 6 rewrites totalfiles to COUNT(binaries), landing under
 *     minfilestoformrelease) with FK_Collections cascading the parts away.
 *
 * An earlier revision of this branch fixed (1) by keying per real file. That was
 * reverted: it leaves (2) fully intact, so it converts silent corruption into
 * reliable deletion. Fixing this properly needs a per-file ordinal no single
 * header can supply -- a par2 volume cannot be ranked without the payload count,
 * and the gates reading MAX(filenumber) as a file count reject sparse or
 * high-band numbering -- so it is scoped as separate work.
 *
 * Until then the historical residue is reclaimed by
 * nntmux:repair-brace-token-identity, which sees a whole cohort at once and can
 * therefore allocate dense ordinals; see BraceTokenIdentityRepairServiceTest and
 * BraceTokenRepairSurvivesReleaseGatesTest.
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

    public function test_header_parser_strips_the_token_and_pins_one_of_one(): void
    {
        $parsed = $this->parse([
            $this->rawHeader(1, '{Soulm8te.2026.part01.rar} {4e6V9vStTb1E} yEnc (7/512)'),
        ]);

        $this->assertCount(1, $parsed);
        $this->assertSame(1, $parsed[0]['collection_file_number']);
        $this->assertSame(1, $parsed[0]['collection_total_files']);
        $this->assertSame('{Soulm8te.2026.part01.rar} yEnc', $parsed[0]['matches'][1]);
    }

    public function test_ordinary_subjects_are_left_alone(): void
    {
        $parsed = $this->parse([
            $this->rawHeader(1, 'Some.Normal.Release.2024 - [01/20] - "file.rar" yEnc (3/40)'),
        ]);

        $this->assertCount(1, $parsed);
        $this->assertArrayNotHasKey('collection_file_number', $parsed[0]);
    }

    /**
     * Every article of one file does share one collection and one binary -- the
     * half of the fix that works, and the reason these posts no longer stall at
     * currentparts=1.
     */
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
     * The known-bad half, pinned deliberately.
     *
     * Five real files of one posting collapse onto two collections holding one
     * binary each, and a binary named for ONE file ends up owning the parts of
     * three. No article is dropped at ingest -- but attribution is lost, and the
     * one-binary collections are then deleted downstream, taking the parts with
     * them.
     *
     * If this test starts failing, ingest behaviour changed: check which way.
     * More binaries per collection is progress; fewer parts is data loss.
     */
    public function test_distinct_files_of_one_posting_currently_collapse_onto_one_binary(): void
    {
        $raw = [];
        $article = 0;
        foreach (['part01', 'part02', 'part03'] as $file) {
            foreach ([1, 2, 3] as $part) {
                $article++;
                $raw[] = $this->rawHeader(
                    $article,
                    '{Soulm8te.2026.'.$file.'.rar} {tok'.str_pad((string) $article, 9, 'x').'} yEnc ('.$part.'/3)'
                );
            }
        }
        foreach (['vol000+01', 'vol001+02'] as $file) {
            foreach ([1, 2] as $part) {
                $article++;
                $raw[] = $this->rawHeader(
                    $article,
                    '{Soulm8te.2026.'.$file.'.par2} {tok'.str_pad((string) $article, 9, 'x').'} yEnc ('.$part.'/2)'
                );
            }
        }

        $this->assertSame([], $this->storage()->store($this->parse($raw), self::GROUP, true));

        // 5 real files in, 2 collections out: the payload volumes fuse, and so do
        // the par2 volumes.
        $this->assertSame(2, DB::table('collections')->count());
        $this->assertSame(2, DB::table('binaries')->count());
        // No article is lost at ingest; only the file it belongs to is.
        $this->assertSame(13, DB::table('parts')->count());

        foreach (DB::table('collections')->get() as $collection) {
            $this->assertSame(
                1,
                DB::table('binaries')->where('collections_id', $collection->id)->count(),
                'One binary per collection is the shape the release pipeline deletes.'
            );
        }

        // A binary holding 9 parts is holding three files' worth of articles
        // under a single file's name.
        $this->assertSame(
            [4, 9],
            DB::table('binaries')
                ->get()
                ->map(static fn ($binary): int => DB::table('parts')->where('binaries_id', $binary->id)->count())
                ->sort()
                ->values()
                ->all()
        );
    }

    /**
     * The repair pass's key must stay distinct from anything ingest computes, so
     * a repaired posting cannot collide with a live collection.
     */
    public function test_the_posting_key_is_distinct_from_the_per_file_key(): void
    {
        $this->assertNotSame(
            ObfuscatedSubjectNormalizer::postingKey('Soulm8te.2026', self::GROUP['id']),
            ObfuscatedSubjectNormalizer::collectionKey('{Soulm8te.2026.part01.rar} yEnc', self::GROUP['id']),
        );

        // Same posting, different files -> one key.
        $this->assertSame(
            ObfuscatedSubjectNormalizer::postingIdentity('{Soulm8te.2026.part01.rar} yEnc')['posting'],
            ObfuscatedSubjectNormalizer::postingIdentity('{Soulm8te.2026.vol000+01.par2} yEnc')['posting'],
        );
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
