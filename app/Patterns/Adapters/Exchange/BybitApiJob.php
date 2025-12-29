<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

use App\Enums\Cache\CacheKeysEnum;
use App\Enums\Trading\OrderDirectionsEnum;
use App\Enums\Trading\OrderStatusesEnum;
use App\Enums\Trading\OrderTypesEnum;
use App\Enums\Trading\TriggerTypesEnum;
use App\Enums\Trading\TypesOfTriggerWorkEnum;
use App\Jobs\AbstractChannelJob;
use App\Repositories\Trading\OrderRepository;
use App\Repositories\Trading\OrderTargetRepository;
use App\Services\AbstractCacheService;
use App\Services\Trading\RiskManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BybitApiJob extends AbstractExchangeApi
{
    protected const string EXCHANGE_NAME = 'bybit';

    protected const float MIN_MONEY_IN_ORDER = 5.0;

    public function handle(
        RiskManager $riskManager,
        OrderRepository $orderRepository,
        OrderTargetRepository $orderTargetRepository,
    ): void {
        // подготавливаем пару для отправки по api
        $symbol = $this->prepareSymbol($this->orderData['symbol']);
        // получаем актуальную информацию о цене
        $url = $this->apiUrlBeginning.'/v5/market/tickers';
        $this->orderData['entry'] = $this->getPrice($url, $symbol);
        // если в сообщении не был найден stopLoss, то берем 5% от цены
        if ($this->orderData['stopLoss'] === AbstractChannelJob::NOT_FOUND_PLACEHOLDER) {
            $entry = (float) $this->orderData['entry'];
            if ($this->orderData['direction'] === AbstractExchangeApi::LONG_DIRECTION) {
                $this->orderData['stopLoss'] = $entry - ($entry * 0.05);
            } else {
                $this->orderData['stopLoss'] = $entry + ($entry * 0.05);
            }
        }

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
        if ($accountBalance < self::MIN_MONEY_IN_ORDER) {
            Log::channel('exchangeApiErrors')
                ->error(
                    'На балансе всего $' . $accountBalance,
                    [
                        'channel_id' => $this->orderData['channelId'],
                        'orderData' => $this->orderData,
                    ],
                );
            return;
        }

        // Сколько денег хотим использовать (3% от баланса) или минимальную сумму по требованиям биржи
        $balanceToUse = $riskManager->balanceToUseFromPercent($accountBalance, RiskManager::RISK_PERCENT_FOR_LOST);
        if ($balanceToUse < self::MIN_MONEY_IN_ORDER) {
            $balanceToUse = self::MIN_MONEY_IN_ORDER;
        }

        // Считаем неокругленный qty по риску (linear)
        $rawQty = $riskManager->calculateQtyFromRiskLinear(
            $balanceToUse,
            (float) $this->orderData['entry'],
            (float) $this->orderData['stopLoss'],
        );

        // Приводим к шагу и проверяем minOrderValue/minQty
        $qty = $riskManager->applyQtyStep($rawQty, $qtyStep);
        $qty = $riskManager->enforceLimits($qty, $qtyStep, $minQty, $this->orderData['entry']);

        // Если необходимая маржа > $accountBalance, уменьшаем qty
        $qty = $riskManager->fitQtyByMargin(
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

        $cacheKey = CacheKeysEnum::PairWithLeverageInExchange
            ->getKeyForLeverageOfPair(self::EXCHANGE_NAME, $symbol);
        $isIssetLeverage = Cache::get($cacheKey, false);
        if ($isIssetLeverage === false) {
            // ставим плечо для пришедшей пары
            $url = $this->apiUrlBeginning.'/v5/position/set-leverage';
            $this->placeLeverage($url, $symbol);
            Cache::put($cacheKey, true, AbstractCacheService::TREE_MINUTES_CACHE_TTL);
        }

        // ставим Order
        $url = $this->apiUrlBeginning.'/v5/order/create';
        $body = [
            'category' => self::MARKET_LINEAR_CATEGORY,
            'symbol' =>	$symbol,
            'side' => $this->orderData['direction'],
            'orderType' => self::MARKET_ORDER_TYPE,
            'qty' => (string) $qty,
        ];
        $response = $this->placeOrderOrTp($url, $body);
        if ($response['retCode'] !== 0) {
            Log::channel('exchangeApiErrors')
                ->error(
                    'Ошибка Установки Order',
                    [
                        'channel_id' => $this->orderData['channelId'],
                        'url' => $url,
                        'params' => $body,
                        'orderData' => $this->orderData,
                        'responseMessage' => $response['retMsg'],
                        'responseCode' => $response['retCode'],
                    ],
                );
            return;
        }
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

        $cacheKey = CacheKeysEnum::PairWithSlInExchange
            ->getKeyForSlOfPair(self::EXCHANGE_NAME, $symbol);
        $isIssetSl = Cache::get($cacheKey, false);
        if ($isIssetSl === false) {
            // Канал Cvc не дает TP и SL. Потому буду брать SL как 5% от цены
            if ($this->orderData['stopLoss'] === AbstractChannelJob::NOT_FOUND_PLACEHOLDER) {
                $this->orderData['stopLoss'] = $this
                    ->getPercentFromEntryPriceForSL($this->orderData['direction'], $this->orderData['entry']);
            }
            // ставим stopLoss
            $url = $this->apiUrlBeginning.'/v5/position/trading-stop';
            $body = [
                'category' => self::MARKET_LINEAR_CATEGORY,
                'symbol' => $symbol,
                'tpslMode' => self::FULL_CLOSE_LIMIT_MODE,
                'positionIdx' => 0,
                'stopLoss' => (string) $this->orderData['stopLoss'],
                'slTriggerBy' => self::PRICE_TYPE_FOR_SL_TRIGGER_WORK,
            ];
            $response = $this->placeStopLoss($url, $body);
            $now = Carbon::createFromTimestamp($response['time']);
            $stopLossDataToSave = [
                'exchange_tp_id' => 'SL',
                'type' => TriggerTypesEnum::SL->value,
                'price' => $this->orderData['stopLoss'],
                'qty' => $qty,
                'trigger_by' => TypesOfTriggerWorkEnum::MarkPrice->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            Cache::put($cacheKey, true, AbstractCacheService::TREE_MINUTES_CACHE_TTL);
        }

        // ставим takeProfit(ы)
        $url = $this->apiUrlBeginning.'/v5/order/create';
        $body = [
            'category' => self::MARKET_LINEAR_CATEGORY,
            'symbol' => $symbol,
            'side' => self::OPPOSITE_DIRECTION_MAP[$this->orderData['direction']],
            'orderType' => self::LIMIT_ORDER_TYPE,
            'reduceOnly' => true,
        ];

        // Канал Ks рисует таргеты на картинках, по-этому пока буду брать 15% от точки входа
        if (
            count($this->orderData['targets']) === 1 &&
            head($this->orderData['targets']) === AbstractChannelJob::NOT_FOUND_PLACEHOLDER
        ) {
            $this->orderData['targets'] = [
                $this->getPercentFromEntryPriceForTP($this->orderData['direction'], $this->orderData['entry'])
            ];
        }

        $weights = $riskManager->splitTargetsQty($qty, count($this->orderData['targets']), $qtyStep);
        [$this->orderData['targets'], $weights] =
            $this->rebuildTargetsIfWeightsEmpty($this->orderData['targets'], $weights, $qty);
        $takeProfitDataToSave = [];
        foreach ($this->orderData['targets'] as $key => $target) {
            $body['price'] = (string) $target;
            $body['qty'] = (string) $weights[$key];
            if ($key === 0) {
                continue;
            }
            $response = $this->placeOrderOrTp($url, $body);
            if ($response['retCode'] !== 0) {
                Log::channel('exchangeApiErrors')
                    ->error(
                        'Ошибка Установки takeProfit',
                        [
                            'channel_id' => $this->orderData['channelId'],
                            'url' => $url,
                            'params' => $body,
                            'orderData' => $this->orderData,
                            'responseMessage' => $response['retMsg'],
                            'responseCode' => $response['retCode'],
                        ],
                    );
                return;
            }
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
            $id = $orderRepository->insertGetId($orderDataToSave);
            $stopLossDataToSave['order_id'] = $id;
            foreach ($takeProfitDataToSave as &$takeProfitData) {
                $takeProfitData['order_id'] = $id;
            }
            unset($takeProfitData);
            $takeProfitDataToSave[] = $stopLossDataToSave;
            $orderTargetRepository->insert($takeProfitDataToSave);
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
