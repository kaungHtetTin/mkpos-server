<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_support_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('enabled')->nullable();
            $table->text('bot_token_encrypted')->nullable();
            $table->text('webhook_secret_encrypted')->nullable();
            $table->string('webhook_url', 2048)->nullable();
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('per_chat_daily_limit')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->string('bot_username', 64)->nullable();
            $table->timestamp('last_registered_at')->nullable();
            $table->timestamps();
        });
        // Nulls retain the existing server configuration until first saved in Office.
        DB::table('telegram_support_settings')->insert(['id' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_support_settings');
    }
};
