<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('marketplace_categories')->restrictOnDelete();
            $table->string('name_en', 160);
            $table->string('name_my', 160)->nullable();
            $table->string('slug', 160)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by_admin_id');
            $table->unsignedBigInteger('updated_by_admin_id');
            $table->timestamps();
            $table->index(['is_active', 'sort_order', 'id']);
        });
        // Serialize hierarchy writes to prevent concurrent moves forming a cycle.
        Schema::create('marketplace_category_locks', fn (Blueprint $table) => $table->unsignedTinyInteger('id')->primary());
        DB::table('marketplace_category_locks')->insert(['id' => 1]);
        Schema::create('marketplace_category_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->index();
            $table->unsignedBigInteger('admin_id');
            $table->string('action', 20);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_category_audits');
        Schema::dropIfExists('marketplace_category_locks');
        Schema::dropIfExists('marketplace_categories');
    }
};
