<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->uuid('conversation_id')->nullable()->index();
            $table->unsignedBigInteger('message_id')->nullable()->unique();
            $table->string('source', 20)->default('assistant');
            $table->string('app', 30)->default('unknown');
            $table->string('title', 160);
            $table->text('description');
            $table->text('expected_result')->nullable();
            $table->text('attempted_steps')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->text('resolution')->nullable();
            $table->text('internal_note')->nullable();
            $table->json('screenshots')->nullable();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
