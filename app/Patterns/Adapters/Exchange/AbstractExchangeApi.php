<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

use App\Enums\CacheKeysEnum;
use App\Exceptions\Exchanges\AbstractExchangeException;
use App\Exceptions\Exchanges\Price\GetLotSizeFilterFromExchangeResponseException;
use App\Exceptions\Exchanges\Price\GetPriceFromExchangeResponseException;
use App\Exceptions\Exchanges\Price\GetSymbolLimitsException;
use App\Exceptions\Exchanges\Price\GetTickerException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

abstract class AbstractExchangeApi
{
    private const int TWO_SECONDS_CACHE_TTL = 2;

    private const int ONE_HOUR_CACHE_TTL = 3600;

    protected const string CATEGORY_FOR_TICKER = 'linear';

    protected const string SECOND_WORD_FOR_PAIR = 'USDT';

    protected const string EXCHANGE_NAME  = '';

    protected string $apiKey;

    protected string $apiSecret;

    protected string $apiUrlBeginning;

    public function __construct(protected array $orderData) {
        $exchangeName = config('exchanges.default_exchange');
        $apiKeys = config('exchanges.api_keys.' . $exchangeName);
        $this->apiKey = $apiKeys['api_key'];
        $this->apiSecret = $apiKeys['api_secret'];
        $this->apiUrlBeginning = $apiKeys['api_url'];
    }

    /**
     * Получение текущей информации о паре
     */
    protected function getPrice(string $url, string $symbol): float
    {
        $cacheKey = CacheKeysEnum::CurrentPriceForSymbolInExchange
            ->getKeyForSymbolPrice(static::EXCHANGE_NAME, $symbol);
        return Cache::remember($cacheKey, self::TWO_SECONDS_CACHE_TTL, function () use ($url, $symbol): float {
            try {
                $response = Http::get($url, [
                    'category' => self::CATEGORY_FOR_TICKER,
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
                            'category' => self::CATEGORY_FOR_TICKER,
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


    abstract protected function placeOrder(array $orderData): array;

    abstract protected function placeStopLoss(array $orderData): array;

    abstract protected function placeTakeProfit(array $orderData): array;

    abstract public function handle(): void;

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
     */
    protected function getLimits(string $url, string $symbol): array
    {
        $cacheKey = CacheKeysEnum::PairLimitsForSymbolInExchange
            ->getKeyForSymbolLimits(static::EXCHANGE_NAME, $symbol);
        return Cache::remember($cacheKey, self::ONE_HOUR_CACHE_TTL, function () use ($url, $symbol): array {
            try {
                $response = Http::get($url, [
                    'category' => self::CATEGORY_FOR_TICKER,
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
                            'category' => self::CATEGORY_FOR_TICKER,
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

    // применение middleware RateLimited, чтобы не было слишком частых запросов в биржу, чтобы не забанили
    public function middleware(): array
    {
        return [
            new RateLimited('exchange-job'),
        ];
    }
}
