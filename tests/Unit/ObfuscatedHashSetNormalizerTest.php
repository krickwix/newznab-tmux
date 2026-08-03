<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\ObfuscatedHashSetNormalizer;
use PHPUnit\Framework\TestCase;

final class ObfuscatedHashSetNormalizerTest extends TestCase
{
    private function enabled(): ObfuscatedHashSetNormalizer
    {
        return new ObfuscatedHashSetNormalizer(['alt.binaries.movies', 'alt.binaries.hdtv']);
    }

    public function test_it_only_applies_to_configured_groups(): void
    {
        $n = $this->enabled();

        self::assertTrue($n->appliesTo('alt.binaries.movies'));
        self::assertTrue($n->appliesTo('ALT.BINARIES.HDTV'));
        self::assertFalse($n->appliesTo('alt.binaries.teevee'));
    }

    public function test_it_is_inert_when_no_groups_are_configured(): void
    {
        self::assertFalse((new ObfuscatedHashSetNormalizer([]))->appliesTo('alt.binaries.movies'));
    }

    public function test_it_preserves_the_real_file_counter_instead_of_pinning_to_one(): void
    {
        // The bracket counter here is a genuine FILE counter, unlike the
        // brace-token case where it was a part counter.
        $result = $this->enabled()->normalize(
            '[007/122] - "233d5dd359b5bd3ced824704e60627cc7b035b3f" yEnc',
            6436,
            1785000000
        );

        self::assertNotNull($result);
        self::assertSame(7, $result['file_number']);
        self::assertSame(122, $result['total_files']);
    }

    public function test_it_collapses_every_file_of_one_post_onto_a_single_identity(): void
    {
        $n = $this->enabled();
        $groupId = 6436;
        $posted = 1785000000;

        $first = $n->normalize('[001/199] - "002377997d944ccb76d4ae1814579afe1930411f" yEnc', $groupId, $posted);
        $second = $n->normalize('[002/199] - "00cb51d150ef49c6cd0716b6282796df8ab4b828" yEnc', $groupId, $posted);
        // par2 companions carry an extension and previously matched a different regex.
        $third = $n->normalize('[003/199] - "03234d0658b14b4c72b4d92a0dbe7a2972446578.par2" yEnc', $groupId, $posted);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($third);

        self::assertSame($first['name'], $second['name']);
        self::assertSame($first['name'], $third['name']);

        // ...while still describing distinct files within that set.
        self::assertSame([1, 2, 3], [$first['file_number'], $second['file_number'], $third['file_number']]);
    }

    public function test_it_never_merges_distinct_posts(): void
    {
        $n = $this->enabled();
        $subject = '[001/199] - "002377997d944ccb76d4ae1814579afe1930411f" yEnc';

        $base = $n->normalize($subject, 6436, 1785000000);
        $otherGroup = $n->normalize($subject, 6979, 1785000000);
        $otherSecond = $n->normalize($subject, 6436, 1785000001);
        $otherTotal = $n->normalize('[001/198] - "002377997d944ccb76d4ae1814579afe1930411f" yEnc', 6436, 1785000000);

        self::assertNotNull($base);
        self::assertNotNull($otherGroup);
        self::assertNotNull($otherSecond);
        self::assertNotNull($otherTotal);

        self::assertNotSame($base['name'], $otherGroup['name'], 'different newsgroup must not merge');
        self::assertNotSame($base['name'], $otherSecond['name'], 'one second apart must not merge');
        self::assertNotSame($base['name'], $otherTotal['name'], 'different declared total must not merge');
    }

    public function test_it_ignores_readable_and_non_hash_subjects(): void
    {
        $n = $this->enabled();

        $cases = [
            // Ordinary readable multi-file post.
            '[04/19] - "Life.in.Pieces.S02E10.Musical.Motel.Property.mkv" yEnc',
            // Brace-token style belongs to ObfuscatedSubjectNormalizer.
            '{Supergirl.2026.vol127+72.par2} {5Zn03jrjsly2} yEnc',
            // Short random token, not a 40-char sha1.
            '[1/5] - "xXc1RSkuqFPbup3KGPk5T.rar" yEnc',
            // Hash too short.
            '[001/122] - "233d5dd359b5bd3ced8247" yEnc',
            // Single-file set carries no cohort meaning.
            '[001/001] - "233d5dd359b5bd3ced824704e60627cc7b035b3f" yEnc',
            // Counter out of range.
            '[200/199] - "233d5dd359b5bd3ced824704e60627cc7b035b3f" yEnc',
        ];

        foreach ($cases as $subject) {
            self::assertNull($n->normalize($subject, 6436, 1785000000), $subject);
        }
    }

    public function test_it_tolerates_surrounding_whitespace(): void
    {
        $result = $this->enabled()->normalize(
            '  [012/122]  "233d5dd359b5bd3ced824704e60627cc7b035b3f"  yEnc  ',
            6436,
            1785000000
        );

        self::assertNotNull($result);
        self::assertSame(12, $result['file_number']);
    }
}
