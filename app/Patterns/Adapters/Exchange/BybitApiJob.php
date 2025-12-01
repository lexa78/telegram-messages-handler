<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

class BybitApiJob extends AbstractExchangeApi
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

    public function handle(): void
    {
        //получаем актуальную информацию о цене
        $price = $this->getPrice($this->orderData['symbol']);

        //рассчитываем количество
        // todo написать формулу правильно (например, проверить, что price > 0)
        $this->orderData['qty'] = ($this->orderData['balanceToUse'] * $this->orderData['leverage']) / $price;

        //ставим Order
        $result = $this->placeOrder($this->orderData);

        //todo обработать result: запомнить id ордера, чтобы отслеживать его и channelId,
        // чтобы анализировать прибыль/убыток от этого канала
        // бросить исключение, если ордер не проставился

        // SL и TP
        //todo реализовать обработку SL и TP
        // имеется ввиду, если sl был поставлен в placeOrder и он один, то placeStopLoss делать не нужно
        // если tp был один и он был поставлен в placeOrder то placeTakeProfit делать не нужно
        // обязательно учесть передачу нужных параметров, например reduce_only, stop_loss_price, take_profit_price
        $result = $this->placeStopLoss($this->orderData);
        foreach ($this->orderData['targets'] as $tpPrice) {
            $this->orderData['tpPrice'] = $tpPrice;
            $result = $this->placeTakeProfit($this->orderData);
        }

    }
}
