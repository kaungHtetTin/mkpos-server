<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_branding', function (Blueprint $table) {
            $table->string('theme_mode', 8)->default('light');
            $table->json('theme_palettes')->nullable();
            $table->unsignedInteger('theme_version')->default(1);
            $table->string('theme_source', 30)->default('baseline');
        });
        Schema::create('platform_theme_previews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('admin_id')->index();
            $table->string('primary_color', 7);
            $table->json('palettes');
            $table->text('rationale');
            $table->string('estimated_cost_usd', 30)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_theme_previews');
        Schema::table('platform_branding', fn (Blueprint $table) => $table->dropColumn(['theme_mode', 'theme_palettes', 'theme_version', 'theme_source']));
    }
};
