<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ecommerce_orders', fn (Blueprint $table) => $table->string('delivery_fee_recipient', 20)->default('shop'));
    }

    public function down(): void
    {
        Schema::table('ecommerce_orders', fn (Blueprint $table) => $table->dropColumn('delivery_fee_recipient'));
    }
};
