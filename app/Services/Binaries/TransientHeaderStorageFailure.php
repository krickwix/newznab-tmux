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
