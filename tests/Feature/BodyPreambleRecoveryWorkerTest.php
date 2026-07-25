<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BodyPreambleRecoveryWorkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT NULL)');
        DB::table('settings')->insert([
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'orchestrator_profile', 'value' => 'fail_safe'],
            ['name' => 'orchestrator_recovery_ok', 'value' => '0'],
            ['name' => 'orchestrator_lease_until', 'value' => '0'],
        ]);
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::table('usenet_groups')->insert(['id' => 5, 'name' => 'alt.binaries.lossless']);
        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            numberid INT,
            groups_id INT,
            attempts INT DEFAULT 0,
            recovery_kind VARCHAR(32) NULL,
            recovery_source_collection_id BIGINT NULL,
            recovery_source_binary_id BIGINT NULL,
            claim_token VARCHAR(64) NULL,
            claim_owner VARCHAR(128) NULL,
            claim_expires_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(numberid, groups_id)
        )');
        Settings::forgetCachedSettings();
    }

    public function test_worker_fails_closed_when_orchestrator_admission_is_stale(): void
    {
        $exit = Artisan::call('nntmux:body-preamble-recovery-worker', [
            'group' => 'alt.binaries.lossless',
            '--once' => true,
            '--owner' => 'test-worker',
        ]);

        self::assertSame(0, $exit);
        self::assertSame('denied', json_decode(trim(Artisan::output()), true)['status'] ?? null);
        self::assertSame(0, DB::table('missed_parts')->whereNotNull('claim_token')->count());
    }

    public function test_worker_reports_idle_without_claiming_when_admitted_queue_is_empty(): void
    {
        DB::table('settings')->where('name', 'orchestrator_recovery_ok')->update(['value' => '1']);
        DB::table('settings')->where('name', 'orchestrator_lease_until')->update(['value' => (string) (time() + 600)]);
        Settings::forgetCachedSettings();

        $exit = Artisan::call('nntmux:body-preamble-recovery-worker', [
            'group' => 'alt.binaries.lossless',
            '--once' => true,
            '--owner' => 'test-worker',
        ]);

        self::assertSame(0, $exit);
        self::assertSame('idle', json_decode(trim(Artisan::output()), true)['status'] ?? null);
    }

    public function test_worker_rejects_unknown_group(): void
    {
        self::assertSame(1, Artisan::call('nntmux:body-preamble-recovery-worker', [
            'group' => 'missing.group',
            '--once' => true,
        ]));
        self::assertStringContainsString('Unknown Usenet group', Artisan::output());
    }
}
