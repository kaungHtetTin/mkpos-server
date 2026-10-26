<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_service_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_id');
            $table->unsignedBigInteger('chat_id');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['bot_id', 'chat_id']);
        });
        Schema::create('customer_service_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_id')->constrained('customer_service_conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('bot_id');
            $table->unsignedBigInteger('update_id');
            $table->unsignedBigInteger('telegram_message_id');
            $table->unsignedBigInteger('reply_message_id')->nullable();
            $table->unsignedInteger('conversation_version');
            $table->string('language', 2)->default('en');
            $table->string('status', 24);
            $table->boolean('uses_ai')->default(false);
            // Conversation text is encrypted with APP_KEY, never copied into queue payloads.
            $table->text('input')->nullable();
            $table->text('reply')->nullable();
            $table->string('model', 100)->default('deepseek-flash');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->nullable();
            $table->json('tools')->nullable();
            $table->timestamp('charged_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamps();
            $table->unique(['bot_id', 'update_id']);
            $table->index(['status', 'available_at']);
            $table->index(['conversation_id', 'created_at']);
        });
        Schema::create('customer_service_budget_days', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('requests')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service_messages');
        Schema::dropIfExists('customer_service_conversations');
        Schema::dropIfExists('customer_service_budget_days');
    }
};
