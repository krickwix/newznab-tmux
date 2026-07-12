<?php

namespace Tests\Feature;

use App\Services\Binaries\MissedPartHandler;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class MissedPartHandlerTest extends TestCase
{
    private string $databasePath;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'nntmux-missed-parts-');
        if ($databasePath === false) {
            throw new \RuntimeException('Unable to create the missed-parts test database.');
        }
        $this->databasePath = $databasePath;
        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('innerfileblacklist', '')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->databasePath]);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
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
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->databasePath) && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_add_missing_parts_inserts_and_increments_duplicates(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);

        $handler->addMissingParts([100, 101, 102], 7);
        $handler->addMissingParts([101, 103], 7);
        $handler->addMissingParts([101], 8);

        $this->assertSame(4, DB::table('missed_parts')->where('groups_id', 7)->count());
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 7, 'numberid' => 100])->value('attempts'));
        $this->assertSame(2, (int) DB::table('missed_parts')->where(['groups_id' => 7, 'numberid' => 101])->value('attempts'));
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 8, 'numberid' => 101])->value('attempts'));
    }

    public function test_get_missing_parts_applies_attempt_limit_order_and_repair_limit(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 2, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            ['numberid' => 300, 'groups_id' => 9, 'attempts' => 1],
            ['numberid' => 100, 'groups_id' => 9, 'attempts' => 1],
            ['numberid' => 200, 'groups_id' => 9, 'attempts' => 3],
            ['numberid' => 50, 'groups_id' => 8, 'attempts' => 1],
        ]);

        $parts = $handler->getMissingParts(9);

        $this->assertCount(2, $parts);
        $this->assertSame([100, 300], array_map(static fn (object $part): int => (int) $part->numberid, $parts));
    }

    public function test_get_missing_parts_excludes_all_body_recovery_rows_regardless_of_claim_state(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            [
                'numberid' => 100,
                'groups_id' => 9,
                'attempts' => 1,
                'recovery_kind' => null,
                'claim_token' => null,
                'claim_expires_at' => null,
            ],
            [
                'numberid' => 200,
                'groups_id' => 9,
                'attempts' => 1,
                'recovery_kind' => 'body_preamble',
                'claim_token' => 'active',
                'claim_expires_at' => now()->addMinute(),
            ],
            [
                'numberid' => 300,
                'groups_id' => 9,
                'attempts' => 1,
                'recovery_kind' => 'body_preamble',
                'claim_token' => 'expired',
                'claim_expires_at' => now()->subMinute(),
            ],
            [
                'numberid' => 400,
                'groups_id' => 9,
                'attempts' => 1,
                'recovery_kind' => 'body_preamble',
                'claim_token' => null,
                'claim_expires_at' => null,
            ],
        ]);

        $parts = $handler->getMissingParts(9);

        $this->assertSame([100], array_map(static fn (object $part): int => (int) $part->numberid, $parts));
    }

    public function test_body_recovery_claim_is_scoped_ordered_and_reclaims_expired_leases(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            ['numberid' => 400, 'groups_id' => 9, 'attempts' => 0, 'recovery_kind' => 'body_preamble', 'claim_token' => null, 'claim_owner' => null, 'claim_expires_at' => null],
            ['numberid' => 100, 'groups_id' => 9, 'attempts' => 0, 'recovery_kind' => 'body_preamble', 'claim_token' => null, 'claim_owner' => null, 'claim_expires_at' => null],
            ['numberid' => 200, 'groups_id' => 9, 'attempts' => 0, 'recovery_kind' => null, 'claim_token' => null, 'claim_owner' => null, 'claim_expires_at' => null],
            ['numberid' => 300, 'groups_id' => 8, 'attempts' => 0, 'recovery_kind' => 'body_preamble', 'claim_token' => null, 'claim_owner' => null, 'claim_expires_at' => null],
            [
                'numberid' => 250,
                'groups_id' => 9,
                'attempts' => 0,
                'recovery_kind' => 'body_preamble',
                'claim_token' => 'expired-token',
                'claim_owner' => 'dead-worker',
                'claim_expires_at' => now()->subMinute(),
            ],
            [
                'numberid' => 150,
                'groups_id' => 9,
                'attempts' => 0,
                'recovery_kind' => 'body_preamble',
                'claim_token' => 'active-token',
                'claim_owner' => 'live-worker',
                'claim_expires_at' => now()->addMinute(),
            ],
        ]);

        $claimed = $handler->claimBodyRecoveryParts(9, 'new-token', 'worker-1', 2, now()->addMinutes(2));

        $this->assertSame([100, 250], array_map(static fn (object $part): int => (int) $part->numberid, $claimed));
        $this->assertSame(['new-token', 'new-token'], array_map(static fn (object $part): string => $part->claim_token, $claimed));
        $this->assertSame(2, DB::table('missed_parts')->where('claim_token', 'new-token')->count());
        $this->assertSame('worker-1', DB::table('missed_parts')->where('numberid', 250)->value('claim_owner'));
        $this->assertSame('active-token', DB::table('missed_parts')->where('numberid', 150)->value('claim_token'));
    }

    public function test_claim_mutations_are_token_fenced(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            ['numberid' => 100, 'groups_id' => 9, 'attempts' => 0, 'recovery_kind' => 'body_preamble'],
            ['numberid' => 200, 'groups_id' => 9, 'attempts' => 0, 'recovery_kind' => 'body_preamble'],
            ['numberid' => 300, 'groups_id' => 9, 'attempts' => 0, 'recovery_kind' => 'body_preamble'],
        ]);
        $first = $handler->claimBodyRecoveryParts(9, 'token-a', 'worker-a', 2, now()->addMinute());
        $third = $handler->claimBodyRecoveryParts(9, 'token-b', 'worker-b', 2, now()->addMinute());
        $firstIds = array_map(static fn (object $part): int => (int) $part->id, $first);
        $thirdId = (int) $third[0]->id;

        $this->assertSame(2, $handler->countExistingClaimedIds([...$firstIds, $thirdId], 'token-a'));
        $this->assertSame(0, $handler->incrementClaimedAttempts([$firstIds[0], $thirdId], 'wrong-token'));
        $this->assertSame(1, $handler->incrementClaimedAttempts([$firstIds[0], $thirdId], 'token-a'));
        $this->assertSame(1, (int) DB::table('missed_parts')->where('id', $firstIds[0])->value('attempts'));
        $this->assertSame(0, (int) DB::table('missed_parts')->where('id', $thirdId)->value('attempts'));

        $this->assertSame(0, $handler->removeRepairedClaimedParts([$firstIds[1]], 'wrong-token'));
        $this->assertSame(1, $handler->removeRepairedClaimedParts([$firstIds[1]], 'token-a'));
        $this->assertSame(1, $handler->releaseClaimedParts([$firstIds[0], $thirdId], 'token-a'));
        $this->assertNull(DB::table('missed_parts')->where('id', $firstIds[0])->value('claim_token'));
        $this->assertSame('token-b', DB::table('missed_parts')->where('id', $thirdId)->value('claim_token'));
    }

    public function test_expired_claim_cannot_mutate_or_renew_and_is_not_counted_as_owned(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            'numberid' => 100,
            'groups_id' => 9,
            'attempts' => 0,
            'recovery_kind' => 'body_preamble',
            'claim_token' => 'stale-token',
            'claim_owner' => 'stale-worker',
            'claim_expires_at' => now()->subSecond(),
        ]);
        $id = (int) DB::table('missed_parts')->value('id');

        $this->assertSame(0, $handler->countExistingClaimedIds([$id], 'stale-token'));
        $this->assertSame(0, $handler->renewClaimedParts([$id], 'stale-token', now()->addMinute()));
        $this->assertSame(0, $handler->incrementClaimedAttempts([$id], 'stale-token'));
        $this->assertSame(0, $handler->removeRepairedClaimedParts([$id], 'stale-token'));
        $this->assertSame(0, $handler->releaseClaimedParts([$id], 'stale-token'));
        $this->assertTrue(DB::table('missed_parts')->where('id', $id)->exists());
    }

    public function test_deferred_claim_is_unavailable_until_its_cooldown_expires(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            'numberid' => 100,
            'groups_id' => 9,
            'attempts' => 0,
            'recovery_kind' => 'body_preamble',
        ]);
        $claimed = $handler->claimBodyRecoveryParts(9, 'token-a', 'worker-a', 1, now()->addMinute());
        $id = (int) $claimed[0]->id;

        $this->assertSame(1, $handler->deferClaimedParts([$id], 'token-a', now()->addSeconds(20)));
        $this->assertSame([], $handler->claimBodyRecoveryParts(9, 'token-b', 'worker-b', 1, now()->addMinute()));

        DB::table('missed_parts')->where('id', $id)->update(['claim_expires_at' => now()->subSecond()]);
        $reclaimed = $handler->claimBodyRecoveryParts(9, 'token-b', 'worker-b', 1, now()->addMinute());
        $this->assertCount(1, $reclaimed);
        $this->assertSame('token-b', $reclaimed[0]->claim_token);
    }

    public function test_claim_migration_is_additive_and_reversible_on_sqlite(): void
    {
        DB::statement('DROP TABLE missed_parts');
        DB::statement('CREATE TABLE missed_parts (
            id INTEGER PRIMARY KEY,
            numberid INT,
            groups_id INT,
            attempts INT DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE(numberid, groups_id)
        )');

        $migration = require database_path('migrations/2026_07_12_180000_add_body_recovery_claims_to_missed_parts.php');
        $migration->up();

        $this->assertTrue(DB::getSchemaBuilder()->hasColumns('missed_parts', [
            'recovery_kind',
            'recovery_source_collection_id',
            'recovery_source_binary_id',
            'claim_token',
            'claim_owner',
            'claim_expires_at',
        ]));
        $this->assertTrue(DB::table('sqlite_master')->where('name', 'ix_missed_parts_recovery_claim')->exists());
        $this->assertTrue(DB::table('sqlite_master')->where('name', 'ix_missed_parts_claim_token_id')->exists());

        $migration->down();

        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('missed_parts', 'claim_token'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('missed_parts', 'numberid'));
    }

    public function test_remove_increment_count_and_cleanup_paths(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            ['numberid' => 10, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 20, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 30, 'groups_id' => 1, 'attempts' => 2],
            ['numberid' => 20, 'groups_id' => 2, 'attempts' => 1],
        ]);

        $handler->removeRepairedParts([20, 999], 1);
        $this->assertFalse(DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 20])->exists());
        $this->assertTrue(DB::table('missed_parts')->where(['groups_id' => 2, 'numberid' => 20])->exists());

        $handler->incrementAttempts(1, 30);
        $this->assertSame(2, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 10])->value('attempts'));
        $this->assertSame(3, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 30])->value('attempts'));
        $this->assertSame(2, $handler->getCount(1, 30));

        $handler->decrementAttempts([10, 999], 1);
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 10])->value('attempts'));

        $handler->cleanupExhaustedParts(1);
        $this->assertSame([10], DB::table('missed_parts')->where('groups_id', 1)->pluck('numberid')->all());
    }

    public function test_increment_range_attempts_handles_single_and_multi_article_ranges(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3);
        DB::table('missed_parts')->insert([
            ['numberid' => 10, 'groups_id' => 1, 'attempts' => 0],
            ['numberid' => 11, 'groups_id' => 1, 'attempts' => 0],
            ['numberid' => 12, 'groups_id' => 1, 'attempts' => 0],
            ['numberid' => 12, 'groups_id' => 2, 'attempts' => 0],
        ]);

        $handler->incrementRangeAttempts(1, 10, 10);
        $handler->incrementRangeAttempts(1, 11, 12);

        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 10])->value('attempts'));
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 11])->value('attempts'));
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 12])->value('attempts'));
        $this->assertSame(0, (int) DB::table('missed_parts')->where(['groups_id' => 2, 'numberid' => 12])->value('attempts'));
    }

    public function test_exact_cohort_accounting_ignores_concurrently_replenished_rows(): void
    {
        $handler = new MissedPartHandler(partRepairLimit: 10, partRepairMaxTries: 3, chunkSize: 50);
        DB::table('missed_parts')->insert([
            ['numberid' => 10, 'groups_id' => 1, 'attempts' => 0],
            ['numberid' => 20, 'groups_id' => 1, 'attempts' => 0],
        ]);
        $cohortIds = DB::table('missed_parts')->where('groups_id', 1)->orderBy('numberid')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        DB::table('missed_parts')->insert(['numberid' => 15, 'groups_id' => 1, 'attempts' => 0]);
        DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 10])->delete();
        DB::table('missed_parts')->insert(['numberid' => 10, 'groups_id' => 1, 'attempts' => 0]);
        $handler->incrementAttemptsForIds($cohortIds, 1);

        $this->assertSame(1, $handler->countExistingIds($cohortIds, 1));
        $this->assertSame(0, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 10])->value('attempts'));
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 20])->value('attempts'));
        $this->assertSame(0, (int) DB::table('missed_parts')->where(['groups_id' => 1, 'numberid' => 15])->value('attempts'));
    }
}
