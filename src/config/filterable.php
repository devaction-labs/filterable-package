<?php

return [
    'cache' => [
        'enabled' => env('FILTERABLE_CACHE_ENABLED', true),
        'ttl' => env('FILTERABLE_CACHE_TTL', 60),
        'prefix' => env('FILTERABLE_CACHE_PREFIX', 'filterable_'),
    ],
];
