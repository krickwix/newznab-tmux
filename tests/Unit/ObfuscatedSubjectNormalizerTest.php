<?php

declare(strict_types=1);

use App\Services\Binaries\ObfuscatedSubjectNormalizer;

$enabled = new ObfuscatedSubjectNormalizer(['alt.binaries.movies', 'alt.binaries.hdtv']);

it('only applies to configured groups', function () use ($enabled) {
    expect($enabled->appliesTo('alt.binaries.movies'))->toBeTrue()
        ->and($enabled->appliesTo('ALT.BINARIES.HDTV'))->toBeTrue()
        ->and($enabled->appliesTo('alt.binaries.moovee'))->toBeFalse()
        ->and($enabled->appliesTo('alt.binaries.teevee'))->toBeFalse();
});

it('is inert when no groups are configured', function () {
    expect((new ObfuscatedSubjectNormalizer([]))->appliesTo('alt.binaries.movies'))->toBeFalse();
});

it('collapses every article of one file onto a single identity', function () use ($enabled) {
    $articles = [
        '{Supergirl.2026.vol127+72.par2} {5Zn03jrjsly2} yEnc',
        '{Supergirl.2026.vol127+72.par2} {K7x0l9F182pk} yEnc',
        '{Supergirl.2026.vol127+72.par2} {NWeU5FDTXu5o} yEnc',
    ];

    $names = [];
    foreach ($articles as $article) {
        $result = $enabled->normalize($article);
        expect($result)->not->toBeNull();
        $names[] = $result['name'];
    }

    expect(array_unique($names))->toHaveCount(1)
        ->and($names[0])->toBe('{Supergirl.2026.vol127+72.par2} yEnc');
});

it('pins collection file numbers to a single file', function () use ($enabled) {
    // The subject carries only a part counter; it must not be read as a file count.
    $result = $enabled->normalize('{Lioness.S02E07.2160p.AMZN.WEB-DL.H.265-FLUX.rar} {k6SDjjsQ3MmW} yEnc');

    expect($result['file_number'])->toBe(1)
        ->and($result['total_files'])->toBe(1);
});

it('keeps distinct files in a posting distinct', function () use ($enabled) {
    $rar = $enabled->normalize('{Supergirl.2026.part01.rar} {stWsuZvUnzVX} yEnc');
    $par = $enabled->normalize('{Supergirl.2026.vol127+72.par2} {5Zn03jrjsly2} yEnc');

    expect($rar['name'])->not->toBe($par['name']);
});

it('leaves non-obfuscated subjects untouched', function () use ($enabled) {
    $subjects = [
        'Some.Normal.Release.2024.1080p.rar - [01/20] - "file.rar" yEnc',
        '[01/57] - "9981dffbf76f18421da1a76a63d938e5c9ae260c" yEnc',
        'Plain.Release.Without.Braces.mkv',
        '{Only.One.Braced.Group.rar} yEnc',
    ];

    foreach ($subjects as $subject) {
        expect($enabled->normalize($subject))->toBeNull();
    }
});

it('does not strip braced metadata that is not a random token', function () use ($enabled) {
    // Real metadata carries separators; tokens are bare alphanumeric runs.
    expect($enabled->normalize('{Movie.Name.2024.rar} {Some.Group.Name} yEnc'))->toBeNull()
        ->and($enabled->normalize('{Movie.Name.2024.rar} {a-b-c-d-e-f-g} yEnc'))->toBeNull()
        ->and($enabled->normalize('{Movie.Name.2024.rar} {short} yEnc'))->toBeNull();
});
