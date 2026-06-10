<?php

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\ArchiveExtractionService;
use dariusiii\rarinfo\ArchiveInfo;
use dariusiii\rarinfo\Par2Info;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ArchiveExtractionServiceTest extends TestCase
{
    use CreatesProcessingConfiguration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_file_data_directly_from_archive_info_when_available(): void
    {
        $archiveInfo = Mockery::mock(ArchiveInfo::class);
        $archiveInfo->shouldReceive('setData')->once()->with('ARCHIVE', true)->andReturn(true);
        $archiveInfo->shouldReceive('getFileData')->once()->with('cover.jpg')->andReturn('IMAGE-DATA');

        $service = new ArchiveExtractionService(
            $this->makeConfig(),
            $archiveInfo,
            Mockery::mock(Par2Info::class)
        );

        $this->assertSame('IMAGE-DATA', $service->extractSpecificFile('ARCHIVE', 'cover.jpg', sys_get_temp_dir().'/'));
    }

    #[Test]
    public function it_detects_common_standalone_video_signatures(): void
    {
        $service = new ArchiveExtractionService(
            $this->makeConfig(),
            Mockery::mock(ArchiveInfo::class),
            Mockery::mock(Par2Info::class)
        );

        $avi = 'RIFF'.str_repeat("\0", 4).'AVI '.str_repeat("\0", 16);
        $mp4 = str_repeat("\0", 4).'ftypisom'.str_repeat("\0", 16);

        $this->assertSame('avi', $service->detectStandaloneVideo($avi));
        $this->assertSame('mp4', $service->detectStandaloneVideo($mp4));
        $this->assertNull($service->detectStandaloneVideo('tiny'));
    }

    #[Test]
    public function it_parses_7z_listing_output_into_release_file_rows(): void
    {
        $service = new ArchiveExtractionService(
            $this->makeConfig(),
            Mockery::mock(ArchiveInfo::class),
            Mockery::mock(Par2Info::class)
        );
        $method = new \ReflectionMethod($service, 'parseSevenZipFileList');

        $files = $method->invoke($service, <<<'LIST'
Path = sample.7z.001
Type = Split
Physical Size = 202
Volumes = 1

Path = sample.7z
Size = 202

Path = sample.7z
Type = 7z
Physical Size = 202

Path = Movie.Title.2026.1080p.BluRay.x264-GRP.mkv
Size = 4
Packed Size = 8
Attributes = A -rw-r--r--
CRC = D87F7E0C
Encrypted = -
LIST);

        $this->assertSame([
            [
                'name' => 'Movie.Title.2026.1080p.BluRay.x264-GRP.mkv',
                'size' => 4,
                'pass' => false,
                'crc32' => 'D87F7E0C',
            ],
        ], $files);
    }
}
