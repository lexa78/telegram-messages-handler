<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

use Illuminate\Queue\Middleware\RateLimited;

abstract class AbstractExchangeApi
{
    protected string $apiKey;

    protected string $apiSecret;

    public function __construct(protected array $orderData) {
        $exchangeName = config('exchanges.default_exchange');
        $apiKeys = config('exchanges.api_keys.' . $exchangeName);
        $this->apiKey = $apiKeys['api_key'];
        $this->apiSecret = $apiKeys['api_secret'];
    }

    abstract protected function getPrice(string $symbol): float;

    abstract protected function placeOrder(array $orderData): array;

    abstract protected function placeStopLoss(array $orderData): array;

    abstract protected function placeTakeProfit(array $orderData): array;

    abstract public function handle(): void;

    // применение middleware RateLimited, чтобы не было слишком частых запросов в биржу, чтобы не забанили
    public function middleware(): array
    {
        return [
            new RateLimited('exchange-job'),
        ];
    }
}
