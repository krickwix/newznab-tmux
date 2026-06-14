<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\Extractors\FileNameExtractor;
use PHPUnit\Framework\TestCase;

class FileNameExtractorTest extends TestCase
{
    public function test_extracts_release_name_from_nzb_split_wrapper(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile(
            'NBA__NZBSPLIT__bdab31d6f79989608009e7e8eadcbe66__NZBSPLIT__NBA_20260419_PHI_BOS_1080p60_ABC.7z.073'
        );

        $this->assertNotNull($result);
        $this->assertSame('NBA.20260419.PHI.BOS.1080p60.ABC', $result->newName);
        $this->assertSame('NZBSPLIT wrapper', $result->method);
        $this->assertSame('File', $result->checkerName);
    }

    public function test_rejects_low_information_nzb_split_payloads(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile(
            'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__setup.7z.001'
        );

        $this->assertNull($result);
    }

    public function test_extracts_bare_classic_movie_avi_filename(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Kon-Tiki 1950.avi');

        $this->assertNotNull($result);
        $this->assertSame('Kon-Tiki 1950 DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Bare movie (year) avi', $result->method);
    }

    public function test_extracts_bare_classic_movie_mkv_filename(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Squibs (1935).mkv');

        $this->assertNotNull($result);
        $this->assertSame('Squibs (1935) BDRip x264 NoGroup', $result->newName);
        $this->assertSame('Bare movie (year) mkv/mp4', $result->method);
    }

    public function test_rejects_bare_classic_movie_sample_filename(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Lover Come Back (1961)_Sample.mp4');

        $this->assertNull($result);
    }

    public function test_rejects_subtitle_support_filenames_before_folder_fallback(): void
    {
        $extractor = new FileNameExtractor;

        $this->assertNull($extractor->extractFromFile("you're telling me 1934 xvid.sub"));
        $this->assertNull($extractor->extractFromFile("it's a gift 1934 xvid.idx"));
    }

    public function test_extracts_bare_classic_movie_from_path(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Vrah skryva tvar (The Murderer Hides His Face) (1966)/Vrah skrývá tvár (1966).mkv');

        $this->assertNotNull($result);
        $this->assertSame('Vrah skrývá tvár (1966) BDRip x264 NoGroup', $result->newName);
        $this->assertSame('Bare movie (year) mkv/mp4', $result->method);
    }

    public function test_extracts_movie_title_from_archive_part_with_year(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Die, Monster, Die! (1965) NL.part01.rar');

        $this->assertNotNull($result);
        $this->assertSame('Die, Monster, Die! (1965) NL DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Movie (year) archive part', $result->method);
    }

    public function test_cleans_generic_video_prefix_from_archive_part_with_year(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('an_mp4_file_The_ Lost_Battalion_(1919).part25.rar');

        $this->assertNotNull($result);
        $this->assertSame('The Lost Battalion (1919) DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Movie (year) archive part', $result->method);
    }

    public function test_moves_year_before_title_after_generic_video_prefix(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('an_mkv_film_(1954)_Suddenly.part08.rar');

        $this->assertNotNull($result);
        $this->assertSame('Suddenly (1954) DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Movie (year) archive part', $result->method);
    }

    public function test_extracts_year_first_generic_video_archive_part(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('(1949)_mkv_film_all_the_kings_men.part30.rar');

        $this->assertNotNull($result);
        $this->assertSame('all the kings men (1949) DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Movie (year) archive part', $result->method);
    }

    public function test_extracts_article_year_generic_video_archive_part(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile("a_1949_mp4_film_Adam's Rib.part13.rar");

        $this->assertNotNull($result);
        $this->assertSame("Adam's Rib (1949) DVDRip XviD NoGroup", $result->newName);
        $this->assertSame('Movie (year) archive part', $result->method);
    }

    public function test_extracts_movie_title_from_yenc_subject_prefix(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Jennifer.Siebel.Newsom.Miss.Representation.2011.DVDR.NTSC.DVD9 [03/84] - "MISS_REP.part01.rar" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('Jennifer.Siebel.Newsom.Miss.Representation.2011.DVDR.NTSC.DVD9', $result->newName);
        $this->assertSame('yEnc subject title', $result->method);
    }

