<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use PHPUnit\Framework\TestCase;

final class BackfillLegacyCommandGuardTest extends TestCase
{
    public function test_direct_backfill_command_checks_managed_production_admission_before_nntp_connection(): void
    {
        $source = file_get_contents(__DIR__.'/../../../app/Console/Commands/UpdateBackfill.php');

        self::assertIsString($source);
        self::assertStringContainsString('use App\\Services\\Distributed\\BackfillExecutionGuard;', $source);
        $guard = strpos($source, 'assertLegacyCommandAllowed(');
        $connect = strpos($source, '$this->getNntp()');
        self::assertIsInt($guard);
        self::assertIsInt($connect);
        self::assertLessThan($connect, $guard, 'The backfill guard must run before any provider side effect.');
    }
}
