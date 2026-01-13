<?php

declare(strict_types=1);

namespace App\Patterns\Factories;

use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Adapters\Exchange\GateApiJob;

/**
 * Возвращает объект Api биржи в зависимости от полученного названия
 */
class ExchangeFactory
{
    public static function make(string $exchange, array $orderData): ?AbstractExchangeApi
    {
        return match ($exchange) {
            config('exchanges.default_exchange') => new GateApiJob($orderData),
            //'bybit' => new BybitApiJob($orderData), for example
            default => null,
        };
    }
}
