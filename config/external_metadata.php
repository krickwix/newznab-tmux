<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('NNTMUX_METADATA_REFRESH_ENABLED', false),
    'limit' => (int) env('NNTMUX_METADATA_REFRESH_LIMIT', 25),
    'postprocess_enabled' => (bool) env('NNTMUX_METADATA_REFRESH_POSTPROCESS_ENABLED', false),
    'postprocess_limit' => (int) env('NNTMUX_METADATA_REFRESH_POSTPROCESS_LIMIT', 10),
    'sleep_ms' => (int) env('NNTMUX_METADATA_REFRESH_SLEEP_MS', 2500),
    'timer' => (int) env('NNTMUX_METADATA_REFRESH_TIMER', 900),
    'timeout' => (int) env('NNTMUX_METADATA_REFRESH_TIMEOUT', 20),
    'connect_timeout' => max(1, (int) env('NNTMUX_METADATA_CONNECT_TIMEOUT', 3)),
    // A lookup that got a healthy empty answer is not repeated for this long.
    'negative_ttl' => max(0, (int) env('NNTMUX_METADATA_NEGATIVE_TTL', 86400)),
    // Consecutive failures (timeouts, 5xx, 401/403/429) that open a source's breaker, and
    // how long it stays open. A threshold of 0 disables the breaker.
    'breaker_threshold' => max(0, (int) env('NNTMUX_METADATA_BREAKER_THRESHOLD', 3)),
    'breaker_cooldown' => max(1, (int) env('NNTMUX_METADATA_BREAKER_COOLDOWN', 900)),
    'sources' => [
        'srrdb' => [
            'enabled' => (bool) env('NNTMUX_METADATA_SOURCE_SRRDB', true),
            'base_url' => env('NNTMUX_SRRDB_BASE_URL', 'https://api.srrdb.com/v1'),
        ],
        'predb-net' => [
            'enabled' => (bool) env('NNTMUX_METADATA_SOURCE_PREDB_NET', true),
            'base_url' => env('NNTMUX_PREDB_NET_BASE_URL', 'https://api.predb.net'),
        ],
        'predb-ovh' => [
            'enabled' => (bool) env('NNTMUX_METADATA_SOURCE_PREDB_OVH', true),
            'base_url' => env('NNTMUX_PREDB_OVH_BASE_URL', 'https://predb.ovh/api/v1'),
        ],
        'xrel' => [
            'enabled' => (bool) env('NNTMUX_METADATA_SOURCE_XREL', true),
            'base_url' => env('NNTMUX_XREL_BASE_URL', 'https://api.xrel.to/v2'),
        ],
        'xrel-p2p' => [
            'enabled' => (bool) env('NNTMUX_METADATA_SOURCE_XREL_P2P', true),
            'base_url' => env('NNTMUX_XREL_BASE_URL', 'https://api.xrel.to/v2'),
        ],
        'internet-archive-predb' => [
            'enabled' => (bool) env('NNTMUX_METADATA_SOURCE_IA_PREDB', false),
            'dump_path' => env('NNTMUX_IA_PREDB_DUMP_PATH'),
            'archive_url' => env('NNTMUX_IA_PREDB_URL', 'https://archive.org/details/predb'),
        ],
        'nzbindex' => [
            'enabled' => (bool) env('NNTMUX_METADATA_SOURCE_NZBINDEX', false),
            'base_url' => env('NNTMUX_NZBINDEX_BASE_URL', 'https://www.nzbindex.com/api'),
            'api_key' => env('NNTMUX_NZBINDEX_API_KEY'),
        ],
    ],
];
