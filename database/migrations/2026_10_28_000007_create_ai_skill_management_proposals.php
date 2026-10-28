<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_skill_management_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_id')->index();
            $table->string('status', 20)->default('processing');
            $table->text('instruction');
            $table->json('context');
            $table->json('proposal')->nullable();
            $table->json('baseline')->nullable();
            $table->json('result')->nullable();
            $table->decimal('estimated_cost_usd', 18, 9)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_skill_management_proposals');
    }
};
