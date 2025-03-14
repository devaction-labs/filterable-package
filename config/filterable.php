<?php

return [
    'cache' => [
        'enabled' => env('FILTERABLE_CACHE_ENABLED', true),
        'ttl' => env('FILTERABLE_CACHE_TTL', 60),
        'prefix' => env('FILTERABLE_CACHE_PREFIX', 'filterable_'),
        'memory_ttl' => env('FILTERABLE_CACHE_MEMORY_TTL', 60),
    ],
    'eager_loading' => [
        'enabled' => env('FILTERABLE_EAGER_LOADING_ENABLED', true),
    ],
    'query' => [
        'chunk_size' => env('FILTERABLE_QUERY_CHUNK_SIZE', 100),
    ],
];
