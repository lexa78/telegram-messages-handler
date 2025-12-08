<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->comment('Таблица ордеров');
            $table->id();
            $table->string('exchange_order_id')->comment('id ордера, полученный от биржи');
            $table
                ->foreignId('channel_id')
                ->comment('Из какого канала пришёл сигнал')
                ->constrained('channels')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('symbol', 10)->comment('Какая пара в ордере');
            $table->tinyInteger('direction')->comment('Направление ордера Buy/Sell');
            $table->tinyInteger('type')->comment('Тип ордера Market/Limit');
            $table->smallInteger('leverage')->comment('Размер плеча');
            $table
                ->decimal('entry_price', 22, 8)
                ->comment('По какой цене вошли');
            $table
                ->decimal('sl_price', 22, 8)
                ->nullable()
                ->comment('Цена стоп лосса');
            $table
                ->decimal('qty', 22, 8)
                ->comment('Количество покупки');
            $table
                ->decimal('remaining_qty', 22, 8)
                ->nullable()
                ->comment('Оставшееся количество после срабатывания TP');
            $table
                ->tinyInteger('status')
                ->comment('Статус ордера (Open, PartiallyClosed, Closed, Cancelled)');
            $table->timestamp('opened_at')->comment('Время открытия ордера');
            $table->timestamp('closed_at')->nullable()->comment('Время полного закрытия ордера');
            $table
                ->decimal('enter_balance', 22, 8)
                ->comment('Баланс на момент входа');
            $table
                ->decimal('pnl', 22, 8)
                ->nullable()
                ->comment('Прибыль/убыток ордера на момент закрытия. Пересчитывается каждый раз, когда срабатывает TP/SL');
            $table
                ->decimal('pnl_percent', 9, 3)
                ->nullable()
                ->comment('Процент прибыли/убытка ордера на момент закрытия. Пересчитывается каждый раз, когда срабатывает TP/SL');
            $table
                ->decimal('commission', 22, 8)
                ->nullable()
                ->comment('Коммиссия сделки');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
