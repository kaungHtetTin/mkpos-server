<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_ai_settings', fn (Blueprint $t) => $t->json('sales_policies')->nullable());
        Schema::table('shop_order_requests', fn (Blueprint $t) => $t->json('requested_changes')->nullable());
        Schema::create('sales_ai_carts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('thread_id')->unique()->constrained('shop_chat_threads')->cascadeOnDelete();
            $t->foreignId('platform_customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $t->json('items');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('sales_ai_actions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('thread_id')->constrained('shop_chat_threads')->cascadeOnDelete();
            $t->foreignId('platform_customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $t->unsignedBigInteger('trigger_message_id');
            $t->unsignedBigInteger('message_id')->nullable()->index();
            $t->uuid('run_id');
            $t->string('tool', 80);
            $t->string('kind', 30);
            $t->string('status', 20)->default('pending');
            $t->string('request_key', 64);
            $t->unsignedInteger('thread_version');
            $t->unsignedInteger('settings_version');
            $t->unsignedInteger('revision')->default(1);
            $t->json('payload');
            $t->json('preview');
            $t->string('preview_hash', 64);
            $t->json('result')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['thread_id', 'request_key']);
            $t->index(['thread_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_ai_actions');
        Schema::dropIfExists('sales_ai_carts');
        Schema::table('shop_order_requests', fn (Blueprint $t) => $t->dropColumn('requested_changes'));
        Schema::table('sales_ai_settings', fn (Blueprint $t) => $t->dropColumn('sales_policies'));
    }
};
