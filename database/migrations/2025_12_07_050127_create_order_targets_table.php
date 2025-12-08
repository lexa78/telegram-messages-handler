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
        Schema::create('order_targets', function (Blueprint $table) {
            $table->comment('Точки закрытия ордера');
            $table->id();
            $table
                ->foreignId('order_id')
                ->comment('Для какого ордера эта точка')
                ->constrained('orders')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('exchange_tp_id')->nullable()->comment('id ордера, полученный от биржи');
            $table->tinyInteger('type')->comment('Тип точки выхода (TP/SL)');
            $table
                ->decimal('price', 22, 8)
                ->comment('По какой цене должен стработать');
            $table
                ->decimal('qty', 22, 8)
                ->comment('Какое количество будет убрано из ордера');
            $table
                ->tinyInteger('trigger_by')
                ->comment('Каким образом сработает триггер (MarkPrice/LastPrice/SL)');
            $table->boolean('is_triggered')->default(false)->comment('Сработал ли триггер');
            $table->timestamp('triggered_at')->nullable()->comment('Время срабатывания триггера');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_targets');
    }
};
