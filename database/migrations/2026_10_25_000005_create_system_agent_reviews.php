<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_agent_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->unsignedBigInteger('payment_request_id')->unique();
            $table->char('evidence_hash', 64)->nullable()->index();
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
            $table->string('model', 100)->default('deepseek-flash');
            $table->json('report')->nullable();
            $table->timestamps();
        });
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->decimal('requested_amount', 18, 2)->nullable();
            $table->string('requested_currency', 20)->nullable();
            $table->string('transaction_id', 120)->nullable();
            $table->char('payment_transaction_key', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_agent_reviews');
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->dropIndex(['payment_transaction_key']);
            $table->dropColumn(['requested_amount', 'requested_currency', 'transaction_id', 'payment_transaction_key']);
        });
    }
};
