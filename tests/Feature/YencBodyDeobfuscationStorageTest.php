<?php

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\YencBodyPreamble;
use App\Services\BlacklistService;
use App\Services\CollectionsCleaningService;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class YencBodyDeobfuscationStorageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
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
            UNIQUE(binaryhash, collections_id)
        )');

        DB::statement('CREATE TABLE parts (
            binaries_id INT,
            number INT,
            messageid VARCHAR(255),
            partnumber INT,
            size INT,
            UNIQUE(binaries_id, number)
        )');

        DB::statement('CREATE TABLE collection_regexes (
            id INTEGER PRIMARY KEY,
            group_regex VARCHAR(255),
            regex VARCHAR(255),
            status INT DEFAULT 1,
            ordinal INT DEFAULT 0
        )');
    }

    public function test_body_deobfuscated_header_keeps_segment_total_out_of_collection_totalfiles(): void
    {
        $metadata = YencBodyPreamble::fromLines([
            '=ybegin part=104 total=634 line=128 size=454033408 name=Y7FouDJBgKrPFCGpz4wp.part070.rar',
            '=ypart begin=73830401 end=74547200',
        ]);

        $header = [
            'Number' => 785650027,
            'Subject' => $metadata->toSyntheticSubject(),
            'From' => 'Qyb8IOrhzbQFL@ngPost.com',
            'Date' => time(),
            'Bytes' => 740162,
            'Message-ID' => '<757aeb2bb70f4915a13a1fecd8090147@ngPost>',
            'Xref' => 'news.example a.b.boneless:785650027',
            'matches' => [
                0 => $metadata->toSyntheticSubject(),
                1 => '"'.$metadata->name.'"',
                2 => $metadata->part,
                3 => $metadata->total,
            ],
            'collection_file_number' => $metadata->collectionFileNumber(),
            'collection_total_files' => 0,
        ];

        $service = new HeaderStorageService(
            new CollectionHandler(new class extends CollectionsCleaningService
            {
                public function __construct()
                {
                    parent::__construct();
                }

                public function collectionsCleaner(string $subject, string $groupName = ''): array
                {
                    $name = preg_replace('/\.part\d+\.rar"?$/i', '', $subject) ?: $subject;

                    return ['id' => 0, 'name' => trim($name, '" ')];
                }
            }),
            config: new BinariesConfig(partsChunkSize: 10, headerChunkSize: 10)
        );
        $failed = $service->store([$header], ['id' => 2, 'name' => 'a.b.boneless'], true);

        $this->assertSame([], $failed);
        $this->assertSame(0, (int) DB::table('collections')->value('totalfiles'));
        $this->assertSame(70, (int) DB::table('binaries')->value('filenumber'));
        $this->assertSame(634, (int) DB::table('binaries')->value('totalparts'));
        $this->assertSame(104, (int) DB::table('parts')->value('partnumber'));
    }

    public function test_scan_uses_body_preamble_for_hash_only_subjects_in_configured_groups(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getXOVER')
            ->once()
            ->with('785650027-785650027')
            ->andReturn([[
                'Number' => '785650027',
                'Subject' => '757aeb2bb70f4915a13a1fecd8090147',
                'From' => 'Qyb8IOrhzbQFL@ngPost.com',
                'Date' => 'Thu, 04 Jun 2026 12:32:44 +0000',
                'Message-ID' => '<757aeb2bb70f4915a13a1fecd8090147@ngPost>',
                'Bytes' => '740162',
                'Xref' => 'news.example a.b.boneless:785650027',
            ]]);
        $nntp->shouldReceive('getYencBodyPreambleLines')
            ->once()
            ->with('a.b.boneless', '785650027', 8)
            ->andReturn([
                '=ybegin part=104 total=634 line=128 size=454033408 name=Y7FouDJBgKrPFCGpz4wp.part070.rar',
                '=ypart begin=73830401 end=74547200',
            ]);

        $service = new BinariesService(
            new BinariesConfig(
                partsChunkSize: 10,
                headerChunkSize: 10,
                bodyPreambleDeobfuscateGroups: ['a.b.boneless'],
                bodyPreambleDeobfuscateLimit: 10,
                bodyPreambleLineLimit: 8
            ),
            headerParser: new HeaderParser(new class extends BlacklistService
            {
                public function isBlackListed(array $msg, string $groupName): bool
                {
                    return false;
                }
            }),
            headerStorage: $this->deterministicHeaderStorage(),
            nntp: $nntp
        );

        $summary = $service->scan(['id' => 2, 'name' => 'a.b.boneless'], 785650027, 785650027);

        $this->assertSame('785650027', $summary['firstArticleNumber']);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame('"Y7FouDJBgKrPFCGpz4wp.part070.rar"', DB::table('collections')->value('subject'));
        $this->assertSame(0, (int) DB::table('collections')->value('totalfiles'));
        $this->assertSame(70, (int) DB::table('binaries')->value('filenumber'));
        $this->assertSame(104, (int) DB::table('parts')->value('partnumber'));
    }

    private function deterministicHeaderStorage(): HeaderStorageService
    {
        return new HeaderStorageService(
            new CollectionHandler(new class extends CollectionsCleaningService
            {
                public function __construct()
                {
                    parent::__construct();
                }

                public function collectionsCleaner(string $subject, string $groupName = ''): array
                {
                    $name = preg_replace('/\.part\d+\.rar"?$/i', '', $subject) ?: $subject;

                    return ['id' => 0, 'name' => trim($name, '" ')];
                }
            }),
            config: new BinariesConfig(partsChunkSize: 10, headerChunkSize: 10)
        );
    }
}
