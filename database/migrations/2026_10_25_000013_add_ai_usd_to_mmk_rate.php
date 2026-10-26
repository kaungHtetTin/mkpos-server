<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_assistant_settings', fn (Blueprint $table) => $table->decimal('usd_to_mmk_rate', 15, 6)->nullable());
    }

    public function down(): void
    {
        Schema::table('ai_assistant_settings', fn (Blueprint $table) => $table->dropColumn('usd_to_mmk_rate'));
    }
};
