<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_product_categories', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained('products')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('marketplace_categories')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('updated_by_admin_id');
            $table->timestamps();
            $table->index(['business_id', 'category_id']);
        });
        Schema::create('marketplace_product_category_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('admin_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_product_category_audits');
        Schema::dropIfExists('marketplace_product_categories');
    }
};
