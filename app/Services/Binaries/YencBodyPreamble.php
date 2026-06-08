<?php

declare(strict_types=1);

namespace App\Services\Binaries;

final readonly class YencBodyPreamble
{
    public function __construct(
        public string $name,
        public int $part,
        public int $total,
        public int $size
    ) {}

    /**
     * @param  array<int, string>  $lines
     */
    public static function fromLines(array $lines): ?self
    {
        foreach ($lines as $line) {
            if (! str_starts_with($line, '=ybegin ')) {
                continue;
            }

            $attributes = self::parseAttributes($line);
            $name = trim((string) ($attributes['name'] ?? ''));
            $part = (int) ($attributes['part'] ?? 0);
            $total = (int) ($attributes['total'] ?? 0);
            $size = (int) ($attributes['size'] ?? 0);

            if ($name === '' || $part <= 0 || $total <= 0 || $size <= 0) {
                return null;
            }

            return new self($name, $part, $total, $size);
        }

        return null;
    }

    public function toSyntheticSubject(): string
    {
        return '"'.$this->name.'" ('.$this->part.'/'.$this->total.') yEnc';
    }

    public function collectionFileNumber(): int
    {
        if (preg_match('/(?:^|[._-])part0*(\d{1,5})\.rar$/i', $this->name, $match) === 1) {
            return (int) $match[1];
        }

        if (preg_match('/\.r(\d{2,5})$/i', $this->name, $match) === 1) {
            return (int) $match[1] + 2;
        }

        if (preg_match('/\.rar$/i', $this->name) === 1) {
            return 1;
        }

        if (preg_match('/\.vol0*(\d{1,5})[+\-]\d+\.par2$/i', $this->name, $match) === 1) {
            return (int) $match[1] + 1;
        }

        return 0;
    }

    /**
     * @return array<string, string>
     */
    private static function parseAttributes(string $line): array
    {
        $attributes = [];
        if (preg_match_all('/(?:^|\s)([A-Za-z][A-Za-z0-9_-]*)=("[^"]*"|\S+)/', $line, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $attributes[strtolower($match[1])] = trim($match[2], '"');
            }
        }

        return $attributes;
    }
}
