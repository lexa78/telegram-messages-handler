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
        Schema::table('orders', function (Blueprint $table) {
            $table
                ->foreignId('last_exit_order_id')
                ->comment('id последнего сработавшего ордера')
                ->nullable()
                ->constrained('order_targets')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_last_exit_order_id_foreign');
            $table->dropColumn('last_exit_order_id');
        });
    }
};
