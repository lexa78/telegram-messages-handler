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
        Schema::create('balances', function (Blueprint $table) {
            $table->comment('Балансы разных типов');
            $table->id();
            $table->string('currency', 8)->default('USDT')->comment('Валюта баланса');
            $table->decimal('sum', 20, 8)->comment('Сумма баланса');
            $table->tinyInteger('type')->comment('Тип баланса (Init, EndOfDay, etc)');
            $table->tinyInteger('exchange')->default(1)->comment('С какой биржи баланс');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};
