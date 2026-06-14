<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

final class TransientDatabaseError
{
    /**
     * MySQL/MariaDB transient contention codes:
     * 1213 = deadlock, 1205 = lock wait timeout, 1020/123 = record changed.
     *
     * @var list<string>
     */
    private const DRIVER_CODES = ['40001', '1213', '1205', '1020', '123'];

    /**
     * @var list<string>
     */
    private const MESSAGE_FRAGMENTS = [
        'SQLSTATE[40001]',
        'Deadlock found',
        'deadlock detected',
        'has been chosen as the deadlock victim',
        'Lock wait timeout',
        'Record has changed since last read',
        'Got error 123 when reading table',
        'try restarting transaction',
        'WSREP detected deadlock/conflict',
    ];

    public static function is(Throwable $e): bool
    {
        if (self::hasDriverCode($e)) {
            return true;
        }

        $message = self::exceptionMessages($e);
        foreach (self::MESSAGE_FRAGMENTS as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }

        return false;
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

    private static function hasDriverCode(Throwable $e): bool
    {
        do {
            if (in_array((string) $e->getCode(), self::DRIVER_CODES, true)) {
                return true;
            }

            if ($e instanceof QueryException) {
                $driverCode = (string) ($e->errorInfo[1] ?? '');
                if ($driverCode !== '' && in_array($driverCode, self::DRIVER_CODES, true)) {
                    return true;
                }
            }

            $e = $e->getPrevious();
        } while ($e !== null);

        return false;
    }
}
