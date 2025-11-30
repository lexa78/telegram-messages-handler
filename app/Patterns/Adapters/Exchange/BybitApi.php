<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

class BybitApi extends AbstractExchangeApi
{
    protected function getPrice(string $symbol): float
    {
        // todo запрос к /v5/market/tickers или WebSocket
        return 0.0;
    }

    protected function placeOrder(array $orderData): array
    {
        // todo  POST /v5/order
        return [];
    }

    protected function placeStopLoss(array $orderData): array
    {
        // todo  POST /v5/stop-order с reduceOnly
        return [];
    }

    protected function placeTakeProfit(array $orderData): array
    {
        // todo  POST /v5/stop-order с reduceOnly
        return [];
    }

    public function interactWithExchange(): void
    {
        /*
 $orderData = $job->orderData;

// Создаём нужный объект через фабрику
$exchange = ExchangeFactory::make($orderData['exchange']);

$price = $exchange->getPrice($orderData['symbol']);
$orderData['qty'] = ($orderData['balanceToUse'] * $orderData['leverage']) / $price;

$exchange->placeOrder($orderData);

// SL и TP
if (!empty($orderData['stopLoss'])) {
$exchange->placeStopLoss($orderData);
}
foreach ($orderData['targets'] as $tpPrice) {
$orderData['tpPrice'] = $tpPrice;
$exchange->placeTakeProfit($orderData);
}

 */

    }
}
