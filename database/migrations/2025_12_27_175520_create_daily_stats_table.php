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
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->comment('Cron script будет записывать сюда информацию в конце дня');
            $table->id();
            $table->date('date')->comment('Дата статистики');
            $table
                ->foreignId('channel_id')
                ->comment('Для какого канала статистика')
                ->constrained('channels')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table
                ->decimal('pnl', 22, 8)
                ->nullable()
                ->comment('Прибыль/убыток за день');
            $table
                ->decimal('pnl_percent', 9, 3)
                ->nullable()
                ->comment('Процент прибыли/убытка за день');
            $table->integer('orders_count')->comment('Количество ордеров за день');
            $table->integer('wins')->comment('Количество положительных ордеров за день');
            $table->integer('losses')->comment('Количество отрицательных ордеров за день');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
