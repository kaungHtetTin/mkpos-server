<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_customer_id')->constrained('platform_customers')->cascadeOnDelete();
            // Zero identifies the shop; positive IDs identify products within that shop.
            $table->unsignedBigInteger('target_id')->default(0);
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'target_id', 'platform_customer_id'], 'shop_reviews_customer_target');
            $table->index(['business_id', 'target_id', 'id']);
        });
    }

    public function down(): void { Schema::dropIfExists('shop_reviews'); }
};
