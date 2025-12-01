<?php

namespace App\Providers;

use App\Patterns\Adapters\Exchange\BybitApiJob;
use Illuminate\Support\ServiceProvider;

/**
 * Предназначен, чтобы получать Singleton объект из фабрики по созданию Exchange Api Object
 */
class ExchangeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Если работа будет только с одной биржей, то нужно будет этот Singleton заменить на Api Class своей биржи
        // Singleton для Bybit
        $this->app->singleton(BybitApiJob::class, function ($app) {
            return new BybitApiJob();
        });

        // Если планирутся работа с несколькими биржами, то ниже добавляем нужные Singletons
        // Наример, тут Singleton для Binance
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
