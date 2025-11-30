<?php

declare(strict_types=1);

namespace App\Patterns\Adapters\Exchange;

abstract class AbstractExchangeApi
{
    protected string $apiKey;

    protected string $apiSecret;

    public function __construct() {
        $exchangeName = config('exchanges.default_exchange');
        $apiKeys = config('exchanges.api_keys.' . $exchangeName);
        $this->apiKey = $apiKeys['api_key'];
        $this->apiSecret = $apiKeys['api_secret'];
    }

    abstract protected function getPrice(string $symbol): float;

    abstract protected function placeOrder(array $orderData): array;

    abstract protected function placeStopLoss(array $orderData): array;

    abstract protected function placeTakeProfit(array $orderData): array;

    abstract public function interactWithExchange(): void;
}
