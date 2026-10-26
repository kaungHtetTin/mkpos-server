<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_payment_validations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('owner_id')->index();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('subscription_request_id')->nullable()->unique();
            $table->char('binding_hash', 64)->index();
            $table->char('image_hash', 64)->index();
            $table->string('status', 20);
            $table->json('report')->nullable();
            $table->string('model', 100)->default('deepseek-flash');
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_payment_validations');
    }
};
