<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

use App\Enums\Trading\OrderDirectionsEnum;
use App\Enums\Trading\OrderStatusesEnum;
use App\Enums\Trading\OrderTypesEnum;
use App\Enums\Trading\TriggerTypesEnum;
use App\Enums\Trading\TypesOfTriggerWorkEnum;
use App\Exceptions\Exchanges\AbstractExchangeException;
use App\Exceptions\Exchanges\Traiding\GetPriceFromExchangeResponseException;
use App\Exceptions\Exchanges\Traiding\GetTickerException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class BybitApiJob extends AbstractExchangeApi
{
    protected const string EXCHANGE_NAME = 'bybit';

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
        $url = $this->apiUrlBeginning.'/v5/market/tickers';
        $price = $this->getPrice($url, $symbol);

        // получаем лимиты для корректных данных при постановке ордера
        $url = $this->apiUrlBeginning.'/v5/market/instruments-info';
        $limits = $this->getLimits($url, $symbol);
        // Шаг изменения количества, чтобы отправить в ордере правильное количество
        $qtyStep = (float) $limits['qtyStep'];
        // Минимальное количество для ордера
        $minQty = (float) $limits['minQty'] ?? 0;

        // Получаем баланс аккаунта (available balance) заранее
        $url = $this->apiUrlBeginning.'/v5/account/wallet-balance';
        // USDT на торговом кошельке
        $accountBalance = $this->getCurrentBalance($url);

        // Сколько денег хотим использовать (3% от баланса)
        $balanceToUse = $this->riskManager->balanceToUseFromPercent($accountBalance, self::RISK_PERCENT_FOR_LOST);

        // Считаем неокругленный qty по риску (linear)
        $rawQty = $this->riskManager->calculateQtyFromRiskLinear(
            $balanceToUse,
            $this->orderData['entry'],
            $this->orderData['stopLoss'],
        );

        // Приводим к шагу и проверяем minOrderValue/minQty
        $qty = $this->riskManager->applyQtyStep($rawQty, $qtyStep);
        $qty = $this->riskManager->enforceLimits($qty, $qtyStep, $minQty, $this->orderData['entry']);

        // Если необходимая маржа > $accountBalance, уменьшаем qty
        $qty = $this->riskManager->fitQtyByMargin(
            $qty,
            $this->orderData['entry'],
            $this->orderData['leverage'],
            $accountBalance,
            $qtyStep,
        );

        // Если qty == 0 то денег не осталось, ордер не поставить
        if ($qty === 0.0) {
            Log::error('Недостаточно денег на счете, чтобы поставить ордер', [
                'orderData' => $this->orderData,
                'balance' => $accountBalance,
            ]);
            return;
        }

        // ставим плечо для пришедшей пары
        $url = $this->apiUrlBeginning.'/v5/position/set-leverage';
        $this->placeLeverage($url, $symbol);

        // ставим Order
        $url = $this->apiUrlBeginning.'/v5/order/create';
        $body = [
            'category' => self::MARKET_LINEAR_CATEGORY,
            'symbol' =>	$symbol,
            'side' => $this->orderData['direction'],
            'orderType' => self::MARKET_ORDER_TYPE,
            'qty' => $qty,
        ];
        $response = $this->placeOrderOrTp($url, $body);
        $now = Carbon::createFromTimestamp($response['time']);
        $orderDataToSave = [
            'exchange_order_id' => $response['result']['orderId'],
            'channel_id' => $this->orderData['channelId'],
            'symbol' => $symbol,
            'direction' => $this->orderData['direction'] === self::LONG_DIRECTION
                ? OrderDirectionsEnum::Buy->value
                : OrderDirectionsEnum::Sell->value,
            'type' => OrderTypesEnum::Market->value,
            'leverage' => $this->orderData['leverage'],
            'entry_price' => $this->orderData['entry'],
            'sl_price' => $this->orderData['stopLoss'],
            'qty' => $qty,
            'status' => OrderStatusesEnum::Open->value,
            'opened_at' => $now,
            'enter_balance' => $accountBalance,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // ставим stopLoss
        $url = $this->apiUrlBeginning.'/v5/position/trading-stop';
        $body = [
            'category' => self::MARKET_LINEAR_CATEGORY,
            'symbol' => $symbol,
            'tpslMode' => self::FULL_CLOSE_LIMIT_MODE,
            'positionIdx' => 0,
            'stopLoss' => $this->orderData['stopLoss'],
            'slTriggerBy' => self::PRICE_TYPE_FOR_SL_TRIGGER_WORK,
        ];
        $response = $this->placeStopLoss($url, $body);
        $now = Carbon::createFromTimestamp($response['time']);
        $stopLossDataToSave = [
            'type' => TriggerTypesEnum::SL->value,
            'price' => $this->orderData['stopLoss'],
            'qty' => $qty,
            'trigger_by' => TypesOfTriggerWorkEnum::MarkPrice->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // ставим takeProfit(ы)
        $url = $this->apiUrlBeginning.'/v5/order/create';
        $body = [
            'category' => self::MARKET_LINEAR_CATEGORY,
            'symbol' => $symbol,
            'side' => self::OPPOSITE_DIRECTION_MAP[$this->orderData['direction']],
            'orderType' => self::LIMIT_ORDER_TYPE,
            'reduceOnly' => true,
        ];
        $weights = $this->riskManager->splitTargetsQty($qty, count($this->orderData['targets']));
        $takeProfitDataToSave = [];
        foreach ($this->orderData['targets'] as $key => $target) {
            $body['price'] = $target;
            $body['qty'] = $weights[$key];
            $response = $this->placeOrderOrTp($url, $body);
            $now = Carbon::createFromTimestamp($response['time']);
            $takeProfitDataToSave[] = [
                'exchange_tp_id' => $response['result']['orderId'],
                'type' => TriggerTypesEnum::TP->value,
                'price' => $body['price'],
                'qty' => $body['qty'],
                'trigger_by' => TypesOfTriggerWorkEnum::MarkPrice->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::beginTransaction();
        try {
            $id = $this->orderRepository->insertGetId($orderDataToSave);
            $stopLossDataToSave['order_id'] = $id;
            foreach ($takeProfitDataToSave as &$takeProfitData) {
                $takeProfitData['order_id'] = $id;
            }
            unset($takeProfitData);
            $takeProfitDataToSave[] = $stopLossDataToSave;
            $this->orderTargetRepository->insert($takeProfitDataToSave);
        } catch (Throwable $e) {
            Log::error('Ошибка записи информации об ордере. Описание: ' . $e->getMessage(), [
                'orderDataToSave' => $orderDataToSave,
                'takeProfitDataToSave' => $takeProfitDataToSave,
            ]);
            DB::rollBack();
        }
        DB::commit();
    }
}
