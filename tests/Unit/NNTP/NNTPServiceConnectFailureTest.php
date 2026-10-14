<?php

declare(strict_types=1);

namespace Tests\Unit\NNTP;

use App\Services\NNTP\NNTPService;
use ErrorException;
use PHPUnit\Framework\TestCase;

final class NNTPServiceConnectFailureTest extends TestCase
{
    public function test_unresolvable_host_returns_an_error_instead_of_throwing(): void
    {
        $service = new class extends NNTPService
        {
            public function __construct() {}
        };

        // Same rule as Laravel's HandleExceptions, which also reports every
        // level: any reported warning becomes an ErrorException.
        // hashed-fixnames died this way when news.eweka.nl failed to resolve
        // during an internet outage.
        $reporting = error_reporting(-1);
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $level) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $level, $file, $line);
        });

        try {
            $result = $service->connect('nntmux-test.invalid', false, 119, 2);
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }

        $this->assertTrue(NNTPService::isError($result));
        $this->assertStringContainsString('Connection to tcp://nntmux-test.invalid:119 failed.', $result->getMessage());
    }
}
