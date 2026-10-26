<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_purchase_drafts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('image_message_id');
            $t->uuid('conversation_id');
            $t->json('data');
            $t->timestamps();
            $t->index(['business_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_purchase_drafts');
    }
};
