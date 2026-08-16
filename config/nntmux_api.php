<?php

return [
    'anidb_api_key' => env('ANIDB_APIKEY', ''),
    'fanarttv_api_key' => env('FANARTTV_APIKEY', ''),
    'google_books_api_key' => env('GOOGLE_BOOKS_API_KEY', ''),
    'isbndb_api_key' => env('ISBNDB_API_KEY', ''),
    // Disabled: imdbapi.dev is an unrelated third-party endpoint and is currently unavailable.
    'imdbapi_dev_enabled' => env('IMDBAPI_DEV_ENABLED', false),
    'imdbapi_dev_base_url' => env('IMDBAPI_DEV_BASE_URL', 'https://api.imdbapi.dev'),
    // In-cluster IMDb metadata service (imdb-metadata.media.svc). Consulted before any web source:
    // it answers from the local dataset in milliseconds and cannot be WAF-blocked. Empty disables it.
    'local_imdb_metadata_url' => env('LOCAL_IMDB_METADATA_URL', ''),
    'imdbapi_dev_min_interval_seconds' => env('IMDBAPI_DEV_MIN_INTERVAL_SECONDS', 15),
    'imdbapi_dev_cooldown_seconds' => env('IMDBAPI_DEV_COOLDOWN_SECONDS', 300),
    'movie_lookup_max_attempts' => env('MOVIE_LOOKUP_MAX_ATTEMPTS', 3),
    'movie_lookup_retry_minutes' => env('MOVIE_LOOKUP_RETRY_MINUTES', 30),
    'omdb_api_key' => env('OMDB_APIKEY', ''),
    // How long to stop calling OMDB after it answers "Request limit reached!".
    // Its quota is DAILY, so this is an hour rather than the five minutes
    // imdbapi.dev uses -- long enough to stop hammering a spent allowance,
    // short enough to pick the reset up promptly. 0 disables the cooldown.
    'omdb_cooldown_seconds' => (int) env('OMDB_COOLDOWN_SECONDS', 3600),
    'trakttv_api_key' => env('TRAKTTV_APIKEY', ''),
];
