<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_support_settings', function (Blueprint $table) {
            $table->boolean('images_enabled')->default(false);
            $table->boolean('receipt_notices_enabled')->default(false);
            $table->bigInteger('admin_group_chat_id')->nullable();
            $table->unsignedBigInteger('admin_group_thread_id')->default(0);
        });
        Schema::table('customer_service_messages', function (Blueprint $table) {
            $table->text('image_file_id_encrypted')->nullable();
            $table->string('image_source', 10)->nullable();
            $table->text('image_report_encrypted')->nullable();
        });
        Schema::create('customer_service_receipt_notices', function (Blueprint $table) {
            $table->uuid('message_id')->primary();
            $table->foreign('message_id')->references('id')->on('customer_service_messages')->cascadeOnDelete();
            $table->unsignedBigInteger('bot_id');
            $table->bigInteger('chat_id');
            $table->unsignedBigInteger('thread_id')->default(0);
            $table->string('status', 24)->default('pending');
            $table->string('error_code', 40)->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        // Forward-only: retain encrypted evidence and delivery metadata.
    }
};
