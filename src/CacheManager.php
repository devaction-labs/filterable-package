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

    public static function remember(string $key, int $ttl, Closure $callback): mixed
    {
        if (isset(static::$memoryCache[$key])) {
            return static::$memoryCache[$key];
        }

        if (!static::isEnabled()) {
            return static::$memoryCache[$key] = $callback();
        }

        $cacheKey = static::getPrefix() . $key;

        return static::$memoryCache[$key] = Cache::tags(['filterable'])->remember($cacheKey, $ttl, $callback);
    }

    public static function clear(): void
    {
        Cache::tags(['filterable'])->flush();
        static::$memoryCache = [];
    }
}
