<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->boolean('enabled')->default(false);
            $t->string('model', 100);
            $t->unsignedInteger('daily_limit')->default(50);
            $t->unsignedInteger('max_tokens')->default(1600);
            $t->timestamps();
        });
        DB::table('ai_assistant_settings')->insert(['id' => 1, 'enabled' => config('ai_assistant.enabled', false), 'model' => config('ai_assistant.model', 'deepseek-flash'), 'daily_limit' => 50, 'max_tokens' => 1600, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        Schema::create('ai_conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 120)->default('New conversation');
            $t->timestamps();
            $t->index(['business_id', 'user_id', 'updated_at']);
        });
        Schema::create('ai_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $t->uuid('client_id')->nullable();
            $t->string('role', 12);
            $t->text('body');
            $t->string('status', 20)->default('pending');
            $t->string('request_hash', 64)->nullable();
            $t->unsignedBigInteger('reply_to_id')->nullable()->unique();
            $t->json('attachment')->nullable();
            $t->unsignedInteger('attempts')->default(1);
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->json('tools')->nullable();
            $t->string('model', 100)->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'client_id']);
            $t->index(['conversation_id', 'id']);
        });
        Schema::create('ai_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('business_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('status', 20);
            $t->string('model', 100);
            $t->json('tools')->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_assistant_settings');
    }
};
