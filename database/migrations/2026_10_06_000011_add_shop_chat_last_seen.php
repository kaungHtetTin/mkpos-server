<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop_chat_threads', function (Blueprint $table) { $table->timestamp('customer_last_seen_at')->nullable(); });
        Schema::create('shop_chat_presence', function (Blueprint $table) {
            $table->foreignId('business_id')->primary()->constrained('businesses')->cascadeOnDelete();
            $table->timestamp('owner_last_seen_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('shop_chat_presence');
        Schema::table('shop_chat_threads', function (Blueprint $table) { $table->dropColumn('customer_last_seen_at'); });
    }
};
