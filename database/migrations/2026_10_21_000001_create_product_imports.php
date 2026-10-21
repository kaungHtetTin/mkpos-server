<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('filename');
            $table->char('fingerprint', 64);
            $table->string('status', 20)->default('preview');
            $table->unsignedInteger('total_rows');
            $table->timestamps();
            $table->unique(['business_id', 'fingerprint']);
        });
        Schema::create('product_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('import_id')->constrained('product_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('name')->default('');
            $table->json('payload');
            $table->string('status', 20);
            $table->json('errors')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamps();
            $table->unique(['import_id', 'row_number']);
            $table->index(['import_id', 'status', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_rows');
        Schema::dropIfExists('product_imports');
    }
};
