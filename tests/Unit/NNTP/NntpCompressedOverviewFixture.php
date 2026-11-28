<?php

declare(strict_types=1);

namespace Tests\Unit\NNTP;

use RuntimeException;

/**
 * Builds a compressed XOVER response whose compressed bytes contain ".\r\n",
 * and serves it over a real socket.
 *
 * Both halves matter. ingest's real-socket experiment showed the old reader's
 * trigger is byte content, not timing: after stream_select() reports data
 * ready, the old reader switched the socket to non-blocking and called
 * fgets(), and a non-blocking fgets() over binary data returns '' whenever the
 * next "\n" is not already within reach. It read that empty result as the end
 * of the response. So the 5 ms poll was never the problem -- it succeeds -- and
 * a chance ".\r\n" anywhere in the compressed stream is enough to truncate it
 * with no network pause at all. Their controls: with ".\r\n" present and the
 * whole response written in ONE sendall the old reader still failed; with it
 * absent, same single write, it succeeded.
 *
 * That makes it reproducible single-threaded, which is better than the
 * stand-in fixtures: a socket pair is a real selectable socket, so
 * stream_select() behaves as it does in production, and everything can be
 * written before the reader starts.
 *
 * The ".\r\n" is searched for rather than constructed because zlib's output
 * bytes cannot be dictated. Real compression is required: with stored blocks
 * the payload's own newlines are everywhere, so a non-blocking fgets() finds
 * one immediately and the old reader recovers.
 */
final class NntpCompressedOverviewFixture
{
    /** Hit rate is ~1 in 130 at this size, so the search is cheap and bounded. */
    private const int LINES = 1200;

    private const int MAX_ATTEMPTS = 4000;

    /**
     * @return array{socket: resource, peer: resource, lines: list<string>, compressed_bytes: int, false_terminator_at: int}
     */
    public static function withFalseTerminator(int $seed = 1234): array
    {
        return self::build($seed, true);
    }

    /**
     * @return array{socket: resource, peer: resource, lines: list<string>, compressed_bytes: int, false_terminator_at: int}
     */
    public static function withoutFalseTerminator(int $seed = 1234): array
    {
        return self::build($seed, false);
    }

    /**
     * @return array{socket: resource, peer: resource, lines: list<string>, compressed_bytes: int, false_terminator_at: int}
     */
    private static function build(int $seed, bool $wantFalseTerminator): array
    {
        mt_srand($seed);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $lines = self::overviewLines();
            $compressed = gzcompress(implode("\r\n", $lines)."\r\n", 6);

            if ($compressed === false) {
                throw new RuntimeException('Unable to compress the fixture payload.');
            }

            $at = strpos($compressed, ".\r\n");

            if (($at !== false) === $wantFalseTerminator) {
                [$reader, $writer] = self::socketServing($compressed.".\r\n");

                return [
                    'socket' => $reader,
                    'peer' => $writer,
                    'lines' => $lines,
                    'compressed_bytes' => strlen($compressed),
                    'false_terminator_at' => $at === false ? -1 : $at,
                ];
            }
        }

        throw new RuntimeException(sprintf(
            'No payload %s a ".\r\n" in its compressed bytes after %d attempts.',
            $wantFalseTerminator ? 'with' : 'without',
            self::MAX_ATTEMPTS,
        ));
    }

    /**
     * Overview lines shaped like alt.binaries.boneless, which is the group the
     * production failures came from.
     *
     * @return list<string>
     */
    private static function overviewLines(): array
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $lines = [];

        for ($i = 0; $i < self::LINES; $i++) {
            $token = '';
            for ($c = 0; $c < 10; $c++) {
                $token .= $alphabet[mt_rand(0, 61)];
            }

            $lines[] = sprintf(
                "%d\t%s - [%02d/20] - \"%s.part%03d.rar\" yEnc (1/50)\tanon <anon@anon.local>"
                ."\tSat, 28 Nov 2026 01:00:00 +0000\t<%s@news>\t\t739200\t2890\tXref: s",
                1000 + $i,
                $token,
                $i % 20 + 1,
                $token,
                $i % 250,
                $token,
            );
        }

        return $lines;
    }

    /**
     * A real, selectable socket carrying the whole response, written before
     * the reader starts.
     *
     * The writing end is deliberately left open and handed back to the caller.
     * Closing it would make feof() true as soon as the buffer drained, and the
     * old reader exits its loop on feof() with a different error -- which
     * would make this fixture reproduce the wrong defect and, worse, make the
     * control case fail too. A server that has sent a complete response and is
     * waiting for the next command is what production looks like, and it is
     * what isolates the byte-content trigger: with the writer open, the old
     * reader passes the control and fails only when ".\r\n" is present.
     *
     * The read timeout is the safety net that keeps a wrong reader from
     * hanging the suite instead of failing it.
     *
     * @return array{0: resource, 1: resource}
     */
    private static function socketServing(string $wire): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            throw new RuntimeException('Unable to create the fixture socket pair.');
        }

        [$reader, $writer] = $pair;

        // ~25 KB against a buffer that accepts 300 KB, so this cannot block
        // and the test stays single-threaded.
        if (fwrite($writer, $wire) !== strlen($wire)) {
            throw new RuntimeException('Fixture socket would not take the whole response.');
        }

        stream_set_timeout($reader, 2);

        return [$reader, $writer];
    }
}
