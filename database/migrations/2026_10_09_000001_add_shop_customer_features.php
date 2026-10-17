<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_customer_favorites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('product_id')->default(0);
            $t->timestamps();
            $t->unique(['customer_id', 'business_id', 'product_id'], 'shop_favorite_unique');
        });
        Schema::create('shop_customer_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $t->string('label', 80);
            $t->string('recipient', 255);
            $t->string('phone', 80);
            $t->text('address');
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->index(['customer_id', 'id']);
        });
        Schema::create('shop_customer_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('event', 80);
            $t->string('entity_key', 100);
            $t->json('payload');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->unique(['customer_id', 'entity_key'], 'shop_notice_unique');
            $t->index(['customer_id', 'read_at', 'id']);
        });
        Schema::create('shop_stock_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamp('notified_at')->nullable();
            $t->timestamps();
            $t->unique(['customer_id', 'product_id']);
            $t->index(['active', 'id']);
        });
        Schema::create('shop_order_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('ecommerce_orders')->cascadeOnDelete();
            $t->string('type', 20);
            $t->string('status', 20)->default('pending');
            $t->text('reason');
            $t->string('photo_path')->nullable();
            $t->text('staff_note')->nullable();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestamps();
            $t->unique(['order_id', 'type']);
            $t->index(['business_id', 'status', 'id']);
        });
        Schema::table('storefront_settings', function (Blueprint $t) {
            $t->json('opening_hours')->nullable();
            $t->text('announcement')->nullable();
            $t->timestamp('closed_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', fn (Blueprint $t) => $t->dropColumn(['opening_hours', 'announcement', 'closed_until']));
        foreach (['shop_order_requests', 'shop_stock_alerts', 'shop_customer_notifications', 'shop_customer_addresses', 'shop_customer_favorites'] as $table) Schema::dropIfExists($table);
    }
};
