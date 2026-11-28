<?php

declare(strict_types=1);

namespace Tests\Unit\NNTP;

use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error as NntpError;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The XFEATURE COMPRESS GZIP reader used to decide where the response ended by
 * looking for a line ending in ".\r\n", waiting 5 ms for more bytes and, if
 * none arrived, decompressing what it had. Compressed bytes contain ".\r\n" by
 * chance, so a pause at such a byte truncated the stream: on 2026-11-28, with
 * compression enabled on the binaries lane for nine minutes, 11 of 153 chunks
 * died with "Decompression of OVER headers failed."
 *
 * The end is now decided by the compressed stream itself (ZLIB_STREAM_END),
 * with no timer involved.
 */
final class NNTPServiceCompressedHeadersTest extends TestCase
{
    /** @var list<string> */
    private const array HEADERS = [
        "123\tFirst.Post (1/2) yEnc\tposter@example.local\tSat, 01 Aug 2026 17:48:51 UTC\t<a@example.local>\t\t4242\t33",
        "124\tSecond.Post.vol001.par2\tposter@example.local\tSat, 01 Aug 2026 17:48:52 UTC\t<b@example.local>\t\t91\t1",
        "125\tThird.Post\tposter@example.local\tSat, 01 Aug 2026 17:48:53 UTC\t<c@example.local>\t\t17\t1",
        // Ends in a period, so the stored-block fixture below embeds ".\r\n"
        // inside the compressed stream -- the byte sequence the old reader
        // mistook for the end of the response.
        "126\tFourth.Post\tposter@example.local\tSat, 01 Aug 2026 17:48:54 UTC\t<d@example.local>\t\t91\t1\tXref: news alt.binaries.test.",
    ];

    public function test_a_dot_terminator_inside_the_compressed_stream_no_longer_ends_the_response(): void
    {
        // Level 0 produces stored deflate blocks, so the compressed stream
        // literally contains the payload's own bytes. That makes the hazard
        // the old reader tripped over -- ".\r\n" occurring inside the
        // compressed data -- a certainty rather than a matter of luck.
        $payload = implode("\r\n", self::HEADERS)."\r\n";
        $compressed = gzcompress($payload, 0);

        self::assertStringContainsString(".\r\n", $compressed, 'Fixture must embed the hazard it is testing.');

        $result = $this->readResponse($compressed.".\r\n");

        self::assertSame(self::HEADERS, $result);
    }

    public function test_a_stream_that_never_signals_readiness_is_still_read_to_completion(): void
    {
        // PausingNntpStream serves the response in small chunks and never
        // reports data ready to stream_select(), standing in for the "no bytes
        // available right now" condition the old reader mistook for the end of
        // the response. It is a stand-in, not a byte-level reproduction: the
        // real failure needed a >5 ms pause on a real socket at a chance
        // ".\r\n", which no single-threaded fixture can produce. What this
        // pins is the property that makes that race impossible -- the new
        // reader consults no clock and no readiness signal, only the
        // compressed stream's own end.
        $payload = implode("\r\n", self::HEADERS)."\r\n";
        $compressed = gzcompress($payload, 0);

        self::assertStringContainsString(".\r\n", $compressed, 'Fixture must embed the hazard it is testing.');

        $socket = PausingNntpStream::open($compressed.".\r\n", 64);

        self::assertSame(self::HEADERS, $this->readFrom($socket));
    }

    public function test_a_chunked_stream_still_decodes_an_ordinary_response(): void
    {
        $payload = implode("\r\n", self::HEADERS)."\r\n";
        $socket = PausingNntpStream::open(gzcompress($payload, 6).".\r\n", 64);

        self::assertSame(self::HEADERS, $this->readFrom($socket));
    }

    public function test_an_ordinary_compressed_response_is_decoded(): void
    {
        $payload = implode("\r\n", self::HEADERS)."\r\n";

        $result = $this->readResponse(gzcompress($payload, 6).".\r\n");

        self::assertSame(self::HEADERS, $result);
    }

    public function test_the_terminator_is_drained_so_the_next_command_reads_its_own_response(): void
    {
        // A payload with no newlines for well over fgets()' 16 KB limit, so
        // the reads split on the buffer boundary and the stream completes on a
        // read that does not carry the terminator. zlib ignores anything after
        // its own stream end, so an undrained terminator would be left in the
        // socket for the next command to misread.
        $lines = [];
        for ($i = 0; $i < 400; $i++) {
            // Incompressible, so the compressed stream is larger than the read
            // buffer rather than collapsing to a couple of KB.
            $lines[] = $i.'-'.bin2hex(random_bytes(60));
        }
        $payload = implode("\r\n", $lines)."\r\n";
        $compressed = gzcompress($payload, 1);

        self::assertGreaterThan(16384, strlen($compressed), 'Fixture must span more than one fgets() read.');

        $socket = $this->socketFor($compressed.".\r\n");
        $result = $this->readFrom($socket);

        self::assertSame($lines, $result);
        self::assertFalse(fgets($socket), 'The dot terminator must be consumed, not left in the socket.');
    }

    public function test_corrupt_compressed_data_reports_the_failure_instead_of_crashing(): void
    {
        // cli()->error() returns null, and the old call sites passed that
        // straight into throwError(string), so a decode failure raised a
        // TypeError in the middle of a header download instead of reporting
        // itself.
        $result = $this->readResponse("not-a-zlib-stream-at-all\r\n.\r\n");

        self::assertInstanceOf(NntpError::class, $result);
        self::assertSame('Decompression of OVER headers failed.', $result->getMessage());
    }

    public function test_a_stream_cut_short_is_reported_as_incomplete_rather_than_decoded(): void
    {
        $payload = implode("\r\n", self::HEADERS)."\r\n";
        $compressed = gzcompress($payload, 6);

        // Half the stream and no terminator: the peer went away mid-response.
        $result = $this->readResponse(substr($compressed, 0, intdiv(strlen($compressed), 2)));

        self::assertInstanceOf(NntpError::class, $result);
        self::assertSame(
            'Connection closed before the compressed OVER headers were complete.',
            $result->getMessage(),
        );
    }

    public function test_a_complete_stream_without_its_terminator_is_reported_separately(): void
    {
        $payload = implode("\r\n", self::HEADERS)."\r\n";

        // Distinguishing the two tells an operator whether the headers were
        // lost or only the framing was.
        $result = $this->readResponse(gzcompress($payload, 6));

        self::assertInstanceOf(NntpError::class, $result);
        self::assertSame(
            'Connection closed before the OVER header terminator arrived.',
            $result->getMessage(),
        );
    }

    /**
     * @return list<string>|string|NntpError
     */
    private function readResponse(string $wire): array|string|NntpError
    {
        return $this->readFrom($this->socketFor($wire));
    }

    /**
     * @param  resource  $socket
     * @return list<string>|string|NntpError
     */
    private function readFrom($socket): array|string|NntpError
    {
        $service = new class extends NNTPService
        {
            public function __construct()
            {
                $this->_echo = false;
            }

            // The real destructor sends QUIT over the socket; this one is a
            // fixture stream, not a server.
            public function __destruct() {}
        };

        $property = new ReflectionProperty(NNTPService::class, '_socket');
        $property->setValue($service, $socket);

        $method = new ReflectionMethod(NNTPService::class, '_getXFeatureTextResponse');

        return $method->invoke($service);
    }

    /**
     * @return resource
     */
    private function socketFor(string $wire)
    {
        $socket = fopen('php://temp', 'r+b');
        self::assertNotFalse($socket);
        fwrite($socket, $wire);
        rewind($socket);

        return $socket;
    }
}
