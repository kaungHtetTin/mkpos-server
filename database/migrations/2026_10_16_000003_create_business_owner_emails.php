<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_owner_emails', function (Blueprint $table) {
            $table->uuid('idempotency_key')->primary();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('office_admin_id')->index();
            $table->string('payload_hash', 64);
            $table->string('status', 20);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('business_owner_emails'); }
};
