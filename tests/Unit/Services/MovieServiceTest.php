<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MovieInfo;
use App\Models\Release;
use App\Services\MovieService;
use App\Services\TmdbClient;
use App\Services\TraktService;
use App\Services\TvProcessing\Providers\TraktProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\ImdbScraperTestCase;

class MovieServiceTest extends ImdbScraperTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('movieinfo');
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->id();
            $table->string('imdbid')->unique();
            $table->unsignedInteger('tmdbid')->default(0);
            $table->unsignedInteger('traktid')->default(0);
            $table->string('title')->default('');
            $table->string('tagline')->default('');
            $table->string('rating', 4)->default('');
            $table->string('rtrating', 10)->default('');
            $table->string('plot')->default('');
            $table->string('year', 4)->default('');
            $table->string('genre', 64)->default('');
            $table->string('type', 32)->default('');
            $table->string('director', 64)->default('');
            $table->text('actors')->default('');
            $table->string('language', 64)->default('');
            $table->boolean('cover')->default(false);
            $table->boolean('backdrop')->default(false);
            $table->string('trailer')->default('');
            $table->timestamps();
        });

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname')->default('');
            $table->unsignedInteger('categories_id')->default(0);
            $table->string('imdbid')->nullable();
            $table->unsignedBigInteger('movieinfo_id')->nullable();
        });
    }

    #[Test]
    public function it_accepts_numeric_trakt_years_when_matching_movie_metadata(): void
    {
        Cache::flush();

        $service = $this->makeMovieServiceForTraktResponse([
            'title' => 'Example Movie',
            'year' => 2024,
            'ids' => ['trakt' => 12345],
            'overview' => 'Test overview',
            'tagline' => 'Test tagline',
            'genres' => ['Drama'],
            'rating' => 7.5,
            'votes' => 10,
            'language' => 'en',
            'runtime' => 100,
            'trailer' => '',
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Example Movie');
        $this->setMovieServiceProperty($service, 'currentYear', '2024');

        $movie = $service->fetchTraktTVProperties('8169446');

        $this->assertIsArray($movie);
        $this->assertSame('Example Movie', $movie['title']);
        $this->assertSame(2024, $movie['year']);
    }

    #[Test]
    public function it_still_rejects_mismatched_numeric_trakt_years(): void
    {
        Cache::flush();

        $service = $this->makeMovieServiceForTraktResponse([
            'title' => 'Example Movie',
            'year' => 2024,
            'ids' => ['trakt' => 12345],
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Example Movie');
        $this->setMovieServiceProperty($service, 'currentYear', '2023');

        $this->assertFalse($service->fetchTraktTVProperties('8169447'));
    }

    #[Test]
    public function it_finds_movie_info_for_imdb_ids_with_meaningful_leading_zeroes(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;

        $service->update([
            'imdbid' => '0137523',
            'title' => 'Example Movie',
            'year' => '2024',
        ]);

        $movie = $service->getMovieInfo('0137523');

        $this->assertNotNull($movie);
        $this->assertSame('0137523', $movie->imdbid);
        $this->assertSame('Example Movie', $movie->title);
    }

    #[Test]
    public function it_returns_existing_trailer_for_imdb_ids_with_meaningful_leading_zeroes(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;

        $service->update([
            'imdbid' => '0137523',
            'title' => 'Example Movie',
            'year' => '2024',
            'trailer' => 'https://example.test/embed/trailer',
        ]);

        $this->assertSame('https://example.test/embed/trailer', $service->getTrailer('0137523'));
    }

    #[Test]
    public function it_distinguishes_pending_movie_lookup_sentinels_from_failed_empty_values(): void
    {
        $this->assertTrue(imdb_id_needs_lookup(null));
        $this->assertTrue(imdb_id_needs_lookup('0'));
        $this->assertTrue(imdb_id_needs_lookup('0000000'));
        $this->assertTrue(imdb_id_needs_lookup('00000000'));
        $this->assertFalse(imdb_id_needs_lookup(''));
        $this->assertFalse(imdb_id_needs_lookup('0137523'));
    }

    #[Test]
    public function it_repairs_releases_with_an_imdb_id_but_missing_movieinfo_link(): void
    {
        $this->assertTrue(movieinfo_needs_repair('0137523', null));
        $this->assertTrue(movieinfo_needs_repair('0137523', 0));
        $this->assertFalse(movieinfo_needs_repair(null, null));
        $this->assertFalse(movieinfo_needs_repair('0137523', 42));
    }

    #[Test]
    public function it_keeps_a_found_imdb_id_when_metadata_refresh_fails(): void
    {
        Cache::flush();

        $service = new class extends MovieService
        {
            public function updateMovieInfo(string $imdbId): bool
            {
                return false;
            }
        };
        $service->echooutput = false;

        Release::query()->insert([
            'id' => 2,
            'searchname' => 'Example.Movie.2024',
            'categories_id' => 2000,
            'imdbid' => null,
            'movieinfo_id' => null,
        ]);

        $result = $service->doMovieUpdate('tt0137523', 'IMDb(scrape)', 2);

        $this->assertSame('0137523', $result);
        $this->assertSame('0137523', Release::query()->whereKey(2)->value('imdbid'));
        $this->assertNull(Release::query()->whereKey(2)->value('movieinfo_id'));
    }

    #[Test]
    public function it_retries_existing_imdb_ids_that_are_missing_movieinfo_links(): void
    {
        Cache::flush();

        $service = new class extends MovieService
        {
            public function updateMovieInfo(string $imdbId): bool
            {
                MovieInfo::query()->create([
                    'imdbid' => $imdbId,
                    'title' => 'Example Movie',
                    'year' => '2024',
                ]);

                return true;
            }
        };
        $service->echooutput = false;
        $service->movieqty = 100;

        Release::query()->insert([
            'id' => 3,
            'searchname' => 'Example.Movie.2024',
            'categories_id' => 2000,
            'imdbid' => '0137523',
            'movieinfo_id' => null,
        ]);

        $service->processMovieReleases();

        $movieInfoId = MovieInfo::query()->where('imdbid', '0137523')->value('id');

        $this->assertNotNull($movieInfoId);
        $this->assertSame((int) $movieInfoId, (int) Release::query()->whereKey(3)->value('movieinfo_id'));
    }

    #[Test]
    public function it_repairs_existing_imdb_ids_without_stale_title_constraints_or_negative_provider_cache(): void
    {
        Cache::flush();
        foreach (['tmdb_movie_', 'imdb_movie_', 'trakt_movie_', 'omdb_movie_'] as $prefix) {
            Cache::put($prefix.md5('0137523'), false, now()->addHours(6));
            Cache::put($prefix.md5('tt0137523'), false, now()->addHours(6));
        }

        $service = new class extends MovieService
        {
            public function updateMovieInfo(string $imdbId): bool
            {
                foreach (['tmdb_movie_', 'imdb_movie_', 'trakt_movie_', 'omdb_movie_'] as $prefix) {
                    Assert::assertFalse(Cache::has($prefix.md5($imdbId)));
                    Assert::assertFalse(Cache::has($prefix.md5('tt'.$imdbId)));
                }
                Assert::assertSame('', $this->currentTitle);
                Assert::assertSame('', $this->currentYear);

                MovieInfo::query()->create([
                    'imdbid' => $imdbId,
                    'title' => 'Example Movie',
                    'year' => '2024',
                ]);

                return true;
            }

            public function poisonCurrentLookupContext(): void
            {
                $this->currentTitle = 'Wrong Noisy Title';
                $this->currentYear = '1977';
            }
        };
        $service->echooutput = false;
        $service->movieqty = 100;
        $service->poisonCurrentLookupContext();

        Release::query()->insert([
            'id' => 4,
            'searchname' => 'Example.Movie.2024',
            'categories_id' => 2000,
            'imdbid' => '0137523',
            'movieinfo_id' => null,
        ]);

        $service->processMovieReleases();

        $movieInfoId = MovieInfo::query()->where('imdbid', '0137523')->value('id');

        $this->assertNotNull($movieInfoId);
        $this->assertSame((int) $movieInfoId, (int) Release::query()->whereKey(4)->value('movieinfo_id'));
    }

    #[Test]
    public function it_extracts_alternate_movie_titles_from_release_file_paths(): void
    {
        $service = new class extends MovieService
        {
            /**
             * @return list<array{title: string, year: string}>
             */
            public function candidates(string $value): array
            {
                return $this->extractMovieTitleCandidatesFromString($value);
            }
        };
        $service->echooutput = false;

        $candidates = $service->candidates('Vrah skrývá tvár (The Murderer Hides His Face) (1966)/Vrah skrývá tvár (1966).mkv');

        $this->assertContains(['title' => 'The Murderer Hides His Face', 'year' => '1966'], $candidates);
        $this->assertContains(['title' => 'Vrah skrývá tvár', 'year' => '1966'], $candidates);
    }

    #[Test]
    public function it_removes_archive_language_suffixes_from_file_title_candidates(): void
    {
        $service = new class extends MovieService
        {
            /**
             * @return list<array{title: string, year: string}>
             */
            public function candidates(string $value): array
            {
                return $this->extractMovieTitleCandidatesFromString($value);
            }
        };
        $service->echooutput = false;

        $candidates = $service->candidates('Die, Monster, Die! (1965) NL.part01.rar');

        $this->assertContains(['title' => 'Die, Monster, Die!', 'year' => '1965'], $candidates);
    }

    #[Test]
    public function it_extracts_year_first_generic_media_file_title_candidates(): void
    {
        $service = new class extends MovieService
        {
            /**
             * @return list<array{title: string, year: string}>
             */
            public function candidates(string $value): array
            {
                return $this->extractMovieTitleCandidatesFromString($value);
            }
        };
        $service->echooutput = false;

        $candidates = $service->candidates('(1949)_mkv_film_all_the_kings_men.part30.rar');

        $this->assertContains(['title' => 'all the kings men', 'year' => '1949'], $candidates);
    }

    #[Test]
    public function it_extracts_alternate_movie_titles_from_noisy_release_subjects(): void
    {
        $service = new class extends MovieService
        {
            /**
             * @return list<array{title: string, year: string}>
             */
            public function candidates(string $value): array
            {
                return $this->releaseNameTitleCandidates($value);
            }
        };
        $service->echooutput = false;

        $candidates = $service->candidates('Mann ihrer Traeume butchers wife German1991 MP4 Demi Moore Jeff Daniels [19/37] - "traum.part14.rar"');

        $this->assertContains(['title' => 'butchers wife', 'year' => '1991'], $candidates);

        $candidates = $service->candidates('"Xuder Won Espylacopa-2-Pittis AVCHD1080p.Ger.Eng.part112.rar"');

        $this->assertContains(['title' => 'Apocalypse Now Redux', 'year' => ''], $candidates);

        $candidates = $service->candidates('THE MIRACLE OF MARCELLINO (1991) 480p DVDrip Xvid MP3 AOS');

        $this->assertContains(['title' => 'Miracle of Marcellino', 'year' => '1991'], $candidates);
        $this->assertContains(['title' => 'Marcellino', 'year' => '1991'], $candidates);

        $candidates = $service->candidates('(yrraH ytriD - 1971 - BDRip > x.264 > MP4) [25/37] - "DRTYHRRY');

        $this->assertContains(['title' => 'Dirty Harry', 'year' => '1971'], $candidates);

        $candidates = $service->candidates('(esaerG - 1978 - BDRip > x.264 > MP4) [22/39] - "GRSE');

        $this->assertContains(['title' => 'Grease', 'year' => '1978'], $candidates);

        $candidates = $service->candidates('("The Immigrant" - Charlie Chaplin - 1917 - H.264 MP4) [07/15] - "The Immigrant - Charlie Chaplin - 1917.part6.rar"');

        $this->assertContains(['title' => 'The Immigrant', 'year' => '1917'], $candidates);

        $candidates = $service->candidates('The Vagabond - Charlie Chaplin - 1916');

        $this->assertContains(['title' => 'The Vagabond', 'year' => '1916'], $candidates);
    }

    #[Test]
    public function it_parses_generic_media_release_search_names(): void
    {
        Cache::flush();

        $service = new class extends MovieService
        {
            /**
             * @return array{title: string, year: string}|null
             */
            public function parsed(string $value): ?array
            {
                if (! $this->parseMovieSearchName($value)) {
                    return null;
                }

                return [
                    'title' => $this->currentTitle,
                    'year' => $this->currentYear,
                ];
            }
        };
        $service->echooutput = false;

        $this->assertSame(
            ['title' => 'The Lost Battalion', 'year' => '1919'],
            $service->parsed('an mp4 file The Lost Battalion (1919) DVDRip XviD NoGroup')
        );
        $this->assertSame(
            ['title' => 'all the kings men', 'year' => '1949'],
            $service->parsed('"(1949)_mkv_film_all_the_kings_men.part30.rar"')
        );
        $this->assertSame(
            ['title' => 'Suddenly', 'year' => '1954'],
            $service->parsed('an mkv film (1954) Suddenly DVDRip XviD NoGroup')
        );
        $this->assertSame(
            ['title' => "Adam's Rib", 'year' => '1949'],
            $service->parsed("Adam's Rib (1949) DVDRip XviD NoGroup")
        );
        $this->assertSame(
            ['title' => 'I Love You, Alice B Toklas!', 'year' => '1968'],
            $service->parsed('"I Love You, Alice B Toklas! (1968) AVC 480p.MKV.001" 1 of 7')
        );
        $this->assertSame(
            ['title' => 'Lost Jungle', 'year' => '1934'],
            $service->parsed('Lost Jungle (Clyde Beatty,1934) DVDRip XviD NoGroup')
        );
        $this->assertSame(
            ['title' => 'Rififi', 'year' => '1955'],
            $service->parsed('Rififi 1955 Criterion Rififi 1955 Criterion DVDRip XviD NoGroup')
        );
        $this->assertSame(
            ['title' => 'Texas Lady', 'year' => '1955'],
            $service->parsed('NTEXAS LADYNO (1955) DVDRip XviD NoGroup')
        );
        $this->assertSame(
            ['title' => 'Rikki-Tikki-Tavi', 'year' => ''],
            $service->parsed('Rikki-Tikki-Tavi')
        );
        $this->assertSame(
            ['title' => 'Ma And Pa Kettle At The Fair', 'year' => '1951'],
            $service->parsed('Marjorie Main-Ma And Pa Kettle At The Fair (1951) 480p DVDrip Divx MP3 AOS')
        );
        $this->assertSame(
            ['title' => 'Saludos Amigos', 'year' => ''],
            $service->parsed('Saludos Amigos - [01/12] - "Saludos Amigos.par2"')
        );
        $this->assertSame(
            ['title' => 'GIRL WITH GREEN EYES', 'year' => ''],
            $service->parsed('NZBcave/gwgeyezzz [71/74] - "GIRL WITH GREEN EYES.part70.rar"')
        );
        $this->assertSame(
            ['title' => 'ALIAS NICK AND NORA', 'year' => ''],
            $service->parsed('[55/86] - ALIAS_NICK_AND_NORA.part54.rar"Alias Nick and Nora Bonus Read"')
        );
        $this->assertSame(
            ['title' => 'Shadow of the Thin Man', 'year' => ''],
            $service->parsed('[114/117] - SHADOW_OF_THE_THIN_MAN.vol033+27.PAR2"Shadow of the Thin Man"')
        );
        $this->assertSame(
            ['title' => 'Mr Motos Gamble', 'year' => ''],
            $service->parsed('[101/102] - Mr Motos Gamble.vol068+27.PAR2"Mr Motos Gamble"')
        );
        $this->assertSame(
            ['title' => 'Mann ihrer Traeume butchers wife', 'year' => '1991'],
            $service->parsed('Mann ihrer Traeume butchers wife German1991 MP4 Demi Moore Jeff Daniels [19/37] - "traum.part14.rar"')
        );
    }

    #[Test]
    public function it_rejects_same_franchise_imdb_search_hits_that_only_match_loosely(): void
    {
        $service = new class extends MovieService
        {
            public function acceptsFor(
                string $currentTitle,
                string $currentYear,
                string $candidateTitle,
                string $candidateYear,
                int $rank = 0,
            ): bool {
                $this->currentTitle = $currentTitle;
                $this->currentYear = $currentYear;

                return $this->isImdbSearchMatchAcceptable($candidateTitle, $candidateYear, $rank);
            }
        };
        $service->echooutput = false;

        $this->assertFalse($service->acceptsFor('Ma And Pa Kettle At The Fair', '1951', 'Ma and Pa Kettle Back on the Farm', '1951'));
        $this->assertTrue($service->acceptsFor('Ma And Pa Kettle At The Fair', '1951', 'Ma and Pa Kettle at the Fair', '1951'));
        $this->assertTrue($service->acceptsFor('Ma And Pa Kettle At The Fair', '1951', 'Ma and Pa Kettle at the Fair', '1952'));
        $this->assertTrue($service->acceptsFor('Marcelino', '1955', 'The Miracle of Marcelino', '1955'));
        $this->assertTrue($service->acceptsFor('Fruehlingssinfonie', '1983', 'Spring Symphony', '1983', 0));
        $this->assertFalse($service->acceptsFor('Fruehlingssinfonie', '1983', 'Spring Symphony', '1983', 1));
        $this->assertTrue($service->acceptsFor('Jim Croce - Live In Concert', '2003', 'Have You Heard: Jim Croce - Live', '2003'));
        $this->assertTrue($service->acceptsFor('Apocalypse Now Redux', '', 'Apocalypse Now', '1979'));
    }

    #[Test]
    public function it_does_not_reject_tmdb_results_by_year_when_the_release_has_no_year(): void
    {
        Cache::flush();

        $tmdbClient = new class extends TmdbClient
        {
            public ?string $searchedYear = 'not-called';

            public function isConfigured(): bool
            {
                return true;
            }

            public function searchMovies(string $query, int $page = 1, ?string $year = null): ?array
            {
                $this->searchedYear = $year;

                return [
                    'total_results' => 1,
                    'results' => [
                        [
                            'id' => 123,
                            'title' => 'Alias Nick and Nora',
                            'release_date' => '2005-06-15',
                        ],
                    ],
                ];
            }
        };

        app()->instance(TmdbClient::class, $tmdbClient);

        $service = new class extends MovieService
        {
            public function fetchTMDBProperties(string $imdbId, bool $text = false): array|false
            {
                return [
                    'imdbid' => '1234567',
                    'title' => 'Alias Nick and Nora',
                    'tmdbid' => (int) $imdbId,
                ];
            }

            public function updateMovieInfo(string $imdbId): bool
            {
                MovieInfo::query()->create([
                    'imdbid' => $imdbId,
                    'title' => 'Alias Nick and Nora',
                    'year' => '2005',
                ]);

                return true;
            }
        };
        $service->echooutput = false;
        $this->setMovieServiceProperty($service, 'currentTitle', 'Alias Nick and Nora');
        $this->setMovieServiceProperty($service, 'currentYear', '');

        Release::query()->insert([
            'id' => 5,
            'searchname' => 'Alias Nick and Nora',
            'categories_id' => 2999,
            'imdbid' => null,
            'movieinfo_id' => null,
        ]);

        $searchTMDB = new \ReflectionMethod($service, 'searchTMDB');

        $this->assertTrue($searchTMDB->invoke($service, 5));
        $this->assertNull($tmdbClient->searchedYear);
        $this->assertSame('1234567', Release::query()->whereKey(5)->value('imdbid'));
        $this->assertNotNull(Release::query()->whereKey(5)->value('movieinfo_id'));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function makeMovieServiceForTraktResponse(array $response): MovieService
    {
        $client = new class($response) extends TraktService
        {
            /**
             * @param  array<string, mixed>  $response
             */
            public function __construct(private array $response)
            {
                parent::__construct('test_trakt_key');
            }

            /**
             * @return array<string, mixed>|null
             */
            public function getMovieSummary(string $movie, string $extended = 'min'): ?array
            {
                return $this->response;
            }
        };

        $provider = new TraktProvider;
        $provider->client = $client;

        $service = new MovieService;
        $service->traktTv = $provider;
        $service->echooutput = false;

        $this->setMovieServiceProperty($service, 'traktcheck', 'test-key');

        return $service;
    }

    private function setMovieServiceProperty(MovieService $service, string $property, mixed $value): void
    {
        $reflectionProperty = new \ReflectionProperty($service, $property);
        $reflectionProperty->setValue($service, $value);
    }
}
