<?php

declare(strict_types=1);

use App\Services\Binaries\ObfuscatedHashSetNormalizer;

$enabled = new ObfuscatedHashSetNormalizer(['alt.binaries.movies', 'alt.binaries.hdtv']);

it('only applies to configured groups', function () use ($enabled) {
    expect($enabled->appliesTo('alt.binaries.movies'))->toBeTrue()
        ->and($enabled->appliesTo('ALT.BINARIES.HDTV'))->toBeTrue()
        ->and($enabled->appliesTo('alt.binaries.teevee'))->toBeFalse();
});

it('is inert when no groups are configured', function () {
    expect((new ObfuscatedHashSetNormalizer([]))->appliesTo('alt.binaries.movies'))->toBeFalse();
});

it('preserves the real file counter instead of pinning to 1/1', function () use ($enabled) {
    // The bracket counter here is a genuine FILE counter, unlike the
    // brace-token case where it was a part counter.
    $result = $enabled->normalize(
        '[007/122] - "233d5dd359b5bd3ced824704e60627cc7b035b3f" yEnc',
        6436,
        1785000000
    );

    expect($result)->not->toBeNull()
        ->and($result['file_number'])->toBe(7)
        ->and($result['total_files'])->toBe(122);
});

it('collapses every file of one post onto a single identity', function () use ($enabled) {
    $groupId = 6436;
    $posted = 1785000000;

    $first = $enabled->normalize('[001/199] - "002377997d944ccb76d4ae1814579afe1930411f" yEnc', $groupId, $posted);
    $second = $enabled->normalize('[002/199] - "00cb51d150ef49c6cd0716b6282796df8ab4b828" yEnc', $groupId, $posted);
    // par2 companions carry an extension and previously matched a different regex.
    $third = $enabled->normalize('[003/199] - "03234d0658b14b4c72b4d92a0dbe7a2972446578.par2" yEnc', $groupId, $posted);

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($third)->not->toBeNull()
        ->and($second['name'])->toBe($first['name'])
        ->and($third['name'])->toBe($first['name'])
        // ...while still describing distinct files within that set.
        ->and([$first['file_number'], $second['file_number'], $third['file_number']])->toBe([1, 2, 3]);
});

it('never merges distinct posts', function () use ($enabled) {
    $subject = '[001/199] - "002377997d944ccb76d4ae1814579afe1930411f" yEnc';

    $base = $enabled->normalize($subject, 6436, 1785000000);
    $otherGroup = $enabled->normalize($subject, 6979, 1785000000);
    $otherSecond = $enabled->normalize($subject, 6436, 1785000001);
    $otherTotal = $enabled->normalize('[001/198] - "002377997d944ccb76d4ae1814579afe1930411f" yEnc', 6436, 1785000000);

    expect($base)->not->toBeNull()
        ->and($otherGroup['name'])->not->toBe($base['name'])
        ->and($otherSecond['name'])->not->toBe($base['name'])
        ->and($otherTotal['name'])->not->toBe($base['name']);
});

it('ignores readable and non-hash subjects', function () use ($enabled) {
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
        expect($enabled->normalize($subject, 6436, 1785000000))->toBeNull();
    }
});

it('tolerates surrounding whitespace', function () use ($enabled) {
    $result = $enabled->normalize(
        '  [012/122]  "233d5dd359b5bd3ced824704e60627cc7b035b3f"  yEnc  ',
        6436,
        1785000000
    );

    expect($result)->not->toBeNull()
        ->and($result['file_number'])->toBe(12);
});
