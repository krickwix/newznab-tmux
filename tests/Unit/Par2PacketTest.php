<?php

declare(strict_types=1);

use App\Services\Binaries\Par2Packet;

/**
 * Build a synthetic PAR2 packet. $declaredLength defaults to the real length but
 * can be overridden to simulate a recovery slice that spans several articles.
 */
function par2Packet(string $setIdHex, string $type, string $body = '', ?int $declaredLength = null): string
{
    $typeField = str_pad("PAR 2.0\0".$type, 16, "\0");
    $header = "PAR2\0PKT"
        .pack('P', $declaredLength ?? (64 + \strlen($body)))
        .str_repeat("\xAA", 16)          // packet md5, not validated
        .hex2bin($setIdHex)
        .$typeField;

    return $header.$body;
}

function fileDescBody(string $name): string
{
    return str_repeat("\xBB", 16)        // fileid
        .str_repeat("\xCC", 16)          // file md5
        .str_repeat("\xDD", 16)          // md5 of first 16k
        .pack('P', 123456)               // file length
        .$name;
}

it('reads the recovery set id and type', function () {
    $packet = Par2Packet::firstFrom(par2Packet('502593ec93849dc0f76ec7efb5919c0a', 'IFSC'));

    expect($packet)->not->toBeNull()
        ->and($packet->recoverySetId)->toBe('502593ec93849dc0f76ec7efb5919c0a')
        ->and($packet->type)->toBe('IFSC')
        ->and($packet->offset)->toBe(0);
});

it('accepts a packet whose declared length spans beyond the available bytes', function () {
    // This is the recovery-slice case: the packet claims ~4.6MB but this article
    // only carries the leading fragment. It must still be readable.
    $packet = Par2Packet::firstFrom(
        par2Packet('4b57ad46dac0dd522f5dc994ce3d1e05', 'RecvSlic', str_repeat("\x01", 128), 4608068)
    );

    expect($packet)->not->toBeNull()
        ->and($packet->recoverySetId)->toBe('4b57ad46dac0dd522f5dc994ce3d1e05')
        ->and($packet->type)->toBe('RecvSlic')
        ->and($packet->declaredLength)->toBe(4608068)
        ->and($packet->fileName)->toBeNull();
});

it('finds a packet that does not start at offset zero', function () {
    $packet = Par2Packet::firstFrom(str_repeat("\x00", 40).par2Packet('aabbccddeeff00112233445566778899', 'Main'));

    expect($packet)->not->toBeNull()
        ->and($packet->offset)->toBe(40)
        ->and($packet->type)->toBe('Main');
});

it('extracts the filename from a complete FileDesc packet', function () {
    $packet = Par2Packet::firstFrom(
        par2Packet('502593ec93849dc0f76ec7efb5919c0a', 'FileDesc', fileDescBody("Some.Real.Movie.2026.mkv\0\0"))
    );

    expect($packet)->not->toBeNull()
        ->and($packet->isFileDescription())->toBeTrue()
        ->and($packet->fileName)->toBe('Some.Real.Movie.2026.mkv');
});

it('does not report a filename when the FileDesc body is truncated', function () {
    $full = par2Packet('502593ec93849dc0f76ec7efb5919c0a', 'FileDesc', fileDescBody('Truncated.Name.mkv'));

    $packet = Par2Packet::firstFrom(substr($full, 0, 80));

    expect($packet)->not->toBeNull()
        ->and($packet->fileName)->toBeNull();
});

it('returns null when there is no packet boundary', function () {
    // Continuation article landing mid-slice: valid data, just no header here.
    expect(Par2Packet::firstFrom(str_repeat("\x7F", 4096)))->toBeNull()
        ->and(Par2Packet::firstFrom(''))->toBeNull();
});

it('returns null when the header is truncated', function () {
    expect(Par2Packet::firstFrom(substr(par2Packet('502593ec93849dc0f76ec7efb5919c0a', 'IFSC'), 0, 48)))->toBeNull();
});

it('rejects a magic match that is not followed by a PAR2 type signature', function () {
    $bogus = "PAR2\0PKT".pack('P', 128).str_repeat("\xAA", 16).str_repeat("\xBB", 16).str_pad('NOT-PAR2', 16, "\0");

    expect(Par2Packet::firstFrom($bogus))->toBeNull();
});

it('rejects a packet declaring a length shorter than its own header', function () {
    expect(Par2Packet::firstFrom(par2Packet('502593ec93849dc0f76ec7efb5919c0a', 'IFSC', '', 32)))->toBeNull();
});
