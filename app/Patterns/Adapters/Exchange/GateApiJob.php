<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

use App\Enums\Cache\CacheKeysEnum;
use App\Enums\Trading\OrderDirectionsEnum;
use App\Enums\Trading\OrderStatusesEnum;
use App\Enums\Trading\OrderTypesEnum;
use App\Enums\Trading\TriggerTypesEnum;
use App\Enums\Trading\TypesOfTriggerWorkEnum;
use App\Exceptions\Exchanges\AbstractExchangeException;
use App\Exceptions\Exchanges\Traiding\GetCurrentBalanceException;
use App\Exceptions\Exchanges\Traiding\GetLotSizeFilterFromExchangeResponseException;
use App\Exceptions\Exchanges\Traiding\GetPriceFromExchangeResponseException;
use App\Exceptions\Exchanges\Traiding\GetSymbolLimitsException;
use App\Exceptions\Exchanges\Traiding\GetTickerException;
use App\Exceptions\Exchanges\Traiding\SetLeverageException;
use App\Exceptions\Exchanges\Traiding\SetOrderOrTpException;
use App\Exceptions\Exchanges\Traiding\SetStopLossException;
use App\Jobs\AbstractChannelJob;
use App\Repositories\Trading\OrderRepository;
use App\Repositories\Trading\OrderTargetRepository;
use App\Services\AbstractCacheService;
use App\Services\Trading\RiskManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GateApiJob extends AbstractExchangeApi
{
    protected const string EXCHANGE_NAME = 'gate';

    protected const float MIN_MONEY_IN_ORDER = 5.0;

    /**
     * Возвращает rule и order_type на основании направления ордера
     * rule
     * 1: Trigger when the price calculated based on strategy_type and price_type is greater than or equal to Trigger.Price, while Trigger.Price must > last_price
     * 2: Trigger when the price calculated based on strategy_type and price_type is less than or equal to Trigger.Price, and Trigger.Price must < last_price
     * order_type
     * close-long-position: Order take-profit/stop-loss, close long position (все ордера позиции и весь size)
     * close-short-position: Order take-profit/stop-loss, close short position (все ордера позиции и весь size)
     * plan-close-long-position для частичного TP
     * plan-close-short-position для частичного TP
     */
    private function getRuleAndOrderType(int $direction, TriggerTypesEnum $triggerType): array
    {
        $prefixOfOrderType = $triggerType === TriggerTypesEnum::SL ? '' : 'plan-';

        //1 - 'price' > last_price (sl to close short) (tp to close long)
        //2 - 'price' < last_price (sl to close long) (tp to close short)
        if ($direction === OrderDirectionsEnum::Buy->value) {
            $rule = $triggerType === TriggerTypesEnum::SL ? 2 : 1;
            return [$rule, $prefixOfOrderType.'close-long-position'];
        }

        $rule = $triggerType === TriggerTypesEnum::SL ? 1 : 2;

        return [$rule, $prefixOfOrderType.'close-short-position'];
    }

    /**
     * Направление ордера определяется знаком size. Если buy, то size положительный. В противном случае отрицательный
     */
    private function setSizeSignDependingOnDirection(string $direction, int $size): int
    {
        //size > 0  → BUY / long
        //size < 0  → SELL / short

        if ($direction === self::LONG_DIRECTION) {
            return $size;
        }

        return $size * -1;
    }

    /**
     * Проверяет, что leverage в доступных пределах, если нет, то возвращает граничный
     */
    private function checkLeverage(array $contractInfo, int $currentLeverage): int
    {
        if ($currentLeverage < $contractInfo['leverageMin']) {
            return $contractInfo['leverageMin'];
        }

        if ($currentLeverage > $contractInfo['leverageMax']) {
            return $contractInfo['leverageMax'];
        }

        return $currentLeverage;
    }

    /**
     * Подсчет size для простановки ордера
     */
    private function getSize(float $qty, array $limits): int
    {
        $minQty = (float) $limits['minQty'];
        $size = (int) floor($qty / $minQty);

        if ($size === 0 || $size < $limits['orderSizeMin']) {
            $size = $limits['orderSizeMin'];
        }

        if ($size > $limits['orderSizeMax']) {
            $size = $limits['orderSizeMax'];
        }

        return $size;
    }

    /**
     * Создает строку запроса для подписи
     */
    private function buildQueryString(array $query): string
    {
        if (empty($query)) {
            return '';
        }

        ksort($query);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * // При простановке TP может быть ошибка order price 2.0957 while trigger price 2.3193 and deviation-rate limit 0.02
     * // чтобы ее избежать, нужно цену в 'initial'.'price' сделать на RISK_PERCENT_OF_GATE_API_TO_SET_TP% меньше/больше чем в TP
     */
    private function createRightInitialPriceDependingOnGateLimit(float $target, float $openPrice, string $direction): ?float
    {
        $percentOfDiff = 0.0;

        if ($target + $openPrice !== 0.0) { // чтобы не делить на ноль, если сумма чисел 0
            $percentOfDiff = abs($openPrice - $target) / (($target + $openPrice) / 2) * 100;
        }

        if ($percentOfDiff > RiskManager::RISK_PERCENT_OF_GATE_API_TO_SET_TP) {
            $percentOfDiffValue = $openPrice * RiskManager::RISK_PERCENT_OF_GATE_API_TO_SET_TP;
            if ($direction === self::LONG_DIRECTION) {
                return $openPrice + $percentOfDiffValue;
            }

            return $openPrice - $percentOfDiffValue;
        }

        return null;
    }

    /**
     * Получение цены из ответа из биржи
     */
    protected function getPriceFromResponse(array $response, string $cacheKey): float
    {
        return Cache::remember(
            $cacheKey,
            AbstractCacheService::TWO_SECONDS_CACHE_TTL,
            function () use ($response, $cacheKey): float {
                $price = $response[0]['last'] ?? null;
                if (empty($price)) {
                    throw new GetPriceFromExchangeResponseException(
                        message: 'Ошибка получения цены из ответа биржи $response[0][\'last\']',
                        code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                        context: [
                            'cacheKey' => $cacheKey,
                            'price' => $price,
                            'orderData' => $this->orderData,
                            'exchangeResponse' => $response,
                        ]
                    );
                }

                return (float) $price;
            });
    }

    /**
     * Получение баланса из ответа от биржи
     */
    protected function getBalanceFromResponse(array $response, string $cacheKey): float
    {
        return Cache::remember($cacheKey, AbstractCacheService::HALF_OF_HOUR_CACHE_TTL,
            function () use ($cacheKey, $response): float {
                $balance = $response['available'] ?? null;
                if ($balance === null) {
                    throw new GetLotSizeFilterFromExchangeResponseException(
                        message: 'Ошибка при получении ключа баланса из ответа от биржи $response[\'available\']',
                        code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                        context: [
                            'cacheKey' => $cacheKey,
                            'orderData' => $this->orderData,
                            'exchangeResponse' => $response,
                        ]
                    );
                }

                return (float) $balance;
            });
    }

    /**
     * Формирует hash заголовки подписи
     */
    protected function createSignatureHeaders(
        string $method,
        string $path,
        array $query,
        string $body,
        string $timestamp
    ): array {
        $queryString = $this->buildQueryString($query);

        $bodyHash = hash('sha512', $body);

        $payload = Str::upper($method)
            ."\n"
            .$path
            ."\n"
            .$queryString
            ."\n"
            .$bodyHash
            ."\n"
            .$timestamp;

        $sign = hash_hmac('sha512', $payload, $this->apiSecret);

        return [
            'KEY' => $this->apiKey,
            'Timestamp' => $timestamp,
            'SIGN' => $sign,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Заголовки для подписи запроса
     */
    protected function createSignatureForHeaders(int $timestamp, string $sign): array
    {
        return [
            'KEY' => $this->apiKey,
            'Timestamp' => $timestamp,
            'SIGN' => $sign,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Получение информации о монетах
     */
    protected function getContracts(string $url): array
    {
        $cacheKey = CacheKeysEnum::ContractsInExchange->getKeyForContracts(static::EXCHANGE_NAME);
        return Cache::remember(
            $cacheKey,
            AbstractCacheService::CACHE_TTL,
            function () use ($url): array {
                try {
                    $response = Http::get($url)->throw()->json();
                } catch (Throwable $e) {
                    throw new GetSymbolLimitsException(
                        message: 'Ошибка при получении информации о монетах',
                        code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                        context: [
                            'url' => $url,
                            'orderData' => $this->orderData,
                            'responseBody' => $e->response?->body(),
                            'statusCode' => $e->response?->status(),
                        ],
                        previous: $e,
                    );
                }

                if (empty(head($response))) {
                    throw new GetPriceFromExchangeResponseException(
                        message: 'Ошибка получения контрактов',
                        code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                        context: [
                            'exchange' => self::EXCHANGE_NAME,
                            'orderData' => $this->orderData,
                            'exchangeResponse' => $response,
                        ]
                    );
                }

                $result = [];
                foreach ($response as $item) {
                    $result[$item['name']] = [
                        'orderSizeMin' => (int) $item['order_size_min'],
                        'orderSizeMax' => (int) $item['order_size_max'],
                        'leverageMin' => (int) $item['leverage_min'],
                        'leverageMax' => (int) $item['leverage_max'],
                        // Шаг изменения количества, чтобы отправить в ордере правильное количество
                        'qtyStep' => 1,
                        // Минимальное количество для ордера
                        'minQty' => $item['quanto_multiplier'],
                        // Шаг изменения цены, чтобы посчитать SL
                        'orderPriceRound' => (float) $item['order_price_round'],
                    ];
                }

                return $result;
            });
    }

    /**
     * Простановка плеча для пары
     */
    protected function setLeverage(string $url, array $headers): void
    {
        try {
            Http::withHeaders($headers)
                ->send(self::POST_METHOD, $url)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new SetLeverageException(
                message: 'Ошибка при постановке плеча для пары',
                code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                context: [
                    'url' => $url,
                    'headers' => $headers,
                    'orderData' => $this->orderData,
                    'responseBody' => $e->response?->body(),
                    'statusCode' => $e->response?->status(),
                ],
                previous: $e,
            );
        }
    }

    /**
     * Простановка ордера
     */
    protected function placeOrder(string $url, array $body, array $headers): array
    {
        try {
            $response = Http::withHeaders($headers)
                ->post($url, $body)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new SetOrderOrTpException(
                message: 'Ошибка при постановке ордера',
                code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                context: [
                    'url' => $url,
                    'params' => $body,
                    'orderData' => $this->orderData,
                    'responseBody' => $e->response?->body(),
                    'statusCode' => $e->response?->status(),
                ],
                previous: $e,
            );
        }

        return $response;
    }


    /**
     * Установка stopLoss
     */
    protected function setSlOrTp(string $url, array $body, array $headers, string $triggerName): array
    {
        try {
            $response = Http::withHeaders($headers)
                ->post($url, $body)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new SetStopLossException(
                message: 'Ошибка при постановке ' . $triggerName,
                code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                context: [
                    'url' => $url,
                    'params' => $body,
                    'responseBody' => $e->response?->body(),
                    'statusCode' => $e->response?->status(),
                ],
                previous: $e,
            );
        }

        return $response;
    }

    /**
     * Получение информации о позиции
     */
    protected function getPositionInfo(string $url, array $query, array $headers): array
    {
        if ($query === []) {
            $query = null;
        }
        try {
            $response = Http::withHeaders($headers)
                ->get($url, $query)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new GetCurrentBalanceException(
                message: 'Ошибка при получении информации по позиции',
                code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                context: [
                    'url' => $url,
                    'params' => $query,
                    'responseBody' => $e->response?->body(),
                    'statusCode' => $e->response?->status(),
                ],
                previous: $e,
            );
        }

        return $response;
    }

    public function handle(
        RiskManager $riskManager,
        OrderRepository $orderRepository,
        OrderTargetRepository $orderTargetRepository,
    ): void {
        // подготавливаем пару для отправки по api
        $symbol = $this->prepareSymbol($this->orderData['symbol'], '_');

        // получаем информацию о монетах
        $url = $this->apiUrlBeginning.'/futures/usdt/contracts';
        $contracts = $this->getContracts($url);

        // если на бирже не торгуется такая монета, то ничего не делаем
        if (!isset($contracts[$symbol])) {
            Log::alert('Монета '.$symbol.' отсутствует на бирже '.self::EXCHANGE_NAME);

            return;
        }

        // проверяем, что leverage в доступных пределах, если нет, то ставим граничный
        $this->orderData['leverage'] = $this->checkLeverage($contracts[$symbol], (int) $this->orderData['leverage']);

        // получаем актуальную информацию о цене
        $url = $this->apiUrlBeginning.'/futures/usdt/tickers';
        $cacheKey = CacheKeysEnum::CurrentPriceForSymbolInExchange
            ->getKeyForSymbolPrice(static::EXCHANGE_NAME, $symbol);
        $price = Cache::get($cacheKey);
        // Если цену еще не получали или получали давно, то надо обновить и запомнить
        if ($price === null) {
            $priceResponse = $this->sendRequestForGetPrice(
                $url,
                [
                    'contract' => $symbol,
                ]
            );
            $this->orderData['entry'] = $this->getPriceFromResponse($priceResponse, $cacheKey);
        } else {
            $this->orderData['entry'] = $price;
        }

        // если в сообщении не был найден stopLoss, то берем установленный % от цены
        if ($this->orderData['stopLoss'] === AbstractChannelJob::NOT_FOUND_PLACEHOLDER) {
            $this->orderData['stopLoss'] = $this->getDefaultStopLossPercent(
                (float) $this->orderData['entry'],
                $this->orderData['direction'],
                $contracts[$symbol]['orderPriceRound'],
            );
        }

        // Шаг изменения количества, чтобы отправить в ордере правильное количество
        $qtyStep = (float) $contracts[$symbol]['qtyStep'];
        // Минимальное количество для ордера
        $minQty = (float) $contracts[$symbol]['minQty'] ?? 0;

        // Получаем баланс аккаунта (available balance) заранее
        $uri = '/futures/usdt/accounts';
        $url = $this->apiUrlBeginning.$uri;
        // USDT на торговом кошельке
        $cacheKey = CacheKeysEnum::CurrentBalanceForExchange->getKeyForBalance(self::EXCHANGE_NAME);
        $accountBalance = Cache::get($cacheKey);
        // Если баланс еще не получали или получали давно, то надо обновить и запомнить
        if ($accountBalance === null) {
            // USDT на торговом кошельке
            $path = $this->apiVersion.$uri;
            $query = [];
            $timestamp = time();
            $signatureHeaders = $this->createSignatureHeaders(
                self::GET_METHOD,
                $path,
                $query,
                '',
                (string) $timestamp,
            );

            $balanceResponse = $this->getCurrentBalance(
                $url,
                $query,
                $signatureHeaders,
            );
            $accountBalance = $this->getBalanceFromResponse($balanceResponse, $cacheKey);
        }
        if ($accountBalance < self::MIN_MONEY_IN_ORDER) {
            Log::channel('exchangeApiErrors')
                ->error(
                    'На балансе всего $'.$accountBalance,
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
            $uri = '/futures/usdt/positions/'.$symbol.'/leverage';
            $path = $this->apiVersion.$uri;
            $url = $this->apiUrlBeginning.$uri;
            $query = [
                'leverage' => $this->orderData['leverage'],
            ];
            $timestamp = time();
            $signatureHeaders = $this->createSignatureHeaders(
                self::POST_METHOD,
                $path,
                $query,
                '',
                (string) $timestamp,
            );

            $queryString = $this->buildQueryString($query);
            $this->setLeverage($url.'?'.$queryString, $signatureHeaders);
            Cache::put($cacheKey, true, AbstractCacheService::TREE_MINUTES_CACHE_TTL);
        }

        // ставим Order
        $uri = '/futures/usdt/orders';
        $url = $this->apiUrlBeginning.$uri;
        $path = $this->apiVersion.$uri;
        $size = $this->getSize($qty, $contracts[$symbol]);
        $body = [
            'contract' => $symbol,
            'size' => $this->setSizeSignDependingOnDirection($this->orderData['direction'], $size),
            'price' => '0',
            'tif' => 'ioc',
        ];
        $bodyAsString = json_encode($body, JSON_UNESCAPED_SLASHES);
        $query = [];
        $timestamp = time();
        $signatureHeaders = $this->createSignatureHeaders(
            self::POST_METHOD,
            $path,
            $query,
            $bodyAsString,
            (string) $timestamp,
        );

        $response = $this->placeOrder($url, $body, $signatureHeaders);

        $now = Carbon::createFromTimestamp($response['create_time']);
        $orderDataToSave = [
            'exchange_order_id' => $response['id'],
            'channel_id' => $this->orderData['channelId'],
            'symbol' => $symbol,
            'direction' => $this->orderData['direction'] === self::LONG_DIRECTION
                ? OrderDirectionsEnum::Buy->value
                : OrderDirectionsEnum::Sell->value,
            'type' => OrderTypesEnum::Market->value,
            'leverage' => $this->orderData['leverage'],
            'entry_price' => $this->orderData['entry'],
            'sl_price' => $this->orderData['stopLoss'],
            'qty' => $size,
            'remaining_qty' => $size,
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
                $this->orderData['stopLoss'] = $this->getPercentFromEntryPriceForSL(
                    $this->orderData['direction'],
                    $this->orderData['entry'],
                    $contracts[$symbol]['orderPriceRound']
                );
            }

            // Получаем информацию о позиции. В ней есть расчетная цена ликвидации позиции.
            // Если SL больше/меньше этого значения, то SL надо сделать больше/меньше на 1% от этой цены
            $uri = '/futures/usdt/positions/'.$symbol;
            $url = $this->apiUrlBeginning.$uri;
            $cacheKey = CacheKeysEnum::PositionInfo->getKeyForPositionInfo(self::EXCHANGE_NAME, $symbol);
            $positionInfo = Cache::get($cacheKey);
            // Если информацию по позиции еще не получали или получали давно, то надо обновить и запомнить
            if ($positionInfo === null) {
                $path = $this->apiVersion.$uri;
                $query = [];
                $timestamp = time();
                $signatureHeaders = $this->createSignatureHeaders(
                    self::GET_METHOD,
                    $path,
                    $query,
                    '',
                    (string) $timestamp,
                );

                $positionInfo = $this->getPositionInfo(
                    $url,
                    $query,
                    $signatureHeaders,
                );

                Cache::put($cacheKey, $positionInfo, AbstractCacheService::TREE_MINUTES_CACHE_TTL);

                $liqPrice = $positionInfo['liq_price'] ?? null;
                if ($liqPrice !== null) {
                    $liqPrice = (float) $liqPrice;
                    $rawSl = null;
                    if (
                        $this->orderData['direction'] === self::SHORT_DIRECTION
                        && $this->orderData['stopLoss'] > $liqPrice
                    ) {
                        $rawSl = $liqPrice * 0.99;
                    }

                    if (
                        $this->orderData['direction'] === self::LONG_DIRECTION
                        && $this->orderData['stopLoss'] < $liqPrice
                    ) {
                        $rawSl = $liqPrice * 1.01;
                    }

                    if ($rawSl !== null) {
                        // Нужно привести SL к шагу позиции
                        $slPrice = floor($rawSl / $contracts[$symbol]['orderPriceRound']) * $contracts[$symbol]['orderPriceRound'];
                        $this->orderData['stopLoss'] = $slPrice;
                    }
                }
            }

            // ставим stopLoss
            $uri = '/futures/usdt/price_orders';
            $url = $this->apiUrlBeginning.$uri;
            $path = $this->apiVersion.$uri;
            [$rule, $orderType] = $this->getRuleAndOrderType($orderDataToSave['direction'], TriggerTypesEnum::SL);
            $body = [
                'initial' => [
                    'contract' => $symbol,
                    //'size' => 0, size не указывается, чтобы закрыть всю позицию. Вместо него надо "close": true
                    'price' => '0', //Order price. Set to 0 to use market price
                    'reduce_only' => true,
                    'tif' => 'ioc',
                    'close' => true,
                ],
                'trigger' => [
                    'strategy_type' => 0,
                    'price_type' => 1, //0 - Latest trade price, 1 - Mark price, 2 - Index price
                    'price' => (string) $this->orderData['stopLoss'],
                    'rule' => $rule,
                ],
                'order_type' => $orderType,
            ];
            $bodyAsString = json_encode($body, JSON_UNESCAPED_SLASHES);
            $query = [];
            $timestamp = time();
            $signatureHeaders = $this->createSignatureHeaders(
                self::POST_METHOD,
                $path,
                $query,
                $bodyAsString,
                (string) $timestamp,
            );
            $response = $this->setSlOrTp($url, $body, $signatureHeaders, 'stopLoss');
            $now = now();
            $stopLossDataToSave = [
                'exchange_tp_id' => $response['id'],
                'type' => TriggerTypesEnum::SL->value,
                'price' => $this->orderData['stopLoss'],
                'qty' => $size,
                'trigger_by' => TypesOfTriggerWorkEnum::MarkPrice->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            Cache::put($cacheKey, true, AbstractCacheService::TREE_MINUTES_CACHE_TTL);
        }

        // ставим takeProfit(ы)
        // Канал Ks рисует таргеты на картинках, по-этому пока буду брать 15% от точки входа
        $targetsCount = count($this->orderData['targets']);
        if (
            $targetsCount === 1 &&
            head($this->orderData['targets']) === AbstractChannelJob::NOT_FOUND_PLACEHOLDER
        ) {
            $this->orderData['targets'] = [
                $this->getPercentFromEntryPriceForTP(
                    $this->orderData['direction'],
                    $this->orderData['entry'],
                    $contracts[$symbol]['orderPriceRound'],
                )
            ];
        }

        $weights = $riskManager->splitTargetsQty($size, $targetsCount, $contracts[$symbol]['qtyStep']);
        [$this->orderData['targets'], $weights] =
            $this->rebuildTargetsIfWeightsEmpty($this->orderData['targets'], $weights, $size);
        $weightsCount = count($weights);
        if ($weightsCount > 1) {
            $weightsSum = array_sum($weights);
            if ($weightsSum !== $size) {
                $lastWeightIndex = $weightsCount - 1;
                $diff = abs($size - $weightsSum);
                if ($weightsSum < $size) {
                    $weights[$lastWeightIndex] += $diff;
                }
                if ($weightsSum > $size) {
                    $weights[$lastWeightIndex] -= $diff;
                }
            }
        }
        $takeProfitDataToSave = [];
        [$rule, $orderType] = $this->getRuleAndOrderType($orderDataToSave['direction'], TriggerTypesEnum::TP);
        $now = now();
        $body['initial']['price'] = (string) $this->orderData['entry'];
        unset($body['initial']['close']);
        unset($body['initial']['tif']);
        foreach ($this->orderData['targets'] as $key => $target) {
            $size = $this->setSizeSignDependingOnDirection(
                self::OPPOSITE_DIRECTION_MAP[$this->orderData['direction']],
                (int) $weights[$key]
            );
            $body['initial']['size'] = $size;
            $rightInitialPrice = $this->createRightInitialPriceDependingOnGateLimit(
                $target,
                $this->orderData['entry'],
                $this->orderData['direction']
            );
            if ($rightInitialPrice !== null) {
                $body['initial']['price'] = $rightInitialPrice;
            }
            $body['trigger']['price'] = (string) $target;
            $body['trigger']['rule'] = $rule;
            $body['order_type'] = $orderType;

            $bodyAsString = json_encode($body, JSON_UNESCAPED_SLASHES);
            $timestamp = time();
            $signatureHeaders = $this->createSignatureHeaders(
                self::POST_METHOD,
                $path,
                $query,
                $bodyAsString,
                (string) $timestamp,
            );
            $response = $this->setSlOrTp($url, $body, $signatureHeaders, 'takeProfit');
            $takeProfitDataToSave[] = [
                'exchange_tp_id' => $response['id'],
                'type' => TriggerTypesEnum::TP->value,
                'price' => $body['trigger']['price'],
                'qty' => $body['initial']['size'],
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
            Log::error('Ошибка записи информации об ордере. Описание: '.$e->getMessage(), [
                'orderDataToSave' => $orderDataToSave,
                'takeProfitDataToSave' => $takeProfitDataToSave,
            ]);
            DB::rollBack();
        }
        DB::commit();
    }
}
