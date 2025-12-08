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
        Schema::create('trade_pnl_logs', function (Blueprint $table) {
            $table->comment('Сумма прибыли/убытка по проставленному ордеру');
            $table->id();
            $table
                ->foreignId('order_id')
                ->comment('Идентификатор ордера')
                ->constrained('orders')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->decimal('pnl', 12, 8)->comment('Сумма прибыли/убытка');
            $table->decimal('pnl_percent', 12, 9)->nullable()->comment('Процент прибыли/убытка');
            $table->tinyInteger('reason')->nullable()->comment('Что закрыло сделку (TP/SL/Manual)');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_pnl_logs');
    }
};
