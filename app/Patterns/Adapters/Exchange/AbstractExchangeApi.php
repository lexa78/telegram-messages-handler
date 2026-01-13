<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

use App\Exceptions\Exchanges\AbstractExchangeException;
use App\Exceptions\Exchanges\Traiding\GetCurrentBalanceException;
use App\Exceptions\Exchanges\Traiding\GetSymbolLimitsException;
use App\Exceptions\Exchanges\Traiding\GetTickerException;
use App\Exceptions\Exchanges\Traiding\SetLeverageException;
use App\Exceptions\Exchanges\Traiding\SetOrderOrTpException;
use App\Exceptions\Exchanges\Traiding\SetStopLossException;
use App\Repositories\Trading\OrderRepository;
use App\Repositories\Trading\OrderTargetRepository;
use App\Services\Trading\RiskManager;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

abstract class AbstractExchangeApi
{
    protected const int RECV_WINDOW = 5000;

    protected const string EXCHANGE_NAME = '';

    protected const float MIN_MONEY_IN_ORDER = 0.0;

    protected const string MARKET_LINEAR_CATEGORY = 'linear';

    protected const string SECOND_WORD_FOR_PAIR = 'USDT';

    protected const string MARKET_ORDER_TYPE = 'Market';

    protected const string LIMIT_ORDER_TYPE = 'Limit';

    protected const string FULL_CLOSE_LIMIT_MODE = 'Full';

    protected const string PRICE_TYPE_FOR_SL_TRIGGER_WORK = 'MarkPrice';

    protected const array OPPOSITE_DIRECTION_MAP = [
        self::LONG_DIRECTION => self::SHORT_DIRECTION,
        self::SHORT_DIRECTION => self::LONG_DIRECTION,
    ];

    protected const string GET_METHOD = 'GET';

    protected const string POST_METHOD = 'POST';

    public const string LONG_DIRECTION = 'Buy';

    public const string SHORT_DIRECTION = 'Sell';

    protected string $apiKey;

    protected string $apiSecret;

    protected string $apiUrlBeginning;

    protected ?string $apiVersion;

    public function __construct(protected array $orderData)
    {
        $exchangeName = config('exchanges.default_exchange');
        $apiKeys = config('exchanges.api_keys.'.$exchangeName);
        $this->apiKey = $apiKeys['api_key'];
        $this->apiSecret = $apiKeys['api_secret'];
        $this->apiUrlBeginning = $apiKeys['api_url'];
        $this->apiVersion = $apiKeys['api_version'];
    }

    abstract public function handle(
        RiskManager $riskManager,
        OrderRepository $orderRepository,
        OrderTargetRepository $orderTargetRepository,
    ): void;

    /**
     * Получение цены из ответа от биржи
     */
    abstract protected function getPriceFromResponse(array $response, string $cacheKey): float;

    /**
     * Получение лимитов из ответа от биржи
     */
    protected function getLimitsFromResponse(array $response, string $cacheKey): float
    {
        return 0.0;
    }

    /**
     * Получение баланса из ответа от биржи
     */
    abstract protected function getBalanceFromResponse(array $response, string $cacheKey): float;

    // если в сообщении не был найден stopLoss, то берем PERCENT_FOR_UNDEFINED_STOP_LOSS% от цены
    protected function getDefaultStopLossPercent(float $entry, string $direction, float $priceStep): float
    {
        if ($direction === AbstractExchangeApi::LONG_DIRECTION) {
            $rawSl = $entry - ($entry * RiskManager::PERCENT_FOR_UNDEFINED_STOP_LOSS);

            return floor($rawSl / $priceStep) * $priceStep;
        }

        $rawSl = $entry + ($entry * RiskManager::PERCENT_FOR_UNDEFINED_STOP_LOSS);

        return ceil($rawSl / $priceStep) * $priceStep;
    }

