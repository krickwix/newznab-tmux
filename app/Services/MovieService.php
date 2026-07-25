<?php

declare(strict_types=1);

namespace App\Services;

use aharen\OMDbAPI;
use App\Facades\Search;
use App\Models\Category;
use App\Models\MovieInfo;
use App\Models\Release;
use App\Models\Settings;
use App\Services\Movies\MovieLookupState;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Support\ReleaseSearchIndexSync;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service class for movie data fetching and processing.
 */
class MovieService
{
    protected const MATCH_PERCENT = 75;

    /** Lower threshold when year matches exactly; allows matching by alternate title (e.g. "Girl Cops" vs primary). */
    protected const MATCH_PERCENT_ALT_TITLE = 55;

    protected const YEAR_MATCH_PERCENT = 80;

    /**
     * Title similarity at/above which a near-exact title match is trusted even
     * when the release-name year disagrees with the catalog year (re-release /
     * compilation year drift). Kept high to avoid false positives.
     */
    protected const TITLE_YEAR_OVERRIDE_PERCENT = 92;

    protected const IDENTITY_MOVIE = 'movie';

    protected const IDENTITY_NON_MOVIE = 'non_movie';

    protected const IDENTITY_MISMATCH = 'mismatch';

    protected const IDENTITY_UNKNOWN = 'unknown';

    protected string $currentTitle = '';

    protected string $currentYear = '';

    protected string $currentRelID = '';

    protected ?string $activeMovieClaimToken = null;

    protected bool $movieIdentityQualified = false;

    protected string $showPasswords;

    protected ReleaseImageService $releaseImage;

    protected Client $client;

    protected string $lookuplanguage;

    public FanartTvService $fanart;

    public ?string $fanartapikey;

    public ?string $omdbapikey;

    public bool $imdburl;

    public int $movieqty;

    public bool $echooutput;

    public string $imgSavePath;

    public string $service;

    public ?TraktProvider $traktTv = null;

    public ?OMDbAPI $omdbApi = null;

    protected ?string $traktcheck;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $this->releaseImage = new ReleaseImageService;
        $traktApiKey = trim((string) config('nntmux_api.trakttv_api_key', ''));
        $this->traktcheck = $traktApiKey !== '' ? $traktApiKey : null;
        if ($this->traktcheck !== null) {
            $this->traktTv = new TraktProvider;
        }
        $this->client = new Client;
        $fanartApiKey = trim((string) config('nntmux_api.fanarttv_api_key', ''));
        $this->fanartapikey = $fanartApiKey !== '' ? $fanartApiKey : null;
        $this->fanart = new FanartTvService($this->fanartapikey);
        $omdbApiKey = trim((string) config('nntmux_api.omdb_api_key', ''));
        $this->omdbapikey = $omdbApiKey !== '' ? $omdbApiKey : null;
        if ($this->omdbapikey !== null) {
            $this->omdbApi = new OMDbAPI($this->omdbapikey);
        }

        $this->lookuplanguage = Settings::settingValue('imdblanguage') !== '' ? (string) Settings::settingValue('imdblanguage') : 'en';
        $cacheDir = storage_path('framework/cache/imdb_cache');
        if (! File::isDirectory($cacheDir)) {
            File::makeDirectory($cacheDir, 0777, false, true);
        }

        $this->imdburl = (int) Settings::settingValue('imdburl') !== 0;
        $this->movieqty = Settings::settingValue('maximdbprocessed') !== '' ? (int) Settings::settingValue('maximdbprocessed') : 100;
        $this->showPasswords = app(ReleaseBrowseService::class)->showPasswords();

