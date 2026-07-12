<?php

declare(strict_types=1);

namespace App\Services\NNTP;

final class NntpArticleDate
{
    private const int EARLIEST_TIMESTAMP = 946_684_800;

    private const int FUTURE_GRACE_SECONDS = 86_400;

    public static function timestamp(mixed $value, ?int $now = null): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $value = is_string($value) ? trim($value) : $value;
        if ($value === '') {
            return null;
        }

        $timestamp = is_int($value) || preg_match('/^-?\d+$/D', $value) === 1
            ? (int) $value
            : strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        $upperBound = ($now ?? time()) + self::FUTURE_GRACE_SECONDS;

        return $timestamp >= self::EARLIEST_TIMESTAMP && $timestamp <= $upperBound
            ? $timestamp
            : null;
    }
}
