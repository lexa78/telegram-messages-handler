<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKeysEnum;
use App\Repositories\ChannelRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Все необходимые действия с данными о каналах
 */
class ChannelService
{
    // Сутки в секундах
    const int CACHE_TTL = 86400;

    public function __construct(
        private readonly ChannelRepository $channelRepository,
    ) {

    }

    /**
     * Если в кэше нет ключа со всеми каналами, то запоминаем и отдаем
     */
     public function getAllCached(): mixed
    {
        return Cache::remember(CacheKeysEnum::AllChannelsKey->value, self::CACHE_TTL, function () {
            return $this->channelRepository->getAll()->keyBy('cid');
        });
    }

    /**
     * Находит/создает канал и сохраняет его в Cache
     */
    public function findOrCreate(string $channelTelegramId, string $channelName): void
    {
        $this->channelRepository->findOrCreate($channelTelegramId, $channelName);
        $this->refreshCache();
    }

    /**
     * Перезаписываем кэш
     */
    public function refreshCache(): mixed
    {
        Cache::forget(CacheKeysEnum::AllChannelsKey->value);
        return $this->getAllCached();
    }
}
