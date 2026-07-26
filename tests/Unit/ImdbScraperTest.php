<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImdbScraper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

class ImdbScraperTest extends ImdbScraperTestCase
{
    public function test_fetch_by_id_parses_title_page_from_fixture(): void
    {
        $scraper = $this->makeScraperWithResponses([
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], file_get_contents(base_path('tests/Fixtures/imdb/title_jsonld.html')) ?: ''),
        ]);

        $data = $scraper->fetchById('1234567');

        $this->assertIsArray($data);
        $this->assertSame('1234567', $data['imdbid']);
        $this->assertSame('Example Movie', $data['title']);
        $this->assertSame('2024', $data['year']);
        $this->assertSame('An example plot goes here.', $data['plot']);
        $this->assertSame('7.3', $data['rating']);
        $this->assertSame('https://example.com/poster_from_jsonld.jpg', $data['cover']);
        $this->assertSame(['Action', 'Adventure'], $data['genre']);
        $this->assertSame(['Famous Director'], $data['director']);
        $this->assertSame(['First Actor', 'Second Actor'], $data['actors']);
        $this->assertSame('English, Spanish', $data['language']);
        $this->assertSame('movie', $data['type']);
    }

    public function test_fetch_by_id_detects_waf_challenge_and_marks_temporary_block(): void
    {
        config(['nntmux_api.imdbapi_dev_enabled' => false]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
        ]);

        $data = $scraper->fetchById('1234567');

        $this->assertFalse($data);
        $this->assertTrue($scraper->wasBlockedByWaf());
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        $this->assertSame('fallback_disabled', $scraper->getLastFallbackFailureReason());
        $this->assertNull($scraper->getLastFetchSource());
        $this->assertSame(1, Cache::get('metrics:imdb_lookup:outcome:failed:reason:waf_block:fallback:fallback_disabled:source:none'));
    }

    public function test_cached_negative_restores_concrete_failure_diagnostics(): void
    {
        config(['nntmux_api.imdbapi_dev_enabled' => false]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
        ]);

        $this->assertFalse($scraper->fetchById('1234567'));
        $this->assertFalse($scraper->fetchById('1234567'));
        $this->assertTrue($scraper->wasBlockedByWaf());
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        $this->assertSame('fallback_disabled', $scraper->getLastFallbackFailureReason());
        $this->assertNull($scraper->getLastFetchSource());
    }

    public function test_legacy_cached_negative_uses_a_stable_reason(): void
    {
        Cache::put('imdb_scrape_id_1234567', false, now()->addMinutes(30));

        $scraper = $this->makeScraperWithResponses([]);

        $this->assertFalse($scraper->fetchById('1234567'));
        $this->assertSame('cached_negative_legacy', $scraper->getLastFailureReason());
    }

    public function test_fetch_by_id_falls_back_to_imdbapi_dev_when_title_page_is_blocked(): void
    {
        config(['nntmux_api.imdbapi_dev_enabled' => true]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
            new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode([
                'id' => 'tt1234567',
                'type' => 'movie',
                'primaryTitle' => 'API Dev Movie',
                'startYear' => 2026,
                'genres' => ['Horror', 'Thriller'],
                'plot' => 'Fallback plot from imdbapi.dev.',
                'rating' => [
                    'aggregateRating' => 7.4,
                    'voteCount' => 123,
                ],
                'primaryImage' => [
                    'url' => 'https://example.com/api-dev-poster.jpg',
                ],
                'directors' => [
                    ['displayName' => 'API Director'],
                ],
                'stars' => [
                    ['displayName' => 'API Star One'],
                    ['displayName' => 'API Star Two'],
                ],
                'spokenLanguages' => [
                    ['name' => 'English'],
                    ['name' => 'Spanish'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $data = $scraper->fetchById('1234567');

        $this->assertIsArray($data);
        $this->assertTrue($scraper->wasBlockedByWaf());
        $this->assertSame('imdbapi_dev', $scraper->getLastFetchSource());
        $this->assertNull($scraper->getLastFailureReason());
        $this->assertNull($scraper->getLastFallbackFailureReason());
        $this->assertSame('1234567', $data['imdbid']);
        $this->assertSame('API Dev Movie', $data['title']);
        $this->assertSame('2026', $data['year']);
        $this->assertSame('Fallback plot from imdbapi.dev.', $data['plot']);
        $this->assertSame('7.4', $data['rating']);
        $this->assertSame('https://example.com/api-dev-poster.jpg', $data['cover']);
        $this->assertSame(['Horror', 'Thriller'], $data['genre']);
        $this->assertSame(['API Director'], $data['director']);
        $this->assertSame(['API Star One', 'API Star Two'], $data['actors']);
        $this->assertSame('English, Spanish', $data['language']);
        $this->assertSame('movie', $data['type']);
    }

    public function test_fetch_by_id_returns_false_when_imdbapi_dev_payload_lacks_title(): void
    {
        config(['nntmux_api.imdbapi_dev_enabled' => true]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
            new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode([
                'id' => 'tt1234567',
                'startYear' => 2026,
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertFalse($scraper->fetchById('1234567'));
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        $this->assertSame('fallback_invalid_payload', $scraper->getLastFallbackFailureReason());
        $this->assertNull($scraper->getLastFetchSource());
    }

    public function test_fetch_by_id_skips_imdbapi_dev_when_minimum_interval_is_active(): void
    {
        config([
            'nntmux_api.imdbapi_dev_enabled' => true,
            'nntmux_api.imdbapi_dev_min_interval_seconds' => 60,
            'nntmux_api.imdbapi_dev_cooldown_seconds' => 300,
        ]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
            new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode([
                'id' => 'tt1234567',
                'type' => 'movie',
                'primaryTitle' => 'First Fallback Movie',
                'startYear' => 2026,
            ], JSON_THROW_ON_ERROR)),
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
        ]);

        $first = $scraper->fetchById('1234567');
        $second = $scraper->fetchById('2345678');

        $this->assertIsArray($first);
        $this->assertFalse($second);
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        $this->assertSame('fallback_min_interval_active', $scraper->getLastFallbackFailureReason());
        $this->assertNull($scraper->getLastFetchSource());
        $this->assertSame(1, Cache::get('metrics:imdb_lookup:outcome:success:reason:none:fallback:none:source:imdbapi_dev'));
        $this->assertSame(1, Cache::get('metrics:imdb_lookup:outcome:failed:reason:waf_block:fallback:fallback_min_interval_active:source:none'));
    }

    public function test_fetch_by_id_skips_imdbapi_dev_when_cooldown_is_active_after_rate_limit(): void
    {
        config([
            'nntmux_api.imdbapi_dev_enabled' => true,
            'nntmux_api.imdbapi_dev_min_interval_seconds' => 0,
            'nntmux_api.imdbapi_dev_cooldown_seconds' => 300,
        ]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
            new Response(429, ['Content-Type' => 'application/json; charset=UTF-8'], '{"code":8,"message":"RATE_LIMITED"}'),
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
        ]);

        $first = $scraper->fetchById('1234567');
        $this->assertFalse($first);
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        $this->assertSame('fallback_rate_limited', $scraper->getLastFallbackFailureReason());

        $second = $scraper->fetchById('2345678');
        $this->assertFalse($second);
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        $this->assertSame('fallback_cooldown_active', $scraper->getLastFallbackFailureReason());
        $this->assertNull($scraper->getLastFetchSource());
    }

    public function test_search_parses_suggestion_json_results(): void
    {
        $scraper = $this->makeScraperWithResponses([
            new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], file_get_contents(base_path('tests/Fixtures/imdb/search_inception.json')) ?: '{}'),
        ]);

        $results = $scraper->search('Inception');

        $this->assertNotEmpty($results);
        $this->assertCount(2, $results);
        $this->assertSame('1375666', $results[0]['imdbid']);
        $this->assertSame('Inception', $results[0]['title']);
        $this->assertSame('2010', $results[0]['year']);
        $this->assertSame('movie', $results[0]['type']);
        $this->assertSame('short', $results[1]['type']);
    }

    public function test_imdbapi_dev_fallback_is_disabled_by_default(): void
    {
        $this->assertFalse(config('nntmux_api.imdbapi_dev_enabled'));
    }

    public function test_search_empty_returns_empty_array(): void
    {
        $scraper = new ImdbScraper;
        $this->assertSame([], $scraper->search(''));
    }

    public function test_fetch_by_id_falls_back_to_omdb_when_imdb_html_is_blocked_and_imdbapi_dev_is_disabled(): void
    {
        config([
            'nntmux_api.imdbapi_dev_enabled' => false,
            'nntmux_api.omdb_api_key' => 'test-omdb-key',
        ]);

        $scraper = $this->makeScraperWithResponses([
            new Response(403, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><body>awswaf challenge</body></html>'),
            new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode([
                'Title' => 'OMDB Fallback Movie',
                'Year' => '2024',
                'Rated' => 'PG-13',
                'Released' => '01 Jan 2024',
                'Runtime' => '120 min',
                'Genre' => 'Action, Adventure, Sci-Fi',
                'Director' => 'Jane Director',
                'Writer' => 'A Writer',
                'Actors' => 'Star One, Star Two, Star Three',
                'Plot' => 'A fallback plot from OMDB.',
                'Language' => 'English, Spanish',
                'Country' => 'United States',
                'Awards' => 'N/A',
                'Poster' => 'https://example.com/omdb-poster.jpg',
                'imdbRating' => '7.4',
                'imdbID' => 'tt1234567',
                'Type' => 'movie',
                'Response' => 'True',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $data = $scraper->fetchById('1234567');

        $this->assertIsArray($data);
        $this->assertTrue($scraper->wasBlockedByWaf());
        $this->assertSame('omdb', $scraper->getLastFetchSource());
        $this->assertNull($scraper->getLastFailureReason());
        $this->assertNull($scraper->getLastFallbackFailureReason());
        $this->assertSame('1234567', $data['imdbid']);
        $this->assertSame('OMDB Fallback Movie', $data['title']);
        $this->assertSame('2024', $data['year']);
        $this->assertSame('A fallback plot from OMDB.', $data['plot']);
        $this->assertSame('7.4', $data['rating']);
        $this->assertSame('https://example.com/omdb-poster.jpg', $data['cover']);
        $this->assertSame(['Action', 'Adventure', 'Sci-Fi'], $data['genre']);
        $this->assertSame(['Jane Director'], $data['director']);
        $this->assertSame(['Star One', 'Star Two', 'Star Three'], $data['actors']);
        $this->assertSame('English, Spanish', $data['language']);
        $this->assertSame('movie', $data['type']);
    }

    public function test_fetch_by_id_omdb_fallback_maps_na_sentinels_to_empty_and_series_to_tvseries(): void
    {
        config([
            'nntmux_api.imdbapi_dev_enabled' => false,
            'nntmux_api.omdb_api_key' => 'test-omdb-key',
        ]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
            new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode([
                'Title' => 'A Series',
                'Year' => '2019–2023',
                'Genre' => 'Drama, N/A, Mystery',
                'Director' => 'N/A',
                'Actors' => 'Lead Actor, N/A, Supporting Actor',
                'Plot' => 'N/A',
                'Language' => 'English',
                'Poster' => 'N/A',
                'imdbRating' => 'N/A',
                'imdbID' => 'tt2345678',
                'Type' => 'series',
                'Response' => 'True',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $data = $scraper->fetchById('2345678');

        $this->assertIsArray($data);
        $this->assertSame('omdb', $scraper->getLastFetchSource());
        $this->assertSame('A Series', $data['title']);
        $this->assertSame('2019', $data['year']);
        $this->assertSame('', $data['plot']);
        $this->assertSame('', $data['rating']);
        $this->assertSame('', $data['cover']);
        $this->assertSame(['Drama', 'Mystery'], $data['genre']);
        $this->assertSame([], $data['director']);
        $this->assertSame(['Lead Actor', 'Supporting Actor'], $data['actors']);
        $this->assertSame('English', $data['language']);
        $this->assertSame('tvseries', $data['type']);
    }

    public function test_fetch_by_id_omdb_fallback_no_ops_when_key_missing(): void
    {
        config([
            'nntmux_api.imdbapi_dev_enabled' => false,
            'nntmux_api.omdb_api_key' => '',
        ]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
        ]);

        $this->assertFalse($scraper->fetchById('1234567'));
        $this->assertTrue($scraper->wasBlockedByWaf());
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        // imdbapi.dev runs first and is disabled, recording 'fallback_disabled'.
        // The OMDB stage (no key) must not mask that earlier diagnostic.
        $this->assertSame('fallback_disabled', $scraper->getLastFallbackFailureReason());
        $this->assertNull($scraper->getLastFetchSource());
    }

    public function test_fetch_by_id_omdb_fallback_returns_false_on_response_false_payload(): void
    {
        config([
            'nntmux_api.imdbapi_dev_enabled' => false,
            'nntmux_api.omdb_api_key' => 'test-omdb-key',
        ]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
            new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode([
                'Response' => 'False',
                'Error' => 'Invalid API key!',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->assertFalse($scraper->fetchById('1234567'));
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        $this->assertSame('omdb_fallback_invalid_payload', $scraper->getLastFallbackFailureReason());
        $this->assertNull($scraper->getLastFetchSource());
    }

    public function test_fetch_by_id_omdb_fallback_returns_false_on_non_200(): void
    {
        config([
            'nntmux_api.imdbapi_dev_enabled' => false,
            'nntmux_api.omdb_api_key' => 'test-omdb-key',
        ]);

        $scraper = $this->makeScraperWithResponses([
            new Response(202, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><script>window.awsWafCookieDomainList=[];window.gokuProps={};</script></html>'),
            new Response(500, ['Content-Type' => 'text/plain'], 'internal server error'),
        ]);

        $this->assertFalse($scraper->fetchById('1234567'));
        $this->assertSame('waf_block', $scraper->getLastFailureReason());
        $this->assertSame('omdb_fallback_http_failure', $scraper->getLastFallbackFailureReason());
        $this->assertNull($scraper->getLastFetchSource());
    }

    /**
     * @param  array<int, Response>  $responses
     */
    private function makeScraperWithResponses(array $responses): ImdbScraper
    {
        $mock = new MockHandler($responses);
        $client = new Client([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        return new ImdbScraper($client);
    }
}
