<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->default('');
            $table->string('barcode')->default('');
            $table->string('category')->default('');
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('cost')->default(0);
            $table->unsignedBigInteger('base_cost')->default(0);
            $table->decimal('stock', 15, 3)->default(0);
            $table->decimal('low_stock_threshold', 15, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('active_barcode')->nullable()->storedAs("CASE WHEN is_active = 1 AND barcode <> '' THEN barcode ELSE NULL END");
            $table->timestamps();
            $table->index(['business_id', 'name', 'sku', 'barcode']);
            $table->unique(['business_id', 'active_barcode']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->unsignedBigInteger('price')->default(0);
            $table->boolean('is_manual')->default(true);
            $table->unique(['business_id', 'product_id', 'name']);
        });

        Schema::create('price_type_rules', function (Blueprint $table) {
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->enum('pricing_mode', ['manual', 'automatic'])->default('manual');
            $table->decimal('markup_percent', 10, 3)->default(0);
            $table->unsignedInteger('rounding')->default(1);
            $table->unsignedBigInteger('minimum_profit')->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->primary(['business_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->default('');
            $table->text('address')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['business_id', 'name', 'phone']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->default('');
            $table->text('address')->nullable();
            $table->string('contact_person')->default('');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['business_id', 'name', 'phone', 'contact_person']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_no');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('payment_type', ['cash', 'credit']);
            $table->string('payment_method')->default('Cash');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedBigInteger('credit_amount')->default(0);
            $table->string('status')->default('completed');
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->unique(['business_id', 'receipt_no']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name');
            $table->string('price_type', 50)->default('Retail');
            $table->decimal('quantity', 15, 3);
            $table->decimal('foc_quantity', 15, 3)->default(0);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->unsignedBigInteger('line_total');
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name')->default('');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('total_cost')->default(0);
            $table->string('status')->default('completed');
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name');
            $table->decimal('quantity', 15, 3);
            $table->decimal('foc_quantity', 15, 3)->default(0);
            $table->unsignedBigInteger('unit_cost');
            $table->decimal('effective_unit_cost', 15, 3)->default(0);
            $table->unsignedBigInteger('line_total');
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['customer_to_shop', 'shop_to_customer'])->default('customer_to_shop');
            $table->unsignedBigInteger('amount');
            $table->string('payment_method')->default('Cash');
            $table->text('note')->nullable();
            $table->string('status')->default('completed');
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('category')->default('');
            $table->unsignedBigInteger('amount');
            $table->string('payment_method')->default('Cash');
            $table->date('expense_date');
            $table->text('note')->nullable();
            $table->string('status')->default('completed');
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
            $table->index(['business_id', 'expense_date']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value');
            $table->primary(['business_id', 'key']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('movement_type');
            $table->decimal('quantity_change', 15, 3);
            $table->string('reference_type')->default('');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['business_id', 'product_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'stock_movements', 'settings', 'expenses', 'customer_payments',
            'purchase_items', 'purchases', 'sale_items', 'sales', 'suppliers',
            'customers', 'price_type_rules', 'product_prices', 'products',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
