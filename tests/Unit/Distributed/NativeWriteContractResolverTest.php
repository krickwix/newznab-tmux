<?php

declare(strict_types=1);

namespace Tests\Unit\Distributed;

use App\Facades\Search;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NativeHashedFixNameWriteContractResolver;
use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class NativeWriteContractResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_resolves_php_owned_category_event_and_search_side_effects_without_writes(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $categorization = Mockery::mock(CategorizationService::class);
        $categorization
            ->shouldReceive('determineCategory')
            ->once()
            ->with(4, 'Show.Name.S03E05.720p.HDTV.x264-GROUP', 'poster@example.com')
            ->andReturn(['categories_id' => 5040]);

        $result = (new NativeHashedFixNameWriteContractResolver($categorization))->resolve([
            'release_updates' => [
                [
                    'release_id' => 100,
                    'type' => 'CRC32, ',
                    'method' => 'crc-predb',
                    'match_source' => 'predb-crc',
                    'columns' => [
                        ['column' => 'videos_id', 'value' => 0],
                        ['column' => 'predb_id', 'value' => 10],
                        ['column' => 'searchname', 'value' => 'Show.Name.S03E05.720p.HDTV.x264-GROUP'],
                        [
                            'column' => 'categories_id',
                            'value' => null,
                            'value_source' => 'CategorizationService.determineCategory(groups_id, new_title, fromname)',
                        ],
                        ['column' => 'fromname', 'value' => 'poster@example.com'],
                        ['column' => 'redis_key', 'value' => 'nntmux_database_secret_physical_key'],
                        ['column' => 'isrenamed', 'value' => 1],
                    ],
                ],
            ],
            'required_events' => [
                [
                    'release_id' => 100,
                    'old_name' => 'd41d8cd98f00b204e9800998ecf8427e',
                    'new_name' => 'Show.Name.S03E05.720p.HDTV.x264-GROUP',
                    'old_category_id' => 20,
                    'group_id' => 4,
                    'poster' => 'poster@example.com',
                ],
            ],
            'search_updates' => [
                ['release_id' => 100, 'reason' => 'release-update'],
                ['release_id' => 100, 'reason' => 'crc-predb-match-confirmation'],
            ],
            'category_resolution_required' => 1,
            'writes' => 0,
        ]);

        $this->assertSame(1, $result['schema_version']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['writes']);
        $this->assertSame(1, $result['write_contract']['release_updates_seen']);
        $this->assertSame(1, $result['write_contract']['release_updates_resolved']);
        $this->assertSame(0, $result['write_contract']['release_updates_blocked']);
        $this->assertSame(1, $result['write_contract']['required_events']);
        $this->assertSame(2, $result['write_contract']['required_search_updates']);

        $resolved = $result['write_contract']['resolved_release_updates'][0];

        $this->assertSame(100, $resolved['release_id']);
        $this->assertSame(5040, $resolved['category_resolution']['categories_id']);
        $this->assertSame('CategorizationService.determineCategory(groups_id, new_title, fromname)', $resolved['category_resolution']['value_source']);
        $this->assertSame([
            'release_id' => 100,
            'old_name' => 'd41d8cd98f00b204e9800998ecf8427e',
            'new_name' => 'Show.Name.S03E05.720p.HDTV.x264-GROUP',
            'old_category_id' => 20,
            'new_category_id' => 5040,
            'group_id' => 4,
            'poster_present' => true,
        ], $resolved['required_event']);
        $this->assertSame([
            ['release_id' => 100, 'reason' => 'release-update'],
            ['release_id' => 100, 'reason' => 'crc-predb-match-confirmation'],
        ], $resolved['required_search_updates']);

        $categoryColumn = collect($resolved['columns'])
            ->firstWhere('column', 'categories_id');

        $this->assertSame(5040, $categoryColumn['value']);
        $this->assertSame('CategorizationService.determineCategory(groups_id, new_title, fromname)', $categoryColumn['value_source']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('poster@example.com', $encoded);
        $this->assertStringNotContainsString('"column":"fromname"', $encoded);
        $this->assertStringNotContainsString('redis_key', $encoded);
        $this->assertStringNotContainsString('nntmux_database_secret_physical_key', $encoded);
    }

    public function test_it_blocks_release_updates_when_required_event_context_is_missing(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldNotReceive('determineCategory');

        $result = (new NativeHashedFixNameWriteContractResolver($categorization))->resolve([
            'release_updates' => [
                [
                    'release_id' => 200,
                    'type' => 'PAR2 hash, ',
                    'method' => 'par-hash',
                    'match_source' => 'release-files',
                    'columns' => [
                        ['column' => 'searchname', 'value' => 'Movie.Title.2024.1080p-GROUP'],
                        [
                            'column' => 'categories_id',
                            'value' => null,
                            'value_source' => 'CategorizationService.determineCategory(groups_id, new_title, fromname)',
                        ],
                    ],
                ],
            ],
            'required_events' => [],
            'search_updates' => [],
            'category_resolution_required' => 1,
            'writes' => 0,
        ]);

        $this->assertSame(0, $result['write_contract']['release_updates_resolved']);
        $this->assertSame(1, $result['write_contract']['release_updates_blocked']);
        $this->assertSame([
            'release_id' => 200,
            'reason' => 'missing-required-event-context',
        ], $result['write_contract']['blocked_release_updates'][0]);
    }

    public function test_it_preserves_single_column_update_intent_without_category_resolution(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldNotReceive('determineCategory');

        $result = (new NativeHashedFixNameWriteContractResolver($categorization))->resolve([
            'release_updates' => [],
            'single_column_updates' => [
                [
                    'release_id' => 300,
                    'column' => 'proc_hash16k',
                    'value' => 1,
                    'reason' => 'par-hash-status-only',
                ],
            ],
            'required_events' => [],
            'search_updates' => [
                ['release_id' => 300, 'reason' => 'par-hash-status-only'],
            ],
            'category_resolution_required' => 0,
            'writes' => 0,
        ]);

        $this->assertSame(0, $result['write_contract']['release_updates_resolved']);
        $this->assertSame(1, $result['write_contract']['single_column_updates_seen']);
        $this->assertSame([
            'release_id' => 300,
            'column' => 'proc_hash16k',
            'value' => 1,
            'reason' => 'par-hash-status-only',
        ], $result['write_contract']['single_column_update_intents'][0]);
        $this->assertSame(1, $result['write_contract']['required_search_updates']);
    }

    public function test_it_rejects_non_integer_zero_writes_values(): void
    {
        $categorization = Mockery::mock(CategorizationService::class);
        $categorization->shouldNotReceive('determineCategory');
        $resolver = new NativeHashedFixNameWriteContractResolver($categorization);

        foreach ([null, false, '0', 'not-zero', 1] as $writes) {
            try {
                $resolver->resolve([
                    'release_updates' => [],
                    'required_events' => [],
                    'search_updates' => [],
                    'category_resolution_required' => 0,
                    'writes' => $writes,
                ]);
                $this->fail('Expected invalid writes value to be rejected: '.var_export($writes, true));
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Native write contract must be read-only with writes=0.', $exception->getMessage());
            }
        }
    }
}
