<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Services\Categorization\CategorizationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class NativeWriteContractResolveCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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

    public function test_it_resolves_a_full_native_json_report_from_file(): void
    {
        Search::shouldReceive('updateRelease')->never();

        $categorization = Mockery::mock(CategorizationService::class);
        $categorization
            ->shouldReceive('determineCategory')
            ->once()
            ->with(4, 'Show.Name.S03E05.720p.HDTV.x264-GROUP', 'poster@example.com')
            ->andReturn(['categories_id' => 5040]);
        $this->app->instance(CategorizationService::class, $categorization);

        $this->contractPath = sys_get_temp_dir().'/native-write-contract-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->contractPath, json_encode([
            'schema_version' => 1,
            'mode' => 'native-worker-dry-run',
            'dry_run' => true,
            'native_worker' => [
                'job' => 'hashed-fixnames',
                'lock' => 'nntmux:distributed-worker:hashed-fixnames',
                'commands' => 10,
            ],
            'hashed_fixnames' => [
                'write_contract' => [
                    'release_updates' => [
                        [
                            'release_id' => 100,
                            'type' => 'CRC32, ',
                            'method' => 'crc-predb',
                            'match_source' => 'predb-crc',
                            'columns' => [
                                ['column' => 'searchname', 'value' => 'Show.Name.S03E05.720p.HDTV.x264-GROUP'],
                                [
                                    'column' => 'categories_id',
                                    'value' => null,
                                    'value_source' => 'CategorizationService.determineCategory(groups_id, new_title, fromname)',
                                ],
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
                    ],
                    'category_resolution_required' => 1,
                    'writes' => 0,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $exitCode = Artisan::call('nntmux:native-write-contract:resolve', [
            '--input' => $this->contractPath,
        ]);

        $this->assertSame(0, $exitCode);

        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('native-write-contract-resolve', $result['mode']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['writes']);
        $this->assertSame(5040, $result['write_contract']['resolved_release_updates'][0]['category_resolution']['categories_id']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('poster@example.com', $encoded);
        $this->assertStringNotContainsString('DB_PASSWORD', $encoded);
        $this->assertStringNotContainsString('mysql-dsn', $encoded);
        $this->assertStringNotContainsString('redis_key', $encoded);
    }

    public function test_it_rejects_oversized_input_files_before_decoding(): void
    {
        $this->contractPath = sys_get_temp_dir().'/native-write-contract-oversized-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($this->contractPath, str_repeat('x', 1048577));

        $exitCode = Artisan::call('nntmux:native-write-contract:resolve', [
            '--input' => $this->contractPath,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Input JSON exceeds the maximum native write contract report size.',
            Artisan::output(),
        );
    }
}
