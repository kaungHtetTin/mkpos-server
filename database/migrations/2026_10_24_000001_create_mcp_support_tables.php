<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_support_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('token_hash', 64)->unique();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('credential_version', 64);
            $table->json('scopes');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['actor_type', 'actor_id']);
        });
        Schema::create('mcp_support_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id');
            $table->string('file_path');
            $table->string('sha256', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('mcp_support_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id');
            $table->string('tool', 80);
            $table->uuid('idempotency_key');
            $table->string('arguments_hash', 64);
            $table->json('result');
            $table->timestamps();
            $table->unique(['actor_type', 'actor_id', 'tool', 'idempotency_key'], 'mcp_support_action_once');
        });
        Schema::create('subscription_refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_payment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 20);
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('platform_admins')->restrictOnDelete();
            $table->string('transfer_reference')->nullable()->unique();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_refund_requests');
        Schema::dropIfExists('mcp_support_actions');
        Schema::dropIfExists('mcp_support_evidence');
        Schema::dropIfExists('mcp_support_tokens');
    }
};
