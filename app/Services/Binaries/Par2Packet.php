<?php

declare(strict_types=1);

namespace App\Services\Binaries;

/**
 * Minimal reader for PAR2 packet headers found at the start of article payloads.
 *
 * A PAR2 packet is laid out as:
 *
 *   offset 0  : "PAR2\0PKT"   8  bytes magic
 *   offset 8  : packet length 8  bytes little-endian uint64 (whole packet, incl. header)
 *   offset 16 : packet MD5    16 bytes
 *   offset 32 : RecoverySetID 16 bytes  <-- identity shared by every file of a set
 *   offset 48 : packet type   16 bytes, "PAR 2.0\0" + 8 byte type name
 *   offset 64 : body
 *
 * The RecoverySetID is the authoritative identity of a recovery set: every par2
 * volume belonging to one posted set carries the same value, and it is derived
 * from the set contents rather than from any subject text. That makes it immune
 * to the SHA-1 filename obfuscation that breaks subject-derived grouping (see
 * ObfuscatedHashSetNormalizer, which recovers the same grouping heuristically).
 *
 * IMPORTANT: this reader deliberately never requires a complete packet body.
 * Recovery-slice packets routinely declare multi-megabyte lengths that span many
 * articles, so demanding `offset + length <= strlen($bytes)` rejects every
 * article and makes valid data look unparseable. Only the 64-byte header is
 * required; the declared length is reported for callers that care, and the body
 * is only read when it is genuinely present.
 *
 * Articles that begin part-way through a large packet contain no packet boundary
 * at all. Those simply yield null, and callers are expected to tolerate that and
 * probe another article of the same file.
 */
final readonly class Par2Packet
{
    public const string MAGIC = "PAR2\0PKT";

    private const string TYPE_PREFIX = "PAR 2.0\0";

    /** Header bytes preceding the packet body. */
    private const int HEADER_BYTES = 64;

    /** fileid(16) + file MD5(16) + first-16k MD5(16) + file length(8) */
    private const int FILE_DESC_NAME_OFFSET = 56;

    public function __construct(
        /** Lower-case hex RecoverySetID, 32 characters. */
        public string $recoverySetId,
        /** Packet type name with the "PAR 2.0" prefix removed, e.g. "FileDesc". */
        public string $type,
        /** Declared total packet length in bytes, as recorded in the header. */
        public int $declaredLength,
        /** Byte offset the packet header was found at. */
        public int $offset,
        /** Filename from a FileDesc packet when the body was fully present. */
        public ?string $fileName = null,
    ) {}

    /**
     * Read the first PAR2 packet header present in the given bytes.
     *
     * Returns null when no packet boundary is found, or when the header is
     * truncated, or when the packet type prefix is not a PAR2 signature.
     */
    public static function firstFrom(string $bytes): ?self
    {
        $offset = strpos($bytes, self::MAGIC);
        if ($offset === false || $offset + self::HEADER_BYTES > \strlen($bytes)) {
            return null;
        }

        /** @var array{1: int}|false $length */
        $length = unpack('P', substr($bytes, $offset + 8, 8));
        if ($length === false) {
            return null;
        }
        $declaredLength = (int) $length[1];

        // A packet must at least contain its own header, and a negative value
        // here means the length overflowed PHP's signed integer.
        if ($declaredLength < self::HEADER_BYTES) {
            return null;
        }

        $rawType = substr($bytes, $offset + 48, 16);
        if (! str_starts_with($rawType, self::TYPE_PREFIX)) {
            return null;
        }
        $type = rtrim(substr($rawType, \strlen(self::TYPE_PREFIX)), "\0");

        $recoverySetId = bin2hex(substr($bytes, $offset + 32, 16));

        return new self(
            $recoverySetId,
            $type,
            $declaredLength,
            $offset,
            self::readFileName($bytes, $offset, $declaredLength, $type),
        );
    }

    public function isFileDescription(): bool
    {
        return $this->type === 'FileDesc';
    }

    /**
     * Extract the filename from a FileDesc packet, when its body is present.
     *
     * FileDesc bodies are small, so unlike recovery slices they are normally
     * contained within a single article. The name is null padded to a 4 byte
     * boundary and is only trustworthy when the whole packet is available.
     */
    private static function readFileName(string $bytes, int $offset, int $declaredLength, string $type): ?string
    {
        if ($type !== 'FileDesc') {
            return null;
        }

        $packetEnd = $offset + $declaredLength;
        $nameStart = $offset + self::HEADER_BYTES + self::FILE_DESC_NAME_OFFSET;
        if ($packetEnd > \strlen($bytes) || $nameStart >= $packetEnd) {
            return null;
        }

        $name = rtrim(substr($bytes, $nameStart, $packetEnd - $nameStart), "\0");

        return $name === '' ? null : $name;
    }
}
