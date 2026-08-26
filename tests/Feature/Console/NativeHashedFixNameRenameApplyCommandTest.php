<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class NativeHashedFixNameRenameApplyCommandTest extends TestCase
{
    private string $contractPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        foreach ([
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'innerfileblacklist' => '',
        ] as $name => $value) {
            DB::table('settings')->updateOrInsert(['name' => $name], ['value' => $value]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->contractPath !== '' && is_file($this->contractPath)) {
            unlink($this->contractPath);
        }

        parent::tearDown();
    }

    public function test_it_applies_resolved_native_renames_from_file_without_leaking_titles(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);
        $this->createReleasesTable();
        $this->insertRelease(100, 'Hash.Target.CRC.PreDB', 20);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->once())->method('updateRelease');
        $this->app->instance(ReleaseUpdateService::class, $updates);

        $this->contractPath = sys_get_temp_dir().'/native-rename-apply-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->contractPath, json_encode($this->resolvedReport(), JSON_THROW_ON_ERROR));

        $output = new BufferedOutput();
        $exitCode = Artisan::call('nntmux:native-hashed-fixnames:apply-renames', [
            '--input' => $this->contractPath,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(0, $exitCode, $captured);

        $result = json_decode(trim($captured), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('native-hashed-fixname-rename-apply', $result['mode']);
        $this->assertSame(1, $result['release_updates_applied']);
        $this->assertSame([100], $result['release_ids']);
        $this->assertStringNotContainsString('Hash.Target', $captured);
        $this->assertStringNotContainsString('Predb.Match', $captured);
        $this->assertStringNotContainsString('poster@example', $captured);
    }

    public function test_it_fails_stale_rows_without_leaking_titles(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);
        $this->createReleasesTable();
        $this->insertRelease(100, 'Already.Changed.Release', 20);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');
        $this->app->instance(ReleaseUpdateService::class, $updates);

        $this->contractPath = sys_get_temp_dir().'/native-rename-apply-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->contractPath, json_encode($this->resolvedReport(), JSON_THROW_ON_ERROR));

        $output = new BufferedOutput();
        $exitCode = Artisan::call('nntmux:native-hashed-fixnames:apply-renames', [
            '--input' => $this->contractPath,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(1, $exitCode, $captured);
        $this->assertStringContainsString('Native rename apply release [100] is stale.', $captured);
        $this->assertStringNotContainsString('Hash.Target', $captured);
        $this->assertStringNotContainsString('Predb.Match', $captured);
        $this->assertStringNotContainsString('poster@example', $captured);
    }

    public function test_it_sanitizes_release_update_failures(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);
        $this->createReleasesTable();
        $this->insertRelease(100, 'Hash.Target.CRC.PreDB', 20);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->once())
            ->method('updateRelease')
            ->willThrowException(new RuntimeException('backend credentials for Predb.Match leaked'));
        $this->app->instance(ReleaseUpdateService::class, $updates);

        $this->contractPath = sys_get_temp_dir().'/native-rename-apply-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->contractPath, json_encode($this->resolvedReport(), JSON_THROW_ON_ERROR));

        $output = new BufferedOutput();
        $exitCode = Artisan::call('nntmux:native-hashed-fixnames:apply-renames', [
            '--input' => $this->contractPath,
        ], $output);
        $captured = $output->fetch();

        $this->assertSame(1, $exitCode, $captured);
        $this->assertStringContainsString('Native rename apply release [100] failed.', $captured);
        $this->assertStringNotContainsString('Hash.Target', $captured);
        $this->assertStringNotContainsString('Predb.Match', $captured);
        $this->assertStringNotContainsString('credentials', $captured);
    }

    private function createReleasesTable(): void
    {
        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('searchname');
            $table->unsignedInteger('groups_id')->default(0);
            $table->string('fromname')->nullable();
            $table->unsignedInteger('categories_id')->default(0);
            $table->unsignedInteger('predb_id')->default(0);
            $table->unsignedTinyInteger('isrenamed')->default(0);
            $table->unsignedTinyInteger('iscategorized')->default(0);
        });
    }

    private function insertRelease(int $id, string $searchName, int $categoryId): void
    {
        DB::table('releases')->insert([
            'id' => $id,
            'name' => $searchName,
            'searchname' => $searchName,
            'groups_id' => 1,
            'fromname' => 'poster@example',
            'categories_id' => $categoryId,
            'predb_id' => 0,
            'isrenamed' => 0,
            'iscategorized' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedReport(): array
    {
        return [
            'schema_version' => 1,
            'mode' => 'native-write-contract-resolve',
            'dry_run' => true,
            'writes' => 0,
            'write_contract' => [
                'release_updates_seen' => 1,
                'release_updates_resolved' => 1,
                'release_updates_blocked' => 0,
                'resolved_release_updates' => [
                    [
                        'release_id' => 100,
                        'type' => 'CRC32, ',
                        'method' => 'crc-predb',
                        'columns' => [
                            ['column' => 'predb_id', 'value' => 10],
                            ['column' => 'searchname', 'value' => 'Predb.Match.2026.1080p.BluRay.x264-GRP'],
                        ],
                        'required_event' => [
                            'release_id' => 100,
                            'old_name' => 'Hash.Target.CRC.PreDB',
                            'new_name' => 'Predb.Match.2026.1080p.BluRay.x264-GRP',
                            'old_category_id' => 20,
                            'new_category_id' => 5040,
                            'group_id' => 1,
                            'poster_present' => true,
                        ],
                        'required_search_updates' => [
                            ['release_id' => 100, 'reason' => 'release-update'],
                        ],
                    ],
                ],
                'blocked_release_updates' => [],
                'writes' => 0,
            ],
        ];
    }
}
