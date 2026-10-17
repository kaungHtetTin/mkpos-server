<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_product_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('updated_by_admin_id')->nullable()->change();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
        });
        Schema::table('marketplace_product_category_audits', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_id')->nullable()->change();
            $table->unsignedBigInteger('user_id')->nullable();
        });
    }
    public function down(): void
    {
        // Nullable admin IDs remain valid for historical merchant audit entries.
        Schema::table('marketplace_product_categories', fn (Blueprint $table) => $table->dropColumn('updated_by_user_id'));
        Schema::table('marketplace_product_category_audits', fn (Blueprint $table) => $table->dropColumn('user_id'));
    }
};
