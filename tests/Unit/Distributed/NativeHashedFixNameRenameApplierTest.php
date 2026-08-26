<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Services\NameFixing\NativeHashedFixNameRenameApplier;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class NativeHashedFixNameRenameApplierTest extends TestCase
{
    public function test_it_applies_resolved_rename_candidates_through_release_update_service_without_leaking_names(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);
        $this->createReleasesTable();
        $this->insertRelease(100, 'Hash.Target.CRC.PreDB', 20);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->once())
            ->method('updateRelease')
            ->with(
                $this->callback(static fn (object $release): bool => $release->releases_id === 100
                    && $release->searchname === 'Hash.Target.CRC.PreDB'
                    && (int) $release->categories_id === 20),
                'Predb.Match.2026.1080p.BluRay.x264-GRP',
                'crc-predb',
                true,
                'CRC32, ',
                true,
                false,
                10,
            );

        $result = (new NativeHashedFixNameRenameApplier)->apply($this->resolvedReport(), $updates);

        $this->assertSame(1, $result['schema_version']);
        $this->assertSame('native-hashed-fixname-rename-apply', $result['mode']);
        $this->assertFalse($result['dry_run']);
        $this->assertSame(1, $result['release_updates_seen']);
        $this->assertSame(1, $result['release_updates_applied']);
        $this->assertSame([100], $result['release_ids']);
        $this->assertSame(1, $result['writes']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Hash.Target', $encoded);
        $this->assertStringNotContainsString('Predb.Match', $encoded);
        $this->assertStringNotContainsString('poster@example', $encoded);
    }

    public function test_it_rejects_stale_release_rows_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);
        $this->createReleasesTable();
        $this->insertRelease(100, 'Already.Changed.Release', 20);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply release [100] is stale.');

        (new NativeHashedFixNameRenameApplier)->apply($this->resolvedReport(), $updates);
    }

    public function test_it_refuses_to_run_without_test_guard(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => false]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply requires NNTMUX_NATIVE_WORKER_RENAME_APPLY_TEST_ENABLED=1.');

        (new NativeHashedFixNameRenameApplier)->apply($this->resolvedReport(), $updates);
    }

    public function test_it_rejects_blocked_release_updates_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['release_updates_blocked'] = 1;
        $payload['write_contract']['blocked_release_updates'] = [
            [
                'release_id' => 100,
                'reason' => 'missing-required-event',
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply requires all release updates to be resolved.');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_rejects_resolved_update_count_mismatches_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['release_updates_resolved'] = 2;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply resolved release update count does not match release_updates_resolved.');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_rejects_seen_update_count_mismatches_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['release_updates_seen'] = 2;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply resolved release update count does not match release_updates_seen.');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_rejects_required_event_count_mismatches_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['required_events'] = 2;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply required event count does not match resolved release updates.');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_rejects_underreported_required_search_update_counts_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['required_search_updates'] = 0;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply required search update count is lower than resolved release updates.');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_rejects_category_resolution_count_mismatches_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['category_resolution_required'] = 2;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply category resolution count does not match resolved release updates.');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_rejects_required_event_release_id_mismatches_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['resolved_release_updates'][0]['required_event']['release_id'] = 300;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply release [100] has mismatched required event context.');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_rejects_category_resolution_release_context_mismatches_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['resolved_release_updates'][0]['category_resolution']['group_id'] = 999;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply release [100] has mismatched category resolution context.');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_rejects_search_update_release_id_mismatches_before_calling_release_update_service(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['resolved_release_updates'][0]['required_search_updates'][0]['release_id'] = 300;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply release [100] has mismatched search update context.');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_rejects_duplicate_release_ids_before_partial_application(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);
        $this->createReleasesTable();
        $this->insertRelease(100, 'Hash.Target.CRC.PreDB', 20);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updateRelease');

        $payload = $this->resolvedReport();
        $payload['write_contract']['resolved_release_updates'][] = $payload['write_contract']['resolved_release_updates'][0];
        $payload['write_contract']['release_updates_seen'] = 2;
        $payload['write_contract']['release_updates_resolved'] = 2;
        $payload['write_contract']['required_events'] = 2;
        $payload['write_contract']['required_search_updates'] = 2;
        $payload['write_contract']['category_resolution_required'] = 2;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Native rename apply duplicate release ID [100].');

        (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
    }

    public function test_it_sanitizes_release_update_service_failures(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);
        $this->createReleasesTable();
        $this->insertRelease(100, 'Hash.Target.CRC.PreDB', 20);

        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->once())
            ->method('updateRelease')
            ->willThrowException(new RuntimeException('backend credentials for Predb.Match leaked'));

        try {
            (new NativeHashedFixNameRenameApplier)->apply($this->resolvedReport(), $updates);
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Native rename apply release [100] failed.', $exception->getMessage());
            $this->assertStringNotContainsString('Predb.Match', $exception->getMessage());
            $this->assertStringNotContainsString('credentials', $exception->getMessage());

            return;
        }

        $this->fail('Expected native rename apply failure.');
    }

    public function test_it_reports_partial_application_when_later_release_update_fails(): void
    {
        config(['nntmux.native_worker_rename_apply_test_enabled' => true]);
        $this->createReleasesTable();
        $this->insertRelease(100, 'Hash.Target.CRC.PreDB', 20);
        $this->insertRelease(300, 'Hash.Target.Par.Match', 20);

        $payload = $this->resolvedReport();
        $secondUpdate = $payload['write_contract']['resolved_release_updates'][0];
        $secondUpdate['release_id'] = 300;
        $secondUpdate['type'] = 'PAR2 hash, ';
        $secondUpdate['method'] = 'par-predb';
        $secondUpdate['columns'][0]['value'] = 88;
        $secondUpdate['columns'][1]['value'] = 'Predb.Second.2026.1080p.BluRay.x264-GRP';
        $secondUpdate['required_event']['release_id'] = 300;
        $secondUpdate['required_event']['old_name'] = 'Hash.Target.Par.Match';
        $secondUpdate['required_event']['new_name'] = 'Predb.Second.2026.1080p.BluRay.x264-GRP';
        $secondUpdate['category_resolution']['new_name'] = 'Predb.Second.2026.1080p.BluRay.x264-GRP';
        $secondUpdate['required_search_updates'][0]['release_id'] = 300;
        $payload['write_contract']['resolved_release_updates'][] = $secondUpdate;
        $payload['write_contract']['release_updates_seen'] = 2;
        $payload['write_contract']['release_updates_resolved'] = 2;
        $payload['write_contract']['required_events'] = 2;
        $payload['write_contract']['required_search_updates'] = 2;
        $payload['write_contract']['category_resolution_required'] = 2;

        $calledReleaseIds = [];
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->exactly(2))
            ->method('updateRelease')
            ->willReturnCallback(static function (object $release) use (&$calledReleaseIds): void {
                $calledReleaseIds[] = (int) $release->releases_id;
                if ((int) $release->releases_id === 300) {
                    throw new RuntimeException('backend credentials for Predb.Second leaked');
                }
            });

        try {
            (new NativeHashedFixNameRenameApplier)->apply($payload, $updates);
        } catch (InvalidArgumentException $exception) {
            $this->assertSame([100, 300], $calledReleaseIds);
            $this->assertSame(
                'Native rename apply release [300] failed after applying release IDs [100].',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('Predb.Second', $exception->getMessage());
            $this->assertStringNotContainsString('credentials', $exception->getMessage());

            return;
        }

        $this->fail('Expected native rename apply partial failure.');
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
                        'match_source' => 'predb-crc',
                        'columns' => [
                            ['column' => 'predb_id', 'value' => 10],
                            ['column' => 'searchname', 'value' => 'Predb.Match.2026.1080p.BluRay.x264-GRP'],
                            ['column' => 'categories_id', 'value' => 5040],
                            ['column' => 'isrenamed', 'value' => 1],
                            ['column' => 'iscategorized', 'value' => 1],
                            ['column' => 'proc_crc32', 'value' => 1],
                        ],
                        'category_resolution' => [
                            'group_id' => 1,
                            'new_name' => 'Predb.Match.2026.1080p.BluRay.x264-GRP',
                            'poster_present' => true,
                            'categories_id' => 5040,
                            'value_source' => 'CategorizationService.determineCategory(groups_id, new_title, fromname)',
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
                'single_column_updates_seen' => 1,
                'single_column_update_intents' => [
                    [
                        'release_id' => 100,
                        'column' => 'proc_crc32',
                        'value' => 1,
                        'reason' => 'crc-predb-match-confirmation',
                    ],
                ],
                'required_events' => 1,
                'required_search_updates' => 1,
                'category_resolution_required' => 1,
                'writes' => 0,
            ],
        ];
    }
}
