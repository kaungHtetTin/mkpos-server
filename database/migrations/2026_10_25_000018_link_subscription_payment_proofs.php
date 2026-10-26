<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->foreignId('business_subscription_id')->nullable()->constrained('business_subscriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('business_subscription_id'));
    }
};
