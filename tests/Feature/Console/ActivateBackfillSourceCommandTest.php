<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ActivateBackfillSourceCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux_nntp.use_alternate_nntp_server' => false,
            'nntmux.orchestrator.backfill_probe_groups' => ['alt.binaries.movie'],
        ]);
        DB::purge();
        DB::reconnect();

        DB::statement('CREATE TABLE settings (name VARCHAR(255) PRIMARY KEY, value TEXT)');
        DB::statement('CREATE TABLE usenet_groups (
            id INTEGER PRIMARY KEY,
            name VARCHAR(255) UNIQUE,
            active INTEGER NOT NULL DEFAULT 0,
            backfill INTEGER NOT NULL DEFAULT 0,
            backfill_target INTEGER NOT NULL DEFAULT 1,
            first_record INTEGER NOT NULL DEFAULT 0,
            first_record_postdate DATETIME NULL,
            last_record INTEGER NOT NULL DEFAULT 0,
            last_record_postdate DATETIME NULL,
            last_updated DATETIME NULL
        )');
        DB::statement('CREATE TABLE short_groups (
            name VARCHAR(255),
            first_record INTEGER,
            last_record INTEGER,
            updated DATETIME NULL
        )');
        DB::table('settings')->insert([
            ['name' => 'orchestrator_mode', 'value' => 'active'],
            ['name' => 'orchestrator_lease_until', 'value' => (string) (time() + 600)],
            ['name' => 'backfillthreads', 'value' => '1'],
            ['name' => 'backfill_groups', 'value' => '1'],
        ]);
        DB::table('usenet_groups')->insert([
            'id' => 1,
            'name' => 'alt.binaries.movie',
            'active' => 0,
            'backfill' => 0,
        ]);
    }

    public function test_dry_run_verifies_the_configured_provider_without_writes(): void
    {
        $nntp = new BackfillSourceNntpFake;
        $this->app->instance(NNTPService::class, $nntp);

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie')
            ->expectsOutputToContain('Dry run passed; no database state was changed.')
            ->assertSuccessful();

        self::assertSame([['compression' => true, 'alternate' => false]], $nntp->connections);
        self::assertSame(['99990001-100000000'], $nntp->ranges);
        $this->assertDatabaseHas('usenet_groups', [
            'name' => 'alt.binaries.movie',
            'active' => 0,
            'backfill' => 0,
            'backfill_target' => 1,
            'first_record' => 0,
            'last_record' => 0,
        ]);
        self::assertSame(0, DB::table('short_groups')->count());
    }

    public function test_apply_initializes_only_the_backfill_lane(): void
    {
        $nntp = new BackfillSourceNntpFake;
        $this->app->instance(NNTPService::class, $nntp);

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie --apply')
            ->expectsOutputToContain('Activated alt.binaries.movie with active=0 and backfill=1; awaiting the provider-range refresh.')
            ->assertSuccessful();

        $this->assertDatabaseHas('usenet_groups', [
            'name' => 'alt.binaries.movie',
            'active' => 0,
            'backfill' => 1,
            'backfill_target' => 9999,
            'first_record' => 100_000_001,
            'last_record' => 100_000_000,
            'first_record_postdate' => '2026-07-13 05:22:57',
            'last_record_postdate' => '2026-07-13 05:22:57',
        ]);
        self::assertSame(0, DB::table('short_groups')->count());
    }

    public function test_repeated_apply_is_a_no_op_that_preserves_backfill_progress(): void
    {
        $nntp = new BackfillSourceNntpFake;
        $this->app->instance(NNTPService::class, $nntp);

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie --apply')->assertSuccessful();
        DB::table('usenet_groups')->where('name', 'alt.binaries.movie')->update([
            'backfill_target' => 365,
            'first_record' => 99_950_000,
            'first_record_postdate' => '2026-07-12 05:22:57',
        ]);
        $nntp->last = 100_050_000;

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie --apply')
            ->expectsOutputToContain('is already safely configured; no state was changed.')
            ->assertSuccessful();

        $this->assertDatabaseHas('usenet_groups', [
            'name' => 'alt.binaries.movie',
            'active' => 0,
            'backfill' => 1,
            'backfill_target' => 365,
            'first_record' => 99_950_000,
            'first_record_postdate' => '2026-07-12 05:22:57',
            'last_record' => 100_000_000,
        ]);
        self::assertSame(0, DB::table('short_groups')->count());
    }

    public function test_invalid_header_dates_fail_without_mutation(): void
    {
        $nntp = new BackfillSourceNntpFake;
        $nntp->date = '0000-00-00 00:00:00';
        $this->app->instance(NNTPService::class, $nntp);

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie --apply')
            ->expectsOutputToContain('has no sane date on the provider high article')
            ->assertFailed();

        $this->assertDatabaseHas('usenet_groups', [
            'name' => 'alt.binaries.movie',
            'active' => 0,
            'backfill' => 0,
            'first_record' => 0,
        ]);
        self::assertSame(0, DB::table('short_groups')->count());
    }

    public function test_sparse_sample_missing_the_exact_lower_boundary_fails_without_mutation(): void
    {
        $nntp = new BackfillSourceNntpFake;
        $nntp->omitLowerBoundary = true;
        $this->app->instance(NNTPService::class, $nntp);

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie --apply')
            ->expectsOutputToContain('does not contain both exact window boundaries')
            ->assertFailed();

        $this->assertDatabaseHas('usenet_groups', ['name' => 'alt.binaries.movie', 'backfill' => 0]);
        self::assertSame(0, DB::table('short_groups')->count());
    }

    public function test_provider_group_mismatch_fails_without_mutation(): void
    {
        $nntp = new BackfillSourceNntpFake;
        $nntp->selectedGroup = 'alt.binaries.not-the-requested-group';
        $this->app->instance(NNTPService::class, $nntp);

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie --apply')
            ->expectsOutputToContain('selected a different group')
            ->assertFailed();

        $this->assertDatabaseHas('usenet_groups', ['name' => 'alt.binaries.movie', 'backfill' => 0]);
        self::assertSame(0, DB::table('short_groups')->count());
    }

    public function test_apply_refuses_a_stale_orchestrator_lease(): void
    {
        DB::table('settings')->where('name', 'orchestrator_lease_until')->update(['value' => (string) (time() - 1)]);
        $this->app->instance(NNTPService::class, new BackfillSourceNntpFake);

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie --apply')
            ->expectsOutputToContain('requires a fresh active orchestrator lease')
            ->assertFailed();

        $this->assertDatabaseHas('usenet_groups', ['name' => 'alt.binaries.movie', 'backfill' => 0]);
    }

    public function test_apply_refuses_the_alternate_provider_until_retries_preserve_provider_identity(): void
    {
        config(['nntmux_nntp.use_alternate_nntp_server' => true]);
        $this->app->instance(NNTPService::class, new BackfillSourceNntpFake);

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie --apply')
            ->expectsOutputToContain('Apply is disabled for the alternate provider')
            ->assertFailed();

        $this->assertDatabaseHas('usenet_groups', ['name' => 'alt.binaries.movie', 'backfill' => 0]);
    }

    public function test_apply_refuses_to_reset_a_disabled_partially_initialized_group(): void
    {
        DB::table('usenet_groups')->where('name', 'alt.binaries.movie')->update([
            'first_record' => 50_000_000,
            'first_record_postdate' => '2026-07-01 00:00:00',
            'last_record' => 60_000_000,
            'last_record_postdate' => '2026-07-02 00:00:00',
        ]);
        $this->app->instance(NNTPService::class, new BackfillSourceNntpFake);

        $this->artisan('orchestrator:activate-backfill-source alt.binaries.movie --apply')
            ->expectsOutputToContain('is partially initialized; refusing an implicit cursor reset')
            ->assertFailed();

        $this->assertDatabaseHas('usenet_groups', [
            'name' => 'alt.binaries.movie',
            'backfill' => 0,
            'first_record' => 50_000_000,
            'last_record' => 60_000_000,
        ]);
    }
}

