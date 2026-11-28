<?php

declare(strict_types=1);

namespace App\Services\Orchestrator;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Recognises a statement killed by max_statement_time, and nothing else.
 *
 * The distinction matters because the two failures deserve opposite
 * treatments. A capture that errors may be evidence the pipeline is broken, so
 * it keeps the fail-closed path. A capture that timed out means only "I could
 * not measure the pipeline inside the budget this cycle", which is not
 * evidence of anything being wrong -- and failClosed() is expensive for a
 * non-event: it zeroes orchestrator_lease_until and revokes every permit, so
 * the lanes drop to their fail-safe timers until a later cycle re-grants, and
 * a lane on a 300 s sleep wears that for far longer than the cycle that caused
 * it.
 *
 * Matched on the error codes rather than the message, because message text is
 * a locale- and version-dependent surface that would silently stop matching:
 * MariaDB raises ER_STATEMENT_TIMEOUT (1969) with SQLSTATE 70100.
 */
final class SnapshotStatementTimeout
{
    /**
     * MariaDB's driver error code for a statement killed by its own timeout.
     */
    private const int DRIVER_CODE = 1969;

    /**
     * SQLSTATE for the same condition.
     */
    private const string SQL_STATE = '70100';

    public static function matches(Throwable $error): bool
    {
        if (! $error instanceof QueryException) {
            return false;
        }

        if ((string) $error->getCode() === self::SQL_STATE) {
            return true;
        }

        // errorInfo is [SQLSTATE, driver code, driver message]. Both halves are
        // checked because a PDO wrapper may surface one without the other.
        $errorInfo = $error->errorInfo ?? [];

        return isset($errorInfo[1]) && (int) $errorInfo[1] === self::DRIVER_CODE;
    }
}
