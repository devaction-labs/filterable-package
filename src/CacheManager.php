<?php

namespace DevactionLabs\FilterablePackage;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class CacheManager
{
    protected static array $memoryCache = [];
    protected static int $defaultMemoryCacheTtl = 60;
    protected static array $memoryCacheTimestamps = [];

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

    public static function getMemoryCacheTtl(): int
    {
        return Config::get('filterable.cache.memory_ttl', static::$defaultMemoryCacheTtl);
    }

    public static function remember(string $key, int $ttl, Closure $callback): mixed
    {
        if (isset(static::$memoryCache[$key])) {
            if (time() < static::$memoryCacheTimestamps[$key]) {
                return static::$memoryCache[$key];
            }
            unset(static::$memoryCache[$key], static::$memoryCacheTimestamps[$key]);
        }

        if (!static::isEnabled()) {
            $result = $callback();
            static::$memoryCache[$key] = $result;
            static::$memoryCacheTimestamps[$key] = time() + static::getMemoryCacheTtl();
            return $result;
        }

        $cacheKey = static::getPrefix() . $key;

        $result = $callback();

        try {
            if (is_object($result) && method_exists($result, 'toArray')) {
                $cacheData = $result->toArray();
                Cache::tags(['filterable'])->put($cacheKey, $cacheData, $ttl);
            } else {
                Cache::tags(['filterable'])->put($cacheKey, $result, $ttl);
            }
        } catch (\Exception $e) {
             Log::error('Erro ao armazenar em cache: ' . $e->getMessage());
        }

        static::$memoryCache[$key] = $result;
        static::$memoryCacheTimestamps[$key] = time() + static::getMemoryCacheTtl();

        return $result;
    }

    public static function clear(): void
    {
        Cache::tags(['filterable'])->flush();
        static::clearMemoryCache();
    }

    public static function clearMemoryCache(): void
    {
        static::$memoryCache = [];
        static::$memoryCacheTimestamps = [];
    }

    public static function forget(string $key): void
    {
        $cacheKey = static::getPrefix() . $key;
        Cache::tags(['filterable'])->forget($cacheKey);

        if (isset(static::$memoryCache[$key])) {
            unset(static::$memoryCache[$key], static::$memoryCacheTimestamps[$key]);
        }
    }
}
