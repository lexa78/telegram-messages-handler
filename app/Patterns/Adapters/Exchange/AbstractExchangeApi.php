<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

use App\Enums\Cache\CacheKeysEnum;
use App\Exceptions\Exchanges\AbstractExchangeException;
use App\Exceptions\Exchanges\Traiding\GetCurrentBalanceException;
use App\Exceptions\Exchanges\Traiding\GetLotSizeFilterFromExchangeResponseException;
use App\Exceptions\Exchanges\Traiding\GetPriceFromExchangeResponseException;
use App\Exceptions\Exchanges\Traiding\GetSymbolLimitsException;
use App\Exceptions\Exchanges\Traiding\GetTickerException;
use App\Exceptions\Exchanges\Traiding\SetLeverageException;
use App\Exceptions\Exchanges\Traiding\SetOrderOrTpException;
use App\Exceptions\Exchanges\Traiding\SetStopLossException;
use App\Repositories\Trading\OrderRepository;
use App\Repositories\Trading\OrderTargetRepository;
use App\Services\AbstractCacheService;
use App\Services\Trading\RiskManager;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

abstract class AbstractExchangeApi
{
    private const int RECV_WINDOW = 5000;

    protected const string EXCHANGE_NAME  = '';

    // todo потом эту константу надо будет вынести в админку, чтобы там можно было менять этот процент
    protected const float RISK_PERCENT_FOR_LOST = 0.03;

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

    public const string LONG_DIRECTION = 'Buy';

    public const string SHORT_DIRECTION = 'Sell';

    protected string $apiKey;

    protected string $apiSecret;

    protected string $apiUrlBeginning;

    public function __construct(
        protected readonly RiskManager $riskManager,
        protected readonly OrderRepository $orderRepository,
        protected readonly OrderTargetRepository $orderTargetRepository,
        protected array $orderData
    ) {
        $exchangeName = config('exchanges.default_exchange');
        $apiKeys = config('exchanges.api_keys.' . $exchangeName);
        $this->apiKey = $apiKeys['api_key'];
        $this->apiSecret = $apiKeys['api_secret'];
        $this->apiUrlBeginning = $apiKeys['api_url'];
    }

    abstract public function handle(): void;

    /**
     * Формирует заголовки для подписания GET запросов
     */
    private function createSignatureForHeaders(array $params = [], bool $isGet = false): array
    {
        if ($isGet === true) {
            $queryStringOrBody = http_build_query($params);
        } else {
            $queryStringOrBody = json_encode($params, JSON_UNESCAPED_SLASHES);;
        }
        $timestamp = (int)(microtime(true) * 1000);

        // строка для подписи
        $signPayload = $timestamp . $this->apiKey . self::RECV_WINDOW . $queryStringOrBody;

        // подпись
        $signature = hash_hmac('sha256', $signPayload, $this->apiSecret);

        return [
            'X-BAPI-SIGN'         => $signature,
            'X-BAPI-API-KEY'      => $this->apiKey,
            'X-BAPI-TIMESTAMP'    => $timestamp,
            'X-BAPI-RECV-WINDOW'  => self::RECV_WINDOW,
        ];
    }

