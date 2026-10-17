<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_chat_threads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('phone', 80)->default('');
            $table->string('guest_token_hash', 64)->nullable();
            $table->unsignedBigInteger('owner_read_id')->default(0);
            $table->unsignedBigInteger('customer_read_id')->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'platform_customer_id']);
            $table->index(['business_id', 'updated_at']);
        });
        Schema::create('shop_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('shop_chat_threads')->cascadeOnDelete();
            $table->uuid('client_id');
            $table->string('sender', 12);
            $table->text('body');
            $table->timestamp('created_at');
            $table->unique(['thread_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_chat_messages');
        Schema::dropIfExists('shop_chat_threads');
    }
};
