<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImdbScraper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The in-cluster IMDb metadata service is consulted before any web source: it answers from the
 * local dataset in milliseconds and cannot be WAF-blocked. Plot and cover are not in the IMDb
 * dumps, so they stay empty and updateMovieInfo fills them from TMDB/Trakt/OMDb as it always has.
 */
class ImdbScraperLocalMetadataTest extends ImdbScraperTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        config(['nntmux_api.local_imdb_metadata_url' => '']);
        parent::tearDown();
    }

    public function test_local_service_answers_before_any_web_source(): void
    {
        config(['nntmux_api.local_imdb_metadata_url' => 'http://imdb-metadata.media.svc']);

        // Only ONE response is queued. If the scraper touched imdb.com or any fallback after the
        // local hit, the mock would throw on the unexpected second request.
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'imdbId' => 'tt5699154',
                'title' => "C'est la vie!",
                'originalTitle' => 'Le sens de la fête',
                'year' => 2017,
                'runtimeMinutes' => 117,
                'rating' => 6.9,
                'votes' => 13338,
                'titleType' => 'movie',
                'genres' => ['Comedy', 'Drama', 'Romance'],
                'directors' => ['Olivier Nakache', 'Éric Toledano'],
                'actors' => ['Jean-Pierre Bacri', 'Jean-Paul Rouve'],
            ])),
        ]);
        $scraper = new ImdbScraper(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $scraper->fetchById('5699154');

        $this->assertIsArray($result);
        $this->assertSame("C'est la vie!", $result['title']);
        $this->assertSame('2017', $result['year']);
        $this->assertSame('6.9', $result['rating']);
        $this->assertSame(['Comedy', 'Drama', 'Romance'], $result['genre']);
        $this->assertSame(['Olivier Nakache', 'Éric Toledano'], $result['director']);
        $this->assertSame('movie', $result['type']);
        $this->assertSame('local_metadata', $scraper->getLastFetchSource());
        $this->assertSame(0, $mock->count(), 'exactly the local request was made');
    }

    public function test_a_local_miss_falls_through_to_the_existing_chain(): void
    {
        config(['nntmux_api.local_imdb_metadata_url' => 'http://imdb-metadata.media.svc']);

        $html = <<<'HTML'
<!doctype html>
<html lang="en"><head>
<script type="application/ld+json">{"@type":"Movie","name":"Fallback Film","datePublished":"1999-03-31"}</script>
</head><body></body></html>
HTML;
        $mock = new MockHandler([
            new Response(404),                                   // local service: not in the dataset
            new Response(200, ['Content-Type' => 'text/html'], $html), // imdb.com scrape proceeds
        ]);
        $scraper = new ImdbScraper(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $scraper->fetchById('0133093');

        $this->assertIsArray($result);
        $this->assertSame('Fallback Film', $result['title']);
        $this->assertSame('imdb_html', $scraper->getLastFetchSource());
    }

    public function test_a_tv_series_id_is_typed_so_the_movie_gate_rejects_it(): void
    {
        config(['nntmux_api.local_imdb_metadata_url' => 'http://imdb-metadata.media.svc']);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'title' => 'Breaking Bad',
                'year' => 2008,
                'titleType' => 'tvSeries',
                'genres' => [],
                'directors' => [],
                'actors' => [],
            ])),
        ]);
        $scraper = new ImdbScraper(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $scraper->fetchById('0903747');

        $this->assertIsArray($result);
        $this->assertSame('tvSeries', $result['type']);
    }

    public function test_a_video_id_is_not_typed_as_a_movie(): void
    {
        // IMDb's "video" type is overwhelmingly shorts, adult content and music videos --
        // 135,511 Short and 114,651 Adult of 324,200 in the dataset, with only 1,564 above
        // 1,000 votes. MovieService::isExplicitNonMovieMediaType() has always listed it as
        // non-movie; the local provider must not be the one source that lets them through.
        config(['nntmux_api.local_imdb_metadata_url' => 'http://imdb-metadata.media.svc']);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'title' => 'Some Direct To Video Thing',
                'year' => 2011,
                'titleType' => 'video',
                'genres' => [],
                'directors' => [],
                'actors' => [],
            ])),
        ]);
        $scraper = new ImdbScraper(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $scraper->fetchById('1234567');

        $this->assertIsArray($result);
        $this->assertNotSame('movie', $result['type']);
        $this->assertSame('video', $result['type']);
    }

    public function test_a_tv_movie_id_is_still_typed_as_a_movie(): void
    {
        // The counterpart: tvMovie is a film the gate accepts, so collapsing it to 'movie'
        // is correct and must survive the video change.
        config(['nntmux_api.local_imdb_metadata_url' => 'http://imdb-metadata.media.svc']);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'title' => 'A Television Film',
                'year' => 1999,
                'titleType' => 'tvMovie',
                'genres' => [],
                'directors' => [],
                'actors' => [],
            ])),
        ]);
        $scraper = new ImdbScraper(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $scraper->fetchById('7654321');

        $this->assertIsArray($result);
        $this->assertSame('movie', $result['type']);
    }

    public function test_unconfigured_local_service_changes_nothing(): void
    {
        config(['nntmux_api.local_imdb_metadata_url' => '']);

        $html = <<<'HTML'
<!doctype html>
<html lang="en"><head>
<script type="application/ld+json">{"@type":"Movie","name":"Direct Film","datePublished":"2001-01-01"}</script>
</head><body></body></html>
HTML;
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $html),
        ]);
        $scraper = new ImdbScraper(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $scraper->fetchById('0208092');

        $this->assertIsArray($result);
        $this->assertSame('imdb_html', $scraper->getLastFetchSource());
    }
}
