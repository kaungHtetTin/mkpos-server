<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_management_audits', function (Blueprint $t) {
            $t->id();
            $t->uuid('action_id')->unique();
            $t->unsignedBigInteger('business_id');
            $t->unsignedBigInteger('user_id');
            $t->string('operation', 80);
            $t->json('review');
            $t->json('result');
            $t->timestamp('created_at');
            $t->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_management_audits');
    }
};
