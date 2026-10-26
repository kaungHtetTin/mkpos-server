<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_business_actions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('user_id');
            $t->uuid('conversation_id');
            $t->unsignedBigInteger('message_id');
            $t->string('request_key', 64);
            $t->string('operation', 50);
            $t->string('status', 20)->default('pending');
            $t->json('payload');
            $t->json('baseline');
            $t->json('preview');
            $t->string('preview_hash', 64);
            $t->json('result')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'message_id', 'request_key'], 'ai_action_request_unique');
            $t->index(['business_id', 'user_id', 'conversation_id', 'created_at'], 'ai_action_owner_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_business_actions');
    }
};
