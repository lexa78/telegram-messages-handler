<?php

declare(strict_types=1);

namespace App\Enums\Cache;

/**
 * Хранение всех наименований ключей кэша, чтобы не создать 2 одинаковых
 */
enum CacheKeysEnum: string
{
    /** Информация о всех каналах */
    case AllChannelsKey = 'channels.all';

    /** Лимиты по парам для простановки ордера, полученные с биржи */
    case PairLimitsForSymbolInExchange = '%s.%s.limits';

    /** Текущая цена пары на бирже */
    case CurrentPriceForSymbolInExchange = '%s.%s.price';

    case CurrentBalanceForExchange = '%s.balance';

    public function getKeyForSymbolLimits(string $exchange, string $symbol): string
    {
        return sprintf($this->value, $exchange, $symbol);
    }

    public function getKeyForSymbolPrice(string $exchange, string $symbol): string
    {
        return sprintf($this->value, $exchange, $symbol);
    }

    public function getKeyForBalance(string $exchange): string
    {
        return sprintf($this->value, $exchange);
    }
}
