<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_chat_threads', fn (Blueprint $t) => $t->string('sales_ai_channel', 16)->default('web'));
        Schema::create('sales_telegram_connections', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('bot_id')->nullable()->unique();
            $t->text('token_encrypted')->nullable();
            $t->text('secret_encrypted');
            $t->string('bot_username')->nullable();
            $t->boolean('enabled')->default(false);
            $t->boolean('groups_enabled')->default(false);
            $t->unsignedInteger('daily_limit')->default(200);
            $t->unsignedInteger('per_user_limit')->default(20);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('registered_at')->nullable();
            $t->timestamps();
        });
        Schema::create('sales_telegram_groups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('sales_telegram_connections')->cascadeOnDelete();
            $t->unsignedBigInteger('bot_id');
            $t->bigInteger('chat_id');
            $t->string('title');
            $t->string('status', 16)->default('pending');
            $t->boolean('is_member')->default(false);
            $t->boolean('reply_mentions')->default(true);
            $t->boolean('reply_to_bot')->default(true);
            $t->unsignedInteger('daily_limit')->default(100);
            $t->unsignedInteger('per_user_limit')->default(20);
            $t->unsignedInteger('version')->default(1);
            $t->string('last_reason', 50)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'bot_id', 'chat_id'], 'sales_tg_group_identity');
        });
        Schema::create('sales_telegram_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('sales_telegram_connections')->cascadeOnDelete();
            $t->unsignedBigInteger('bot_id');
            $t->bigInteger('chat_id');
            $t->unsignedBigInteger('sender_id');
            $t->unsignedBigInteger('topic_id')->default(0);
            $t->foreignId('thread_id')->nullable()->constrained('shop_chat_threads')->nullOnDelete();
            $t->timestamps();
            $t->unique(['connection_id', 'bot_id', 'chat_id', 'sender_id', 'topic_id'], 'sales_tg_conversation_identity');
        });
        Schema::create('sales_telegram_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('sales_telegram_connections')->cascadeOnDelete();
            $t->unsignedBigInteger('bot_id');
            $t->unsignedBigInteger('update_id');
            $t->foreignId('conversation_id')->nullable()->constrained('sales_telegram_conversations')->nullOnDelete();
            $t->unsignedBigInteger('telegram_message_id');
            $t->unsignedInteger('connection_version');
            $t->unsignedInteger('group_version')->nullable();
            $t->foreignId('trigger_message_id')->nullable()->constrained('shop_chat_messages')->nullOnDelete();
            $t->string('status', 32)->default('accepted');
            $t->timestamps();
            $t->unique(['connection_id', 'bot_id', 'update_id'], 'sales_tg_update_dedup');
        });
        Schema::create('sales_telegram_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('update_id')->constrained('sales_telegram_updates')->cascadeOnDelete();
            $t->foreignId('message_id')->nullable()->constrained('shop_chat_messages')->nullOnDelete();
            $t->unsignedInteger('part')->default(0);
            $t->unsignedBigInteger('previous_id')->nullable();
            $t->foreignId('thread_id')->nullable()->constrained('shop_chat_threads')->nullOnDelete();
            $t->text('reply_markup')->nullable();
            $t->text('notice')->nullable();
            $t->string('status', 24)->default('pending');
            $t->string('error_code', 50)->nullable();
            $t->timestamp('available_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'available_at']);
            $t->unique(['message_id', 'part']);
        });
        Schema::create('sales_telegram_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('sales_telegram_connections')->cascadeOnDelete();
            $t->unsignedBigInteger('bot_id');
            $t->unsignedBigInteger('sender_id');
            $t->foreignId('platform_customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['connection_id', 'bot_id', 'sender_id'], 'sales_tg_link_sender');
            $t->unique(['connection_id', 'bot_id', 'platform_customer_id'], 'sales_tg_link_customer');
        });
        Schema::create('sales_telegram_link_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('sales_telegram_connections')->cascadeOnDelete();
            $t->foreignId('platform_customer_id')->constrained('platform_customers')->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->unsignedInteger('connection_version');
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('sales_telegram_checkouts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('conversation_id')->constrained('sales_telegram_conversations')->cascadeOnDelete();
            $t->foreignId('thread_id')->constrained('shop_chat_threads')->cascadeOnDelete();
            $t->uuid('run_id');
            $t->unsignedInteger('connection_version');
            $t->unsignedInteger('version')->default(1);
            $t->string('step', 24)->default('name');
            $t->string('language', 2)->default('en');
            $t->text('payload_encrypted');
            $t->text('quote_encrypted')->nullable();
            $t->text('result_encrypted')->nullable();
            $t->string('order_reference')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['conversation_id', 'step']);
        });
    }

    public function down(): void
    {
        foreach (['sales_telegram_checkouts', 'sales_telegram_link_tokens', 'sales_telegram_links', 'sales_telegram_outbox', 'sales_telegram_updates', 'sales_telegram_conversations', 'sales_telegram_groups', 'sales_telegram_connections'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('shop_chat_threads', fn (Blueprint $t) => $t->dropColumn('sales_ai_channel'));
    }
};
