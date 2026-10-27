<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_admin_proposals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->uuid('token_id');
            $table->string('credential_version', 64);
            $table->string('tool', 80);
            $table->uuid('idempotency_key');
            $table->string('arguments_hash', 64);
            $table->json('arguments');
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['admin_id', 'tool', 'idempotency_key'], 'mcp_admin_proposal_once');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_admin_proposals');
    }
};
