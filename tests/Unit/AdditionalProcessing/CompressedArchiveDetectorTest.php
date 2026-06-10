<?php

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\CompressedArchiveDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CompressedArchiveDetectorTest extends TestCase
{
    #[Test]
    public function it_detects_obfuscated_7z_split_volumes(): void
    {
        $this->assertTrue(CompressedArchiveDetector::titleLooksCompressed(
            '[01/49] "zvhQyzcErKdLt57vNc2sNOJ5STM07e0wY3.7z.001" yEnc'
        ));
    }

    #[Test]
    public function it_keeps_par2_support_files_out_of_the_compressed_path(): void
    {
        $this->assertFalse(CompressedArchiveDetector::titleLooksCompressed(
            '[001/201] - "UGNSKqL7pas9bnnGUJldjqX8DUowDr63.par2" yEnc'
        ));
    }

    #[Test]
    public function it_keeps_existing_rar_and_zip_detection(): void
    {
        $this->assertTrue(CompressedArchiveDetector::titleLooksCompressed('[01/88] "Release.part01.rar" yEnc'));
        $this->assertTrue(CompressedArchiveDetector::titleLooksCompressed('[01/12] "Release.zip" yEnc'));
    }
}
