<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing\ExternalSources;

use App\Facades\Search;
use App\Services\NameFixing\ExternalSources\Clients\PredbOvhClient;
use App\Services\NameFixing\ExternalSources\ExternalMetadataRefreshService;
use App\Services\NameFixing\ExternalSources\ExternalMetadataSourceSummary;
use App\Services\NameFixing\ExternalSources\ExternalSourceGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

final class ExternalSourceGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'external_metadata.negative_ttl' => 86400,
            'external_metadata.breaker_threshold' => 3,
            'external_metadata.breaker_cooldown' => 900,
        ]);
        Search::shouldReceive('insertPredb')->zeroOrMoreTimes();
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
    }

    /** @param list<string> $queries */
    private function refresh(array $queries): ExternalMetadataSourceSummary
    {
        return app(ExternalMetadataRefreshService::class)
            ->refresh(['predb-ovh'], limit: 10, sleepMs: 0, queries: $queries)
            ->source('predb-ovh');
    }

    public function test_clients_use_a_short_connect_timeout(): void
    {
        config(['external_metadata.connect_timeout' => 3, 'external_metadata.timeout' => 20]);
        $client = new PredbOvhClient;
        $request = (new ReflectionMethod($client, 'request'))->invoke($client);

        self::assertSame(3, $request->getOptions()['connect_timeout']);
        self::assertSame(20, $request->getOptions()['timeout']);
    }

    public function test_a_healthy_empty_answer_is_not_asked_again(): void
    {
        Http::fake(['predb.ovh/*' => Http::response(['data' => ['rows' => []]])]);

        $this->refresh(['Some Movie 2024']);
        $second = $this->refresh(['Some Movie 2024', 'Other Movie 2023']);

        Http::assertSentCount(2);
        self::assertSame(1, $second->skipped);
        self::assertSame(1, $second->queried);
    }

    public function test_a_server_error_is_retried_next_run_and_is_not_negatively_cached(): void
    {
        config(['external_metadata.breaker_threshold' => 0]);
        Http::fake(['predb.ovh/*' => Http::response('', 503)]);

        $this->refresh(['Some Movie 2024']);
        $this->refresh(['Some Movie 2024']);

        Http::assertSentCount(2);
    }

    public function test_consecutive_failures_open_the_breaker_and_skip_the_source(): void
    {
        Http::fake(['predb.ovh/*' => Http::response('', 503)]);

        $first = $this->refresh(['a 2024', 'b 2024', 'c 2024', 'd 2024', 'e 2024']);
        Http::assertSentCount(3);
        self::assertSame(3, $first->queried);
        self::assertSame(1, $first->skipped);
        self::assertContains('circuit open; remaining lookups skipped', $first->messages);

        $second = $this->refresh(['f 2024']);
        Http::assertSentCount(3);
        self::assertSame(0, $second->queried);
    }

    public function test_the_breaker_closes_again_after_its_cooldown_and_a_success_resets_it(): void
    {
        Cache::put('nntmux:extmeta:breaker:predb-ovh', ['failures' => 3, 'open_until' => time() - 1], 3600);
        Http::fake(['predb.ovh/*' => Http::response(['data' => ['rows' => [['name' => 'Some.Movie.2024-GRP']]]])]);

        $summary = $this->refresh(['Some Movie 2024']);

        self::assertSame(1, $summary->queried);
        self::assertNull(Cache::get('nntmux:extmeta:breaker:predb-ovh'));
    }

    public function test_negatively_cached_queries_do_not_consume_the_limit(): void
    {
        Http::fake(['predb.ovh/*' => Http::response(['data' => ['rows' => []]])]);
        $service = app(ExternalMetadataRefreshService::class);

        $service->refresh(['predb-ovh'], limit: 2, sleepMs: 0, queries: ['a 2024', 'b 2024']);
        Http::assertSentCount(2);

        $summary = $service->refresh(['predb-ovh'], limit: 2, sleepMs: 0, queries: ['a 2024', 'b 2024', 'c 2024', 'd 2024', 'e 2024'])->source('predb-ovh');

        Http::assertSentCount(4);
        self::assertSame(2, $summary->queried);
        self::assertSame(2, $summary->skipped);
    }

    public function test_negatively_cached_srrdb_rows_advance_the_window(): void
    {
        Schema::create('predb_crcs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('predb_id');
            $table->string('crchash');
            $table->bigInteger('filesize')->default(0);
            $table->timestamps();
        });
        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->bigInteger('size')->default(0);
            $table->string('crc32')->default('');
            $table->timestamps();
        });
        foreach (['Rel.A-GRP', 'Rel.B-GRP', 'Rel.C-GRP'] as $title) {
            DB::table('predb')->insert(['title' => $title, 'source' => 'srrdb']);
        }
        Http::fake(['api.srrdb.com/*' => Http::response('', 404)]);
        $service = app(ExternalMetadataRefreshService::class);

        $service->refresh(['srrdb'], limit: 1, sleepMs: 0);
        $service->refresh(['srrdb'], limit: 1, sleepMs: 0);

        $titles = [];
        Http::assertSent(function (Request $request) use (&$titles): bool {
            $titles[] = $request->url();

            return true;
        });
        self::assertCount(2, array_unique($titles));
    }

    public function test_negative_cache_hit_is_recognised_when_the_store_returns_a_string(): void
    {
        $guard = new ExternalSourceGuard;
        $guard->rememberNegative('predb-ovh', 'q');
        $key = 'nntmux:extmeta:neg:predb-ovh:'.sha1('q');
        Cache::put($key, '1', 60);

        self::assertTrue($guard->isNegative('predb-ovh', 'q'));
    }

    public function test_requests_carry_the_query_so_negative_keys_are_per_query(): void
    {
        Http::fake(['predb.ovh/*' => Http::response(['data' => ['rows' => []]])]);

        $this->refresh(['one 2024', 'two 2024']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'q=one'));
        Http::assertSentCount(2);
    }
}
