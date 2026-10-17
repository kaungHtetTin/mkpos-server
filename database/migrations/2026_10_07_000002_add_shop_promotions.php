<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('discount_type', 20);
            $table->unsignedBigInteger('discount_value');
            $table->unsignedBigInteger('minimum_subtotal')->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->decimal('quantity_limit', 15, 3)->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->decimal('used_quantity', 15, 3)->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'type', 'product_id']);
        });
        Schema::create('shop_promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained('shop_promotions')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('ecommerce_orders')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->unsignedBigInteger('discount');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['promotion_id', 'order_id']);
        });
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('discount')->default(0);
            $table->json('promotions')->nullable();
        });
        Schema::table('ecommerce_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('original_unit_price')->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->unsignedBigInteger('coupon_discount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_order_items', fn (Blueprint $table) => $table->dropColumn(['original_unit_price', 'promotion_id', 'coupon_discount']));
        Schema::table('ecommerce_orders', fn (Blueprint $table) => $table->dropColumn(['discount', 'promotions']));
        Schema::dropIfExists('shop_promotion_redemptions');
        Schema::dropIfExists('shop_promotions');
    }
};
