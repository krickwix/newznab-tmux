<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GroupsUpdateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux_nntp.use_alternate_nntp_server' => true,
        ]);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255),
            active INTEGER,
            backfill INTEGER
        )');
        DB::statement('CREATE TABLE short_groups (
            name VARCHAR(255),
            first_record INTEGER,
            last_record INTEGER,
            updated DATETIME NULL
        )');

        DB::table('usenet_groups')->insert([
            [
                'id' => 1,
                'name' => 'alt.binaries.dvd.classics',
                'active' => 0,
                'backfill' => 1,
            ],
            [
                'id' => 2,
                'name' => 'alt.binaries.disabled',
                'active' => 0,
                'backfill' => 0,
            ],
        ]);
        DB::table('short_groups')->insert([
            'name' => 'stale.group',
            'first_record' => 1,
            'last_record' => 2,
        ]);
    }

    public function test_snapshot_uses_the_configured_provider_for_a_backfill_only_group(): void
    {
        $nntp = new class extends NNTPService
        {
            /** @var list<array{compression: bool, alternate: bool}> */
            public array $connections = [];

            /** @var list<string> */
            public array $selectedGroups = [];

            public function __construct() {}

            public function __destruct() {}

            public function doConnect(bool $compression = true, bool $alternate = false): mixed
            {
                $this->connections[] = compact('compression', 'alternate');

                return true;
            }

            public function getGroups(mixed $wildMat = null): mixed
            {
                return [
                    [
                        'group' => 'alt.binaries.dvd.classics',
                        'first' => 2,
                        'last' => 56925589,
                    ],
                    [
                        'group' => 'alt.binaries.disabled',
                        'first' => 2,
                        'last' => 100000,
                    ],
                ];
            }

            public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
            {
                $this->selectedGroups[] = $group;

                return [
                    'group' => $group,
                    'first' => 96727,
                    'last' => 56925589,
                    'count' => 56828863,
                ];
            }
        };

        $this->app->instance(NNTPService::class, $nntp);

        $this->artisan('groups:update')->assertSuccessful();

        $this->assertSame([
            ['compression' => false, 'alternate' => true],
        ], $nntp->connections);
        $this->assertSame(['alt.binaries.dvd.classics'], $nntp->selectedGroups);
        $this->assertDatabaseMissing('short_groups', ['name' => 'stale.group']);
        $this->assertDatabaseHas('short_groups', [
            'name' => 'alt.binaries.dvd.classics',
            'first_record' => 96727,
            'last_record' => 56925589,
        ]);
        $this->assertDatabaseMissing('short_groups', ['name' => 'alt.binaries.disabled']);
        $this->assertDatabaseHas('usenet_groups', [
            'name' => 'alt.binaries.dvd.classics',
            'active' => 0,
            'backfill' => 1,
        ]);
    }

    public function test_failed_group_verification_preserves_the_previous_snapshot(): void
    {
        $nntp = new class extends NNTPService
        {
            public function __construct() {}

            public function __destruct() {}

            public function doConnect(bool $compression = true, bool $alternate = false): mixed
            {
                return true;
            }

            public function getGroups(mixed $wildMat = null): mixed
            {
                return [[
                    'group' => 'alt.binaries.dvd.classics',
                    'first' => 2,
                    'last' => 56925589,
                ]];
            }

            public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
            {
                return 'transient invalid response';
            }
        };

        $this->app->instance(NNTPService::class, $nntp);

        $this->artisan('groups:update')->assertFailed();

        $this->assertDatabaseHas('short_groups', [
            'name' => 'stale.group',
            'first_record' => 1,
            'last_record' => 2,
        ]);
        $this->assertDatabaseMissing('short_groups', ['name' => 'alt.binaries.dvd.classics']);
    }
}
