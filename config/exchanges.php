<?php

declare(strict_types=1);

/*
 * Биржа по умолчанию
 */
return [
    'default_exchange' => env('DEFAULT_EXCHANGE_FOR_TADE', 'gate'),
    'api_keys' => [
        env('DEFAULT_EXCHANGE_FOR_TADE', 'gate') => [
            'api_key' => env('GATE_API_KEY'),
            'api_secret' => env('GATE_API_SECRET'),
            'api_url' => env('GATE_API_URL'),
            'api_version' => env('GATE_API_VERSION'),
        ],
        //'binance' => '...', for example
    ],
];
