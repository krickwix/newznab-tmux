<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Settings;
use App\Services\Nzb\NzbBacklogCreationService;
use App\Services\Orchestrator\PipelineSnapshotRepository;
use App\Services\Orchestrator\PrometheusSafetySignalProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CurrentForwardCohortObservationMariaDbTest extends TestCase
{
    public function test_mariadb_observation_runs_in_a_top_level_consistent_transaction(): void
    {
        if (getenv('NNTMUX_MARIADB_TEST') !== '1') {
            self::markTestSkipped('Set NNTMUX_MARIADB_TEST=1 with the isolated MariaDB connection variables.');
        }

        config([
            'database.default' => 'mariadb',
            'database.connections.mariadb.host' => (string) getenv('NNTMUX_MARIADB_HOST'),
            'database.connections.mariadb.port' => (string) (getenv('NNTMUX_MARIADB_PORT') ?: '3306'),
            'database.connections.mariadb.database' => (string) getenv('NNTMUX_MARIADB_DATABASE'),
            'database.connections.mariadb.username' => (string) getenv('NNTMUX_MARIADB_USERNAME'),
            'database.connections.mariadb.password' => (string) getenv('NNTMUX_MARIADB_PASSWORD'),
        ]);
        DB::purge();
        DB::reconnect();
        $tables = ['binaries', 'collections', 'categories', 'releases', 'usenet_groups', 'settings'];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        try {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name', 25)->primary();
                $table->text('value');
            });
            Schema::create('usenet_groups', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->string('name')->unique();
            });
            Schema::create('categories', function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('root_categories_id')->nullable();
            });
            Schema::create('releases', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedInteger('groups_id');
                $table->unsignedInteger('categories_id')->nullable();
                $table->boolean('nzbstatus');
                $table->dateTime('postdate');
                $table->unsignedBigInteger('size');
                $table->string('name')->nullable();
                $table->string('searchname')->nullable();
            });
            Schema::create('collections', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedInteger('groups_id');
                $table->unsignedBigInteger('releases_id')->nullable();
                $table->unsignedTinyInteger('filecheck');
                $table->dateTime('date');
                $table->unsignedInteger('totalfiles');
                $table->dateTime('dateadded');
            });
            Schema::create('binaries', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('collections_id');
                $table->unsignedInteger('filenumber');
                $table->unsignedInteger('totalparts');
                $table->unsignedInteger('currentparts');
            });
            DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
            Settings::query()->insert([
                ['name' => 'completionpercent', 'value' => '94'],
                ['name' => 'delaytime', 'value' => '2'],
            ]);
            Settings::forgetCachedSettings();
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            config()->set('database.connections.mariadb_writer', config('database.connections.mariadb'));
            DB::purge('mariadb_writer');
            DB::reconnect('mariadb_writer');
            $concurrentInsertObserved = false;
            DB::listen(function (QueryExecuted $query) use (&$concurrentInsertObserved): void {
                if ($concurrentInsertObserved
                    || ! str_contains($query->sql, 'SELECT COUNT(*)')
                    || ! str_contains($query->sql, 'FROM releases r')
                ) {
                    return;
                }
                $concurrentInsertObserved = true;
                DB::connection('mariadb_writer')->table('releases')->insert([
                    'id' => 1,
                    'groups_id' => 1,
                    'categories_id' => null,
                    'nzbstatus' => 1,
                    'postdate' => '2026-07-17 12:02:00',
                    'size' => 200_000_000,
                    'name' => 'late cohort release',
                    'searchname' => 'late cohort release',
                ]);
            });

            $repository = new PipelineSnapshotRepository(
                new PrometheusSafetySignalProvider,
                app(NzbBacklogCreationService::class),
            );
            $observation = $repository->currentForwardCohortObservation(
                'alt.test',
                0,
                '2026-07-17 12:00:00',
                '2026-07-17 12:05:00',
            );

            self::assertSame(0, DB::connection()->transactionLevel());
            self::assertTrue($concurrentInsertObserved);
            self::assertSame(0, $observation['release_count']);
            self::assertSame(0, $observation['release_high']);
            self::assertSame(0, $observation['pending_collections']);
            self::assertSame(['target' => 0, 'non_target' => 0, 'uncategorized' => 0], $observation['counts']);
            self::assertSame(['target' => 0, 'non_target' => 0, 'uncategorized' => 0], $observation['bytes']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $observation['hash']);
            self::assertSame(1, DB::table('releases')->count());
        } finally {
            Settings::forgetCachedSettings();
            DB::purge('mariadb_writer');
            foreach ($tables as $table) {
                Schema::dropIfExists($table);
            }
        }
    }
}
