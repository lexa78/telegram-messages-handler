<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Enums\Cache\CacheKeysEnum;
use App\Repositories\ChannelRepository;
use App\Services\AbstractCacheService;
use Illuminate\Support\Facades\Cache;

/**
 * Все необходимые действия с данными о каналах
 */
class ChannelService extends AbstractCacheService
{
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
    public function findOrCreate(string $channelTelegramId, string $channelName): mixed
    {
        $this->channelRepository->findOrCreate($channelTelegramId, $channelName);
        return $this->refreshCache(CacheKeysEnum::AllChannelsKey->value);
    }
}
