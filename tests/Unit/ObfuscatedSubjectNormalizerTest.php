<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\ObfuscatedSubjectNormalizer;
use PHPUnit\Framework\TestCase;

final class ObfuscatedSubjectNormalizerTest extends TestCase
{
    private ObfuscatedSubjectNormalizer $enabled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enabled = new ObfuscatedSubjectNormalizer(['alt.binaries.movies', 'alt.binaries.hdtv']);
    }

    public function testOnlyAppliesToConfiguredGroups(): void
    {
        $this->assertTrue($this->enabled->appliesTo('alt.binaries.movies'));
        $this->assertTrue($this->enabled->appliesTo('ALT.BINARIES.HDTV'));
        $this->assertFalse($this->enabled->appliesTo('alt.binaries.moovee'));
        $this->assertFalse($this->enabled->appliesTo('alt.binaries.teevee'));
    }

    public function testIsInertWhenNoGroupsAreConfigured(): void
    {
        $this->assertFalse((new ObfuscatedSubjectNormalizer([]))->appliesTo('alt.binaries.movies'));
    }

    public function testCollapsesEveryArticleOfOneFileOntoASingleIdentity(): void
    {
        $articles = [
            '{Supergirl.2026.vol127+72.par2} {5Zn03jrjsly2} yEnc',
            '{Supergirl.2026.vol127+72.par2} {K7x0l9F182pk} yEnc',
            '{Supergirl.2026.vol127+72.par2} {NWeU5FDTXu5o} yEnc',
        ];

        $names = [];
        foreach ($articles as $article) {
            $result = $this->enabled->normalize($article);
            $this->assertNotNull($result);
            $names[] = $result['name'];
        }

        $this->assertCount(1, array_unique($names));
        $this->assertSame('{Supergirl.2026.vol127+72.par2} yEnc', $names[0]);
    }

    public function testPinsCollectionFileNumbersToASingleFile(): void
    {
        // The subject carries only a part counter; it must not be read as a file count.
        $result = $this->enabled->normalize('{Lioness.S02E07.2160p.AMZN.WEB-DL.H.265-FLUX.rar} {k6SDjjsQ3MmW} yEnc');

        $this->assertNotNull($result);
        $this->assertSame(1, $result['file_number']);
        $this->assertSame(1, $result['total_files']);
    }

    public function testKeepsDistinctFilesInAPostingDistinct(): void
    {
        $rar = $this->enabled->normalize('{Supergirl.2026.part01.rar} {stWsuZvUnzVX} yEnc');
        $par = $this->enabled->normalize('{Supergirl.2026.vol127+72.par2} {5Zn03jrjsly2} yEnc');

        $this->assertNotNull($rar);
        $this->assertNotNull($par);
        $this->assertNotSame($rar['name'], $par['name']);
    }

    public function testEveryArticleOfOneFileSharesOneCollectionKey(): void
    {
        $keys = [];
        foreach ([
            '{Supergirl.2026.vol127+72.par2} {5Zn03jrjsly2} yEnc',
            '{Supergirl.2026.vol127+72.par2} {K7x0l9F182pk} yEnc',
            '{Supergirl.2026.vol127+72.par2} {NWeU5FDTXu5o} yEnc',
        ] as $article) {
            $result = $this->enabled->normalize($article);
            $this->assertNotNull($result);
            $keys[] = ObfuscatedSubjectNormalizer::collectionKey($result['name'], 6979);
        }

        $this->assertCount(1, array_unique($keys));
    }

    public function testDistinctFilesOfOnePostGetDistinctCollectionKeys(): void
    {
        // These are precisely the subjects CollectionsCleaningService fuses: it
        // strips digit runs, so all of them clean to '{Soulm8te. } yEnc'. Keying
        // on the cleaned name would put 43 files in one collection, and with
        // file_number pinned to 1/1, in one binary.
        $subjects = [
            '{Soulm8te.2026.part01.rar} {4e6V9vStTb1E} yEnc',
            '{Soulm8te.2026.part02.rar} {6zFxF8To7GWe} yEnc',
            '{Soulm8te.2026.part35.rar} {DIVfOxfTRBEY} yEnc',
            '{Soulm8te.2026.vol000+01.par2} {7378f4360f80} yEnc',
            '{Soulm8te.2026.vol127+71.par2} {P39URH0AB8CS} yEnc',
        ];

        $keys = [];
        foreach ($subjects as $subject) {
            $result = $this->enabled->normalize($subject);
            $this->assertNotNull($result, $subject);
            $keys[] = ObfuscatedSubjectNormalizer::collectionKey($result['name'], 6979);
        }

        $this->assertCount(\count($subjects), array_unique($keys));
    }

    public function testCollectionKeyIsScopedToTheGroup(): void
    {
        $this->assertNotSame(
            ObfuscatedSubjectNormalizer::collectionKey('{Soulm8te.2026.part01.rar} yEnc', 6979),
            ObfuscatedSubjectNormalizer::collectionKey('{Soulm8te.2026.part01.rar} yEnc', 6436),
        );
    }

    public function testCollectionKeyIgnoresSurroundingWhitespace(): void
    {
        $this->assertSame(
            ObfuscatedSubjectNormalizer::collectionKey('{Soulm8te.2026.part01.rar} yEnc', 6979),
            ObfuscatedSubjectNormalizer::collectionKey('  {Soulm8te.2026.part01.rar} yEnc  ', 6979),
        );
    }

    public function testLeavesNonObfuscatedSubjectsUntouched(): void
    {
        $subjects = [
            'Some.Normal.Release.2024.1080p.rar - [01/20] - "file.rar" yEnc',
            '[01/57] - "9981dffbf76f18421da1a76a63d938e5c9ae260c" yEnc',
            'Plain.Release.Without.Braces.mkv',
            '{Only.One.Braced.Group.rar} yEnc',
        ];

        foreach ($subjects as $subject) {
            $this->assertNull($this->enabled->normalize($subject), $subject);
        }
    }

    public function testDoesNotStripBracedMetadataThatIsNotARandomToken(): void
    {
        // Real metadata carries separators; tokens are bare alphanumeric runs.
        $this->assertNull($this->enabled->normalize('{Movie.Name.2024.rar} {Some.Group.Name} yEnc'));
        $this->assertNull($this->enabled->normalize('{Movie.Name.2024.rar} {a-b-c-d-e-f-g} yEnc'));
        $this->assertNull($this->enabled->normalize('{Movie.Name.2024.rar} {short} yEnc'));
    }
}