    public function test_prefers_yenc_subject_prefix_with_year_over_quoted_segment_filename(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('IN OLD CHICAGO.1938.web.[05/33] "In.Old.Chicago.mkv.003" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('IN OLD CHICAGO.1938.web', $result->newName);
        $this->assertSame('yEnc subject title', $result->method);
    }

    public function test_extracts_yenc_subject_prefix_without_space_before_part_count(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('DER SCHWARZE HUSAR.1932.VHS.Xvid.[01/33] - "DerSchwarzeHusar.avi.nfo" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('DER SCHWARZE HUSAR.1932.VHS.Xvid', $result->newName);
        $this->assertSame('yEnc subject title', $result->method);
    }

    public function test_extracts_bracketed_yenc_movie_title_with_year(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('[Dhoom.Dhaam.2025] yEnc');

        $this->assertNotNull($result);
        $this->assertSame('Dhoom.Dhaam.2025', $result->newName);
        $this->assertSame('yEnc subject title', $result->method);
    }

    public function test_extracts_classic_movie_title_from_quoted_yenc_support_file(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('(NMR) [03/34] - "West of Cheyenne (Tom Tyler) (1931).nfo" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('West of Cheyenne (Tom Tyler) (1931)', $result->newName);
        $this->assertSame('Classic movie support filename', $result->method);
    }

    public function test_extracts_scene_title_from_quoted_yenc_archive_file(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('[02/34] - "A.Knight.of.the.Seven.Kingdoms.S01E07.1080p.WEB-DL.H264-iND.part01.rar" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('A.Knight.of.the.Seven.Kingdoms.S01E07.1080p.WEB-DL.H264-iND', $result->newName);
        $this->assertSame('Scene archive filename', $result->method);
    }

    public function test_preserves_audio_dots_in_quoted_yenc_scene_archive_file(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('[02/36] - "The.Boys.S05E08.2160p.WEB-DL.DDP5.1.HEVC-iND.par2" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('The.Boys.S05E08.2160p.WEB-DL.DDP5.1.HEVC-iND', $result->newName);
        $this->assertSame('Scene archive filename', $result->method);
    }

    public function test_rejects_hashed_quoted_yenc_archive_file(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('[01/81] "LTz1puk2WI7NZky5IXBQFOC3eqUgx0b8HQyu8YnUekh3KTzSp0.7z.001" yEnc');

        $this->assertNull($result);
    }

    public function test_rejects_bare_hashed_archive_stem_as_folder_name(): void
    {
        $extractor = new FileNameExtractor;

        $this->assertNull($extractor->extractFromFile('U4BvWIMw96AfmzfxMjHxi.part03.rar'));
        $this->assertNull($extractor->extractFromFile('R9lelFHQEMt9U8UtM9aVj2amEk7TnPx2OZUxcbBgnue8qyp3vD.7z.052'));
        $this->assertNull($extractor->extractFromFile('Hxu6fwEJUsaD2sFb - ISO Premium - BluHD by pornexe.7z.003'));
    }

    public function test_strips_segment_counter_from_bare_movie_archive_candidate(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('[65/80] Hell Divers (1931) Clark Gable,Marie Prevost.part64.rar');

        $this->assertNotNull($result);
        $this->assertSame('Hell Divers (1931) Clark Gable,Marie Prevost DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Movie (year) archive part', $result->method);
    }

    public function test_extracts_classic_collection_disc_archive_part(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Laurel and Hardy cd 12 of 21.part063.rar');

        $this->assertNotNull($result);
        $this->assertSame('Laurel and Hardy CD12 DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Classic movie collection archive part', $result->method);
    }

    public function test_extracts_classic_collection_disc_from_yenc_subject(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('[063/100] - "Laurel and Hardy cd 12 of 21.part063.rar" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('Laurel and Hardy CD12 DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Classic movie collection archive part', $result->method);
    }

    public function test_extracts_bare_movie_subject_title_with_year_from_multipart_yenc(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Midnight (1939) [02/21] - "MIDNIGHT.part01.rar" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('Midnight (1939) DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Bare movie subject title', $result->method);
    }

    public function test_extracts_bare_movie_subject_title_with_unbracketed_year(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Kiss the Girls and Make Them Die 1966 [01/42] - "Kiss.the.Girls.part001.rar" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('Kiss the Girls and Make Them Die 1966 DVDRip XviD NoGroup', $result->newName);
        $this->assertSame('Bare movie subject title', $result->method);
    }

    public function test_extracts_lossless_music_title_from_yenc_subject_prefix(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('New Dimensions in Banjo and Bluegrass 1963 LP (mono) flac [8 of 31] "newdimensions.vol000+01.par2" yEnc');

        $this->assertNotNull($result);
        $this->assertSame('New Dimensions in Banjo and Bluegrass 1963 LP (mono) flac', $result->newName);
        $this->assertSame('yEnc subject title', $result->method);
    }

    public function test_strips_terminal_video_extension_from_bare_movie_candidate(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('[02/12] - "You\'re Only Young Once (1937).avi.par2" yEnc');

        $this->assertNotNull($result);
        $this->assertSame("You're Only Young Once (1937)", $result->newName);
        $this->assertSame('Classic movie support filename', $result->method);
    }

    public function test_extracts_classic_movie_title_from_support_file(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile("Rip Roarin' Buckaroo (Tom Tyler) (1936).sfv");

        $this->assertNotNull($result);
        $this->assertSame("Rip Roarin' Buckaroo (Tom Tyler) (1936)", $result->newName);
        $this->assertSame('Classic movie support filename', $result->method);
    }

    public function test_rejects_extension_only_support_file_names(): void
    {
        $extractor = new FileNameExtractor;

        $this->assertNull($extractor->extractFromFile('nfo'));
        $this->assertNull($extractor->extractFromFile('sfv'));
        $this->assertNull($extractor->extractFromFile('par2'));
    }

    public function test_rejects_bracketed_yenc_subject_without_year(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('[Totally.Random.Title] yEnc');

        $this->assertNull($result);
    }

    public function test_does_not_extract_yenc_marker_as_folder_name(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('"MISS_REP.part01.rar" yEnc');

        $this->assertNull($result);
    }

    public function test_rejects_software_archive_part_with_year(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Kotato All Video Downloader Pro 10.3.15.part1.rar');

        $this->assertNull($result);
    }

    public function test_rejects_vendor_software_archive_part_with_year(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Wondershare Recoverit 13.6.1.1 2025.part1.rar');

        $this->assertNull($result);
    }

    public function test_rejects_acronis_build_archive_part_with_year(): void
    {
        $extractor = new FileNameExtractor;

        $result = $extractor->extractFromFile('Acronis True Image v2021 Build 39287 + Fix.part10.rar');

        $this->assertNull($result);
    }
}
