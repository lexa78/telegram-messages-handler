<?php

declare(strict_types=1);


namespace App\Services;

use Illuminate\Support\Facades\Cache;

abstract class AbstractCacheService
{
    // Сутки в секундах
    public const int CACHE_TTL = 86400;

    public const int TWO_SECONDS_CACHE_TTL = 2;

    public const int TREE_MINUTES_CACHE_TTL = 180;

    public const int ONE_HOUR_CACHE_TTL = 3600;

    public const int HALF_OF_HOUR_CACHE_TTL = 1800;

    /**
     * Перезаписываем кэш
     */
    public function refreshCache(string $key): mixed
    {
        Cache::forget($key);
        return $this->getAllCached();
    }
}
