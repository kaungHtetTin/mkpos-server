<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_reports', function (Blueprint $table) {
            $table->string('contact_channel', 20)->nullable();
            $table->string('contact_value', 255)->nullable();
        });
        Schema::create('error_report_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->uuid('conversation_id')->index();
            $table->unsignedBigInteger('message_id')->unique();
            $table->string('app', 30);
            $table->json('payload');
            $table->unsignedBigInteger('report_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_report_drafts');
        Schema::table('error_reports', fn (Blueprint $table) => $table->dropColumn(['contact_channel', 'contact_value']));
    }
};
