<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_theme_previews', function (Blueprint $table) {
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_theme_previews', fn (Blueprint $table) => $table->dropColumn(['input_tokens', 'output_tokens', 'cached_input_tokens']));
    }
};
