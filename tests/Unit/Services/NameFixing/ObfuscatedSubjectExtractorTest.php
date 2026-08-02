<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ObfuscatedSubjectExtractorTest extends TestCase
{
    #[Test]
    public function it_extracts_quoted_title_from_nzb_prefix(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('N:/NZB [2/5] - "History of War - Issue 158, 2026.rar"');

        $this->assertSame('History of War - Issue 158, 2026', $result);
    }

    #[Test]
    public function it_extracts_title_and_removes_par2_archive_suffix(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('N:/NZB [1/6] - "Woman\'s Day New Zealand - Issue 45 April 27, 2026.par2"');

        $this->assertSame('Woman\'s Day New Zealand - Issue 45 April 27, 2026', $result);
    }

    #[Test]
    public function it_extracts_classic_movie_title_and_removes_nfo_suffix(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('(NMR) [03/34] - "West of Cheyenne (Tom Tyler) (1931).nfo" yEnc');

        $this->assertSame('West of Cheyenne (Tom Tyler) (1931)', $result);
    }

    #[Test]
    public function it_does_not_replace_readable_movie_subject_with_short_par2_sidecar_stem(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('( Jack the Ripper - 1988 ) - - [23/24] - "JacRip1988.vol07+08.PAR2"');

        $this->assertNull($result);
    }

    #[Test]
    public function it_extracts_title_and_removes_part_rar_suffix(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('N:/NZB [2/8] - "Harry Styles Songbook - 1st Edition 2026.part1.rar"');

        $this->assertSame('Harry Styles Songbook - 1st Edition 2026', $result);
    }

    #[Test]
    public function it_returns_null_for_already_clean_title(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('History of War - Issue 158, 2026');

        $this->assertNull($result);
    }

    #[Test]
    public function it_extracts_underscore_nzb_prefix_without_quotes(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('N_NZB_[6]_-_Woman\'s_Day_New_Zealand_-_Issue_45_April_27_2026.par2');

        $this->assertSame('Woman\'s Day New Zealand - Issue 45 April 27 2026', $result);
    }

    #[Test]
    public function it_extracts_underscore_fraction_nzb_prefix_without_quotes(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('N_NZB_[1_6]_-_Woman\'s_Day_New_Zealand_-_Issue_45_April_27_2026.par2');

        $this->assertSame('Woman\'s Day New Zealand - Issue 45 April 27 2026', $result);
    }

    #[Test]
    public function it_title_cases_lowercase_obfuscated_candidates(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('N:/NZB [02/11] - "landscape.garden.design.issue.2.2026.part1.rar" yEnc');

        $this->assertSame('Landscape Garden Design Issue 2 2026', $result);
    }

    #[Test]
    public function it_deobfuscates_rot13_movie_subject(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('Pneevr.Svfure.Jvfushy.Qevaxvat.7565.QIQE.AGFP.QIQ4.UOB [91/01] - "JVFUSHY_QEVAXVAT.cneg99.ene"');

        $this->assertNotNull($result);
        $this->assertStringContainsStringIgnoringCase('Carrie', $result);
        $this->assertStringContainsStringIgnoringCase('Fisher', $result);
        $this->assertStringContainsStringIgnoringCase('Wishful', $result);
        $this->assertStringContainsStringIgnoringCase('Drinking', $result);
        $this->assertStringContainsString('2010', $result);
    }

    #[Test]
    public function it_deobfuscates_rot13_dvdr_subject(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('Fgrcura.Tlyyraunny.Jngreynaq.6447.QIQE.AGFP.QIQ4 [99/01] - "JNGREYNAQ.iby556+57.cne7"');

        $this->assertNotNull($result);
        $this->assertStringContainsStringIgnoringCase('Waterland', $result);
        $this->assertStringContainsString('1992', $result);
    }

    #[Test]
    public function it_leaves_readable_name_untouched_by_rot13(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        $result = $extractor->extract('N:/NZB [2/5] - "History of War - Issue 158, 2026.rar"');

        $this->assertSame('History of War - Issue 158, 2026', $result);
    }

    #[Test]
    public function it_leaves_genuine_hashed_name_untouched_by_rot13(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        // Genuine hashed name: ROT13-decoding it yields no release signature,
        // so the de-obfuscation step must NOT fire and the result must match
        // the pre-existing extractor behaviour (base normalization only).
        $result = $extractor->extract('eNwlv9GZIQBRrhBLimiQsVYa.rar');

        $this->assertSame('eNwlv9GZIQBRrhBLimiQsVYa rar', $result);
    }

    #[Test]
    public function it_decodes_rot13_subject_structure_preserving(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        // Full-subject decoder used by release creation: returns the decoded
        // FULL subject (not the cleaned title stem) so the release name +
        // searchname + category all derive from the readable title.
        $this->assertSame(
            'Charles.Chaplin.The.Great.Dictator.1974.DVDR.2DiSC.D1.DVD4.CC-565',
            $extractor->decodeRot13Subject('Puneyrf.Puncyva.Gur.Terng.Qvpgngbe.6429.QIQE.7QvFP.Q6.QIQ9.PP-010')
        );
        $this->assertSame(
            'Akira.Kurosawa.The.Men.Who.Tread.On.The.Tigers.Tail.1994.DVDR.DVD5.CC-AK655',
            $extractor->decodeRot13Subject('Nxven.Xhebfnjn.Gur.Zra.Jub.Gernq.Ba.Gur.Gvtref.Gnvy.6449.QIQE.QIQ0.PP-NX100')
        );
    }

    #[Test]
    public function it_leaves_readable_subject_untouched_by_rot13_decode(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        // Already-plaintext subject must be returned unchanged (decoding it
        // would garble a real name).
        $subject = 'Charles.Chaplin.The.Great.Dictator.1940.DVDR.2DiSC.D1.DVD9.CC-565';
        $this->assertSame($subject, $extractor->decodeRot13Subject($subject));
    }

    #[Test]
    public function it_leaves_genuine_hashed_subject_untouched_by_rot13_decode(): void
    {
        $extractor = new ObfuscatedSubjectExtractor;

        // Genuine random hash: decoding yields no release signature -> unchanged.
        $subject = 'eNwlv9GZIQBRrhBLimiQsVYa.rar';
        $this->assertSame($subject, $extractor->decodeRot13Subject($subject));
    }
}