final class BackfillSourceNntpFake extends NNTPService
{
    public int $first = 1;

    public int $last = 100_000_000;

    public string $date = '2026-07-13 05:22:57';

    public string $selectedGroup = 'alt.binaries.movie';

    public bool $omitLowerBoundary = false;

    /** @var list<array{compression:bool,alternate:bool}> */
    public array $connections = [];

    /** @var list<string> */
    public array $ranges = [];

    public function __construct() {}

    public function __destruct() {}

    public function doConnect(bool $compression = true, bool $alternate = false): mixed
    {
        $this->connections[] = compact('compression', 'alternate');

        return true;
    }

    public function doQuit(bool $force = false): mixed
    {
        return true;
    }

    public function selectGroup(string $group, mixed $articles = false, bool $force = false): mixed
    {
        return [
            'group' => $this->selectedGroup,
            'first' => $this->first,
            'last' => $this->last,
        ];
    }

    public function getXOVER(string $range): array|string|NNTPService|NntpError
    {
        $this->ranges[] = $range;
        $headers = [];
        for ($number = $this->last - 9_999; $number <= $this->last; $number++) {
            if ($this->omitLowerBoundary && $number === $this->last - 9_999) {
                continue;
            }
            $offset = $number - ($this->last - 9_999);
            $file = intdiv($offset, 100);
            $part = $offset % 100 + 1;
            $headers[] = [
                'Number' => (string) $number,
                'Subject' => sprintf('[1/1] - "file-%d.rar" yEnc (%d/100)', $file, $part),
                'Date' => $this->date,
            ];
        }

        return $headers;
    }
}
