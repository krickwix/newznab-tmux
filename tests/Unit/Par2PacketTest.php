<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\Par2Packet;
use PHPUnit\Framework\TestCase;

final class Par2PacketTest extends TestCase
{
    /**
     * Build a synthetic PAR2 packet. $declaredLength defaults to the real length
     * but can be overridden to simulate a recovery slice spanning several articles.
     */
    private function par2Packet(string $setIdHex, string $type, string $body = '', ?int $declaredLength = null): string
    {
        $typeField = str_pad("PAR 2.0\0".$type, 16, "\0");
        $header = "PAR2\0PKT"
            .pack('P', $declaredLength ?? (64 + \strlen($body)))
            .str_repeat("\xAA", 16)          // packet md5, not validated
            .(string) hex2bin($setIdHex)
            .$typeField;

        return $header.$body;
    }

    private function fileDescBody(string $name): string
    {
        return str_repeat("\xBB", 16)        // fileid
            .str_repeat("\xCC", 16)          // file md5
            .str_repeat("\xDD", 16)          // md5 of first 16k
            .pack('P', 123456)               // file length
            .$name;
    }

    public function test_it_reads_the_recovery_set_id_and_type(): void
    {
        $packet = Par2Packet::firstFrom($this->par2Packet('502593ec93849dc0f76ec7efb5919c0a', 'IFSC'));

        self::assertNotNull($packet);
        self::assertSame('502593ec93849dc0f76ec7efb5919c0a', $packet->recoverySetId);
        self::assertSame('IFSC', $packet->type);
        self::assertSame(0, $packet->offset);
    }

    public function test_it_accepts_a_packet_whose_declared_length_spans_beyond_available_bytes(): void
    {
        // Recovery-slice case: the packet claims ~4.6MB but this article carries
        // only the leading fragment. It must still be readable.
        $packet = Par2Packet::firstFrom(
            $this->par2Packet('4b57ad46dac0dd522f5dc994ce3d1e05', 'RecvSlic', str_repeat("\x01", 128), 4608068)
        );

        self::assertNotNull($packet);
        self::assertSame('4b57ad46dac0dd522f5dc994ce3d1e05', $packet->recoverySetId);
        self::assertSame('RecvSlic', $packet->type);
        self::assertSame(4608068, $packet->declaredLength);
        self::assertNull($packet->fileName);
    }

    public function test_it_finds_a_packet_that_does_not_start_at_offset_zero(): void
    {
        $packet = Par2Packet::firstFrom(
            str_repeat("\x00", 40).$this->par2Packet('aabbccddeeff00112233445566778899', 'Main')
        );

        self::assertNotNull($packet);
        self::assertSame(40, $packet->offset);
        self::assertSame('Main', $packet->type);
    }

    public function test_it_extracts_the_filename_from_a_complete_file_desc_packet(): void
    {
        $packet = Par2Packet::firstFrom(
            $this->par2Packet(
                '502593ec93849dc0f76ec7efb5919c0a',
                'FileDesc',
                $this->fileDescBody("Some.Real.Movie.2026.mkv\0\0")
            )
        );

        self::assertNotNull($packet);
        self::assertTrue($packet->isFileDescription());
        self::assertSame('Some.Real.Movie.2026.mkv', $packet->fileName);
    }

    public function test_it_does_not_report_a_filename_when_the_file_desc_body_is_truncated(): void
    {
        $full = $this->par2Packet(
            '502593ec93849dc0f76ec7efb5919c0a',
            'FileDesc',
            $this->fileDescBody('Truncated.Name.mkv')
        );

        $packet = Par2Packet::firstFrom(substr($full, 0, 80));

        self::assertNotNull($packet);
        self::assertNull($packet->fileName);
    }

    public function test_it_returns_null_when_there_is_no_packet_boundary(): void
    {
        // Continuation article landing mid-slice: valid data, just no header here.
        self::assertNull(Par2Packet::firstFrom(str_repeat("\x7F", 4096)));
        self::assertNull(Par2Packet::firstFrom(''));
    }

    public function test_it_returns_null_when_the_header_is_truncated(): void
    {
        $truncated = substr($this->par2Packet('502593ec93849dc0f76ec7efb5919c0a', 'IFSC'), 0, 48);

        self::assertNull(Par2Packet::firstFrom($truncated));
    }

    public function test_it_rejects_a_magic_match_not_followed_by_a_par2_type_signature(): void
    {
        $bogus = "PAR2\0PKT"
            .pack('P', 128)
            .str_repeat("\xAA", 16)
            .str_repeat("\xBB", 16)
            .str_pad('NOT-PAR2', 16, "\0");

        self::assertNull(Par2Packet::firstFrom($bogus));
    }

    public function test_it_rejects_a_packet_declaring_a_length_shorter_than_its_header(): void
    {
        self::assertNull(
            Par2Packet::firstFrom($this->par2Packet('502593ec93849dc0f76ec7efb5919c0a', 'IFSC', '', 32))
        );
    }
}
