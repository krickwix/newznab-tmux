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

        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
            numberid INT,
            groups_id INT,
            attempts INT DEFAULT 0,
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

    public function test_scan_reports_body_preamble_probe_timing_in_cli_output(): void
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
                echoCli: true,
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

        $service->scan(['id' => 2, 'name' => 'a.b.boneless'], 785650027, 785650027);

        $statsProperty = new \ReflectionProperty(BinariesService::class, 'bodyPreambleStats');
        $stats = $statsProperty->getValue($service);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('elapsed_seconds', $stats);
        $this->assertArrayHasKey('average_ms', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['elapsed_seconds']);
        $this->assertGreaterThanOrEqual(0, $stats['average_ms']);
    }

    public function test_scan_uses_body_preamble_for_quoted_obfuscated_yenc_subjects_in_configured_groups(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getXOVER')
            ->once()
            ->with('7305032856-7305032856')
            ->andReturn([[
                'Number' => '7305032856',
                'Subject' => '[21/52] - "DDrDC5ShYs629Bmm-NvXbM-8ldS68zeZoVLz20OdcUb41oi6FzorrYizrjv.iWT4a90" yEnc',
                'From' => 'poster@example.invalid',
                'Date' => 'Sun, 14 Jun 2026 09:13:21 +0000',
                'Message-ID' => '<7305032856@example.invalid>',
                'Bytes' => '740162',
                'Xref' => 'news.example alt.binaries.blu-ray:7305032856',
            ]]);
        $nntp->shouldReceive('getYencBodyPreambleLines')
            ->once()
            ->with('alt.binaries.blu-ray', '7305032856', 10)
            ->andReturn([
                '=ybegin part=22 total=76 line=128 size=454033408 name=Movie.Payload.part021.rar',
                '=ypart begin=15073281 end=15810560',
            ]);

        $service = new BinariesService(
            new BinariesConfig(
                partsChunkSize: 10,
                headerChunkSize: 10,
                bodyPreambleDeobfuscateGroups: ['alt.binaries.blu-ray'],
                bodyPreambleDeobfuscateLimit: 10,
                bodyPreambleLineLimit: 10
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

        $summary = $service->scan(['id' => 5, 'name' => 'alt.binaries.blu-ray'], 7305032856, 7305032856);

        $this->assertSame('7305032856', $summary['firstArticleNumber']);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame('"Movie.Payload.part021.rar"', DB::table('collections')->value('subject'));
        $this->assertSame(0, (int) DB::table('collections')->value('totalfiles'));
        $this->assertSame(21, (int) DB::table('binaries')->value('filenumber'));
        $this->assertSame(76, (int) DB::table('binaries')->value('totalparts'));
        $this->assertSame(22, (int) DB::table('parts')->value('partnumber'));
    }

    public function test_scan_marks_standalone_body_payload_as_single_collection_file(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getXOVER')
            ->once()
            ->with('7676259507-7676259507')
            ->andReturn([[
                'Number' => '7676259507',
                'Subject' => '[12/99] - "xrGg4N90wddrrVu6vtrc74eODKjeOP9w5r2tNN7lAjdB.TQz" yEnc (33377/62540)',
                'From' => 'poster@example.invalid',
                'Date' => 'Sun, 14 Jun 2026 11:24:21 +0000',
                'Message-ID' => '<7676259507@example.invalid>',
                'Bytes' => '740016',
                'Xref' => 'news.example alt.binaries.blu-ray:7676259507',
            ]]);
        $nntp->shouldReceive('getYencBodyPreambleLines')
            ->once()
            ->with('alt.binaries.blu-ray', '7676259507', 10)
            ->andReturn([
                '=ybegin part=33377 total=62540 line=128 size=45362642923 name=Isle.of.Dogs.2018.Blu-ray.CEE.1080p.AVC.DTS-HD.MA.5.1-CapBd.iso',
                '=ypart begin=24681357901 end=24682097916',
            ]);

        $service = new BinariesService(
            new BinariesConfig(
                partsChunkSize: 10,
                headerChunkSize: 10,
                bodyPreambleDeobfuscateGroups: ['alt.binaries.blu-ray'],
                bodyPreambleDeobfuscateLimit: 10,
                bodyPreambleLineLimit: 10
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

        $service->scan(['id' => 5, 'name' => 'alt.binaries.blu-ray'], 7676259507, 7676259507);

        $this->assertSame('"Isle.of.Dogs.2018.Blu-ray.CEE.1080p.AVC.DTS-HD.MA.5.1-CapBd.iso"', DB::table('collections')->value('subject'));
        $this->assertSame(1, (int) DB::table('collections')->value('totalfiles'));
        $this->assertSame(1, (int) DB::table('binaries')->value('filenumber'));
        $this->assertSame(62540, (int) DB::table('binaries')->value('totalparts'));
        $this->assertSame(33377, (int) DB::table('parts')->value('partnumber'));
    }

    public function test_part_repair_uses_body_preamble_for_string_header_numbers_matching_integer_missing_parts(): void
    {
        DB::table('missed_parts')->insert([
            'numberid' => 7676259507,
            'groups_id' => 5,
            'attempts' => 1,
        ]);

        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getOverview')
            ->once()
            ->with('7676259507-7676259507', true, false)
            ->andReturn([[
                'Number' => '7676259507',
                'Subject' => '[12/99] - "xrGg4N90wddrrVu6vtrc74eODKjeOP9w5r2tNN7lAjdB.TQz" yEnc (33377/62540)',
                'From' => 'poster@example.invalid',
                'Date' => 'Sun, 14 Jun 2026 11:24:21 +0000',
                'Message-ID' => '<7676259507@example.invalid>',
                'Bytes' => '740016',
                'Xref' => 'news.example alt.binaries.blu-ray:7676259507',
            ]]);
        $nntp->shouldReceive('getYencBodyPreambleLines')
            ->once()
            ->with('alt.binaries.blu-ray', '7676259507', 10)
            ->andReturn([
                '=ybegin part=33377 total=62540 line=128 size=45362642923 name=Isle.of.Dogs.2018.Blu-ray.CEE.1080p.AVC.DTS-HD.MA.5.1-CapBd.iso',
                '=ypart begin=24681357901 end=24682097916',
            ]);

        $service = new BinariesService(
            new BinariesConfig(
                partsChunkSize: 10,
                headerChunkSize: 10,
                bodyPreambleDeobfuscateGroups: ['alt.binaries.blu-ray'],
                bodyPreambleDeobfuscateLimit: 10,
                bodyPreambleLineLimit: 10
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

        $service->scan(
            ['id' => 5, 'name' => 'alt.binaries.blu-ray'],
            7676259507,
            7676259507,
            'partrepair',
            [7676259507]
        );

        $this->assertSame('"Isle.of.Dogs.2018.Blu-ray.CEE.1080p.AVC.DTS-HD.MA.5.1-CapBd.iso"', DB::table('collections')->value('subject'));
        $this->assertSame(1, (int) DB::table('collections')->value('totalfiles'));
        $this->assertSame(1, (int) DB::table('binaries')->value('filenumber'));
        $this->assertSame(33377, (int) DB::table('parts')->value('partnumber'));
        $this->assertFalse(DB::table('missed_parts')->where(['groups_id' => 5, 'numberid' => 7676259507])->exists());
    }

    public function test_part_repair_keeps_returned_unparseable_articles_in_missed_parts(): void
    {
        DB::table('missed_parts')->insert([
            'numberid' => 7676259508,
            'groups_id' => 5,
            'attempts' => 1,
        ]);

        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getOverview')
            ->once()
            ->with('7676259508-7676259508', true, false)
            ->andReturn([[
                'Number' => '7676259508',
                'Subject' => 'not a parseable yenc subject',
                'From' => 'poster@example.invalid',
                'Date' => 'Sun, 14 Jun 2026 11:24:21 +0000',
                'Message-ID' => '<7676259508@example.invalid>',
                'Bytes' => '740016',
                'Xref' => 'news.example alt.binaries.blu-ray:7676259508',
            ]]);

        $service = new BinariesService(
            new BinariesConfig(partsChunkSize: 10, headerChunkSize: 10),
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

        $service->scan(
            ['id' => 5, 'name' => 'alt.binaries.blu-ray'],
            7676259508,
            7676259508,
            'partrepair',
            [7676259508]
        );

        $this->assertSame(0, DB::table('collections')->count());
        $this->assertTrue(DB::table('missed_parts')->where(['groups_id' => 5, 'numberid' => 7676259508])->exists());
    }

    public function test_scan_keeps_original_subject_when_body_preamble_name_is_not_useful(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getXOVER')
            ->once()
            ->with('7304619681-7304619681')
            ->andReturn([[
                'Number' => '7304619681',
                'Subject' => '[46/56] - "vs3yp1VCF6UKJD4sjDXwA0X6qQIw15Q1Uf0g0EUIXk4bi-zPHhq1TziqiY52U.TOe" yEnc (5/55)',
                'From' => 'poster@example.invalid',
                'Date' => 'Sun, 14 Jun 2026 09:27:42 +0000',
                'Message-ID' => '<7304619681@example.invalid>',
                'Bytes' => '740162',
                'Xref' => 'news.example alt.binaries.blu-ray:7304619681',
            ]]);
        $nntp->shouldReceive('getYencBodyPreambleLines')
            ->once()
            ->with('alt.binaries.blu-ray', '7304619681', 10)
            ->andReturn([
                '=ybegin part=5 total=56 line=128 size=39854080 name=xASUfbtk4mf45SfeaPdKWQOLhW1d',
                '=ypart begin=2867201 end=3584000',
            ]);

        $service = new BinariesService(
            new BinariesConfig(
                partsChunkSize: 10,
                headerChunkSize: 10,
                bodyPreambleDeobfuscateGroups: ['alt.binaries.blu-ray'],
                bodyPreambleDeobfuscateLimit: 10,
                bodyPreambleLineLimit: 10
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

        $summary = $service->scan(['id' => 5, 'name' => 'alt.binaries.blu-ray'], 7304619681, 7304619681);

        $this->assertSame('7304619681', $summary['firstArticleNumber']);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(
            '[46/56] - "vs3yp1VCF6UKJD4sjDXwA0X6qQIw15Q1Uf0g0EUIXk4bi-zPHhq1TziqiY52U.TOe" yEnc',
            DB::table('collections')->value('subject')
        );
        $this->assertSame(56, (int) DB::table('collections')->value('totalfiles'));
        $this->assertSame(46, (int) DB::table('binaries')->value('filenumber'));
        $this->assertSame(55, (int) DB::table('binaries')->value('totalparts'));
        $this->assertSame(5, (int) DB::table('parts')->value('partnumber'));
    }

    public function test_scan_does_not_spend_body_probe_budget_on_readable_quoted_yenc_subjects(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getXOVER')
            ->once()
            ->with('7305032856-7305032857')
            ->andReturn([
                [
                    'Number' => '7305032856',
                    'Subject' => '[1/2] - "Movie.Title.2024.1080p.BluRay.x264-GRP.mkv" yEnc (1/10)',
                    'From' => 'poster@example.invalid',
                    'Date' => 'Sun, 14 Jun 2026 09:13:21 +0000',
                    'Message-ID' => '<7305032856@example.invalid>',
                    'Bytes' => '740162',
                    'Xref' => 'news.example alt.binaries.blu-ray:7305032856',
                ],
                [
                    'Number' => '7305032857',
                    'Subject' => '[21/52] - "DDrDC5ShYs629Bmm-NvXbM-8ldS68zeZoVLz20OdcUb41oi6FzorrYizrjv.iWT4a90" yEnc (22/76)',
                    'From' => 'poster@example.invalid',
                    'Date' => 'Sun, 14 Jun 2026 09:13:22 +0000',
                    'Message-ID' => '<7305032857@example.invalid>',
                    'Bytes' => '740162',
                    'Xref' => 'news.example alt.binaries.blu-ray:7305032857',
                ],
            ]);
        $nntp->shouldReceive('getYencBodyPreambleLines')
            ->once()
            ->with('alt.binaries.blu-ray', '7305032857', 10)
            ->andReturn([
                '=ybegin part=22 total=76 line=128 size=454033408 name=Movie.Payload.part021.rar',
                '=ypart begin=15073281 end=15810560',
            ]);

        $service = new BinariesService(
            new BinariesConfig(
                partsChunkSize: 10,
                headerChunkSize: 10,
                bodyPreambleDeobfuscateGroups: ['alt.binaries.blu-ray'],
                bodyPreambleDeobfuscateLimit: 1,
                bodyPreambleLineLimit: 10
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

        $summary = $service->scan(['id' => 5, 'name' => 'alt.binaries.blu-ray'], 7305032856, 7305032857);

        $this->assertSame('7305032856', $summary['firstArticleNumber']);
        $this->assertSame(2, DB::table('collections')->count());
        $this->assertTrue(DB::table('collections')->where('subject', '"Movie.Payload.part021.rar"')->exists());
        $this->assertTrue(DB::table('collections')->where('subject', '[1/2] - "Movie.Title.2024.1080p.BluRay.x264-GRP.mkv" yEnc')->exists());
    }

    public function test_scan_stops_body_preamble_probes_after_elapsed_budget_is_exhausted(): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getXOVER')
            ->once()
            ->with('7305032856-7305032857')
            ->andReturn([
                [
                    'Number' => '7305032856',
                    'Subject' => '[21/52] - "DDrDC5ShYs629Bmm-NvXbM-8ldS68zeZoVLz20OdcUb41oi6FzorrYizrjv.iWT4a90" yEnc',
                    'From' => 'poster@example.invalid',
                    'Date' => 'Sun, 14 Jun 2026 09:13:21 +0000',
                    'Message-ID' => '<7305032856@example.invalid>',
                    'Bytes' => '740162',
                    'Xref' => 'news.example alt.binaries.blu-ray:7305032856',
                ],
                [
                    'Number' => '7305032857',
                    'Subject' => '[22/52] - "b15a4UDyIhCzBTg3id5tRrsK59gTGArRR8WgxGRzDreTEG2lqom8jAZld18x.iWT4a90" yEnc',
                    'From' => 'poster@example.invalid',
                    'Date' => 'Sun, 14 Jun 2026 09:13:22 +0000',
                    'Message-ID' => '<7305032857@example.invalid>',
                    'Bytes' => '740162',
                    'Xref' => 'news.example alt.binaries.blu-ray:7305032857',
                ],
            ]);
        $nntp->shouldReceive('getYencBodyPreambleLines')
            ->once()
            ->with('alt.binaries.blu-ray', '7305032856', 10)
            ->andReturnUsing(static function (): array {
                usleep(2500);

                return [
                    '=ybegin part=22 total=76 line=128 size=454033408 name=Movie.Payload.part021.rar',
                    '=ypart begin=15073281 end=15810560',
                ];
            });

        $service = new BinariesService(
            new BinariesConfig(
                partsChunkSize: 10,
                headerChunkSize: 10,
                bodyPreambleDeobfuscateGroups: ['alt.binaries.blu-ray'],
                bodyPreambleDeobfuscateLimit: 10,
                bodyPreambleLineLimit: 10,
                bodyPreambleDeobfuscateMaxSeconds: 0.001
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

        $service->scan(['id' => 5, 'name' => 'alt.binaries.blu-ray'], 7305032856, 7305032857);

        $statsProperty = new \ReflectionProperty(BinariesService::class, 'bodyPreambleStats');
        $stats = $statsProperty->getValue($service);

        $this->assertSame(1, $stats['probed']);
        $this->assertSame(1, $stats['time_limited']);
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertTrue(DB::table('collections')->where('subject', '"Movie.Payload.part021.rar"')->exists());
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
