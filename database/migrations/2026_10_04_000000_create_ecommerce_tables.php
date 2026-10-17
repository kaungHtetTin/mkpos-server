<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('storefront_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('slug')->unique();
            $t->string('display_name');
            $t->text('description')->nullable();
            $t->string('phone', 80)->default('');
            $t->boolean('storefront_enabled')->default(false);
            $t->boolean('accepting_orders')->default(false);
            $t->boolean('marketplace_enabled')->default(false);
            $t->boolean('marketplace_suspended')->default(false);
            $t->text('marketplace_suspension_reason')->nullable();
            $t->string('price_type', 50)->default('Retail');
            $t->text('pickup_instructions')->nullable();
            $t->json('delivery_zones');
            $t->json('payment_methods');
            $t->unsignedInteger('expiration_hours')->default(24);
            $t->timestamps();
        });
        Schema::create('ecommerce_product_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('published')->default(false);
            $t->text('description')->nullable();
            $t->string('unit', 20)->default('base');
            $t->timestamps();
        });
        Schema::create('ecommerce_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40)->unique();
            $t->string('origin', 20);
            $t->string('status', 30)->default('pending');
            $t->string('payment_status', 30)->default('unpaid');
            $t->string('customer_name');
            $t->string('phone', 80);
            $t->string('email')->nullable();
            $t->string('fulfillment', 20);
            $t->text('address')->nullable();
            $t->text('notes')->nullable();
            $t->text('fulfillment_instructions')->nullable();
            $t->json('payment_method');
            $t->string('currency', 20);
            $t->unsignedBigInteger('subtotal');
            $t->unsignedBigInteger('delivery_fee')->default(0);
            $t->unsignedBigInteger('total');
            $t->string('tracking_hash', 64);
            $t->string('idempotency_key', 80);
            $t->string('request_hash', 64);
            // Keep the sale identifier after the existing POS deletion/void workflow.
            $t->unsignedBigInteger('sale_id')->nullable()->unique();
            $t->boolean('sale_voided')->default(false);
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['business_id', 'idempotency_key']);
            $t->index(['business_id', 'status', 'id']);
            $t->index(['status', 'expires_at']);
        });
        Schema::create('ecommerce_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('ecommerce_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('product_id');
            $t->string('name');
            $t->string('unit_name', 50);
            $t->string('price_type', 50);
            $t->decimal('conversion_factor', 15, 3);
            $t->decimal('quantity', 15, 3);
            $t->decimal('base_quantity', 15, 3);
            $t->unsignedBigInteger('unit_price');
            $t->unsignedBigInteger('unit_cost');
            $t->unsignedBigInteger('line_total');
        });
        Schema::create('ecommerce_stock_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('ecommerce_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('product_id');
            $t->decimal('quantity', 15, 3);
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['order_id', 'product_id']);
            $t->index(['business_id', 'product_id', 'status']);
        });
        Schema::create('ecommerce_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('ecommerce_orders')->cascadeOnDelete();
            $t->string('type', 20);
            $t->unsignedBigInteger('amount')->default(0);
            $t->string('reference')->nullable();
            $t->string('proof_path')->nullable();
            $t->string('status', 20);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->text('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('ecommerce_order_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->constrained('ecommerce_orders')->cascadeOnDelete();
            $t->string('event', 40);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->unsignedBigInteger('delivery_fee')->default(0);
        });
        Schema::table('sale_items', function (Blueprint $t) {
            $t->string('ecommerce_unit_name', 50)->nullable();
            $t->decimal('ecommerce_quantity', 15, 3)->nullable();
            $t->unsignedBigInteger('ecommerce_unit_price')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $t) => $t->dropColumn(['ecommerce_unit_name', 'ecommerce_quantity', 'ecommerce_unit_price']));
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('delivery_fee'));
        foreach (['ecommerce_order_events', 'ecommerce_payments', 'ecommerce_stock_reservations', 'ecommerce_order_items', 'ecommerce_orders', 'ecommerce_product_settings', 'storefront_settings'] as $table) Schema::dropIfExists($table);
    }
};
