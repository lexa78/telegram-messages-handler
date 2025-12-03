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
        Schema::create('channels', function (Blueprint $table) {
            $table->comment('Таблица с информацией о каналах');
            $table->id();
            $table->string('cid')->comment('id канала в telegram');
            $table->string('name')->comment('имя канала в telegram');
            $table
                ->boolean('is_for_handle')
                ->default(false)
                ->comment('должны ли обрабатываться сообщения из этого канала');
            $table->timestamps();
            $table->unique('cid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
