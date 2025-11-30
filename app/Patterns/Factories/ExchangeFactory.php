<?php

declare(strict_types=1);

namespace App\Patterns\Factories;

use App\Patterns\Adapters\Exchange\AbstractExchangeApi;
use App\Patterns\Adapters\Exchange\BybitApi;

/**
 * Возвращает объект Api биржи в зависимости от полученного названия
 */
class ExchangeFactory
{
    public static function make(string $exchange): ?AbstractExchangeApi
    {
        return match ($exchange) {
            config('exchanges.default_exchange') => app(BybitApi::class),
            //'binance' => app(BinanceApi::class), for example
            // todo подумать, бросать Exception или возвращать null
            default => null,
        };
    }
}