    /**
     * Получение текущей информации о паре
     */
    protected function getPrice(string $url, string $symbol): float
    {
        $cacheKey = CacheKeysEnum::CurrentPriceForSymbolInExchange
            ->getKeyForSymbolPrice(static::EXCHANGE_NAME, $symbol);
        return Cache::remember($cacheKey, AbstractCacheService::TWO_SECONDS_CACHE_TTL, function () use ($url, $symbol): float {
            try {
                $response = Http::get($url, [
                    'category' => self::MARKET_LINEAR_CATEGORY,
                    'symbol' => $symbol,
                ])
                    ->throw()
                    ->json();
            } catch (Throwable $e) {
                throw new GetTickerException(
                    message: 'Ошибка получения тикера',
                    code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                    context: [
                        'symbol' => $symbol,
                        'url' => $url,
                        'params' => [
                            'category' => self::MARKET_LINEAR_CATEGORY,
                            'symbol' => $symbol,
                        ],
                        'orderData' => $this->orderData,
                        'responseBody' => $e->response?->body(),
                        'statusCode' => $e->response?->status(),
                    ],
                    previous: $e,
                );
            }

            $price = $response['result']['list'][0]['lastPrice'] ?? null;
            if (empty($price)) {
                throw new GetPriceFromExchangeResponseException(
                    message: 'Ошибка получения цены из ответа биржи $response[\'result\'][\'list\'][0][\'lastPrice\']',
                    code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                    context: [
                        'symbol' => $symbol,
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
     * Подготовка наименования пары для отправки по api
     * Пример: на входе btc, на выходе BTCUSDT
     */
    protected function prepareSymbol(string $symbol): string
    {
        $symbol = Str::upper($symbol);
        if (!Str::contains($symbol, self::SECOND_WORD_FOR_PAIR, true)) {
            $symbol .= self::SECOND_WORD_FOR_PAIR;
        }

        return $symbol;
    }

    /**
     * Получение лимитов для корректной простановки ордера
     *
     * @return array<string, string>
     */
    protected function getLimits(string $url, string $symbol): array
    {
        $cacheKey = CacheKeysEnum::PairLimitsForSymbolInExchange
            ->getKeyForSymbolLimits(static::EXCHANGE_NAME, $symbol);
        return Cache::remember($cacheKey, AbstractCacheService::ONE_HOUR_CACHE_TTL, function () use ($url, $symbol): array {
            try {
                $response = Http::get($url, [
                    'category' => self::MARKET_LINEAR_CATEGORY,
                    'symbol' => $symbol,
                ])
                    ->throw()
                    ->json();
            } catch (Throwable $e) {
                throw new GetSymbolLimitsException(
                    message: 'Ошибка при получении лимитов для пары',
                    code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                    context: [
                        'symbol' => $symbol,
                        'url' => $url,
                        'params' => [
                            'category' => self::MARKET_LINEAR_CATEGORY,
                            'symbol' => $symbol,
                        ],
                        'orderData' => $this->orderData,
                        'responseBody' => $e->response?->body(),
                        'statusCode' => $e->response?->status(),
                    ],
                    previous: $e,
                );
            }

            $lotSizeFilter = $response['result']['list'][0]['lotSizeFilter'] ?? null;
            if ($lotSizeFilter === null) {
                throw new GetLotSizeFilterFromExchangeResponseException(
                    message: 'Ошибка при получении ключа lotSizeFilter из ответа от биржи $response[\'result\'][\'list\'][0][\'lotSizeFilter\']',
                    code: AbstractExchangeException::EXCEPTION_CODE_BY_DEFAULT,
                    context: [
                        'symbol' => $symbol,
                        'orderData' => $this->orderData,
                        'exchangeResponse' => $response,
                    ]
                );

            }

            return [
                // Минимальное количество для ордера
                'minQty' => $lotSizeFilter['minOrderQty'],
                // Шаг изменения количества, чтобы отправить в ордере правильное количество
                'qtyStep' => $lotSizeFilter['qtyStep'],
            ];
        });
    }

    /**
     * Получение лимитов для корректной простановки ордера
     */
    protected function getCurrentBalance(string $url): float
    {
        $cacheKey = CacheKeysEnum::CurrentBalanceForExchange->getKeyForBalance(static::EXCHANGE_NAME);
        return Cache::remember($cacheKey, AbstractCacheService::HALF_OF_HOUR_CACHE_TTL, function () use ($url): float {
            $query = [
                'accountType' => 'UNIFIED',
                'coin' => self::SECOND_WORD_FOR_PAIR,
            ];
            try {
                $response = Http::withHeaders($this->createSignatureForHeaders($query, true))
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

            return (float) $response['result']['list'][0]['totalAvailableBalance'];
        });
    }

    /**
     * Установка плеча для пары
     */
    protected function placeLeverage(string $url, string $symbol): array
    {
        $body = [
            'category' => self::MARKET_LINEAR_CATEGORY,
            'symbol' => $symbol,
            'buyLeverage' => $this->orderData['leverage'],
            'sellLeverage' => $this->orderData['leverage'],
        ];

        try {
            $response = Http::withHeaders($this->createSignatureForHeaders($body))
                ->post($url, $body)
                ->throw()
                ->json();
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
                message: 'Ошибка при постановке ' . $aim,
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

    protected function placeTakeProfit(array $orderData): array
    {

    }

    // применение middleware RateLimited, чтобы не было слишком частых запросов в биржу, чтобы не забанили
    public function middleware(): array
    {
        return [
            new RateLimited('exchange-job'),
        ];
    }
}
