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
        Schema::table('channels', function (Blueprint $table) {
            $table
                ->decimal('total_pnl', 22, 8)
                ->default(0.0)
                ->comment('общий PnL по всем сделкам');
            $table
                ->decimal('today_pnl', 22, 8)
                ->default(0.0)
                ->comment('PnL за текущий день');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['total_pnl', 'today_pnl']);
        });
    }
};
