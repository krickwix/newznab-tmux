<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NativeLeafStartupSmokeCommandTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = storage_path('framework/testing/native-leaf-startup-smoke.log');
        if (! is_dir(dirname($this->logPath))) {
            mkdir(dirname($this->logPath), 0777, true);
        }
        file_put_contents($this->logPath, '');

        DB::statement('CREATE TABLE IF NOT EXISTS settings (name VARCHAR(255) PRIMARY KEY, value TEXT NULL)');
        DB::table('settings')->upsert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '1'],
        ], ['name'], ['value']);

        putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1');
        putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG='.$this->logPath);
        $_ENV['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'] = '1';
        $_ENV['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'] = $this->logPath;
        $_SERVER['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'] = '1';
        $_SERVER['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'] = $this->logPath;
    }

    protected function tearDown(): void
    {
        putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE');
        putenv('NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG');
        unset(
            $_ENV['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'],
            $_ENV['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'],
            $_SERVER['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE'],
            $_SERVER['NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG'],
        );

        parent::tearDown();
    }

    public function test_native_leaf_startup_smoke_short_circuits_non_first_lane_commands(): void
    {
        $commands = [
            ['releases:fix-names', ['method' => '20', '--category' => 'hashed', '--limit' => 500, '--update' => true, '--set-status' => true, '--show' => true], 'releases:fix-names 20 --category=hashed --limit=500 --update --set-status --show'],
            ['releases:remove-crap', ['--type' => 'gibberish', '--time' => '4', '--delete' => true], 'releases:remove-crap --type=gibberish --time=4 --delete'],
            ['predb:refresh-external-metadata', ['--source' => ['all'], '--limit' => 7, '--sleep-ms' => 11], 'predb:refresh-external-metadata --source=all --limit=7 --sleep-ms=11'],
            ['postprocess:guid', ['type' => 'movie', 'guid' => 'm'], 'postprocess:guid movie m'],
            ['postprocess:tv-pipeline', ['guid' => 'A', 'renamed' => '1', '--mode' => 'pipeline'], 'postprocess:tv-pipeline A 1 --mode=pipeline'],
            ['group:update-all', ['groupId' => '42'], 'group:update-all 42'],
            ['irc:scrape', [], 'irc:scrape'],
        ];

        foreach ($commands as [$command, $arguments, $expectedLine]) {
            $exitCode = Artisan::call($command, $arguments);

            $this->assertSame(0, $exitCode, $command.' should short-circuit successfully in native leaf startup smoke mode.');
            $this->assertSame($expectedLine, trim((string) file_get_contents($this->logPath)));
            file_put_contents($this->logPath, '');
        }
    }
}