        $this->echooutput = config('nntmux.echocli');
        $this->imgSavePath = storage_path('covers/movies/');
        $this->service = '';
    }

    /**
     * Get movie info by IMDB ID.
     */
    public function getMovieInfo(int|string|null $imdbId): ?MovieInfo
    {
        $sanitized = $this->sanitizeImdbId($imdbId);
        if ($sanitized === null) {
            return null;
        }

        return MovieInfo::query()->where('imdbid', $sanitized)->first();
    }

    /**
     * Get trailer using IMDB Id.
     *
     * @throws \Exception
     * @throws GuzzleException
     */
    public function getTrailer(int|string|null $imdbId): string|false
    {
        $sanitized = $this->sanitizeImdbId($imdbId);
        if ($sanitized === null) {
            return false;
        }

        $trailer = MovieInfo::query()->where('imdbid', $sanitized)->where('trailer', '<>', '')->first(['trailer']);
        if ($trailer !== null) {
            return $trailer['trailer'];
        }

        if ($this->traktcheck !== null) {
            $data = $this->traktTv->client->getMovieSummary('tt'.$sanitized, 'full');
            if (($data !== false) && ! empty($data['trailer'])) {
                return $data['trailer'];
            }
        }

        $trailer = imdb_trailers($sanitized);
        if ($trailer) {
            MovieInfo::query()->where('imdbid', $sanitized)->update(['trailer' => $trailer]);

            return $trailer;
        }

        return false;
    }

    /**
     * Sanitize an IMDB ID to its raw numeric string, preserving leading zeros.
     * Returns null if the ID is empty or all zeros.
     */
    private function sanitizeImdbId(int|string|null $imdbId): ?string
    {
        if ($imdbId === null || $imdbId === '') {
            return null;
        }

        $sanitizedImdbId = preg_replace('/\D/', '', trim((string) $imdbId));
        if ($sanitizedImdbId === null || $sanitizedImdbId === '' || ! imdb_id_is_valid($sanitizedImdbId)) {
            return null;
        }

        return $sanitizedImdbId;
    }

    /**
     * Parse trakt info, insert into DB.
     *
     * @param  array<string, mixed>  $data
     */
    public function parseTraktTv(array &$data): mixed
    {
        if (empty($data['ids']['imdb'])) {
            return false;
        }

        if (! empty($data['trailer'])) {
            $data['trailer'] = str_ireplace(
                ['watch?v=', 'http://'],
                ['embed/', 'https://'],
                $data['trailer']
            );
        }
        $imdbId = (str_starts_with($data['ids']['imdb'], 'tt')) ? substr($data['ids']['imdb'], 2) : $data['ids']['imdb'];
        $cover = 0;
        if (File::isFile($this->imgSavePath.$imdbId.'-cover.jpg')) {
            $cover = 1;
        }

        return $this->update([
            'genre' => implode(', ', $data['genres']),
            'imdbid' => $this->checkTraktValue($imdbId),
            'language' => $this->checkTraktValue($data['language']),
            'plot' => $this->checkTraktValue($data['overview']),
            'rating' => $this->checkTraktValue($data['rating']),
            'tagline' => $this->checkTraktValue($data['tagline']),
            'title' => $this->checkTraktValue($data['title']),
            'tmdbid' => $this->checkTraktValue($data['ids']['tmdb']),
            'traktid' => $this->checkTraktValue($data['ids']['trakt']),
            'trailer' => $this->checkTraktValue($data['trailer']),
            'cover' => $cover,
            'year' => $this->checkTraktValue($data['year']),
        ]);
    }

    private function checkTraktValue(mixed $value): mixed
    {
        if (\is_array($value) && ! empty($value)) {
            $temp = '';
            foreach ($value as $val) {
                if (! is_array($val) && ! is_object($val)) {
                    $temp .= $val;
                }
            }
            $value = $temp;
        }

        return ! empty($value) ? $value : '';
    }

    /**
     * Get array of column keys, for inserting / updating.
     *
     * @return array<int, string>
     */
    public function getColumnKeys(): array
    {
        return [
            'actors', 'backdrop', 'cover', 'director', 'genre', 'imdbid', 'language',
            'plot', 'rating', 'rtrating', 'tagline', 'title', 'tmdbid', 'traktid', 'trailer', 'type', 'year',
        ];
    }

    /**
     * Choose the first non-empty variable from up to five inputs.
     *
     * @param  array<string, mixed>  $variable1
     * @param  array<string, mixed>  $variable2
     * @param  array<string, mixed>  $variable3
     * @param  array<string, mixed>  $variable4
     * @param  array<string, mixed>  $variable5
     * @return array<string, mixed>
     */
    protected function setVariables(string|array|int|float $variable1, string|array|int|float $variable2, string|array|int|float $variable3, string|array|int|float $variable4, string|array|int|float $variable5 = ''): array|string
    {
        if (! empty($variable1)) {
            return is_array($variable1) ? $variable1 : (string) $variable1;
        }
        if (! empty($variable2)) {
            return is_array($variable2) ? $variable2 : (string) $variable2;
        }
        if (! empty($variable3)) {
            return is_array($variable3) ? $variable3 : (string) $variable3;
        }
        if (! empty($variable4)) {
            return is_array($variable4) ? $variable4 : (string) $variable4;
        }
        if (! empty($variable5)) {
            return is_array($variable5) ? $variable5 : (string) $variable5;
        }

        return '';
    }

    /**
     * Update movie on movie-edit page.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): bool
    {
        if (! count($values)) {
            return false;
        }

        $query = [];
        $onDuplicateKey = ['created_at' => now()];
        $found = 0;
        foreach ($values as $key => $value) {
            if (! empty($value)) {
                $found++;
                if (\in_array($key, ['genre', 'language'], true)) {
                    $value = substr($value, 0, 64);
                }
                $query += [$key => $value];
                $onDuplicateKey += [$key => $value];
            }
        }
        if (! $found) {
            return false;
        }
        foreach ($query as $key => $value) {
            $query[$key] = rtrim((string) $value, ', ');
        }

        MovieInfo::upsert($query, ['imdbid'], $onDuplicateKey);

        // Always attempt to fetch a missing cover if imdbid present and cover not provided.
        if (! empty($query['imdbid'])) {
            $imdbIdForCover = $query['imdbid'];
            $coverProvided = array_key_exists('cover', $values) && ! empty($values['cover']);
            if (! $coverProvided && ! $this->hasCover($imdbIdForCover)) {
                if ($this->fetchAndSaveCoverOnly($imdbIdForCover)) {
                    MovieInfo::query()->where('imdbid', $imdbIdForCover)->update(['cover' => 1]);
                }
            }
        }

        return true;
    }

    /**
     * Fetch IMDB/TMDB/TRAKT/OMDB/iTunes info for the movie.
     *
     * @throws \Exception
     */
    public function updateMovieInfo(string $imdbId): bool
    {
        if ($this->echooutput && $this->service !== '') {
            cli()->primary('Fetching IMDB info from TMDB/IMDB/Trakt/OMDB/iTunes using IMDB id: '.$imdbId);
        }

        // Check TMDB for IMDB info.
        $tmdb = $this->fetchTMDBProperties($imdbId);

        // Check IMDB for movie info.
        $imdb = $this->fetchIMDBProperties($imdbId);

        // Check TRAKT for movie info
        $trakt = $this->fetchTraktTVProperties($imdbId);

        // Check OMDb for movie info
        $omdb = $this->fetchOmdbAPIProperties($imdbId);

        if (! $imdb && ! $tmdb && ! $trakt && ! $omdb) {
            return false;
        }

        if (! $this->movieIdentityQualified && ! $this->hasMovieQualifiedMetadata($tmdb, $imdb, $trakt, $omdb)) {
            Log::warning('Movie metadata was not persisted because no provider positively identified a movie.', [
                'imdb_id' => $imdbId,
            ]);

            return false;
        }

        // Check FanArt.tv for cover and background images.
        $fanart = $this->fetchFanartTVProperties($imdbId);

        $mov = [];

        $mov['cover'] = $mov['backdrop'] = $mov['banner'] = 0;
        $mov['type'] = $mov['director'] = $mov['actors'] = $mov['language'] = '';

        $mov['imdbid'] = $imdbId;
        $mov['tmdbid'] = (! isset($tmdb['tmdbid']) || $tmdb['tmdbid'] === '') ? 0 : $tmdb['tmdbid'];
        $mov['traktid'] = (! isset($trakt['id']) || $trakt['id'] === '') ? 0 : $trakt['id'];

        // Prefer Fanart.tv cover over TMDB,TMDB over IMDB,IMDB over OMDB and OMDB over iTunes.
        if (! empty($fanart['cover'])) {
            try {
                $mov['cover'] = $this->releaseImage->saveImage($imdbId.'-cover', $fanart['cover'], $this->imgSavePath);
                if ($mov['cover'] === 0) {
                    Log::warning('Failed to save FanartTV cover for '.$imdbId.' from URL: '.$fanart['cover']);
                }
            } catch (\Throwable $e) {
                Log::error('Error saving FanartTV cover for '.$imdbId.': '.$e->getMessage());
                $mov['cover'] = 0;
            }
        }

        if ($mov['cover'] === 0 && ! empty($tmdb['cover'])) {
            try {
                $mov['cover'] = $this->releaseImage->saveImage($imdbId.'-cover', $tmdb['cover'], $this->imgSavePath);
                if ($mov['cover'] === 0) {
                    Log::warning('Failed to save TMDB cover for '.$imdbId.' from URL: '.$tmdb['cover']);
                }
            } catch (\Throwable $e) {
                Log::error('Error saving TMDB cover for '.$imdbId.': '.$e->getMessage());
                $mov['cover'] = 0;
            }
        }

        if ($mov['cover'] === 0 && ! empty($imdb['cover'])) {
            try {
                $mov['cover'] = $this->releaseImage->saveImage($imdbId.'-cover', $imdb['cover'], $this->imgSavePath);
                if ($mov['cover'] === 0) {
                    Log::warning('Failed to save IMDB cover for '.$imdbId.' from URL: '.$imdb['cover']);
                }
            } catch (\Throwable $e) {
                Log::error('Error saving IMDB cover for '.$imdbId.': '.$e->getMessage());
                $mov['cover'] = 0;
            }
        }

        if ($mov['cover'] === 0 && ! empty($omdb['cover'])) {
            try {
                $mov['cover'] = $this->releaseImage->saveImage($imdbId.'-cover', $omdb['cover'], $this->imgSavePath);
                if ($mov['cover'] === 0) {
                    Log::warning('Failed to save OMDB cover for '.$imdbId.' from URL: '.$omdb['cover']);
                }
            } catch (\Throwable $e) {
                Log::error('Error saving OMDB cover for '.$imdbId.': '.$e->getMessage());
                $mov['cover'] = 0;
            }
        }

        // Backdrops.
        if (! empty($fanart['backdrop'])) {
            try {
                $mov['backdrop'] = $this->releaseImage->saveImage($imdbId.'-backdrop', $fanart['backdrop'], $this->imgSavePath, 1920, 1024);
            } catch (\Throwable $e) {
                Log::warning('Error saving FanartTV backdrop for '.$imdbId.': '.$e->getMessage());
                $mov['backdrop'] = 0;
            }
        }

        if ($mov['backdrop'] === 0 && ! empty($tmdb['backdrop'])) {
            try {
                $mov['backdrop'] = $this->releaseImage->saveImage($imdbId.'-backdrop', $tmdb['backdrop'], $this->imgSavePath, 1920, 1024);
            } catch (\Throwable $e) {
                Log::warning('Error saving TMDB backdrop for '.$imdbId.': '.$e->getMessage());
                $mov['backdrop'] = 0;
            }
        }

        // Banner
        if (! empty($fanart['banner'])) {
            try {
                $mov['banner'] = $this->releaseImage->saveImage($imdbId.'-banner', $fanart['banner'], $this->imgSavePath);
            } catch (\Throwable $e) {
                Log::warning('Error saving FanartTV banner for '.$imdbId.': '.$e->getMessage());
                $mov['banner'] = 0;
            }
        }

        // RottenTomatoes rating from OmdbAPI
        if ($omdb !== false && ! empty($omdb['rtRating'])) {
            $mov['rtrating'] = $omdb['rtRating'];
        }

        $mov['title'] = $this->setVariables($imdb['title'] ?? '', $tmdb['title'] ?? '', $trakt['title'] ?? '', $omdb['title'] ?? '');
        $mov['rating'] = $this->setVariables($imdb['rating'] ?? '', $tmdb['rating'] ?? '', $trakt['rating'] ?? '', $omdb['rating'] ?? '');
        $mov['plot'] = $this->setVariables($imdb['plot'] ?? '', $tmdb['plot'] ?? '', $trakt['overview'] ?? '', $omdb['plot'] ?? '');
        $mov['tagline'] = $this->setVariables($imdb['tagline'] ?? '', $tmdb['tagline'] ?? '', $trakt['tagline'] ?? '', $omdb['tagline'] ?? '');
        $mov['year'] = $this->setVariables($imdb['year'] ?? '', $tmdb['year'] ?? '', $trakt['year'] ?? '', $omdb['year'] ?? '');
        $mov['genre'] = $this->setVariables($imdb['genre'] ?? '', $tmdb['genre'] ?? '', $trakt['genres'] ?? '', $omdb['genre'] ?? '');

        if (! empty($imdb['type'])) {
            $mov['type'] = $imdb['type'];
        }

        if (! empty($imdb['director'])) {
            $mov['director'] = \is_array($imdb['director']) ? implode(', ', array_unique($imdb['director'])) : $imdb['director'];
        } elseif (! empty($omdb['director'])) {
            $mov['director'] = \is_array($omdb['director']) ? implode(', ', array_unique($omdb['director'])) : $omdb['director'];
        } elseif (! empty($tmdb['director'])) {
            $mov['director'] = \is_array($tmdb['director']) ? implode(', ', array_unique($tmdb['director'])) : $tmdb['director'];
        }

        if (! empty($imdb['actors'])) {
            $mov['actors'] = \is_array($imdb['actors']) ? implode(', ', array_unique($imdb['actors'])) : $imdb['actors'];
        } elseif (! empty($omdb['actors'])) {
            $mov['actors'] = \is_array($omdb['actors']) ? implode(', ', array_unique($omdb['actors'])) : $omdb['actors'];
        } elseif (! empty($tmdb['actors'])) {
            $mov['actors'] = \is_array($tmdb['actors']) ? implode(', ', array_unique($tmdb['actors'])) : $tmdb['actors'];
        }

        if (! empty($imdb['language'])) {
            $mov['language'] = \is_array($imdb['language']) ? implode(', ', array_unique($imdb['language'])) : $imdb['language'];
        } elseif (! empty($omdb['language']) && ! is_bool($omdb['language'])) {
            $mov['language'] = \is_array($omdb['language']) ? implode(', ', array_unique($omdb['language'])) : $omdb['language'];
        }

        if (\is_array($mov['genre'])) {
            $mov['genre'] = implode(', ', array_unique($mov['genre']));
        }

        if (\is_array($mov['type'])) {
            $mov['type'] = implode(', ', array_unique($mov['type']));
        }

        $mov['title'] = html_entity_decode($mov['title'], ENT_QUOTES, 'UTF-8');

        $mov['title'] = str_replace(['/', '\\'], '', $mov['title']);
        $movieID = $this->update([
            'actors' => html_entity_decode($mov['actors'], ENT_QUOTES, 'UTF-8'),
            'backdrop' => $mov['backdrop'],
            'cover' => $mov['cover'],
            'director' => html_entity_decode($mov['director'], ENT_QUOTES, 'UTF-8'),
            'genre' => html_entity_decode($mov['genre'], ENT_QUOTES, 'UTF-8'),
            'imdbid' => $mov['imdbid'],
            'language' => html_entity_decode($mov['language'], ENT_QUOTES, 'UTF-8'),
            'plot' => html_entity_decode(preg_replace('/\s+See full summary »/u', ' ', $mov['plot']), ENT_QUOTES, 'UTF-8'),
            'rating' => round((int) $mov['rating'], 1),
            'rtrating' => $mov['rtrating'] ?? 'N/A',
            'tagline' => html_entity_decode($mov['tagline'], ENT_QUOTES, 'UTF-8'),
            'title' => $mov['title'],
            'tmdbid' => $mov['tmdbid'],
            'traktid' => $mov['traktid'],
            'type' => html_entity_decode(ucwords(preg_replace('/[._]/', ' ', $mov['type'])), ENT_QUOTES, 'UTF-8'),
            'year' => $mov['year'],
        ]);

        // After updating, if cover flag is still 0 but file now exists (race condition), update DB.
        if ($mov['cover'] === 0 && $this->hasCover($imdbId)) {
            MovieInfo::query()->where('imdbid', $imdbId)->update(['cover' => 1]);
        }

        if ($this->echooutput && $this->service !== '') {
            PHP_EOL.cli()->headerOver('Added/updated movie: ').
            cli()->primary(
                $mov['title'].
                ' ('.
                $mov['year'].
                ') - '.
                $mov['imdbid']
            );
        }

        return $movieID;
    }

    /**
     * Fetch FanArt.tv backdrop / cover / title.
     *
     * @return array<string, mixed>
     */
    protected function fetchFanartTVProperties(string $imdbId): false|array
    {
        if (! $this->fanart->isConfigured()) {
            return false;
        }

        try {
            $result = $this->fanart->getMovieProperties($imdbId);

            if ($result !== null) {
                if ($this->echooutput) {
                    cli()->info('Fanart found '.$result['title']);
                }

                return $result;
            }
        } catch (\Throwable $e) {
            Log::warning('FanartTV API error for '.$imdbId.': '.$e->getMessage());
        }

        return false;
    }

    /**
     * Fetch movie information from TMDB using an IMDB ID.
     *
     * @return array<string, mixed>
     */
    public function fetchTMDBProperties(string $imdbId, bool $text = false): array|false
    {
        $lookupId = $text === false && (strlen($imdbId) === 7 || strlen($imdbId) === 8) ? 'tt'.$imdbId : $imdbId;

        $cacheKey = 'tmdb_movie_'.md5($lookupId);
        $expiresAt = now()->addDays(7);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $tmdbClient = app(TmdbClient::class);

            if (! $tmdbClient->isConfigured()) {
                return false;
            }

            $tmdbLookup = $tmdbClient->getMovie($lookupId, ['credits']); // @phpstan-ignore argument.type

            if ($tmdbLookup === null || empty($tmdbLookup)) {
                Cache::put($cacheKey, false, $expiresAt);

                return false;
            }

            $title = TmdbClient::getString($tmdbLookup, 'title');
            if ($this->currentTitle !== '' && ! empty($title)) {
                $percent = $this->similarityPercent($this->currentTitle, $title);
                if ($percent < self::MATCH_PERCENT) {
                    $tmdbId = TmdbClient::getInt($tmdbLookup, 'id');
                    $altTitles = $tmdbId > 0 ? $tmdbClient->getMovieAlternativeTitles($tmdbId) : null;
                    $titles = is_array($altTitles) ? ($altTitles['titles'] ?? []) : [];
                    $matched = false;
                    foreach ($titles as $alt) {
                        $altTitle = is_array($alt) ? ($alt['title'] ?? '') : '';
                        if ($altTitle !== '') {
                            $altPercent = $this->similarityPercent($this->currentTitle, $altTitle);
                            if ($altPercent >= self::MATCH_PERCENT) {
                                $matched = true;
                                break;
                            }
                        }
                    }
                    if (! $matched) {
                        Cache::put($cacheKey, false, $expiresAt);

                        return false;
                    }
                }
            }

            $releaseDate = TmdbClient::getString($tmdbLookup, 'release_date');
            if ($this->currentYear !== '' && ! empty($releaseDate)) {
                $tmdbYear = Carbon::parse($releaseDate)->year;

                $percent = $this->similarityPercent($this->currentYear, $tmdbYear);
                if ($percent < self::YEAR_MATCH_PERCENT) {
                    Cache::put($cacheKey, false, $expiresAt);

                    return false;
                }
            }

            $imdbIdFromResponse = TmdbClient::getString($tmdbLookup, 'imdb_id');
            $ret = [
                'title' => $title,
                'tmdbid' => TmdbClient::getInt($tmdbLookup, 'id'),
                'imdbid' => str_replace('tt', '', $imdbIdFromResponse),
                'rating' => '',
                'actors' => '',
                'director' => '',
                'plot' => TmdbClient::getString($tmdbLookup, 'overview'),
                'tagline' => TmdbClient::getString($tmdbLookup, 'tagline'),
                'year' => '',
                'genre' => '',
                'cover' => '',
                'backdrop' => '',
            ];

            $vote = TmdbClient::getFloat($tmdbLookup, 'vote_average');
            if ($vote > 0) {
                $ret['rating'] = $vote;
            }

            $credits = TmdbClient::getArray($tmdbLookup, 'credits');
            $cast = TmdbClient::getArray($credits, 'cast');
            if (! empty($cast)) {
                $actors = [];
                foreach ($cast as $member) {
                    if (is_array($member) && ! empty($member['name'])) {
                        $actors[] = $member['name'];
                    }
                }
                if (! empty($actors)) {
                    $ret['actors'] = $actors;
                }
            }

            $crew = TmdbClient::getArray($credits, 'crew');
            foreach ($crew as $crewMember) {
                if (! is_array($crewMember)) {
                    continue;
                }
                $department = TmdbClient::getString($crewMember, 'department');
                $job = TmdbClient::getString($crewMember, 'job');
                if ($department === 'Directing' && $job === 'Director') {
                    $ret['director'] = TmdbClient::getString($crewMember, 'name');
                    break;
                }
            }

            if (! empty($releaseDate)) {
                $ret['year'] = Carbon::parse($releaseDate)->year;
            }

            $genresa = TmdbClient::getArray($tmdbLookup, 'genres');
            if (! empty($genresa)) {
                $genres = [];
                foreach ($genresa as $genre) {
                    if (is_array($genre) && ! empty($genre['name'])) {
                        $genres[] = $genre['name'];
                    }
                }
                if (! empty($genres)) {
                    $ret['genre'] = $genres;
                }
            }

            $posterPath = TmdbClient::getString($tmdbLookup, 'poster_path');
            if (! empty($posterPath)) {
                $ret['cover'] = 'https://image.tmdb.org/t/p/original'.$posterPath;
            }

            $backdropPath = TmdbClient::getString($tmdbLookup, 'backdrop_path');
            if (! empty($backdropPath)) {
                $ret['backdrop'] = 'https://image.tmdb.org/t/p/original'.$backdropPath;
            }

            if ($this->echooutput) {
                cli()->info('TMDb found '.$ret['title']);
            }

            Cache::put($cacheKey, $ret, $expiresAt);

            return $ret;

        } catch (\Throwable $e) {
            Log::warning('TMDB API error for '.$lookupId.': '.$e->getMessage());
            Cache::put($cacheKey, false, now()->addHours(6));

            return false;
        }
    }

    /**
     * Fetch movie information from IMDB.
     *
     * @return array<string, mixed>
     */
    public function fetchIMDBProperties(string $imdbId): array|false
    {
        $cacheKey = 'imdb_movie_'.md5($imdbId);
        $expiresAt = now()->addDays(7);
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if ($cached === false) {
                if ($this->echooutput) {
                    cli()->warning('IMDb fetch failed [cached_negative_legacy] for tt'.$imdbId);
                }

                return false;
            }
            if (is_array($cached) && ($cached['kind'] ?? null) === 'negative') {
                if ($this->echooutput) {
                    $reason = (string) ($cached['reason'] ?? 'cached_negative');
                    $fallbackReason = $cached['fallback_reason'] ?? null;
                    cli()->warning('IMDb fetch failed ['.$reason.(is_string($fallbackReason) ? ' -> '.$fallbackReason : '').'] for tt'.$imdbId);
                }

                return false;
            }

            return is_array($cached) ? $cached : false;
        }
        try {
            $scraper = app(ImdbScraper::class);
            $scraped = $scraper->fetchById($imdbId);
            if ($scraped === false || empty($scraped['title'])) {
                $ttl = $scraper->wasBlockedByWaf()
                    ? now()->addMinutes(30)
                    : now()->addHours(6);

                $failureReason = $scraper->getLastFailureReason() ?? 'unknown';
                $fallbackFailureReason = $scraper->getLastFallbackFailureReason();

                if ($scraper->wasBlockedByWaf()) {
                    Log::warning('IMDb title fetch failed after WAF block.', [
                        'imdb_id' => $imdbId,
                        'reason' => $failureReason,
                        'fallback_reason' => $fallbackFailureReason,
                        'source' => $scraper->getLastFetchSource(),
                        'negative_cache' => '30m',
                    ]);
                } else {
                    Log::warning('IMDb metadata fetch failed.', [
                        'imdb_id' => $imdbId,
                        'reason' => $failureReason,
                        'fallback_reason' => $fallbackFailureReason,
                        'source' => $scraper->getLastFetchSource(),
                        'negative_cache' => '6h',
                    ]);
                }

                if ($this->echooutput) {
                    $message = 'IMDb fetch failed ['.$failureReason;
                    if ($fallbackFailureReason !== null) {
                        $message .= ' -> '.$fallbackFailureReason;
                    }
                    $message .= '] for tt'.$imdbId;

                    cli()->warning($message);
                }

                $this->cacheImdbMovieFailure($cacheKey, $failureReason, $fallbackFailureReason, $ttl);

                return false;
            }
            $scrapedType = (string) ($scraped['type'] ?? 'unknown');
            if ($this->isExplicitNonMovieMediaType($scrapedType)) {
                $this->cacheImdbMovieFailure($cacheKey, 'non_movie_media_type', null, now()->addHours(6));

                return false;
            }
            if (! empty($this->currentTitle)) {
                $percent = $this->similarityPercent($this->currentTitle, $scraped['title']);
                if ($percent < self::MATCH_PERCENT) {
                    $this->cacheImdbMovieFailure($cacheKey, 'title_mismatch', null, now()->addHours(6));

                    return false;
                }
                if (! empty($this->currentYear) && ! empty($scraped['year'])) {
                    $yearPercent = $this->similarityPercent($this->currentYear, $scraped['year']);
                    if ($yearPercent < self::YEAR_MATCH_PERCENT) {
                        $this->cacheImdbMovieFailure($cacheKey, 'year_mismatch', null, now()->addHours(6));

                        return false;
                    }
                }
            }
            Cache::put($cacheKey, $scraped, $expiresAt);
            if ($this->echooutput) {
                $sourceLabel = match ($scraper->getLastFetchSource()) {
                    'imdbapi_dev' => 'IMDb fallback (imdbapi.dev)',
                    'imdb_html' => 'IMDb scrape',
                    default => 'IMDb',
                };

                cli()->info($sourceLabel.' found '.$scraped['title']);
            }

            return $scraped;
        } catch (\Throwable $e) {
            Log::warning('IMDb scrape error for '.$imdbId.': '.$e->getMessage());
            $this->cacheImdbMovieFailure($cacheKey, 'scrape_exception', null, now()->addHours(6));

            return false;
        }
    }

    private function cacheImdbMovieFailure(
        string $cacheKey,
        string $reason,
        ?string $fallbackReason,
        \DateTimeInterface|\DateInterval|int|null $ttl,
    ): void {
        Cache::put($cacheKey, [
            'kind' => 'negative',
            'reason' => $reason,
            'fallback_reason' => $fallbackReason,
        ], $ttl);
    }

    /**
     * Fetch movie information from Trakt.tv using IMDB ID.
     *
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    public function fetchTraktTVProperties(string $imdbId): array|false
    {
        if ($this->traktcheck === null) {
            return false;
        }

        $cacheKey = 'trakt_movie_'.md5($imdbId);
        $expiresAt = now()->addDays(7);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $resp = $this->traktTv->client->getMovieSummary('tt'.$imdbId, 'full');

            if ($resp === false || empty($resp['title'])) {
                Cache::put($cacheKey, false, now()->addHours(6));

                return false;
            }

            if (! empty($this->currentTitle)) {
                $percent = $this->similarityPercent($this->currentTitle, $resp['title']);
                if ($percent < self::MATCH_PERCENT) {
                    Cache::put($cacheKey, false, now()->addHours(6));

                    return false;
                }
            }

            if (! empty($this->currentYear) && ! empty($resp['year'])) {
                $percent = $this->similarityPercent($this->currentYear, $resp['year']);
                if ($percent < self::YEAR_MATCH_PERCENT) {
                    Cache::put($cacheKey, false, now()->addHours(6));

                    return false;
                }
            }

            $movieData = [
                'id' => $resp['ids']['trakt'] ?? null,
                'title' => $resp['title'],
                'overview' => $resp['overview'] ?? '',
                'tagline' => $resp['tagline'] ?? '',
                'year' => $resp['year'] ?? '',
                'genres' => $resp['genres'] ?? '',
                'rating' => $resp['rating'] ?? '',
                'votes' => $resp['votes'] ?? 0,
                'language' => $resp['language'] ?? '',
                'runtime' => $resp['runtime'] ?? 0,
                'trailer' => $resp['trailer'] ?? '',
            ];

            if ($this->echooutput) {
                cli()->info('Trakt found '.$movieData['title']);
            }

            Cache::put($cacheKey, $movieData, $expiresAt);

            return $movieData;

        } catch (\Throwable $e) {
            Log::warning('Trakt API error for '.$imdbId.': '.$e->getMessage());
            Cache::put($cacheKey, false, now()->addHours(6));

            return false;
        }
    }

    /**
     * Fetch movie information from OMDB API using IMDB ID.
     *
     * @return array<string, mixed>
     */
    public function fetchOmdbAPIProperties(string $imdbId): array|false
    {
        if ($this->omdbapikey === null) {
            return false;
        }

        $cacheKey = 'omdb_movie_'.md5($imdbId);
        $expiresAt = now()->addDays(7);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $resp = $this->omdbApi->fetch('i', 'tt'.$imdbId);

            if (! is_object($resp) ||
                $resp->message !== 'OK' ||
                Str::contains($resp->data->Response, 'Error:') ||
                $resp->data->Response === 'False') {

                Cache::put($cacheKey, false, now()->addHours(6));

                return false;
            }

            if ($this->isExplicitNonMovieMediaType((string) ($resp->data->Type ?? 'unknown'))) {
                Cache::put($cacheKey, false, now()->addHours(6));

                return false;
            }

            if (! empty($this->currentTitle)) {
                $percent = $this->similarityPercent($this->currentTitle, $resp->data->Title);
                if ($percent < self::MATCH_PERCENT) {
                    Cache::put($cacheKey, false, now()->addHours(6));

                    return false;
                }

                if (! empty($this->currentYear)) {
                    $percent = $this->similarityPercent($this->currentYear, $resp->data->Year);
                    if ($percent < self::YEAR_MATCH_PERCENT) {
                        Cache::put($cacheKey, false, now()->addHours(6));

                        return false;
                    }
                }
            }

            $rtRating = '';
            if (isset($resp->data->Ratings) && is_array($resp->data->Ratings) && count($resp->data->Ratings) > 1) {
                $rtRating = $resp->data->Ratings[1]->Value ?? '';
            }

            $movieData = [
                'title' => $resp->data->Title ?? '',
                'type' => $resp->data->Type ?? 'unknown',
                'cover' => $resp->data->Poster ?? '',
                'genre' => $resp->data->Genre ?? '',
                'year' => $resp->data->Year ?? '',
                'plot' => $resp->data->Plot ?? '',
                'rating' => $resp->data->imdbRating ?? '',
                'rtRating' => $rtRating,
                'tagline' => $resp->data->Tagline ?? '',
                'director' => $resp->data->Director ?? '',
                'actors' => $resp->data->Actors ?? '',
                'language' => $resp->data->Language ?? '',
                'boxOffice' => $resp->data->BoxOffice ?? '',
            ];

            if ($this->echooutput) {
                cli()->info('OMDbAPI Found '.$movieData['title']);
            }

            Cache::put($cacheKey, $movieData, $expiresAt);

            return $movieData;

        } catch (\Throwable $e) {
            Log::warning('OMDB API error for '.$imdbId.': '.$e->getMessage());
            Cache::put($cacheKey, false, now()->addHours(6));

            return false;
        }
    }

    /**
     * Update a release with an IMDB ID and related movie information.
     *
     * @throws \Exception
     */
    public function doMovieUpdate(string $buffer, string $service, int $id, int $processImdb = 1): string|false
    {
        $existingImdbId = Release::query()->where('id', $id)->value('imdbid');
        if ($existingImdbId !== null && imdb_id_is_valid($existingImdbId)) {
            return $existingImdbId;
        }

        $imdbId = false;
        if (preg_match('/(?:imdb.*?)?(?:tt|Title\?)(?P<imdbid>\d{5,})/i', $buffer, $hits)) {
            $imdbId = $hits['imdbid'];
        }

        if ($imdbId !== false) {
            try {
                $this->service = $service;
                if ($this->echooutput && $this->service !== '') {
                    cli()->info($this->service.' found IMDBid: tt'.$imdbId);
                }

                $movieInfo = MovieInfo::query()->where('imdbid', $imdbId)->first(['id', 'title', 'year', 'type', 'updated_at']);

                if ($processImdb === 1) {
                    $movCheck = $this->getMovieInfo($imdbId);
                    $thirtyDaysInSeconds = 30 * 24 * 60 * 60;

                    if ($movCheck === null ||
                        (isset($movCheck['updated_at']) &&
                            (time() - strtotime((string) $movCheck['updated_at'])) > $thirtyDaysInSeconds)) {

                        $info = $this->updateMovieInfo($imdbId);

                        if ($info !== true) {
                            return false;
                        }

                        $movieInfo = MovieInfo::query()->where('imdbid', $imdbId)->first(['id', 'title', 'year', 'type', 'updated_at']);
                    }
                }

                if ($movieInfo === null || ! $this->movieInfoMatchesCurrentRelease($movieInfo)) {
                    return false;
                }

                if (! $this->commitMovieLink($id, $imdbId, (int) $movieInfo['id'])) {
                    return false;
                }

                Search::updateRelease($id);

                return $imdbId;
            } catch (\Exception $e) {
                Log::error('Error updating movie information: '.$e->getMessage());

                return false;
            }
        }

        return $imdbId;
    }

    /**
     * Process releases with no IMDB IDs by looking up movie information from various sources.
     *
     * @throws \Exception
     * @throws GuzzleException
     */
    public function processMovieReleases(string $groupID = '', string $guidChar = '', int $lookupIMDB = 1): void
    {
        if ($lookupIMDB === 0) {
            return;
        }

        $query = Release::query()
            ->select(['searchname', 'id', 'imdbid', 'movieinfo_id'])
            ->whereBetween('categories_id', [Category::MOVIE_ROOT, Category::MOVIE_OTHER])
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereNull('imdbid')
                        ->orWhereIn('imdbid', imdb_id_pending_values());
                })->orWhere(function ($query): void {
                    $query->whereNotNull('imdbid')
                        ->where('imdbid', '<>', '')
                        ->whereNotIn('imdbid', imdb_id_pending_values())
                        ->where(function ($query): void {
                            $query->whereNull('movieinfo_id')
                                ->orWhere('movieinfo_id', 0);
                        });
                });
            });
        app(MovieLookupState::class)->applyEligibility($query);

        if ($groupID !== '') {
            $query->where('groups_id', $groupID);
        }

        if ($guidChar !== '') {
            $query->where('leftguid', $guidChar);
        }

        if ((int) $lookupIMDB === 2) {
            $query->where('isrenamed', '=', 1);
        }

        $res = $query->orderByDesc('id')->limit($this->movieqty)->get();

        $movieCount = count($res);
        $failedIDs = [];

        if ($movieCount > 0) {
            if ($this->echooutput && $movieCount > 1) {
                cli()->header('Processing '.$movieCount.' movie releases.');
            }

            foreach ($res as $arr) {
                $lookupState = app(MovieLookupState::class);
                $claimToken = $lookupState->claim((int) $arr['id']);
                if ($claimToken === null) {
                    continue;
                }

                $this->activeMovieClaimToken = $claimToken;
                try {

                    if (movieinfo_needs_repair($arr['imdbid'] ?? null, $arr['movieinfo_id'] ?? null)) {
                        if ($this->repairMovieInfoLink((int) $arr['id'], $arr['imdbid'] ?? null)) {
                            $lookupState->complete((int) $arr['id'], $claimToken);
                        } else {
                            $failureState = $lookupState->fail(
                                (int) $arr['id'],
                                $claimToken,
                                $this->lastRepairFailureReason,
                                $this->lastRepairFailureTerminal,
                            );
                            if (($failureState['status'] ?? null) === MovieLookupState::STATUS_QUARANTINED) {
                                Search::updateRelease((int) $arr['id']);
                            }
                        }

                        continue;
                    }

                    if (! $this->parseMovieSearchName($arr['searchname'])) {
                        if ($lookupState->markNoMatch((int) $arr['id'], $claimToken)) {
                            $failedIDs[] = $arr['id'];
                        }

                        continue;
                    }

                    $this->currentRelID = (string) $arr['id'];
                    $movieName = $this->formatMovieName();

                    if ($this->echooutput) {
                        cli()->info('Looking up: '.$movieName);
                    }

                    $foundIMDB = $this->searchLocalDatabase($arr['id']) ||
                        $this->searchIMDb($arr['id']) ||
                        $this->searchOMDbAPI($arr['id']) ||
                        $this->searchTraktTV($arr['id'], $movieName) ||
                        $this->searchTMDB($arr['id']) ||
                        $this->searchReleaseNameTitleCandidates($arr['id'], $arr['searchname']) ||
                        $this->searchReleaseFileTitleCandidates($arr['id']);

                    if ($foundIMDB) {
                        $lookupState->complete((int) $arr['id'], $claimToken);
                        if ($this->echooutput) {
                            cli()->primary('Successfully updated release with IMDB ID');
                        }

                        continue;
                    } else {
                        $releaseCheck = Release::query()
                            ->where('id', $arr['id'])
                            ->where(function ($query): void {
                                $query->whereNotNull('imdbid')
                                    ->where('imdbid', '<>', '')
                                    ->whereNotIn('imdbid', imdb_id_pending_values());
                            })
                            ->exists();
                        if ($releaseCheck) {
                            $lookupState->complete((int) $arr['id'], $claimToken);
                            if ($this->echooutput) {
                                cli()->info('Release already has IMDB ID, skipping');
                            }

                            continue;
                        }
                    }

                    if ($lookupState->markNoMatch((int) $arr['id'], $claimToken)) {
                        $failedIDs[] = $arr['id'];
                    }
                } finally {
                    $this->activeMovieClaimToken = null;
                }
            }

            if (! empty($failedIDs)) {
                if ($this->echooutput) {
                    $failedReleases = Release::query()
                        ->select(['id', 'searchname'])
                        ->whereIn('id', $failedIDs)
                        ->get();

                    cli()->header('Failed to find IMDB IDs for '.count($failedIDs).' releases:');
                    foreach ($failedReleases as $release) {
                        cli()->error("ID: {$release->id} - {$release->searchname}");
                    }
                }

                ReleaseSearchIndexSync::forIds($failedIDs);
            }
        }
    }

    private function repairMovieInfoLink(int $releaseId, int|string|null $imdbId): bool
    {
        $this->lastRepairFailureReason = 'metadata_unresolved';
        $this->lastRepairFailureTerminal = false;

        if (! imdb_id_is_valid($imdbId)) {
            return false;
        }

        $sanitized = preg_replace('/\D/', '', trim((string) $imdbId));
        if ($sanitized === null || $sanitized === '') {
            return false;
        }

        $previousTitle = $this->currentTitle;
        $previousYear = $this->currentYear;
        $releaseSearchName = (string) Release::query()->whereKey($releaseId)->value('searchname');
        if (! $this->parseMovieSearchName($releaseSearchName)) {
            $this->currentTitle = '';
            $this->currentYear = '';
        }

        try {
            $movieInfo = MovieInfo::query()->where('imdbid', $sanitized)->first(['id', 'title', 'year', 'type']);
            if ($movieInfo !== null) {
                if ($this->isExplicitNonMovieMediaType((string) $movieInfo['type'])) {
                    $this->lastRepairFailureReason = 'non_movie_identity';
                    $this->lastRepairFailureTerminal = true;

                    return false;
                }
                if ($this->currentTitle !== '' && ! $this->isImdbSearchMatchAcceptable(
                    (string) $movieInfo['title'],
                    $movieInfo['year'],
                )) {
                    $this->lastRepairFailureReason = 'identity_mismatch';
                    $this->lastRepairFailureTerminal = true;

                    return false;
                }
            } else {
                $identity = $this->validateStoredImdbSuggestionIdentity($sanitized);
                if ($identity === self::IDENTITY_NON_MOVIE || $identity === self::IDENTITY_MISMATCH) {
                    $this->lastRepairFailureReason = $identity === self::IDENTITY_NON_MOVIE
                        ? 'non_movie_identity'
                        : 'identity_mismatch';
                    $this->lastRepairFailureTerminal = true;

                    return false;
                }

                $previousQualification = $this->movieIdentityQualified;
                $this->movieIdentityQualified = $identity === self::IDENTITY_MOVIE;
                try {
                    $updated = $this->updateMovieInfo($sanitized);
                } finally {
                    $this->movieIdentityQualified = $previousQualification;
                }
                if ($updated !== true) {
                    return false;
                }

                $movieInfo = MovieInfo::query()->where('imdbid', $sanitized)->first(['id', 'title', 'year', 'type']);
                if ($movieInfo === null || ! $this->movieInfoMatchesCurrentRelease($movieInfo)) {
                    return false;
                }
            }
        } finally {
            $this->currentTitle = $previousTitle;
            $this->currentYear = $previousYear;
        }

        if (! $this->commitMovieLink($releaseId, $sanitized, (int) $movieInfo['id'])) {
            return false;
        }

        Search::updateRelease($releaseId);

        return true;
    }

    protected string $lastRepairFailureReason = 'metadata_unresolved';

    protected bool $lastRepairFailureTerminal = false;

    protected function validateStoredImdbSuggestionIdentity(string $imdbId): string
    {
        try {
            foreach (app(ImdbScraper::class)->search('tt'.$imdbId) as $match) {
                if ((string) ($match['imdbid'] ?? '') !== $imdbId) {
                    continue;
                }
                $type = (string) ($match['type'] ?? 'unknown');
                if ($this->isExplicitNonMovieMediaType($type)) {
                    return self::IDENTITY_NON_MOVIE;
                }
                if (! $this->isMovieMediaType($type)) {
                    return self::IDENTITY_UNKNOWN;
                }
                if (! $this->isImdbSearchMatchAcceptable(
                    (string) ($match['title'] ?? ''),
                    $match['year'] ?? null,
                )) {
                    return self::IDENTITY_MISMATCH;
                }

                return self::IDENTITY_MOVIE;
            }
        } catch (\Throwable $e) {
            Log::debug('IMDb identity validation unavailable for tt'.$imdbId.': '.$e->getMessage());
        }

        return self::IDENTITY_UNKNOWN;
    }

    private function commitMovieLink(int $releaseId, string $imdbId, int $movieInfoId): bool
    {
        if ($this->activeMovieClaimToken !== null) {
            return app(MovieLookupState::class)->link($releaseId, $this->activeMovieClaimToken, $imdbId, $movieInfoId);
        }

        return Release::query()->whereKey($releaseId)->update([
            'imdbid' => $imdbId,
            'movieinfo_id' => $movieInfoId,
        ]) === 1;
    }

    private function movieInfoMatchesCurrentRelease(MovieInfo $movieInfo): bool
    {
        if ($this->isExplicitNonMovieMediaType((string) $movieInfo->type)) {
            return false;
        }

        return $this->currentTitle === '' || $this->isImdbSearchMatchAcceptable(
            (string) $movieInfo->title,
            $movieInfo->year,
        );
    }

    /**
     * @param  array<string, mixed>|false  $tmdb
     * @param  array<string, mixed>|false  $imdb
     * @param  array<string, mixed>|false  $trakt
     * @param  array<string, mixed>|false  $omdb
     */
    private function hasMovieQualifiedMetadata(array|false $tmdb, array|false $imdb, array|false $trakt, array|false $omdb): bool
    {
        return $tmdb !== false
            || $trakt !== false
            || ($imdb !== false && $this->isMovieMediaType((string) ($imdb['type'] ?? 'unknown')))
            || ($omdb !== false && $this->isMovieMediaType((string) ($omdb['type'] ?? 'unknown')));
    }

    private function doMovieUpdateWithMovieQualification(string $buffer, string $service, int $releaseId): string|false
    {
        $previousQualification = $this->movieIdentityQualified;
        $this->movieIdentityQualified = true;
        try {
            return $this->doMovieUpdate($buffer, $service, $releaseId);
        } finally {
            $this->movieIdentityQualified = $previousQualification;
        }
    }

    private function formatMovieName(): string
    {
        $movieName = $this->currentTitle;
        if ($this->currentYear !== '') {
            $movieName .= ' ('.$this->currentYear.')';
        }

        return $movieName;
    }

    private function searchLocalDatabase(int $releaseId): bool
    {
        $getIMDBid = $this->localIMDBSearch();
        if ($getIMDBid === false) {
            return false;
        }

        $imdbId = $this->doMovieUpdate('tt'.$getIMDBid, 'Local DB', $releaseId);

        return $imdbId !== false;
    }

    private function searchIMDb(int $releaseId): bool
    {
        try {
            $scraper = app(ImdbScraper::class);
            $matches = $scraper->search($this->currentTitle);
            foreach ($matches as $rank => $match) {
                $title = $match['title'] ?? '';

                if (! $this->isMovieMediaType((string) ($match['type'] ?? 'unknown'))) {
                    continue;
                }

                if (! $this->isImdbSearchMatchAcceptable((string) $title, $match['year'] ?? null, (int) $rank)) {
                    continue;
                }

                $imdbId = $this->doMovieUpdateWithMovieQualification('tt'.$match['imdbid'], 'IMDb(scrape)', $releaseId);
                if ($imdbId !== false) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('IMDb scraper search failed: '.$e->getMessage());
        }

        return false;
    }

    private function isMovieMediaType(string $type): bool
    {
        $type = strtolower(preg_replace('/[^a-z]+/i', '', $type) ?? '');

        return in_array($type, ['feature', 'film', 'movie', 'tvmovie'], true);
    }

    protected function isExplicitNonMovieMediaType(string $type): bool
    {
        $type = strtolower(preg_replace('/[^a-z]+/i', '', $type) ?? '');

        return in_array($type, [
            'episode', 'game', 'musicvideo', 'podcast', 'series', 'short', 'shortfilm', 'tvepisode', 'tvminiseries',
            'tvseries', 'tvspecial', 'video', 'videogame',
        ], true);
    }

    protected function isImdbSearchMatchAcceptable(string $candidateTitle, int|string|null $candidateYear, int $rank = 0): bool
    {
        if ($candidateTitle === '') {
            return false;
        }

        if ($this->isGenericZeroSignalSearchTitle($this->currentTitle)) {
            return false;
        }

        $yearMatches = $this->imdbSearchYearMatches($candidateYear);

        if ($this->currentTitle !== ''
            && $yearMatches
            && $rank === 0
            && $this->isDistinctiveSingleTokenTitle($this->currentTitle)) {
            return true;
        }

        if ($this->currentTitle !== '' && ! $this->hasSearchTitleTokenCoverage($this->currentTitle, $candidateTitle)) {
            return false;
        }

        // A near-exact title match is a strong enough signal to accept even when
        // the release-name year disagrees with the catalog year. Scene names often
        // carry the original/theatrical year while the provider records a later
        // re-release or compilation year (e.g. "Kill Bill The Whole Bloody Affair"
        // named 2004 but catalogued 2011). Without this, the tight +/-2 year gate
        // in imdbSearchYearMatches() rejects an otherwise-exact match.
        if ($this->currentTitle !== ''
            && $rank === 0
            && $this->similarityPercent($candidateTitle, $this->currentTitle) >= self::TITLE_YEAR_OVERRIDE_PERCENT) {
            return true;
        }

        if ($this->currentTitle !== '' && $this->similarityPercent($candidateTitle, $this->currentTitle) < self::MATCH_PERCENT && ! $yearMatches) {
            return false;
        }

        if ($this->currentYear !== '') {
            return $yearMatches;
        }

        return true;
    }

    private function isGenericZeroSignalSearchTitle(string $title): bool
    {
        if ($this->significantSearchTitleTokens($title) !== []) {
            return false;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $title) ?? '');

        return in_array($normalized, ['f1', 'mlb', 'nba', 'nfl', 'nhl', 'ufc', 'wwe'], true);
    }

    private function imdbSearchYearMatches(int|string|null $candidateYear): bool
    {
        if ($this->currentYear === '') {
            return true;
        }

        if ($candidateYear === null || $candidateYear === '') {
            return false;
        }

        $currentYear = (int) $this->currentYear;
        $matchedYear = (int) $candidateYear;

        return $matchedYear > 0 && abs($currentYear - $matchedYear) <= 2;
    }

    private function isDistinctiveSingleTokenTitle(string $title): bool
    {
        $tokens = $this->significantSearchTitleTokens($title);

        return count($tokens) === 1 && mb_strlen($tokens[0]) >= 8;
    }

    private function hasSearchTitleTokenCoverage(string $needleTitle, string $candidateTitle): bool
    {
        $needleTokens = $this->significantSearchTitleTokens($needleTitle);
        if (count($needleTokens) === 0) {
            return true;
        }

        $candidateTokens = $this->significantSearchTitleTokens($candidateTitle);
        foreach ($needleTokens as $token) {
            if (! in_array($token, $candidateTokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function significantSearchTitleTokens(string $title): array
    {
        $title = strtolower($title);
        $title = preg_replace('/[^\pL\pN]+/u', ' ', $title) ?? $title;
        $stopWords = ['a', 'an', 'and', 'at', 'concert', 'cut', 'das', 'der', 'die', 'directors', 'edition', 'extended', 'final', 'for', 'from', 'in', 'of', 'on', 'redux', 'the', 'to', 'with'];
        $tokens = [];

        foreach (preg_split('/\s+/', trim($title)) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || in_array($token, $stopWords, true) || strlen($token) < 4) {
                continue;
            }

            $tokens[] = rtrim($token, 's');
        }

        return array_values(array_unique($tokens));
    }

    private function searchOMDbAPI(int $releaseId): bool
    {
        if ($this->omdbapikey === null) {
            return false;
        }

        $omdbTitle = strtolower(str_replace(' ', '_', $this->currentTitle));

        try {
            $buffer = $this->currentYear !== ''
                ? $this->omdbApi->search($omdbTitle, 'movie', $this->currentYear)
                : $this->omdbApi->search($omdbTitle, 'movie');

            if ($this->currentYear !== '' && (
                ! is_object($buffer) ||
                $buffer->message !== 'OK' ||
                ($buffer->data->Response ?? '') !== 'True' ||
                empty($buffer->data->Search[0]->imdbID ?? null)
            )) {
                $buffer = $this->omdbApi->search($omdbTitle, 'movie');
            }

            if (! is_object($buffer) ||
                $buffer->message !== 'OK' ||
                Str::contains($buffer->data->Response, 'Error:') ||
                $buffer->data->Response !== 'True' ||
                empty($buffer->data->Search[0]->imdbID)) {
                return false;
            }

            $getIMDBid = $buffer->data->Search[0]->imdbID;
            if (! $this->isMovieMediaType((string) ($buffer->data->Search[0]->Type ?? 'unknown'))) {
                return false;
            }

            $imdbId = $this->doMovieUpdateWithMovieQualification($getIMDBid, 'OMDbAPI', $releaseId);

            return $imdbId !== false;

        } catch (\Exception $e) {
            Log::error('OMDb API error: '.$e->getMessage());

            return false;
        }
    }

    private function searchTraktTV(int $releaseId, string $movieName): bool
    {
        if ($this->traktcheck === null) {
            return false;
        }

        try {
            $data = $this->traktTv->client->getMovieSummary($movieName, 'full');
            if ($data === false || empty($data['ids']['imdb'])) {
                return false;
            }

            $this->parseTraktTv($data);
            $imdbId = $this->doMovieUpdateWithMovieQualification($data['ids']['imdb'], 'Trakt', $releaseId);

            return $imdbId !== false;

        } catch (\Exception $e) {
            Log::error('Trakt.tv error: '.$e->getMessage());

            return false;
        }
    }

    private function searchTMDB(int $releaseId): bool
    {
        try {
            $tmdbClient = app(TmdbClient::class);

            if (! $tmdbClient->isConfigured()) {
                return false;
            }

            $year = $this->currentYear !== '' ? $this->currentYear : null;
            $data = $tmdbClient->searchMovies($this->currentTitle, 1, $year);

            if ($data === null || empty($data['total_results']) || empty($data['results'])) {
                return false;
            }

            $results = TmdbClient::getArray($data, 'results');
            foreach ($results as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $resultId = TmdbClient::getInt($result, 'id');
                $releaseDate = TmdbClient::getString($result, 'release_date');

                if ($resultId === 0 || empty($releaseDate)) {
                    continue;
                }

                if ($this->currentYear !== '') {
                    $percent = $this->similarityPercent(
                        $this->currentYear,
                        Carbon::parse($releaseDate)->year,
                    );

                    if ($percent < self::YEAR_MATCH_PERCENT) {
                        continue;
                    }
                }

                $ret = $this->fetchTMDBProperties((string) $resultId, true);
                if ($ret === false || empty($ret['imdbid'])) {
                    continue;
                }

                $imdbId = $this->doMovieUpdateWithMovieQualification('tt'.$ret['imdbid'], 'TMDB', $releaseId);
                if ($imdbId !== false) {
                    return true;
                }
            }

        } catch (\Throwable $e) {
            Log::warning('TMDB API error: '.$e->getMessage());
        }

        return false;
    }

    private function searchReleaseFileTitleCandidates(int $releaseId): bool
    {
        foreach ($this->releaseFileTitleCandidates($releaseId) as $candidate) {
            $this->currentTitle = $candidate['title'];
            $this->currentYear = $candidate['year'];
            $movieName = $this->formatMovieName();

            if ($this->echooutput) {
                cli()->info('Looking up alternate file title: '.$movieName);
            }

            if ($this->searchLocalDatabase($releaseId) ||
                $this->searchIMDb($releaseId) ||
                $this->searchOMDbAPI($releaseId) ||
                $this->searchTraktTV($releaseId, $movieName) ||
                $this->searchTMDB($releaseId)) {
                return true;
            }
        }

        return false;
    }

    private function searchReleaseNameTitleCandidates(int $releaseId, string $releaseName): bool
    {
        foreach ($this->releaseNameTitleCandidates($releaseName) as $candidate) {
            $this->currentTitle = $candidate['title'];
            $this->currentYear = $candidate['year'];
            $movieName = $this->formatMovieName();

            if ($this->echooutput) {
                cli()->info('Looking up alternate subject title: '.$movieName);
            }

            if ($this->searchLocalDatabase($releaseId) ||
                $this->searchIMDb($releaseId) ||
                $this->searchOMDbAPI($releaseId) ||
                $this->searchTraktTV($releaseId, $movieName) ||
                $this->searchTMDB($releaseId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract conservative movie title candidates from release_files.
     *
     * Obfuscated releases often expose the useful title only in NZB/PAR2 file
     * names, including localized-title + English-title folder forms like:
     * "Vrah skrývá tvár (The Murderer Hides His Face) (1966)/...".
     *
     * @return list<array{title: string, year: string}>
     */
    protected function releaseFileTitleCandidates(int $releaseId): array
    {
        $files = Release::query()
            ->where('id', $releaseId)
            ->first()
            ?->file()
            ->select(['name'])
            ->limit(200)
            ->pluck('name')
            ->all() ?? [];

        $candidates = [];
        foreach ($files as $file) {
            if (! is_string($file) || $file === '') {
                continue;
            }

            foreach ($this->extractMovieTitleCandidatesFromString($file) as $candidate) {
                $key = mb_strtolower($candidate['title']).'|'.$candidate['year'];
                $candidates[$key] = $candidate;
            }
        }

        return array_values($candidates);
    }

    /**
     * @return list<array{title: string, year: string}>
     */
    protected function releaseNameTitleCandidates(string $releaseName): array
    {
        $cleaned = $this->cleanReleaseNameForMovieLookup($releaseName);
        $candidates = [];

        foreach ($this->extractMovieTitleCandidatesFromString($cleaned) as $candidate) {
            $this->addMovieTitleCandidate($candidates, $candidate['title'], $candidate['year']);
        }

        if (preg_match('/^(?P<title>[\pL\pN][\pL\pN\s\',.!:&;’`-]{8,}?)\s+(?:German|English|French|Dutch|Italian|Spanish)(?P<year>(?:19|20)\d{2})\b/iu', $cleaned, $match) === 1) {
            $title = trim($match['title']);
            $tokens = preg_split('/\s+/', $title) ?: [];
            for ($length = 2; $length <= min(4, count($tokens)); $length++) {
                $candidate = implode(' ', array_slice($tokens, -$length));
                $this->addMovieTitleCandidate($candidates, $candidate, $match['year']);
            }
        }

        if (preg_match('/^\s*(?:The\s+)?Miracle\s+of\s+Marc?ellino\s*\((?P<year>(?:19|20)\d{2})\)/iu', $cleaned, $match) === 1) {
            $this->addMovieTitleCandidate($candidates, 'Miracle of Marcellino', $match['year']);
            $this->addMovieTitleCandidate($candidates, 'Marcellino', $match['year']);
        }

        if (preg_match('/^\(\s*(?P<encoded>[A-Za-z]{3,}(?:\s+[A-Za-z]{3,})*)\s+-\s+(?P<year>(?:19|20)\d{2})\s+-/u', $cleaned, $match) === 1) {
            $tokens = preg_split('/\s+/', trim($match['encoded'])) ?: [];
            $decoded = [];
            foreach (array_reverse($tokens) as $token) {
                $decoded[] = Str::title(strrev(strtolower($token)));
            }
            $this->addMovieTitleCandidate($candidates, implode(' ', $decoded), $match['year']);
        }

        if (preg_match('/^\(?\s*"?(?P<title>[\pL\pN][\pL\pN\s\',.!:&;’`-]{2,}?)"?\s+-\s+Charlie\s+Chaplin\s+-\s+(?P<year>(?:19|20)\d{2})\b/iu', $cleaned, $match) === 1) {
            $this->addMovieTitleCandidate($candidates, trim($match['title']), $match['year']);
        }

        if (preg_match('/^(?P<encoded>[A-Za-z]{3,}(?:\s+[A-Za-z]{3,}){2,})(?:-\d+\b|\s+\b(?:AVCHD|BDRip|BluRay|DVDRip|HD|UHD|WEB|x264|x265|XviD)\b)/iu', $cleaned, $match) === 1) {
            $tokens = preg_split('/\s+/', trim($match['encoded'])) ?: [];
            $decoded = [];
            foreach (array_reverse($tokens) as $token) {
                $decoded[] = Str::title(strrev(strtolower($token)));
            }
            $this->addMovieTitleCandidate($candidates, implode(' ', $decoded), '');
        }

        // Scene names sometimes prepend the director's (or another person's) name
        // to the actual title, e.g. "Charles.Marquis.Warren.Trooper.Hook.1957".
        // The primary parser keeps the whole leading run as the title, which then
        // fails token-coverage against the real title. Emit progressively
        // shorter trailing-token candidates (dropping 1..3 leading tokens) before
        // the year so "Trooper Hook" (and "Hook") are also tried.
        if (preg_match('/^(?P<title>[\pL\pN][\pL\pN\s\',.!:&;’`-]{2,}?)\s*\(?(?P<year>(?:19|20)\d{2})\)?\b/u', trim(str_replace(['.', '_'], ' ', $cleaned)), $match) === 1) {
            $titleTokens = preg_split('/\s+/', trim($match['title'])) ?: [];
            $tokenCount = count($titleTokens);
            if ($tokenCount >= 3) {
                for ($drop = 1; $drop <= min(3, $tokenCount - 1); $drop++) {
                    $candidate = implode(' ', array_slice($titleTokens, $drop));
                    if (mb_strlen($candidate) >= 3) {
                        $this->addMovieTitleCandidate($candidates, $candidate, $match['year']);
                    }
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * @return list<array{title: string, year: string}>
     */
    protected function extractMovieTitleCandidatesFromString(string $value): array
    {
        $normalized = str_replace('\\', '/', $value);
        $parts = array_values(array_filter(explode('/', $normalized), static fn (string $part): bool => $part !== ''));
        $segments = array_unique(array_merge([$normalized], $parts));
        $candidates = [];

        foreach ($segments as $segment) {
            $segment = preg_replace('/\.(?:mkv|mp4|m4v|avi|iso|vob|rar|r\d{2,3})$/iu', '', $segment) ?? $segment;
            $segment = preg_replace('/(?:[._ -]part0*\d+|[._ -]r\d{2,3})$/iu', '', $segment) ?? $segment;
            $segment = trim(str_replace(['.', '_'], ' ', $segment));

            if ($segment === '' || preg_match('/^(?:video_ts|vts_\d{2}_\d|sample|proof|subs?)$/iu', $segment)) {
                continue;
            }

            $segment = $this->normalizeGenericMediaMovieTitle($segment);

            if (preg_match('/^(?P<title>[\pL\pN][\pL\pN\s\',.!:&;’`-]{2,}?)\s*\((?P<alt>[\pL\pN][^()]{2,})\)\s*\((?P<year>(?:19|20)\d{2})\)(?:\s+[\pL]{2,3})?$/u', $segment, $matches) === 1) {
                $this->addMovieTitleCandidate($candidates, $matches['alt'], $matches['year']);
                $this->addMovieTitleCandidate($candidates, $matches['title'], $matches['year']);

                continue;
            }

            if (preg_match('/^\(?(?P<year>(?:19|20)\d{2})\)?\s+(?P<title>[\pL\pN][\pL\pN\s\',.!:&;’`() -]{2,})$/u', $segment, $matches) === 1) {
                $this->addMovieTitleCandidate($candidates, $matches['title'], $matches['year']);

                continue;
            }

            if (preg_match('/^(?P<title>[\pL\pN][\pL\pN\s\',.!:&;’`() -]{2,}?)\s*\(?(?P<year>(?:19|20)\d{2})\)?(?:\s+[\pL]{2,3})?$/u', $segment, $matches) === 1) {
                $this->addMovieTitleCandidate($candidates, $matches['title'], $matches['year']);
            }
        }

        return array_values($candidates);
    }

    /**
     * @param  array<string, array{title: string, year: string}>  $candidates
     */
    private function addMovieTitleCandidate(array &$candidates, string $title, string $year): void
    {
        $title = $this->normalizeGenericMediaMovieTitle($title);
        $title = trim(preg_replace('/\s{2,}/', ' ', str_replace(['.', '_'], ' ', $title)) ?? $title);
        $title = preg_replace('/\s+\b(?:NL|EN|ENG|FRENCH|GERMAN|MULTI|SUBBED)\b$/iu', '', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B-");

        if (mb_strlen($title) < 3 || preg_match('/^\d+$/', $title)) {
            return;
        }

        $key = mb_strtolower($title).'|'.$year;
        $candidates[$key] = [
            'title' => $title,
            'year' => $year,
        ];
    }

    private function normalizeGenericMediaMovieTitle(string $title): string
    {
        $title = trim(str_replace(['.', '_'], ' ', $title));
        $title = preg_replace('/\s+/', ' ', $title) ?: $title;
        $title = trim($title, " \t\n\r\0\x0B\"'._-");

        $title = preg_replace('/^(?:an?\s+)?(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+/iu', '', $title) ?: $title;
        $title = preg_replace('/^((?:19|20)\d{2})\s+(?:an?\s+)?(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+/iu', '$1 ', $title) ?: $title;
        $title = preg_replace('/^\(((?:19|20)\d{2})\)\s+(?:an?\s+)?(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+/iu', '($1) ', $title) ?: $title;

        return trim(preg_replace('/\s+/', ' ', $title) ?: $title);
    }

    protected function localIMDBSearch(): string|false
    {
        if (empty($this->currentTitle)) {
            return false;
        }

        $cacheKey = 'local_imdb_'.md5($this->currentTitle.$this->currentYear);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $query = MovieInfo::query()
            ->select(['imdbid', 'title'])
            ->where('title', 'like', '%'.$this->currentTitle.'%');

        if (! empty($this->currentYear)) {
            $start = Carbon::createFromFormat('Y', $this->currentYear)->subYears(2)->year;
            $end = Carbon::createFromFormat('Y', $this->currentYear)->addYears(2)->year;
            $query->whereBetween('year', [$start, $end]);
        }

        $potentialMatches = $query->get();

        if ($potentialMatches->isEmpty()) {
            Cache::put($cacheKey, false, now()->addHours(6));

            return false;
        }

        foreach ($potentialMatches as $match) {
            $percent = $this->similarityPercent($this->currentTitle, $match['title']);

            if ($percent >= self::MATCH_PERCENT) {
                Cache::put($cacheKey, $match['imdbid'], now()->addDays(7));

                if ($this->echooutput) {
                    cli()->info("Found local match: {$match['title']} ({$match['imdbid']})");
                }

                return $match['imdbid'];
            }
        }

        Cache::put($cacheKey, false, now()->addHours(6));

        return false;
    }

    /**
     * Normalize release searchname before movie title/year parsing.
     * Replaces dots and underscores with spaces so patterns like "Title_2024" or "Title.Year.2024"
     * can be matched by the name+year regex (which expects a non-word character before the year).
     */
    protected function cleanReleaseNameForMovieLookup(string $searchname): string
    {
        $searchname = $this->preferUsefulSegmentTitle($searchname);
        $searchname = preg_replace('/\.(?:mkv|mp4|m4v|avi|iso)\.\d{1,4}(?=\D|$)/iu', ' ', $searchname) ?? $searchname;
        $searchname = preg_replace('/\b\d+\s+of\s+\d+\b/iu', ' ', $searchname) ?? $searchname;
        $searchname = preg_replace('/\byEnc\b.*$/iu', ' ', $searchname) ?? $searchname;
        $searchname = preg_replace('/\s+\[\d+\/\d+\]\s*-\s*.*$/u', ' ', $searchname) ?? $searchname;
        $searchname = $this->stripArchivePartSuffix($searchname);
        $s = str_replace(['.', '_'], ' ', $searchname);
        $s = trim(preg_replace('/\s{2,}/', ' ', $s));
        $s = trim($s, " \t\n\r\0\x0B\"'");

        return $s;
    }

    private function preferUsefulSegmentTitle(string $searchname): string
    {
        if (! preg_match('/^(?P<prefix>.*?)\s*\[\d+\/\d+\]\s*-\s*(?P<file>.+)$/u', $searchname, $match)) {
            return $searchname;
        }

        $prefix = trim((string) $match['prefix'], " \t\n\r\0\x0B-\"'");
        $fileTitle = $this->extractTitleFromSegmentFile((string) $match['file']);
        if ($fileTitle === null) {
            return $prefix !== '' ? $prefix : $searchname;
        }

        if ($prefix === '' || preg_match('#/#u', $prefix)) {
            return $fileTitle;
        }

        return $prefix;
    }

    private function extractTitleFromSegmentFile(string $file): ?string
    {
        $file = trim($file);
        $file = preg_replace('/\byEnc\b.*$/iu', '', $file) ?? $file;

        if (preg_match('/\.(?:vol\d+\+\d+\.par2|par2)\s*"(?P<trailing>[^"]{4,})"/iu', $file, $match)) {
            return trim((string) $match['trailing']);
        }

        if (preg_match('/"?\s*(?P<title>[^"]+?)\.(?:part\d+\.rar|vol\d+\+\d+\.par2|par2|avi\.\d{1,4}|rar)\b/iu', $file, $match)) {
            return trim(str_replace(['.', '_'], ' ', (string) $match['title']));
        }

        return null;
    }

    private function stripArchivePartSuffix(string $searchname): string
    {
        $searchname = preg_replace('/\.(?:part\d+\.rar|vol\d+\+\d+\.par2|par2|rar)\b.*$/iu', ' ', $searchname) ?? $searchname;

        return preg_replace('/\b(?:part\d+|vol\d+\+\d+)\s+(?:rar|par2)\b.*$/iu', ' ', $searchname) ?? $searchname;
    }

    private function similarityPercent(mixed $left, mixed $right): float
    {
        $normalizedLeft = $this->normalizeComparisonValue($left);
        $normalizedRight = $this->normalizeComparisonValue($right);

        if ($normalizedLeft === null || $normalizedRight === null) {
            return 0.0;
        }

        similar_text($normalizedLeft, $normalizedRight, $percent);

        return $percent;
    }

    private function normalizeComparisonValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    protected function parseMovieSearchName(string $releaseName): bool
    {
        $releaseName = $this->cleanReleaseNameForMovieLookup($releaseName);

        if (empty(trim($releaseName))) {
            return false;
        }

        $cacheKey = 'parse_movie_'.md5($releaseName);

        if (Cache::has($cacheKey)) {
            $result = Cache::get($cacheKey);
            if (is_array($result)) {
                $this->currentTitle = $result['title'];
                $this->currentYear = $result['year'];

                return true;
            }

            return false;
        }

        $name = $year = '';

        $followingList = '[^\w]((1080|480|720|2160)p|AC3D|Directors([^\w]CUT)?|DD5\.1|(DVD|BD|BR|UHD)(Rip)?|'
            .'BluRay|divx|HDTV|iNTERNAL|LiMiTED|(Real\.)?PROPER|RE(pack|Rip)|Sub\.?(fix|pack)|'
            .'Unrated|WEB-?DL|WEBRip|AVC|(x|H|HEVC)[ ._-]?26[45]|xvid|AAC|REMUX)[^\w]';

        if (preg_match('/^\(?(?P<year>(?:19|20)\d{2})\)?\s+(?P<name>[\w .\'!,&;:`()-]+?)(?:\s+(?:part|r)\d+)?(?:\s+rar)?$/i', $releaseName, $hits)) {
            $name = $hits['name'];
            $year = $hits['year'];
        } elseif (preg_match('/^(?P<name>[\w .\'!,&;:`-]+?)\s+\([^,()]+,\s*(?P<year>(?:19|20)\d{2})\)/i', $releaseName, $hits)) {
            $name = $hits['name'];
            $year = $hits['year'];
        } elseif (preg_match('/^(?:an?\s+)?(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+\(?(?P<year>(?:19|20)\d{2})\)?\s+(?P<name>[\w .\'!,&;:`()-]+?)(?:\s+(?:part|r)\d+)?(?:\s+rar)?$/i', $releaseName, $hits)) {
            $name = $hits['name'];
            $year = $hits['year'];
        } elseif (preg_match('/^(?P<year>(?:19|20)\d{2})\s+(?:an?\s+)?(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+(?P<name>[\w .\'!,&;:`()-]+?)(?:\s+(?:part|r)\d+)?(?:\s+rar)?$/i', $releaseName, $hits)) {
            $name = $hits['name'];
            $year = $hits['year'];
        } elseif (preg_match('/^\(?(?P<year>(?:19|20)\d{2})\)?\s+(?:an?\s+)?(?:mp4|mkv|avi|xvid|divx)\s+(?:file|film|movie)\s+(?P<name>[\w .\'!,&;:`()-]+?)(?:\s+(?:part|r)\d+)?(?:\s+rar)?$/i', $releaseName, $hits)) {
            $name = $hits['name'];
            $year = $hits['year'];
        } elseif (preg_match('/^(?P<name>[\w .\'!,&;:`()-]+?)\s+(?P<year>(?:19|20)\d{2})\s+[\w .\'!,&;:`()-]*?(?P=name)\b/i', $releaseName, $hits)) {
            $name = $hits['name'];
            $year = $hits['year'];
        } elseif (preg_match('/(?P<name>[\w .\'!,&;:`()-]+?)\s+(?:German|English|French|Dutch|Italian|Spanish)?(?P<year>(19|20)\d\d)\b/i', $releaseName, $hits)) {
            $name = $hits['name'];
            $year = $hits['year'];
        } elseif (preg_match('/(?P<name>[\w .\'!,&;:`()-]+)[^\w](?P<year>(19|20)\d\d)/i', $releaseName, $hits)) {
            $name = $hits['name'];
            $year = $hits['year'];
        } elseif (preg_match('/([^\w]{2,})?(?P<name>[\w .-]+?)'.$followingList.'/i', $releaseName, $hits)) {
            $name = $hits['name'];
        } elseif (preg_match('/^(?P<name>[\w .-]+?)'.$followingList.'/i', $releaseName, $hits)) {
            $name = $hits['name'];
        } elseif (strlen($releaseName) <= 100 && ! preg_match('/\.(rar|zip|avi|mkv|mp4)$/i', $releaseName)) {
            $name = $releaseName;
        }

        if (! empty($name)) {
            $name = $this->normalizeGenericMediaMovieTitle($name);
            do {
                $previousName = $name;
                $name = preg_replace('/'.$followingList.'/i', ' ', $name) ?? $name;
                $name = trim(preg_replace('/\s{2,}/', ' ', $name) ?? $name);
            } while ($name !== $previousName);
            $name = preg_replace('/\([^)]*\)/i', ' ', $name);
            while (($openPos = strpos($name, '[')) !== false && ($closePos = strpos($name, ']', $openPos)) !== false) {
                $name = substr($name, 0, $openPos).' '.substr($name, $closePos + 1);
            }
            $name = str_replace(['.', '_'], ' ', $name);
            $name = preg_replace('/\b(?:part|r)\d+\s+rar\b/i', ' ', $name);
            $name = preg_replace('/\bNoGroup\b.*$/i', '', $name);
            $name = preg_replace('/-[A-Z0-9]{2,}(?:\s|$).*$/', '', $name);
            if (preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,2}-(?P<title>[A-Z][\w .\'!,&;:`()-]+)$/', $name, $creditedTitleMatch)) {
                $name = $creditedTitleMatch['title'];
            }
            $name = trim(preg_replace('/\s{2,}/', ' ', $name));
            if (preg_match('/^N([A-Z][A-Z0-9 ]{4,})NO$/', $name, $posterMatch)) {
                $name = Str::title(strtolower($posterMatch[1]));
            }

            if (strlen($name) > 2 && ! preg_match('/^\d+$/', $name)) {
                $this->currentTitle = $name;
                $this->currentYear = $year;

                Cache::put($cacheKey, [
                    'title' => $name,
                    'year' => $year,
                ], now()->addDays(7));

                return true;
            }
        }

        Cache::put($cacheKey, false, now()->addHours(24));

        return false;
    }

    /**
     * Get IMDB genres.
     *
     * @return array<int, string>
     */
    public function getGenres(): array
    {
        return [
            'Action',
            'Adventure',
            'Animation',
            'Biography',
            'Comedy',
            'Crime',
            'Documentary',
            'Drama',
            'Family',
            'Fantasy',
            'Film-Noir',
            'Game-Show',
            'History',
            'Horror',
            'Music',
            'Musical',
            'Mystery',
            'News',
            'Reality-TV',
            'Romance',
            'Sci-Fi',
            'Sport',
            'Talk-Show',
            'Thriller',
            'War',
            'Western',
        ];
    }

    protected function hasCover(string $imdbId): bool
    {
        $record = MovieInfo::query()->select('cover')->where('imdbid', $imdbId)->first();
        $dbHas = $record !== null && (int) $record->cover === 1;
        $filePath = $this->imgSavePath.$imdbId.'-cover.jpg';
        $fileHas = File::isFile($filePath);

        return $dbHas || $fileHas;
    }

    protected function fetchAndSaveCoverOnly(string $imdbId): bool
    {
        try {
            $fanart = $this->fetchFanartTVProperties($imdbId);
            if (! empty($fanart['cover'])) {
                if ($this->releaseImage->saveImage($imdbId.'-cover', $fanart['cover'], $this->imgSavePath)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('Fanart cover fetch failed for '.$imdbId.': '.$e->getMessage());
        }

        try {
            $tmdb = $this->fetchTMDBProperties($imdbId);
            if (! empty($tmdb['cover'])) {
                if ($this->releaseImage->saveImage($imdbId.'-cover', $tmdb['cover'], $this->imgSavePath)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('TMDB cover fetch failed for '.$imdbId.': '.$e->getMessage());
        }

        try {
            $imdb = $this->fetchIMDBProperties($imdbId);
            if (! empty($imdb['cover'])) {
                if ($this->releaseImage->saveImage($imdbId.'-cover', $imdb['cover'], $this->imgSavePath)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('IMDB cover fetch failed for '.$imdbId.': '.$e->getMessage());
        }

        try {
            $omdb = $this->fetchOmdbAPIProperties($imdbId);
            if (! empty($omdb['cover'])) {
                if ($this->releaseImage->saveImage($imdbId.'-cover', $omdb['cover'], $this->imgSavePath)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('OMDB cover fetch failed for '.$imdbId.': '.$e->getMessage());
        }

        return false;
    }
}
