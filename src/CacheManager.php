<?php

namespace DevactionLabs\FilterablePackage;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class CacheManager
{
    protected static array $memoryCache = [];

    public static function isEnabled(): bool
    {
        return Config::get('filterable.cache.enabled', true);
    }

    public static function getTtl(): int
    {
        return Config::get('filterable.cache.ttl', 60);
    }

    public static function getPrefix(): string
    {
        return Config::get('filterable.cache.prefix', 'filterable_');
    }

    public static function remember(string $key, int $ttl, Closure $callback)
    {
        if (isset(static::$memoryCache[$key])) {
            return static::$memoryCache[$key];
        }

        $value = Cache::tags(['filterable'])->remember($key, $ttl, $callback);
        static::$memoryCache[$key] = $value;

        return $value;
    }

    public static function clear(): void
    {
        Cache::tags(['filterable'])->flush();
        static::$memoryCache = [];
    }
}
