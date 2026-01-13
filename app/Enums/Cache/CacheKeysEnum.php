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

    /** Текущий баланс на бирже */
    case CurrentBalanceForExchange = '%s.balance';

    /** Т.к. в канале Ks сообщения приходят в ответ на предыдущее, то храним все сообщения в виде id => message */
    case KsChannelMessages = 'ks.messages.%s';

    /** Пара, для которой установлено плечо*/
    case PairWithLeverageInExchange = '%s.%s.l';

    /** Пара, для которой установлен стоп лосс */
    case PairWithSlInExchange = '%s.%s.sl';

    /** Монеты, которые торгуются на бирже и их данные */
    case ContractsInExchange = '%s.contracts';

    /** Информация об открытой позиции */
    case PositionInfo = '%s.%s.position';

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

    public function getKeyForKsMessage(string $messageId): string
    {
        return sprintf($this->value, $messageId);
    }

    public function getKeyForLeverageOfPair(string $exchange, string $symbol): string
    {
        return sprintf($this->value, $exchange, $symbol);
    }

    public function getKeyForSlOfPair(string $exchange, string $symbol): string
    {
        return sprintf($this->value, $exchange, $symbol);
    }

    public function getKeyForContracts(string $exchange): string
    {
        return sprintf($this->value, $exchange);
    }

    public function getKeyForPositionInfo(string $exchange, string $symbol): string
    {
        return sprintf($this->value, $exchange, $symbol);
    }
}
