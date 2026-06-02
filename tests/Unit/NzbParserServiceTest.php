<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbParserService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NzbParserServiceTest extends TestCase
{
    #[DataProvider('par2IndexSubjects')]
    public function test_detects_par2_index_file_subjects(string $subject): void
    {
        $this->assertTrue((new NzbParserService)->detectPar2IndexFile($subject));
    }

    #[DataProvider('nonPar2IndexSubjects')]
    public function test_rejects_non_index_par2_subjects(string $subject): void
    {
        $this->assertFalse((new NzbParserService)->detectPar2IndexFile($subject));
    }

    public function test_detects_par2_recovery_volume_as_par2_file_but_not_index(): void
    {
        $subject = '[02/20] - "Movie.Name.2020.vol001+02.par2" yEnc';
        $parser = new NzbParserService;

        $this->assertTrue($parser->detectPar2File($subject));
        $this->assertFalse($parser->detectPar2IndexFile($subject));
    }

    public function test_rejects_non_par2_as_par2_file(): void
    {
        $this->assertFalse((new NzbParserService)->detectPar2File('[03/20] - "Movie.Name.2020.part01.rar" yEnc'));
    }

    public function test_identifies_par2_only_file_list(): void
    {
        $fileList = [
            ['title' => '[01/02] - "hash.par2" yEnc', 'ext' => 'par2'],
            ['title' => '[02/02] - "hash.vol001+02.par2" yEnc', 'ext' => 'par2'],
        ];

        $this->assertTrue((new NzbParserService)->isPar2OnlyFileList($fileList));
    }

    public function test_rejects_empty_file_list_as_par2_only(): void
    {
        $this->assertFalse((new NzbParserService)->isPar2OnlyFileList([]));
    }

    public function test_rejects_mixed_content_file_list_as_par2_only(): void
    {
        $fileList = [
            ['title' => '[01/03] - "Movie.Name.2020.par2" yEnc', 'ext' => 'par2'],
            ['title' => '[02/03] - "Movie.Name.2020.part01.rar" yEnc', 'ext' => 'rar'],
        ];

        $this->assertFalse((new NzbParserService)->isPar2OnlyFileList($fileList));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function par2IndexSubjects(): array
    {
        return [
            'quoted yenc subject' => ['[01/20] - "Movie.Name.2020.par2" yEnc'],
            'html quote yenc subject' => ['[01/20] - #34;Movie.Name.2020.par2#34; - yEnc'],
            'bare file name' => ['Movie.Name.2020.par2'],
            'closing bracket' => ['[01/20] Movie.Name.2020.par2]'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPar2IndexSubjects(): array
    {
        return [
            'volume par2' => ['[02/20] - "Movie.Name.2020.vol001+02.par2" yEnc'],
            'rar part' => ['[03/20] - "Movie.Name.2020.part01.rar" yEnc'],
            'sample video' => ['Movie.Name.2020.sample.mkv'],
        ];
    }
}
