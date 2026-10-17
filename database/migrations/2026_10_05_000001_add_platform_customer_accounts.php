<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone', 80)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('platform_customer_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->index(['platform_customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->dropIndex(['platform_customer_id', 'id']);
            $table->dropConstrainedForeignId('platform_customer_id');
        });
        Schema::dropIfExists('platform_customer_tokens');
        Schema::dropIfExists('platform_customers');
    }
};
