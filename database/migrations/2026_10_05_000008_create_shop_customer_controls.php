<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_customer_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('customer_key', 80);
            $table->string('phone')->default('');
            $table->boolean('blocked')->default(false);
            $table->foreignId('credit_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'customer_key']);
            $table->index(['business_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_customer_controls');
    }
};
