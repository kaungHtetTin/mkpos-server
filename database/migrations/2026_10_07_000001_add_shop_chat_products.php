<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop_chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable();
            $table->json('product_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_chat_messages', fn (Blueprint $table) => $table->dropColumn(['product_id', 'product_snapshot']));
    }
};
