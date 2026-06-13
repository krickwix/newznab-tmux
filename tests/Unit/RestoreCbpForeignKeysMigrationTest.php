<?php

declare(strict_types=1);

namespace Tests\Unit;

use ReflectionMethod;
use Tests\TestCase;

final class RestoreCbpForeignKeysMigrationTest extends TestCase
{
    public function test_large_parts_table_requires_explicit_opt_in(): void
    {
        $migration = require base_path('database/migrations/2026_05_06_093900_restore_cbp_foreign_keys.php');
        $method = new ReflectionMethod($migration, 'shouldAbortInlineForeignKeyRestore');

        $this->assertTrue($method->invoke($migration, 1_000_001, false));
    }

    public function test_small_parts_table_can_restore_inline_without_opt_in(): void
    {
        $migration = require base_path('database/migrations/2026_05_06_093900_restore_cbp_foreign_keys.php');
        $method = new ReflectionMethod($migration, 'shouldAbortInlineForeignKeyRestore');

        $this->assertFalse($method->invoke($migration, 999_999, false));
    }

    public function test_explicit_opt_in_allows_large_parts_table_restore(): void
    {
        $migration = require base_path('database/migrations/2026_05_06_093900_restore_cbp_foreign_keys.php');
        $method = new ReflectionMethod($migration, 'shouldAbortInlineForeignKeyRestore');

        $this->assertFalse($method->invoke($migration, 1_000_001, true));
    }

    public function test_existing_foreign_keys_skip_restore_work(): void
    {
        $migration = require base_path('database/migrations/2026_05_06_093900_restore_cbp_foreign_keys.php');
        $method = new ReflectionMethod($migration, 'foreignKeysAlreadyRestored');

        $this->assertTrue($method->invoke($migration, true, true));
        $this->assertFalse($method->invoke($migration, true, false));
        $this->assertFalse($method->invoke($migration, false, true));
    }
}
