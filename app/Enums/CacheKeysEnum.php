<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Хранение всех наименований ключей кэша, чтобы не создать 2 одинаковых
 */
enum CacheKeysEnum: string
{
    case AllChannelsKey = 'channels.all';

    case PairLimitsForSymbolInExchange = '%s.%s.limits';

    case CurrentPriceForSymbolInExchange = '%s.%s.price';

    public function getKeyForSymbolLimits(string $exchange, string $symbol): string
    {
        return sprintf($this->value, $exchange, $symbol);
    }

    public function getKeyForSymbolPrice(string $exchange, string $symbol): string
    {
        return sprintf($this->value, $exchange, $symbol);
    }
}
