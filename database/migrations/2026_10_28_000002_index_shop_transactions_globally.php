<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_payments', fn (Blueprint $table) => $table->index('transaction_key', 'ecommerce_payments_global_transaction_key_index'));
    }

    public function down(): void
    {
        Schema::table('ecommerce_payments', fn (Blueprint $table) => $table->dropIndex('ecommerce_payments_global_transaction_key_index'));
    }
};
