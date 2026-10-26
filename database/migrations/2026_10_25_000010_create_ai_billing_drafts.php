<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_billing_drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('user_id');
            $t->uuid('conversation_id');
            $t->unsignedBigInteger('message_id');
            $t->unsignedBigInteger('image_message_id');
            $t->unsignedBigInteger('subscription_plan_id')->nullable();
            $t->unsignedBigInteger('payment_method_id')->nullable();
            $t->text('message')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->string('status', 20)->default('draft');
            $t->string('proof_path')->nullable();
            $t->char('image_hash', 64)->nullable();
            $t->uuid('validation_id')->nullable();
            $t->json('review')->nullable();
            $t->char('preview_hash', 64)->nullable();
            $t->text('error')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->unsignedBigInteger('subscription_request_id')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'message_id']);
            $t->index(['business_id', 'user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_billing_drafts');
    }
};
