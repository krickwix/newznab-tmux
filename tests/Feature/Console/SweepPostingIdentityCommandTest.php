<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\SweepPostingIdentity;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class SweepPostingIdentityCommandTest extends TestCase
{
    /** @var list<string> */
    public static array $calls = [];

    /** @var list<string> */
    public static array $failingGroups = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();
        DB::statement('CREATE TABLE settings (id INTEGER PRIMARY KEY, name VARCHAR(255), value TEXT NULL)');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255), active INT DEFAULT 0)');
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, groups_id INT)');

        self::$calls = [];
        self::$failingGroups = [];
        $kernel = $this->app->make(Kernel::class);
        foreach (['nntmux:repair-split-posting-identity', 'nntmux:repair-fragmented-posting-identity'] as $name) {
            $kernel->registerCommand($this->fakeRepair($name));
        }
    }

    public function test_it_sweeps_active_groups_and_groups_with_collections_only(): void
    {
        DB::table('usenet_groups')->insert([
            ['id' => 6955, 'name' => 'alt.binaries.moovee', 'active' => 1],
            ['id' => 11517, 'name' => 'alt.binaries.teevee', 'active' => 1],
            // Switched off, but its collections have not drained yet.
            ['id' => 5403, 'name' => 'alt.binaries.dvd.criterion', 'active' => 0],
            // Off and empty: nothing to repair.
            ['id' => 5450, 'name' => 'alt.binaries.dvd.movies', 'active' => 0],
        ]);
        // 5511 (a deleted group) still has a collection row: no group row, so it is skipped.
        DB::table('collections')->insert([
            ['id' => 1, 'groups_id' => 5403],
            ['id' => 2, 'groups_id' => 6955],
            ['id' => 3, 'groups_id' => 5511],
        ]);

        self::assertSame(
            [
                ['id' => 5403, 'name' => 'alt.binaries.dvd.criterion'],
                ['id' => 6955, 'name' => 'alt.binaries.moovee'],
                ['id' => 11517, 'name' => 'alt.binaries.teevee'],
            ],
            SweepPostingIdentity::sweepGroups(),
        );

        $exit = Artisan::call('nntmux:sweep-posting-identity', ['--before' => '2026-10-08 08:00:00', '--update' => true]);

        self::assertSame(0, $exit);
        self::assertSame([
            'nntmux:repair-split-posting-identity 5403 limit=25 before=2026-10-08 08:00:00 update',
            'nntmux:repair-fragmented-posting-identity 5403 limit=25 before=2026-10-08 08:00:00 update',
            'nntmux:repair-split-posting-identity 6955 limit=25 before=2026-10-08 08:00:00 update',
            'nntmux:repair-fragmented-posting-identity 6955 limit=25 before=2026-10-08 08:00:00 update',
            'nntmux:repair-split-posting-identity 11517 limit=25 before=2026-10-08 08:00:00 update',
            'nntmux:repair-fragmented-posting-identity 11517 limit=25 before=2026-10-08 08:00:00 update',
        ], self::$calls);
    }

    public function test_a_failing_pass_fails_the_sweep_but_the_other_groups_still_run(): void
    {
        DB::table('usenet_groups')->insert([
            ['id' => 6955, 'name' => 'alt.binaries.moovee', 'active' => 1],
            ['id' => 11517, 'name' => 'alt.binaries.teevee', 'active' => 1],
        ]);
        self::$failingGroups = ['6955'];

        // Not $this->artisan(): its mocked output style would also capture the
        // nested repair commands, so the sweep could not read their JSON.
        $output = new BufferedOutput;
        $exit = Artisan::call('nntmux:sweep-posting-identity', [], $output);
        $text = $output->fetch();

        self::assertSame(1, $exit);
        self::assertCount(4, self::$calls);
        self::assertStringContainsString('SWEEP FAILED: nntmux:repair-split-posting-identity alt.binaries.moovee: boom', $text);
        self::assertStringContainsString('alt.binaries.teevee (11517): split 1/2 merged, fragmented 1/2 merged', $text);
    }

    private function fakeRepair(string $name): Command
    {
        return new class($name) extends Command
        {
            public function __construct(string $name)
            {
                $this->signature = $name.' {group} {--limit=50} {--before=} {--min-files=} {--update} {--json}';
                parent::__construct();
            }

            public function handle(): int
            {
                $group = (string) $this->argument('group');
                SweepPostingIdentityCommandTest::$calls[] = sprintf(
                    '%s %s limit=%s%s%s',
                    $this->getName(),
                    $group,
                    (string) $this->option('limit'),
                    $this->option('before') === null ? '' : ' before='.$this->option('before'),
                    $this->option('update') ? ' update' : '',
                );
                if (in_array($group, SweepPostingIdentityCommandTest::$failingGroups, true)) {
                    $this->line((string) json_encode(['error' => 'boom']));

                    return self::FAILURE;
                }
                $this->line((string) json_encode(['cohorts_found' => 2, 'cohorts_merged' => 1]));

                return self::SUCCESS;
            }
        };
    }
}
