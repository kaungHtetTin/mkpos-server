<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_support_settings', function (Blueprint $table) {
            $table->boolean('groups_enabled')->default(false);
        });
        Schema::create('telegram_support_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_id');
            $table->bigInteger('chat_id');
            $table->string('title', 255);
            $table->string('status', 16)->default('pending');
            $table->boolean('is_member')->default(true);
            $table->boolean('reply_mentions')->default(true);
            $table->boolean('reply_to_bot')->default(true);
            $table->unsignedInteger('daily_limit')->default(100);
            $table->unsignedInteger('per_user_daily_limit')->default(20);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['bot_id', 'chat_id']);
        });
        Schema::table('customer_service_conversations', function (Blueprint $table) {
            $table->dropUnique(['bot_id', 'chat_id']);
        });
        Schema::table('customer_service_conversations', function (Blueprint $table) {
            $table->bigInteger('chat_id')->change();
            $table->unsignedBigInteger('sender_id')->default(0);
            $table->unsignedBigInteger('thread_id')->default(0);
            $table->unique(['bot_id', 'chat_id', 'sender_id', 'thread_id'], 'customer_service_chat_participant_unique');
        });
    }

    public function down(): void
    {
        // Preserve group history and signed chat IDs; rolling back old application code
        // safely ignores group updates. Do not merge participants' private histories.
    }
};
