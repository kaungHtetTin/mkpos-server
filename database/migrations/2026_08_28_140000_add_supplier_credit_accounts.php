<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->enum('payment_type', ['cash', 'credit'])->default('cash')->after('total_cost');
            $table->string('payment_method')->default('Cash')->after('payment_type');
            $table->unsignedBigInteger('paid_amount')->default(0)->after('payment_method');
            $table->unsignedBigInteger('credit_amount')->default(0)->after('paid_amount');
            $table->index(['business_id', 'supplier_id', 'status', 'credit_amount'], 'purchases_supplier_credit_index');
        });

        // Purchases created before supplier credit existed were completed as paid purchases.
        DB::table('purchases')->update([
            'payment_type' => 'cash',
            'payment_method' => 'Cash',
            'paid_amount' => DB::raw('total_cost'),
            'credit_amount' => 0,
        ]);

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['shop_to_supplier', 'supplier_to_shop'])->default('shop_to_supplier');
            $table->unsignedBigInteger('amount');
            $table->string('payment_method')->default('Cash');
            $table->text('note')->nullable();
            $table->string('status')->default('completed');
            $table->text('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'supplier_id', 'status', 'created_at'], 'supplier_payments_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex('purchases_supplier_credit_index');
            $table->dropColumn(['payment_type', 'payment_method', 'paid_amount', 'credit_amount']);
        });
    }
};
