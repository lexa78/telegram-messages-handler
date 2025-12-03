<?php

declare(strict_types=1);

/*
 * Биржа по умолчанию
 */
return [
    'default_exchange' => env('DEFAULT_EXCHANGE_FOR_TADE', 'bybit'),
    'api_keys' => [
        env('DEFAULT_EXCHANGE_FOR_TADE', 'bybit') => [
            'api_key' => env('BYBIT_API_KEY'),
            'api_secret' => env('BYBIT_API_SECRET'),
            'api_url' => env('BYBIT_API_URL'),
        ],
        //'binance' => '...', for example
    ],
];
