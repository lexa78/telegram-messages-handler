<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

use App\Exceptions\Exchanges\AbstractExchangeException;
use App\Exceptions\Exchanges\Price\GetPriceFromExchangeResponseException;
use App\Exceptions\Exchanges\Price\GetTickerException;
use Illuminate\Support\Facades\Http;

class BybitApiJob extends AbstractExchangeApi
{
    protected const string EXCHANGE_NAME  = 'bybit';

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
        // подготавливаем пару для отправки по api
        $symbol = $this->prepareSymbol($this->orderData['symbol']);
        // получаем актуальную информацию о цене
        $url = $this->apiUrlBeginning . '/v5/market/tickers';
        $price = $this->getPrice($url, $symbol);

        // получаем лимиты для корректных данных при постановке ордера
        $url = $this->apiUrlBeginning . '/v5/market/instruments-info';
        $limits = $this->getLimits($url, $symbol);

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
