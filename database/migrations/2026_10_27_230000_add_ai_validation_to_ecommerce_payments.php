<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_payments', function (Blueprint $table) {
            $table->text('ai_validation')->nullable();
            $table->string('transaction_key', 64)->nullable();
            $table->index(['business_id', 'transaction_key']);
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_payments', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'transaction_key']);
            $table->dropColumn(['ai_validation', 'transaction_key']);
        });
    }
};
