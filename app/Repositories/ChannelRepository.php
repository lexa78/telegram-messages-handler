<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Channel;
use Illuminate\Support\Collection;

/**
 * Работа с таблицей channels
 */
class ChannelRepository extends AbstractRepository
{
    public function getAll(): Collection
    {
        return Channel::query()->select(['id', 'cid', 'is_for_handle'])->get();
    }

    public function findOrCreate(string $channelTelegramId, string $channelName): Channel
    {
        return Channel::query()->firstOrCreate(
            ['cid' => $channelTelegramId],
            ['name' => $channelName],
        );
    }

    public function update(Channel $channel, array $data): void
    {
        $channel->update($data);
    }
}
