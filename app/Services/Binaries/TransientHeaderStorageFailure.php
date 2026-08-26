<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\TransientDatabaseError;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TransientHeaderStorageFailure
{
    public static function is(Throwable $e): bool
    {
        // A stolen file ordinal is contention, not corruption: the chunk is
        // rolled back, MAX(filenumber) is re-read from scratch on the next
        // attempt, and after MAX_CHUNK_ATTEMPTS the articles go to part repair.
        // That is the retry contract in section 3 of
        // docs/design/2026-08-04-ingest-collection-keying.md, served by the
        // chunk retry loop that was already there.
        if ($e instanceof CollectionFileNumberCollision) {
            return true;
        }

        return TransientDatabaseError::is($e);
    }

    public static function canRetryStatement(Throwable $e): bool
    {
        return self::is($e) && ! self::insideTransaction();
    }

    private static function insideTransaction(): bool
    {
        try {
            return DB::connection()->transactionLevel() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
