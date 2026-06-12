<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing\ExternalSources;

use App\Facades\Search;
use App\Services\NameFixing\ExternalSources\ExternalMetadataRefreshService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ExternalMetadataRefreshServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('predb', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->unique();
            $table->string('filename')->default('');
            $table->string('source')->default('');
            $table->integer('requestid')->default(0);
            $table->integer('groups_id')->default(0);
            $table->integer('nuked')->default(0);
            $table->string('nukereason')->nullable();
            $table->string('category')->nullable();
            $table->string('size')->nullable();
            $table->string('files')->nullable();
            $table->dateTime('predate')->nullable();
            $table->boolean('searched')->default(false);
            $table->text('nfo')->nullable();
        });

        Schema::create('predb_crcs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('predb_id');
            $table->string('crchash');
            $table->bigInteger('filesize')->default(0);
            $table->timestamps();
            $table->unique(['predb_id', 'crchash', 'filesize']);
        });

        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->bigInteger('size')->default(0);
            $table->string('crc32')->default('');
            $table->timestamps();
        });
    }

    public function test_refresh_imports_srrdb_crc_details_for_existing_srrdb_predb_rows(): void
    {
        DB::table('predb')->insert([
            'id' => 1,
            'title' => 'Movie.Name.2026.1080p.BluRay.x264-GRP',
            'source' => 'srrdb',
        ]);

        Http::fake([
            'api.srrdb.com/v1/details/*' => Http::response([
                'name' => 'Movie.Name.2026.1080p.BluRay.x264-GRP',
                'files' => [
                    ['name' => 'movie.r00', 'size' => 15000000, 'crc' => 'aabbccdd'],
                    ['name' => 'movie.r01', 'size' => 15000000, 'crc' => 'eeff0011'],
                ],
            ]),
        ]);

        $summary = app(ExternalMetadataRefreshService::class)->refresh(['srrdb'], limit: 1, sleepMs: 0);

        $this->assertSame(1, $summary->source('srrdb')->queried);
        $this->assertSame(2, $summary->source('srrdb')->imported);
        $this->assertSame(2, DB::table('predb_crcs')->count());
        $this->assertSame('AABBCCDD', DB::table('predb_crcs')->orderBy('crchash')->value('crchash'));
    }

    public function test_refresh_searches_srrdb_archive_crc_for_postprocess_discovered_release_files(): void
    {
        DB::table('release_files')->insert([
            'releases_id' => 10,
            'name' => 'obfuscated.r00',
            'size' => 15000000,
            'crc32' => 'aabbccdd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'api.srrdb.com/v1/search/*' => Http::response([
                'results' => [
                    ['release' => 'Movie.Name.2026.1080p.BluRay.x264-GRP', 'category' => 'x264'],
                ],
            ]),
        ]);

        Search::shouldReceive('insertPredb')->once();

        $summary = app(ExternalMetadataRefreshService::class)->refresh(['srrdb'], limit: 1, sleepMs: 0);

        $this->assertSame(1, $summary->source('srrdb')->queried);
        $this->assertSame(2, $summary->source('srrdb')->imported);
        $this->assertSame('Movie.Name.2026.1080p.BluRay.x264-GRP', DB::table('predb')->value('title'));
        $this->assertSame('AABBCCDD', DB::table('predb_crcs')->value('crchash'));
        $this->assertSame(15000000, DB::table('predb_crcs')->value('filesize'));
    }

    public function test_refresh_treats_srrdb_archive_timeout_as_failed_lookup(): void
    {
        DB::table('release_files')->insert([
            'releases_id' => 10,
            'name' => 'obfuscated.r00',
            'size' => 15000000,
            'crc32' => 'aabbccdd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'api.srrdb.com/v1/search/*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $summary = app(ExternalMetadataRefreshService::class)->refresh(['srrdb'], limit: 1, sleepMs: 0);

        $this->assertSame(1, $summary->source('srrdb')->queried);
        $this->assertSame(1, $summary->source('srrdb')->failed);
        $this->assertSame(0, $summary->source('srrdb')->imported);
    }

    public function test_refresh_imports_candidate_predb_rows_without_renaming_releases(): void
    {
        Http::fake([
            'api.predb.net/*' => Http::response([
                'status' => 'success',
                'results' => 1,
                'data' => [[
                    'id' => 13926955,
                    'release' => 'Movie.Name.2026.1080p.BluRay.x264-GRP',
                    'section' => 'X264-HD',
                    'files' => 42,
                    'size' => 10240,
                    'group' => 'GRP',
                    'pretime' => 1781200000,
                ]],
            ]),
        ]);

        $summary = app(ExternalMetadataRefreshService::class)->refresh(['predb-net'], limit: 5, sleepMs: 0, queries: ['Movie Name 2026']);

        $this->assertSame(1, $summary->source('predb-net')->queried);
        $this->assertSame(1, $summary->source('predb-net')->imported);
        $this->assertSame('Movie.Name.2026.1080p.BluRay.x264-GRP', DB::table('predb')->value('title'));
        $this->assertSame('predb-net', DB::table('predb')->value('source'));
    }

    public function test_refresh_indexes_new_candidate_predb_rows_for_name_fixing_search(): void
    {
        Http::fake([
            'api.predb.net/*' => Http::response([
                'status' => 'success',
                'results' => 1,
                'data' => [[
                    'id' => 13926955,
                    'release' => 'Indexed.Movie.2026.1080p.BluRay.x264-GRP',
                    'section' => 'X264-HD',
                    'files' => 42,
                    'size' => 10240,
                    'group' => 'GRP',
                    'pretime' => 1781200000,
                ]],
            ]),
        ]);

        Search::shouldReceive('insertPredb')
            ->once()
            ->with(Mockery::on(static fn (array $parameters): bool => $parameters['id'] > 0
                && $parameters['title'] === 'Indexed.Movie.2026.1080p.BluRay.x264-GRP'
                && $parameters['filename'] === ''
                && $parameters['source'] === 'predb-net'));

        app(ExternalMetadataRefreshService::class)->refresh(['predb-net'], limit: 5, sleepMs: 0, queries: ['Indexed Movie 2026']);
    }
}
