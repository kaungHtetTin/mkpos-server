<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_ai_settings', function (Blueprint $t) {
            $t->unsignedBigInteger('business_id')->primary();
            $t->boolean('enabled')->default(false);
            $t->string('name', 80)->default('Sales assistant');
            $t->text('instructions');
            $t->text('soul');
            $t->json('tool_permissions');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('sales_ai_wallets', function (Blueprint $t) {
            $t->unsignedBigInteger('business_id')->primary();
            $t->bigInteger('balance_micros')->default(0);
            $t->bigInteger('reserved_micros')->default(0);
            $t->timestamps();
        });
        Schema::create('sales_ai_topups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('business_id')->index();
            $t->unsignedBigInteger('owner_id');
            $t->uuid('request_key');
            $t->bigInteger('amount_micros');
            $t->unsignedBigInteger('payment_method_id');
            $t->json('payment_account');
            $t->string('screenshot_path');
            $t->string('screenshot_hash', 64)->index();
            $t->string('transaction_reference', 120)->nullable();
            $t->string('transaction_key', 64)->nullable()->unique();
            $t->string('status', 20)->default('pending')->index();
            $t->string('message', 1000)->default('');
            $t->text('admin_note')->nullable();
            $t->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['business_id', 'request_key']);
        });
        Schema::create('sales_ai_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('business_id')->index();
            $t->string('type', 20);
            $t->string('event_key', 150)->unique();
            $t->bigInteger('amount_micros');
            $t->bigInteger('balance_after_micros');
            $t->unsignedBigInteger('topup_id')->nullable();
            $t->uuid('run_id')->nullable()->unique();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('description');
            $t->json('pricing')->nullable();
            $t->timestamp('created_at')->index();
        });
        Schema::create('sales_ai_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('business_id')->index();
            $t->unsignedBigInteger('thread_id')->index();
            $t->unsignedBigInteger('trigger_message_id');
            $t->unsignedInteger('thread_version');
            $t->unsignedInteger('settings_version');
            $t->string('status', 20)->default('pending')->index();
            $t->string('model', 100)->default('deepseek-flash');
            $t->string('agent_name', 80);
            $t->bigInteger('reserved_micros')->default(0);
            $t->bigInteger('charge_micros')->default(0);
            $t->bigInteger('provider_cost_nanos')->nullable();
            $t->unsignedBigInteger('input_tokens')->nullable();
            $t->unsignedBigInteger('output_tokens')->nullable();
            $t->unsignedBigInteger('cached_input_tokens')->nullable();
            $t->json('pricing')->nullable();
            $t->string('reason', 100)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamps();
            $t->unique(['trigger_message_id', 'thread_version']);
        });
        Schema::table('shop_chat_threads', function (Blueprint $t) {
            $t->string('sales_ai_mode', 20)->default('ai');
            $t->unsignedInteger('sales_ai_version')->default(1);
            $t->string('sales_ai_handoff_reason', 160)->nullable();
        });
        Schema::table('shop_chat_messages', function (Blueprint $t) {
            $t->boolean('is_ai')->default(false);
            $t->string('ai_name', 80)->nullable();
            $t->uuid('sales_ai_run_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('shop_chat_messages', fn (Blueprint $t) => $t->dropColumn(['is_ai', 'ai_name', 'sales_ai_run_id']));
        Schema::table('shop_chat_threads', fn (Blueprint $t) => $t->dropColumn(['sales_ai_mode', 'sales_ai_version', 'sales_ai_handoff_reason']));
        foreach (['sales_ai_runs', 'sales_ai_entries', 'sales_ai_topups', 'sales_ai_wallets', 'sales_ai_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
