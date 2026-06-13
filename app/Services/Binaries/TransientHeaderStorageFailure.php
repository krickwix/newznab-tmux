<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use Illuminate\Support\Facades\DB;
use Throwable;

final class TransientHeaderStorageFailure
{
    public static function is(Throwable $e): bool
    {
        $message = self::exceptionMessages($e);

        return str_contains($message, 'SQLSTATE[40001]')
            || str_contains($message, 'Deadlock found')
            || str_contains($message, 'deadlock detected')
            || str_contains($message, 'has been chosen as the deadlock victim')
            || str_contains($message, 'Lock wait timeout')
            || str_contains($message, 'Record has changed since last read')
            || str_contains($message, 'try restarting transaction')
            || str_contains($message, 'WSREP detected deadlock/conflict')
            || self::hasDriverCode($e, ['40001', '1213', '1205']);
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

    private static function exceptionMessages(Throwable $e): string
    {
        $messages = [];
        do {
            $messages[] = $e->getMessage();
            $e = $e->getPrevious();
        } while ($e !== null);

        return implode(' ', $messages);
    }

    /** @param list<string> $codes */
    private static function hasDriverCode(Throwable $e, array $codes): bool
    {
        do {
            if (in_array((string) $e->getCode(), $codes, true)) {
                return true;
            }
            $e = $e->getPrevious();
        } while ($e !== null);

        return false;
    }
}
