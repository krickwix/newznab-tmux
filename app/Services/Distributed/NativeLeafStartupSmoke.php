<?php

declare(strict_types=1);

namespace App\Services\Distributed;

use RuntimeException;

final class NativeLeafStartupSmoke
{
    /**
     * @param  list<int|string>  $arguments
     */
    public static function recordIfEnabled(string $command, array $arguments): bool
    {
        if (getenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE') !== '1') {
            return false;
        }

        $log = getenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG');
        if (! is_string($log) || trim($log) === '') {
            throw new RuntimeException('native leaf startup smoke log is not configured');
        }

        $line = trim($command.' '.implode(' ', array_map('strval', $arguments)));
        file_put_contents($log, $line.PHP_EOL, FILE_APPEND | LOCK_EX);

        return true;
    }
}
