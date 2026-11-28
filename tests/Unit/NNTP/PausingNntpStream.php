<?php

declare(strict_types=1);

namespace Tests\Unit\NNTP;

/**
 * A stream that hands out an NNTP response in fixed chunks and never tells
 * stream_select() that more is coming.
 *
 * That second property is the point. A userland stream has no file descriptor
 * for stream_select() to poll, so it cannot be used to ask "are more bytes
 * coming?" -- the question the old reader answered with a 5 ms timer and got
 * wrong 11 times in 153 chunks on 2026-11-28.
 *
 * This is a stand-in for that condition, not a reproduction of it: the real
 * failure needed a pause on a real socket, which a single-threaded fixture
 * cannot stage. fgets() and feof() work normally here, so a reader that ends
 * on the compressed stream's own end reads the whole response, while one that
 * depends on a readiness signal cannot work at all.
 */
final class PausingNntpStream
{
    public const string PROTOCOL = 'pausing-nntp';

    private static string $payload = '';

    private static int $chunkSize = 512;

    private int $offset = 0;

    /** @var resource|null */
    public $context;

    /**
     * @return resource
     */
    public static function open(string $payload, int $chunkSize = 512)
    {
        self::$payload = $payload;
        self::$chunkSize = max(1, $chunkSize);

        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $handle = fopen(self::PROTOCOL.'://response', 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open the fixture stream.');
        }

        return $handle;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->offset = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$payload, $this->offset, min($count, self::$chunkSize));
        $this->offset += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= \strlen(self::$payload);
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        $this->offset = 0;
    }
}