    /**
     * Получение текущей информации о паре
     */
    protected function sendRequestForGetPrice(string $url, array $params): array
    {
        try {
            $response = Http::get($url, $params)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new GetTickerException(
                message: 'Ошибка получения тикера',
                code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                context: [
                    'url' => $url,
                    'params' => $params,
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
     * Подготовка наименования пары для отправки по api
     * Пример: на входе btc, на выходе BTCUSDT если $delimiter !== null, то BTC$delimiterUSDT
     */
    protected function prepareSymbol(string $symbol, ?string $delimiter = null): string
    {
        $symbol = Str::upper($symbol);
        if (!Str::contains($symbol, self::SECOND_WORD_FOR_PAIR, true)) {
            if ($delimiter === null) {
                $symbol .= self::SECOND_WORD_FOR_PAIR;
            } else {
                $symbol .= ($delimiter.self::SECOND_WORD_FOR_PAIR);
            }
        } else {
            if ($delimiter !== null) {
                $firstPartOfSymbol = explode(self::SECOND_WORD_FOR_PAIR, $symbol)[0];
                $symbol = $firstPartOfSymbol.$delimiter.self::SECOND_WORD_FOR_PAIR;
            }
        }

        return $symbol;
    }

    /**
     * Получение лимитов для корректной простановки ордера
     */
    protected function sendRequestForGetLimits(string $url, array $params): array
    {
        try {
            $response = Http::get($url, $params)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new GetSymbolLimitsException(
                message: 'Ошибка при получении лимитов для пары',
                code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                context: [
                    'url' => $url,
                    'params' => $params,
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
     * Получение баланса на счете
     */
    protected function getCurrentBalance(string $url, array $query, array $headers): array
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
                message: 'Ошибка при получении текущего баланса',
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

    /**
     * Установка плеча для пары
     */
    protected function placeLeverage(string $url, string $symbol): array
    {
        $body = [
            'category' => self::MARKET_LINEAR_CATEGORY,
            'symbol' => $symbol,
            'buyLeverage' => (string) $this->orderData['leverage'],
            'sellLeverage' => (string) $this->orderData['leverage'],
        ];

        try {
            $response = Http::withHeaders($this->createSignatureForHeaders($body))
                ->post($url, $body)
                ->throw()
                ->json();
            if ($response['retCode'] !== 0) {
                Log::channel('exchangeApiErrors')
                    ->error(
                        'Ошибка Установки плеча для пары',
                        [
                            'channel_id' => $this->orderData['channelId'],
                            'url' => $url,
                            'params' => $body,
                            'orderData' => $this->orderData,
                            'responseMessage' => $response['retMsg'],
                            'responseCode' => $response['retCode'],
                        ],
                    );
            }
        } catch (Throwable $e) {
            throw new SetLeverageException(
                message: 'Ошибка при постановке плеча для пары',
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
     * Установка ордера или takeProfit
     */
    protected function placeOrderOrTp(string $url, array $body): array
    {
        try {
            $response = Http::withHeaders($this->createSignatureForHeaders($body))
                ->post($url, $body)
                ->throw()
                ->json();
        } catch (Throwable $e) {
            $aim = isset($body['reduceOnly']) ? 'takeProfit' : 'ордера';
            throw new SetOrderOrTpException(
                message: 'Ошибка при постановке '.$aim,
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
    protected function placeStopLoss(string $url, array $body): array
    {
        try {
            $response = Http::withHeaders($this->createSignatureForHeaders($body))
                ->post($url, $body)
                ->throw()
                ->json();
            if ($response['retCode'] !== 0) {
                Log::channel('exchangeApiErrors')
                    ->error(
                        'Ошибка Установки stopLoss',
                        [
                            'channel_id' => $this->orderData['channelId'],
                            'url' => $url,
                            'params' => $body,
                            'orderData' => $this->orderData,
                            'responseMessage' => $response['retMsg'],
                            'responseCode' => $response['retCode'],
                        ],
                    );
            }
        } catch (Throwable $e) {
            throw new SetStopLossException(
                message: 'Ошибка при постановке stopLoss',
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
     * Если в весах для takeProfit есть хоть один 0, то сворачиваем все takeProfit в самый первый
     * и назначаем ему totalQty
     */
    protected function rebuildTargetsIfWeightsEmpty(array $targets, array $weights, float $totalQty): array
    {
        $weights = array_filter($weights);
        if (count($targets) > count($weights)) {
            return [[head($targets)], [$totalQty]];
        }

        return [$targets, $weights];
    }

    /**
     * Получает 15% от цены для простановки TP
     */
    protected function getPercentFromEntryPriceForTP(string $direction, float $entry, float $priceStep): float
    {
        $fifteenPercent = $entry * RiskManager::PERCENT_FOR_UNDEFINED_TAKE_PROFIT;
        if ($direction === self::LONG_DIRECTION) {
            $rawTp = $entry + $fifteenPercent;

            return ceil($rawTp / $priceStep) * $priceStep;
        }

        $rawTp = $entry - $fifteenPercent;

        return floor($rawTp / $priceStep) * $priceStep;
    }

    /**
     * Получает 5% от цены для простановки SL
     */
    protected function getPercentFromEntryPriceForSL(string $direction, float $entry, float $priceStep): float
    {
        $fivePercent = $entry * RiskManager::PERCENT_FOR_UNDEFINED_STOP_LOSS;
        if ($direction === self::LONG_DIRECTION) {
            $rawSl = $entry - $fivePercent;

            return floor($rawSl / $priceStep) * $priceStep;
        }

        $rawSl = $entry + $fivePercent;

        return ceil($rawSl / $priceStep) * $priceStep;
    }

    // применение middleware RateLimited, чтобы не было слишком частых запросов в биржу, чтобы не забанили
    public function middleware(): array
    {
        return [
            new RateLimited('exchange-job'),
        ];
    }
}
