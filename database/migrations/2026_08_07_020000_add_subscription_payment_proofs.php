<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('bank');
            $table->string('account_name');
            $table->string('account_no');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('subscription_plan_id')
                ->constrained('payment_methods')->nullOnDelete();
            $table->string('payment_bank')->nullable()->after('payment_method_id');
            $table->string('payment_account_name')->nullable()->after('payment_bank');
            $table->string('payment_account_no')->nullable()->after('payment_account_name');
            $table->string('payment_screenshot_path')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn([
                'payment_bank',
                'payment_account_name',
                'payment_account_no',
                'payment_screenshot_path',
            ]);
        });

        Schema::dropIfExists('payment_methods');
    }
};
