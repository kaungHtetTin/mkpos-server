<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_subscriptions', fn (Blueprint $table) => $table->foreignId('last_changed_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('business_subscriptions', fn (Blueprint $table) => $table->dropConstrainedForeignId('last_changed_by_admin_id'));
    }
};
